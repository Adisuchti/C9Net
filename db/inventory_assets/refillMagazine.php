<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit();
}

// Get POST data
validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['itemId']) || !isset($data['inventoryId'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit();
}

$itemId = (int)$data['itemId'];
$inventoryId = (int)$data['inventoryId'];

if ($inventoryId !== $_SESSION['inventory_id']) {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to modify this inventory.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Fetch the content item to be refilled
    $itemQuery = "SELECT Content_Item_Id, Item_Class, Item_Quantity, Item_Properties 
                  FROM content_items 
                  WHERE Content_Item_Id = ? AND Inventory_Id = ? FOR UPDATE";
    $itemStmt = $pdo->prepare($itemQuery);
    $itemStmt->execute([$itemId, $inventoryId]);
    $contentItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$contentItem) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Item not found in your inventory.']);
        exit();
    }

    $itemClass = $contentItem['Item_Class'];
    $currentAmmo = (int)$contentItem['Item_Properties'];

    // 2. Fetch market information for this item
    $marketQuery = "SELECT Purchase_Price, Available_Quantity, Ammo_Count 
                    FROM market 
                    WHERE Market_Item_Class = ? AND Market = 0 LIMIT 1";
    $marketStmt = $pdo->prepare($marketQuery);
    $marketStmt->execute([$itemClass]);
    $marketItem = $marketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$marketItem) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Item not found in the market.']);
        exit();
    }

    if ($marketItem['Available_Quantity'] != -1) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Item does not have unlimited market quantity.']);
        exit();
    }

    $maxAmmo = (int)$marketItem['Ammo_Count'];
    if ($maxAmmo <= 0 || $currentAmmo >= $maxAmmo) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Magazine is already full or has invalid capacity.']);
        exit();
    }

    // 3. Calculate refill cost
    // The user confirmed "no ceil is good" -> meaning "No, ceil is good."
    $missingAmmo = $maxAmmo - $currentAmmo;
    $refillCost = ceil(($missingAmmo / $maxAmmo) * $marketItem['Purchase_Price']);

    // 4. Check inventory money
    $invQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE";
    $invStmt = $pdo->prepare($invQuery);
    $invStmt->execute([$inventoryId]);
    $invData = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invData || $invData['Inventory_Money'] < $refillCost) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => "Insufficient funds. You need $refillCost Cr."]);
        exit();
    }

    // Deduct money
    $newMoney = $invData['Inventory_Money'] - $refillCost;
    $updateMoneyStmt = $pdo->prepare("UPDATE inventories SET Inventory_Money = ? WHERE Inventory_Id = ?");
    $updateMoneyStmt->execute([$newMoney, $inventoryId]);

    // 4.5 Log the transaction
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $inventoryId,
        $itemClass,
        1,
        "refill"
    ]);

    $logStmt->execute([
        $inventoryId,
        'MONEY',
        -$refillCost,
        "refill"
    ]);

    // Update session money
    $_SESSION['inventory_money'] -= $refillCost;

    // 5. Update inventory item quantity / remove old entry
    if ((int)$contentItem['Item_Quantity'] > 1) {
        $updateQtyStmt = $pdo->prepare("UPDATE content_items SET Item_Quantity = Item_Quantity - 1 WHERE Content_Item_Id = ?");
        $updateQtyStmt->execute([$itemId]);
    } else {
        $deleteItemStmt = $pdo->prepare("DELETE FROM content_items WHERE Content_Item_Id = ?");
        $deleteItemStmt->execute([$itemId]);
    }

    // 6. Add the full magazine
    $checkFullStmt = $pdo->prepare("SELECT Content_Item_Id FROM content_items WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ? FOR UPDATE");
    $checkFullStmt->execute([$inventoryId, $itemClass, $maxAmmo]);
    $fullItem = $checkFullStmt->fetch(PDO::FETCH_ASSOC);

    if ($fullItem) {
        $incrementStmt = $pdo->prepare("UPDATE content_items SET Item_Quantity = Item_Quantity + 1 WHERE Content_Item_Id = ?");
        $incrementStmt->execute([$fullItem['Content_Item_Id']]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) VALUES (?, ?, 1, ?)");
        $insertStmt->execute([$inventoryId, $itemClass, $maxAmmo]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
