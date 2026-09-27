<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

// Validate required fields
$requiredFields = ['itemClass', 'displayName', 'itemType', 'purchasePrice', 'sellingPrice', 'availableQuantity', 'tier', 'ammoCount', 'marketId', 'visible'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit();
    }
}

try {
    $pdo->beginTransaction();

    // Get item type ID
    $typeQuery = "SELECT item_types.Item_Type_Id FROM item_types
    LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
    WHERE custom_item_types.Custom_Item_Type = ? LIMIT 1;";
    $typeStmt = $pdo->prepare($typeQuery);
    $typeStmt->execute([$input['itemType']]);
    $typeId = $typeStmt->fetchColumn();

    if (!$typeId) {
        throw new Exception("Invalid item type");
    }

    //Check if item already in table
    $checkItemQuery = "SELECT COUNT(*) FROM items WHERE Item_Class = ?;";
    $checkItemStmt = $pdo->prepare($checkItemQuery);
    $checkItemStmt->execute([$input['itemClass']]);
    $itemExists = $checkItemStmt->fetchColumn() > 0;

    if ($itemExists) {
        //Edit items table
        $itemQuery = "UPDATE items SET Item_Display_Name = ?, Item_Type = ? WHERE Item_Class = ?";
        $itemStmt = $pdo->prepare($itemQuery);
        $itemStmt->execute([
            $input['displayName'],
            $typeId,
            $input['itemClass']
        ]);
    } else {
        // Add to items table if it doesn't exist
        $itemQuery = "INSERT IGNORE INTO items (Item_Class, Item_Display_Name, Item_Type) 
                    VALUES (?, ?, ?)";
        $itemStmt = $pdo->prepare($itemQuery);
        $itemStmt->execute([
            $input['itemClass'],
            $input['displayName'],
            $typeId
        ]);
    };

    $state = $input['ammoCount'] ?? 0;
    if ($state == 0) {
        $state = null;
    }

    // Add to market table
    $marketQuery = "INSERT INTO market 
                   (Market_Item_Class, Market_Item_Type, Purchase_Price, 
                    Selling_Price, Available_Quantity, tier, Ammo_Count, Market, Visible) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $marketStmt = $pdo->prepare($marketQuery);
    $marketStmt->execute([
        $input['itemClass'],
        $typeId,
        $input['purchasePrice'],
        $input['sellingPrice'],
        $input['availableQuantity'],
        $input['tier'],
        $state,
        $input['marketId'],
        $input['visible']
    ]);

    $newMarketItemId = (int)$pdo->lastInsertId();

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'error' => null,
        'itemId' => $newMarketItemId
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
