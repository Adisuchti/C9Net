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

$itemId = (int)($input['itemId'] ?? 0);
$itemClass = $input['itemClass'] ?? '';
$quantity = (int)($input['quantity'] ?? 0);
$itemState = $input['itemState'] ?? '';

$inventoryId = isset($input['sourceInventoryId']) ? (int)$input['sourceInventoryId'] : $_SESSION['inventory_id'];

if (!isUserAuthorizedForInventory($pdo, $_SESSION['user_id'], $inventoryId)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized for this inventory']);
    exit();
}

if ($quantity <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid quantity']);
    exit();
}

if (empty($itemClass)) {
    echo json_encode(['success' => false, 'error' => 'Invalid item class']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Look up the selling price and ammo count from the market table
    $marketQuery = "SELECT Selling_Price, Ammo_Count FROM market WHERE Market_Item_Class = ? AND Market = 0 LIMIT 1";
    $marketStmt = $pdo->prepare($marketQuery);
    $marketStmt->execute([$itemClass]);
    $marketItem = $marketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$marketItem || $marketItem['Selling_Price'] === null) {
        echo json_encode(['success' => false, 'error' => 'This item cannot be sold (no selling price defined)']);
        $pdo->rollBack();
        exit();
    }

    $sellingPrice = (float)$marketItem['Selling_Price'];

    if ($sellingPrice <= 0) {
        echo json_encode(['success' => false, 'error' => 'This item has no sell value']);
        $pdo->rollBack();
        exit();
    }

    // Verify item state (ammo count) matches market ammo count
    $mktAmmoCount = $marketItem['Ammo_Count'] !== null ? trim((string)$marketItem['Ammo_Count']) : '';
    $userItemState = $itemState !== null ? trim((string)$itemState) : '';
    if ($mktAmmoCount !== $userItemState) {
        echo json_encode(['success' => false, 'error' => 'You can only sell this item in its original/market state']);
        $pdo->rollBack();
        exit();
    }

    // Get the source item details
    $sourceQuery = "SELECT Content_Item_Id, Item_Properties, Item_Quantity 
                    FROM content_items 
                    WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ?";
    $sourceStmt = $pdo->prepare($sourceQuery);
    $sourceStmt->execute([$inventoryId, $itemClass, $itemState]);
    $sourceItem = $sourceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sourceItem || $sourceItem['Item_Quantity'] < $quantity) {
        echo json_encode(['success' => false, 'error' => 'Insufficient quantity available']);
        $pdo->rollBack();
        exit();
    }

    // Calculate total earnings (rounded down to the nearest integer)
    $totalEarnings = (int)floor($sellingPrice * $quantity);

    // Update source inventory - reduce quantity or delete
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

    // Add money to inventory
    $updateMoneyQuery = "UPDATE inventories 
                        SET Inventory_Money = Inventory_Money + ? 
                        WHERE Inventory_Id = ?";
    $updateMoneyStmt = $pdo->prepare($updateMoneyQuery);
    $updateMoneyStmt->execute([$totalEarnings, $inventoryId]);

    // Log the sale - item removed
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $inventoryId,
        $itemClass,
        -$quantity,
        "Item Sale"
    ]);

    // Log the sale - money received
    $logQuery2 = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?)";
    $logStmt2 = $pdo->prepare($logQuery2);
    $logStmt2->execute([
        $inventoryId,
        'MONEY',
        $totalEarnings,
        "Item Sale"
    ]);

    // Update session money
    if ($inventoryId == $_SESSION['inventory_id']) {
        $_SESSION['inventory_money'] += $totalEarnings;
    }

    $pdo->commit();

} catch (Exception $e) {
    $pdo->rollBack();
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit();
}

echo json_encode(['success' => true, 'error' => 'null', 'earnings' => $totalEarnings]);
?>
