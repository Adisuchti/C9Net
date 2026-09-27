<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit();
}

validateCsrfToken();

if (!isset($_SESSION['inventory_id'])) {
    echo json_encode(['success' => false, 'error' => 'No active inventory session.']);
    exit();
}

$inventoryId = $_SESSION['inventory_id'];

try {
    $pdo->beginTransaction();

    // 1. Fetch all refillable magazines and market info FOR UPDATE
    $itemQuery = "SELECT ci.Content_Item_Id, ci.Item_Class, ci.Item_Quantity, ci.Item_Properties, m.Purchase_Price, m.Ammo_Count 
                  FROM content_items ci
                  JOIN market m ON ci.Item_Class = m.Market_Item_Class
                  WHERE ci.Inventory_Id = ? AND m.Market = 0 AND m.Ammo_Count IS NOT NULL AND m.Available_Quantity = -1
                  FOR UPDATE";
    $itemStmt = $pdo->prepare($itemQuery);
    $itemStmt->execute([$inventoryId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCost = 0;
    $itemsToRefill = [];

    foreach ($items as $item) {
        $maxAmmo = (int)$item['Ammo_Count'];
        $currentAmmo = is_numeric($item['Item_Properties']) ? (int)$item['Item_Properties'] : $maxAmmo;
        $quantity = (int)$item['Item_Quantity'];
        
        if ($maxAmmo > 0 && $currentAmmo < $maxAmmo) {
            $missingAmmo = $maxAmmo - $currentAmmo;
            $unitCost = ceil(($missingAmmo / $maxAmmo) * $item['Purchase_Price']);
            $cost = $unitCost * $quantity;
            $totalCost += $cost;
            $itemsToRefill[] = [
                'id' => $item['Content_Item_Id'],
                'class' => $item['Item_Class'],
                'quantity' => $quantity,
                'maxAmmo' => $maxAmmo,
                'cost' => $cost
            ];
        }
    }

    if ($totalCost === 0 || empty($itemsToRefill)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'No magazines need refilling.']);
        exit();
    }

    // 2. Check inventory money
    $invQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE";
    $invStmt = $pdo->prepare($invQuery);
    $invStmt->execute([$inventoryId]);
    $invData = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invData || $invData['Inventory_Money'] < $totalCost) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => "Insufficient funds. You need $totalCost Cr."]);
        exit();
    }

    // 3. Deduct money
    $newMoney = $invData['Inventory_Money'] - $totalCost;
    $updateMoneyStmt = $pdo->prepare("UPDATE inventories SET Inventory_Money = ? WHERE Inventory_Id = ?");
    $updateMoneyStmt->execute([$newMoney, $inventoryId]);

    // 4. Log the transaction
    $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    
    // Log the money deduction once for the total amount
    $logStmt->execute([$inventoryId, 'MONEY', -$totalCost, 'refill_all']);

    // Log the items (optional, but good for tracking)
    foreach ($itemsToRefill as $rItem) {
        $logStmt->execute([$inventoryId, $rItem['class'], $rItem['quantity'], 'refill_all']);
    }

    $_SESSION['inventory_money'] -= $totalCost;

    // 5. Update items
    $deleteItemStmt = $pdo->prepare("DELETE FROM content_items WHERE Content_Item_Id = ?");
    $checkFullStmt = $pdo->prepare("SELECT Content_Item_Id FROM content_items WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ? FOR UPDATE");
    $incrementStmt = $pdo->prepare("UPDATE content_items SET Item_Quantity = Item_Quantity + ? WHERE Content_Item_Id = ?");
    $insertStmt = $pdo->prepare("INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) VALUES (?, ?, ?, ?)");

    foreach ($itemsToRefill as $rItem) {
        // Delete the old partial magazines
        $deleteItemStmt->execute([$rItem['id']]);

        // Add the full magazines (check if a full stack already exists)
        $checkFullStmt->execute([$inventoryId, $rItem['class'], $rItem['maxAmmo']]);
        $fullItem = $checkFullStmt->fetch(PDO::FETCH_ASSOC);

        if ($fullItem) {
            $incrementStmt->execute([$rItem['quantity'], $fullItem['Content_Item_Id']]);
        } else {
            $insertStmt->execute([$inventoryId, $rItem['class'], $rItem['quantity'], $rItem['maxAmmo']]);
        }
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
