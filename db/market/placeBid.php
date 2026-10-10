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
$bidAmount = (float)$input['bidAmount'];

if ($bidAmount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Bid amount must be greater than 0']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get the listing
    $listingQuery = "SELECT * FROM player_market_listings WHERE Listing_Id = ? AND Status = 'active' AND Listing_Type = 'auction'";
    $listingStmt = $pdo->prepare($listingQuery);
    $listingStmt->execute([$listingId]);
    $listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$listing) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Auction not found or no longer active']);
        exit();
    }

    // Cannot bid on your own listing
    if ($listing['Seller_Inventory_Id'] == $_SESSION['inventory_id']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'You cannot bid on your own auction']);
        exit();
    }

    // Check if auction has expired
    if ($listing['End_Date'] && new DateTime($listing['End_Date']) <= new DateTime()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'This auction has ended']);
        exit();
    }

    // Check bid is >= min bid
    if ($bidAmount < $listing['Min_Bid']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Bid must be at least ' . $listing['Min_Bid'] . ' Cr']);
        exit();
    }

    // Check bid is higher than current bid
    if ($listing['Current_Bid'] !== null && $bidAmount <= $listing['Current_Bid']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Bid must be higher than the current bid of ' . $listing['Current_Bid'] . ' Cr']);
        exit();
    }

    // Check if bidder has enough money
    $buyerMoneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $buyerMoneyStmt = $pdo->prepare($buyerMoneyQuery);
    $buyerMoneyStmt->execute([$_SESSION['inventory_id']]);
    $buyerMoney = $buyerMoneyStmt->fetch(PDO::FETCH_ASSOC);

    if ($buyerMoney['Inventory_Money'] < $bidAmount) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'You do not have enough credits (need: ' . $bidAmount . ' Cr, have: ' . $buyerMoney['Inventory_Money'] . ' Cr)']);
        exit();
    }

    // Check buyer inventory space
    $typeQuery = "SELECT custom_item_types.Custom_Item_Type FROM items
        LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
        LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
        WHERE items.Item_Class = ?";
    $typeStmt = $pdo->prepare($typeQuery);
    $typeStmt->execute([$listing['Item_Class']]);
    $itemType = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if ($itemType && $itemType['Custom_Item_Type']) {
        $limitQuery = "SELECT item_type_inventory_limit.Item_Limit FROM inventories
            LEFT JOIN inventory_types ON inventory_types.Inventory_Type_Id = inventories.Inventory_Type
            LEFT JOIN item_type_inventory_limit ON item_type_inventory_limit.Inventory_Type = inventory_types.Inventory_Type_Id
            WHERE Inventory_Id = ? AND item_type_inventory_limit.Item_Type = ? LIMIT 1";
        $limitStmt = $pdo->prepare($limitQuery);
        $limitStmt->execute([$_SESSION['inventory_id'], $itemType['Custom_Item_Type']]);
        $limit = $limitStmt->fetch(PDO::FETCH_ASSOC);

        if ($limit && $limit['Item_Limit'] != -1) {
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
                echo json_encode(['success' => false, 'error' => 'Not enough inventory space for this item type']);
                exit();
            }
        }
    }

    // Update the listing with the new highest bid
    $updateQuery = "UPDATE player_market_listings 
                    SET Current_Bid = ?, Current_Bidder_Inventory_Id = ? 
                    WHERE Listing_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$bidAmount, $_SESSION['inventory_id'], $listingId]);

    // Record the bid
    $bidQuery = "INSERT INTO player_market_bids (Listing_Id, Bidder_Inventory_Id, Bid_Amount) VALUES (?, ?, ?)";
    $bidStmt = $pdo->prepare($bidQuery);
    $bidStmt->execute([$listingId, $_SESSION['inventory_id'], $bidAmount]);

    // Log the bid
    $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([$_SESSION['inventory_id'], $listing['Item_Class'], 0, "Placed bid of " . $bidAmount . " Cr on player market auction"]);

    $pdo->commit();

    // Send Discord webhook notification
    $bidderName = $_SESSION['inventory_name'] ?? 'Unknown';
    $itemName = $listing['Item_Display_Name'] ?? $listing['Item_Class'];
    $previousBid = $listing['Current_Bid'] ? number_format($listing['Current_Bid']) . ' Cr' : 'None';

    $description = "**Item:** {$itemName}\n**Bid Amount:** " . number_format($bidAmount) . " Cr\n**Previous Bid:** {$previousBid}\n**Bidder:** {$bidderName}";
    if ($listing['End_Date']) {
        $description .= "\n**Ends:** {$listing['End_Date']}";
    }

    $webhookUrl = defined('DISCORD_WEBHOOK_URL') ? DISCORD_WEBHOOK_URL : '';
    
    if ($webhookUrl) {
        $message = [
            "embeds" => [
                [
                    "title" => "New Bid on Auction",
                    "description" => $description,
                    "color" => 15844367, // Gold color
                    "timestamp" => date('c')
                ]
            ]
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('Discord webhook error (player market bid): ' . curl_error($ch));
        }
        curl_close($ch);
    }

    echo json_encode(['success' => true, 'error' => 'null']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
