<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

validateCsrfToken();

$inventoryId = $_SESSION['inventory_id'];

try {
    $pdo->beginTransaction();

    // Get all magazines in the inventory (items with defined Ammo_Count in market)
    $stmt = $pdo->prepare("
        SELECT ci.Content_Item_Id, ci.Item_Class, ci.Item_Quantity, ci.Item_Properties, m.Ammo_Count 
        FROM content_items ci
        JOIN market m ON ci.Item_Class = m.Market_Item_Class
        WHERE ci.Inventory_Id = ? AND m.Market = 0 AND m.Ammo_Count IS NOT NULL
        FOR UPDATE
    ");
    $stmt->execute([$inventoryId]);
    $magazines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($magazines)) {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => 'No magazines found to repack']);
        exit();
    }

    // Group magazines by Item_Class
    $magazinesByClass = [];
    foreach ($magazines as $mag) {
        $magazinesByClass[$mag['Item_Class']][] = $mag;
    }

    $totalRepacked = 0;

    $deleteStmt = $pdo->prepare("DELETE FROM content_items WHERE Inventory_Id = ? AND Item_Class = ?");
    $insertStmt = $pdo->prepare("INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) VALUES (?, ?, ?, ?)");

    // Process each magazine class separately
    foreach ($magazinesByClass as $itemClass => $classMagazines) {
        // Find max ammo (all should have the same maxAmmo if they are same class, take from first)
        $maxAmmo = (int)$classMagazines[0]['Ammo_Count'];

        if ($maxAmmo <= 0) continue;

        // Calculate total bullets across all magazines of this class
        $totalBullets = 0;
        foreach ($classMagazines as $mag) {
            $currentAmmo = is_numeric($mag['Item_Properties']) ? (int)$mag['Item_Properties'] : $maxAmmo;
            $totalBullets += $currentAmmo * $mag['Item_Quantity'];
        }

        // Delete all existing magazines of this class
        $deleteStmt->execute([$inventoryId, $itemClass]);

        // Create new magazines with consolidated ammo
        if ($totalBullets > 0) {
            // Create full magazines
            $fullMagazines = floor($totalBullets / $maxAmmo);
            if ($fullMagazines > 0) {
                $insertStmt->execute([$inventoryId, $itemClass, $fullMagazines, $maxAmmo]);
            }

            // Create partial magazine with remaining bullets
            $remainingBullets = $totalBullets % $maxAmmo;
            if ($remainingBullets > 0) {
                $insertStmt->execute([$inventoryId, $itemClass, 1, $remainingBullets]);
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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
