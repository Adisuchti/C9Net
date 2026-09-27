<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
$inventoryId = $_SESSION['inventory_id'];
$itemType = $data['itemType'] ?? null;

if (!$itemType) {
    echo json_encode(['success' => false, 'error' => 'Item type not specified']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get all magazines of this type in the inventory
    $stmt = $pdo->prepare("
        SELECT Content_Item_Id, Item_Class, Item_Quantity, Item_Properties 
        FROM content_items 
        WHERE Inventory_Id = ? AND Item_Class IN (
            SELECT DISTINCT ci.Item_Class 
            FROM content_items ci
            LEFT JOIN items ON items.item_class = ci.Item_Class
            LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
            LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
            WHERE ci.Inventory_Id = ? AND custom_item_types.Custom_Item_Type = ?
        )
    ");
    $stmt->execute([$inventoryId, $inventoryId, $itemType]);
    $magazines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($magazines)) {
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'No magazines found to repack']);
        exit();
    }

    // Group magazines by Item_Class
    $magazinesByClass = [];
    foreach ($magazines as $mag) {
        $magazinesByClass[$mag['Item_Class']][] = $mag;
    }

    $totalRepacked = 0;

    // Process each magazine class separately
    foreach ($magazinesByClass as $itemClass => $classMagazines) {
        // Check if this item is on the market and get max ammo count
        $marketStmt = $pdo->prepare("
            SELECT Ammo_Count 
            FROM market 
            WHERE Market_Item_Class = ? AND Ammo_Count IS NOT NULL
        ");
        $marketStmt->execute([$itemClass]);
        $marketItem = $marketStmt->fetch(PDO::FETCH_ASSOC);

        if (!$marketItem || $marketItem['Ammo_Count'] === null) {
            // Skip this magazine class if not in market or no ammo count defined
            continue;
        }

        $maxAmmo = (int)$marketItem['Ammo_Count'];

        // Calculate total bullets across all magazines of this class
        $totalBullets = 0;
        foreach ($classMagazines as $mag) {
            $currentAmmo = is_numeric($mag['Item_Properties']) ? (int)$mag['Item_Properties'] : $maxAmmo;
            $totalBullets += $currentAmmo * $mag['Item_Quantity'];
        }

        // Delete all existing magazines of this class
        $deleteStmt = $pdo->prepare("
            DELETE FROM content_items 
            WHERE Inventory_Id = ? AND Item_Class = ?
        ");
        $deleteStmt->execute([$inventoryId, $itemClass]);

        // Create new magazines with consolidated ammo
        if ($totalBullets > 0) {
            // Create full magazines
            $fullMagazines = floor($totalBullets / $maxAmmo);
            if ($fullMagazines > 0) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties)
                    VALUES (?, ?, ?, ?)
                ");
                $insertStmt->execute([$inventoryId, $itemClass, $fullMagazines, $maxAmmo]);
            }

            // Create partial magazine with remaining bullets
            $remainingBullets = $totalBullets % $maxAmmo;
            if ($remainingBullets > 0) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties)
                    VALUES (?, ?, 1, ?)
                ");
                $insertStmt->execute([$inventoryId, $itemClass, $remainingBullets]);
            }

            $totalRepacked += count($classMagazines);
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'Magazines repacked successfully',
        'repacked_count' => $totalRepacked
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
