<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$quantity = $input['quantity'];
$itemClass = $input['itemClass'];
$itemState = $input['itemState'];
$targetInventoryId = $input['targetInventoryId'];

$quantity = (int)$quantity;

if ($quantity <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid quantity']);
    exit();
}

$sourceInventoryId = $_SESSION['inventory_id'];
try {
    // Start transaction
    $pdo->beginTransaction();

    // Get item limits
    $limitQuery = "SELECT inventories.Inventory_Type, item_type_inventory_limit.Item_Type, item_type_inventory_limit.Item_Limit FROM `inventories`
    LEFT JOIN inventory_types ON inventory_types.Inventory_Type_Id = inventories.Inventory_Type
    LEFT JOIN item_type_inventory_limit ON item_type_inventory_limit.Inventory_Type = inventory_types.Inventory_Type_Id
    WHERE Inventory_Id = ?;";
    $limitStmt = $pdo->prepare($limitQuery);
    $limitStmt->execute([$targetInventoryId]);
    $limits = $limitStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get current item count per type
    $currentCountQuery = "SELECT Custom_Item_Type, SUM(Item_Quantity) AS quantity
    FROM (
        SELECT DISTINCT
            content_items.Content_Item_Id,
            custom_item_types.Custom_Item_Type,
            content_items.Item_Quantity
        FROM content_items
        LEFT JOIN items ON items.item_class = content_items.Item_Class
        LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
        LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
        LEFT JOIN item_sorting ON item_sorting.Item_Sorting_Type = item_types.item_classification
        LEFT JOIN market ON market.Market_Item_Class = content_items.Item_Class
        WHERE Inventory_Id = ?
    ) AS subquery
    GROUP BY Custom_Item_Type;";
    $currentCountStmt = $pdo->prepare($currentCountQuery);
    $currentCountStmt->execute([$targetInventoryId]);
    $currentCounts = $currentCountStmt->fetchAll(PDO::FETCH_ASSOC);

    $transferItemTypeQuery = "SELECT * FROM items
    LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
    LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
    WHERE items.item_class = ?";
    $transferItemTypeStmt = $pdo->prepare($transferItemTypeQuery);
    $transferItemTypeStmt->execute([$itemClass]);
    $transferItemType = $transferItemTypeStmt->fetch(PDO::FETCH_ASSOC);

    foreach ($limits as $limit) {
        $itemType = $limit['Item_Type'];
        $itemLimit = $limit['Item_Limit'];

        if(!$itemType || !$itemLimit || $itemType != $transferItemType['Custom_Item_Type']) {
            continue; // Skip if no item type or limit is defined
        }

        // Check if the item type is in the current counts
        $currentCount = 0;
        foreach ($currentCounts as $count) {
            if ($count['Custom_Item_Type'] == $itemType) {
                $currentCount = $count['quantity'];
                break;
            }
        }

        // Check if the limit is reached
        if ($currentCount >= $itemLimit) {
            echo json_encode(['success' => false, 'error' => "Item limit reached for type: " . $itemType]);
            exit();
        }
    }

    // Get the source item details
    $sourceQuery = "SELECT Content_Item_Id, Item_Properties, Item_Quantity 
                    FROM content_items 
                    WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ?";
    $sourceStmt = $pdo->prepare($sourceQuery);
    $sourceStmt->execute([$sourceInventoryId, $itemClass, $itemState]);
    $sourceItem = $sourceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sourceItem || $sourceItem['Item_Quantity'] < $quantity) {
        echo json_encode(['success' => false, 'error' => "Insufficient quantity available"]);
        exit();
    }

    // Check if item exists in target inventory
    $targetQuery = "SELECT Content_Item_Id, Item_Quantity 
                    FROM content_items 
                    WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ?";
    $targetStmt = $pdo->prepare($targetQuery);
    $targetStmt->execute([$targetInventoryId, $itemClass, $sourceItem['Item_Properties']]);
    $targetItem = $targetStmt->fetch(PDO::FETCH_ASSOC);

    // Update source inventory
    $newSourceQuantity = $sourceItem['Item_Quantity'] - $quantity;
    if ($newSourceQuantity > 0) {
        $updateSourceQuery = "UPDATE content_items 
                            SET Item_Quantity = ? 
                            WHERE Content_Item_Id = ?";
        $updateSourceStmt = $pdo->prepare($updateSourceQuery);
        $updateSourceStmt->execute([$newSourceQuantity, $sourceItem['Content_Item_Id']]);
    } else {
        // Delete the item if quantity becomes 0
        $deleteSourceQuery = "DELETE FROM content_items 
                            WHERE Content_Item_Id = ?";
        $deleteSourceStmt = $pdo->prepare($deleteSourceQuery);
        $deleteSourceStmt->execute([$sourceItem['Content_Item_Id']]);
    }

    // Handle target inventory
    if ($targetItem) {
        // Update existing item quantity
        $newTargetQuantity = $targetItem['Item_Quantity'] + $quantity;
        $updateTargetQuery = "UPDATE content_items 
                            SET Item_Quantity = ? 
                            WHERE Content_Item_Id = ?";
        $updateTargetStmt = $pdo->prepare($updateTargetQuery);
        $updateTargetStmt->execute([$newTargetQuantity, $targetItem['Content_Item_Id']]);
    } else {
        // Create new item entry
        $insertQuery = "INSERT INTO content_items 
                        (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) 
                        VALUES (?, ?, ?, ?)";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([
            $targetInventoryId,
            $itemClass,
            $quantity,
            $sourceItem['Item_Properties']
        ]);
    }

    // Log the transfer
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?), (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $sourceInventoryId,
        $itemClass,
        -$quantity,
        "Transfer",
        $targetInventoryId,
        $itemClass,
        $quantity,
        "Transfer"
    ]);

    $pdo->commit();

} catch (Exception $e) {
    $pdo->rollBack();
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit();
}

echo json_encode(['success' => true, 'error' => 'null']);
?>
