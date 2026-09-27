<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_id'] === -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$listingId = (int)$input['listingId'];

try {
    $pdo->beginTransaction();

    // Get the listing
    $listingQuery = "SELECT * FROM player_market_listings WHERE Listing_Id = ? AND Status = 'active' AND Listing_Type = 'fixed'";
    $listingStmt = $pdo->prepare($listingQuery);
    $listingStmt->execute([$listingId]);
    $listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$listing) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Listing not found or no longer active']);
        exit();
    }

    // Cannot buy your own listing
    if ($listing['Seller_Inventory_Id'] == $_SESSION['inventory_id']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'You cannot buy your own listing']);
        exit();
    }

    // Verify seller still has the item
    $sellerItemQuery = "SELECT Content_Item_Id, Item_Quantity FROM content_items 
                        WHERE Content_Item_Id = ? AND Inventory_Id = ?";
    $sellerItemStmt = $pdo->prepare($sellerItemQuery);
    $sellerItemStmt->execute([$listing['Content_Item_Id'], $listing['Seller_Inventory_Id']]);
    $sellerItem = $sellerItemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sellerItem || $sellerItem['Item_Quantity'] < $listing['Quantity']) {
        // Void the listing - seller no longer has the item
        $voidQuery = "UPDATE player_market_listings SET Status = 'voided', Content_Item_Id = NULL WHERE Listing_Id = ?";
        $voidStmt = $pdo->prepare($voidQuery);
        $voidStmt->execute([$listingId]);
        $pdo->commit();
        echo json_encode(['success' => false, 'error' => 'This listing has been voided - the seller no longer has the item']);
        exit();
    }

    // Check if buyer has enough money
    $buyerMoneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $buyerMoneyStmt = $pdo->prepare($buyerMoneyQuery);
    $buyerMoneyStmt->execute([$_SESSION['inventory_id']]);
    $buyerMoney = $buyerMoneyStmt->fetch(PDO::FETCH_ASSOC);

    if ($buyerMoney['Inventory_Money'] < $listing['Fixed_Price']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'You do not have enough credits (need: ' . $listing['Fixed_Price'] . ' Cr, have: ' . $buyerMoney['Inventory_Money'] . ' Cr)']);
        exit();
    }

    // Check buyer inventory space (item type limits)
    $typeQuery = "SELECT custom_item_types.Custom_Item_Type FROM items
        LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
        LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
        WHERE items.Item_Class = ?";
    $typeStmt = $pdo->prepare($typeQuery);
    $typeStmt->execute([$listing['Item_Class']]);
    $itemType = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if ($itemType && $itemType['Custom_Item_Type']) {
        // Get buyer inventory limit for this type
        $limitQuery = "SELECT item_type_inventory_limit.Item_Limit FROM inventories
            LEFT JOIN inventory_types ON inventory_types.Inventory_Type_Id = inventories.Inventory_Type
            LEFT JOIN item_type_inventory_limit ON item_type_inventory_limit.Inventory_Type = inventory_types.Inventory_Type_Id
            WHERE Inventory_Id = ? AND item_type_inventory_limit.Item_Type = ? LIMIT 1";
        $limitStmt = $pdo->prepare($limitQuery);
        $limitStmt->execute([$_SESSION['inventory_id'], $itemType['Custom_Item_Type']]);
        $limit = $limitStmt->fetch(PDO::FETCH_ASSOC);

        if ($limit && $limit['Item_Limit'] != -1) {
            // Get current count for buyer
            $countQuery = "SELECT SUM(Item_Quantity) AS quantity FROM (
                SELECT DISTINCT content_items.Content_Item_Id, custom_item_types.Custom_Item_Type, content_items.Item_Quantity
                FROM content_items
                LEFT JOIN items ON items.item_class = content_items.Item_Class
                LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
                LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
                WHERE Inventory_Id = ? AND custom_item_types.Custom_Item_Type = ?
            ) AS subquery";
            $countStmt = $pdo->prepare($countQuery);
            $countStmt->execute([$_SESSION['inventory_id'], $itemType['Custom_Item_Type']]);
            $currentCount = $countStmt->fetch(PDO::FETCH_ASSOC);

            $count = $currentCount['quantity'] ?? 0;
            if (($count + $listing['Quantity']) > $limit['Item_Limit']) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Not enough inventory space for this item type (limit: ' . $limit['Item_Limit'] . ', current: ' . $count . ')']);
                exit();
            }
        }
    }

    // === Execute the trade ===

    // 1. Deduct money from buyer
    $deductQuery = "UPDATE inventories SET Inventory_Money = Inventory_Money - ? WHERE Inventory_Id = ?";
    $deductStmt = $pdo->prepare($deductQuery);
    $deductStmt->execute([$listing['Fixed_Price'], $_SESSION['inventory_id']]);

    // 2. Add money to seller
    $addMoneyQuery = "UPDATE inventories SET Inventory_Money = Inventory_Money + ? WHERE Inventory_Id = ?";
    $addMoneyStmt = $pdo->prepare($addMoneyQuery);
    $addMoneyStmt->execute([$listing['Fixed_Price'], $listing['Seller_Inventory_Id']]);

    // 3. Remove item from seller inventory
    // Always UPDATE (never DELETE) because the FK on player_market_listings.Content_Item_Id
    // prevents deletion of content_items rows that are still referenced by a listing
    $updateQuery = "UPDATE content_items SET Item_Quantity = Item_Quantity - ? WHERE Content_Item_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$listing['Quantity'], $listing['Content_Item_Id']]);

    // 4. Add item to buyer inventory (check if they already have the same item class + properties)
    $existingBuyerItemQuery = "SELECT Content_Item_Id, Item_Quantity FROM content_items 
                                WHERE Inventory_Id = ? AND Item_Class = ? AND (Item_Properties = ? OR (Item_Properties IS NULL AND ? IS NULL))";
    $existingBuyerItemStmt = $pdo->prepare($existingBuyerItemQuery);
    $existingBuyerItemStmt->execute([$_SESSION['inventory_id'], $listing['Item_Class'], $listing['Item_Properties'], $listing['Item_Properties']]);
    $existingBuyerItem = $existingBuyerItemStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingBuyerItem) {
        $updateBuyerQuery = "UPDATE content_items SET Item_Quantity = Item_Quantity + ? WHERE Content_Item_Id = ?";
        $updateBuyerStmt = $pdo->prepare($updateBuyerQuery);
        $updateBuyerStmt->execute([$listing['Quantity'], $existingBuyerItem['Content_Item_Id']]);
    } else {
        $insertBuyerQuery = "INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) VALUES (?, ?, ?, ?)";
        $insertBuyerStmt = $pdo->prepare($insertBuyerQuery);
        $insertBuyerStmt->execute([$_SESSION['inventory_id'], $listing['Item_Class'], $listing['Quantity'], $listing['Item_Properties']]);
    }

    // 5. Mark listing as sold and release FK reference
    $soldQuery = "UPDATE player_market_listings SET Status = 'sold', Content_Item_Id = NULL WHERE Listing_Id = ?";
    $soldStmt = $pdo->prepare($soldQuery);
    $soldStmt->execute([$listingId]);

    // 6. Clean up: delete the content_items row if it's now at 0 quantity
    $cleanupQuery = "DELETE FROM content_items WHERE Content_Item_Id = ? AND Item_Quantity <= 0";
    $cleanupStmt = $pdo->prepare($cleanupQuery);
    $cleanupStmt->execute([$listing['Content_Item_Id']]);

    // 7. Log for both parties
    $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    // Buyer item log
    $logStmt->execute([$_SESSION['inventory_id'], $listing['Item_Class'], $listing['Quantity'], 
        "Bought from player market for " . $listing['Fixed_Price'] . " Cr"]);
    // Buyer money log
    $logStmt->execute([$_SESSION['inventory_id'], 'MONEY', -$listing['Fixed_Price'], 
        "Bought from player market for " . $listing['Fixed_Price'] . " Cr"]);
    // Seller item log
    $logStmt->execute([$listing['Seller_Inventory_Id'], $listing['Item_Class'], -$listing['Quantity'], 
        "Sold on player market for " . $listing['Fixed_Price'] . " Cr"]);
    // Seller money log
    $logStmt->execute([$listing['Seller_Inventory_Id'], 'MONEY', $listing['Fixed_Price'], 
        "Sold on player market for " . $listing['Fixed_Price'] . " Cr"]);

    // Update session money
    $_SESSION['inventory_money'] = $buyerMoney['Inventory_Money'] - $listing['Fixed_Price'];

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
