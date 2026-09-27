<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit();
}

if (!isset($_SESSION['inventory_id'])) {
    echo json_encode(['success' => false, 'error' => 'No active inventory session.']);
    exit();
}

$inventoryId = $_SESSION['inventory_id'];

try {
    $itemQuery = "SELECT ci.Content_Item_Id, ci.Item_Class, ci.Item_Quantity, ci.Item_Properties, m.Purchase_Price, m.Ammo_Count 
                  FROM content_items ci
                  JOIN market m ON ci.Item_Class = m.Market_Item_Class
                  WHERE ci.Inventory_Id = ? AND m.Market = 0 AND m.Ammo_Count IS NOT NULL AND m.Available_Quantity = -1";
                  
    $itemStmt = $pdo->prepare($itemQuery);
    $itemStmt->execute([$inventoryId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCost = 0;
    
    foreach ($items as $item) {
        $maxAmmo = (int)$item['Ammo_Count'];
        // If Item_Properties is empty or not numeric, assume it's full (maxAmmo)
        $currentAmmo = is_numeric($item['Item_Properties']) ? (int)$item['Item_Properties'] : $maxAmmo;
        $quantity = (int)$item['Item_Quantity'];
        
        if ($maxAmmo > 0 && $currentAmmo < $maxAmmo) {
            $missingAmmo = $maxAmmo - $currentAmmo;
            $unitCost = ceil(($missingAmmo / $maxAmmo) * $item['Purchase_Price']);
            $totalCost += ($unitCost * $quantity);
        }
    }

    echo json_encode(['success' => true, 'totalCost' => $totalCost]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
