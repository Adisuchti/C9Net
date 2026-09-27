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

if (!isset($input['itemId']) || !isset($input['itemClass']) || 
    !isset($input['purchasePrice']) || !isset($input['sellingPrice']) || 
    !isset($input['availableQuantity']) || !isset($input['visible'])
    || !isset($input['tier'])){
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get current tier from condition_variables
    $tierQuery = "SELECT Var_Value FROM condition_variables WHERE Var_Name = 'current_tier'";
    $tierStmt = $pdo->prepare($tierQuery);
    $tierStmt->execute();
    $currentTier = $tierStmt->fetchColumn() ?: 1; // Default to 1 if not set

    $state = $input['state'] ?? 0; // Default to 0 if not set
    if($state == 0) {
        $state = null;
    }

    // Handle DLC field - convert empty string to null
    $dlc = isset($input['dlc']) && $input['dlc'] !== '' ? (int)$input['dlc'] : null;

    // Update market table
    $updateQuery = "UPDATE market 
                   SET Market_Item_Class = ?,
                       Purchase_Price = ?,
                       Selling_Price = ?,
                       Available_Quantity = ?,
                       market_description = ?,
                       tier = ?,
                       Ammo_Count = ?,
                       Compatible_Items = ?,
                       DLC = ?,
                       Visible = ?,
                       SubCategory_Id = ?
                   WHERE Market_item_Id = ?";
    
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([
        $input['itemClass'],
        $input['purchasePrice'],
        $input['sellingPrice'],
        $input['availableQuantity'],
        $input['description'],
        $input['tier'],
        $state,
        $input['compatibleItems'],
        $dlc,
        $input['visible'],
        $input['subcategoryId'],
        $input['itemId']
    ]);

    // If display name is different from item class, update or insert into items table
    if (isset($input['displayName']) && $input['displayName'] !== $input['itemClass']) {
        $checkItemQuery = "SELECT Item_Class FROM items WHERE Item_Class = ?";
        $checkItemStmt = $pdo->prepare($checkItemQuery);
        $checkItemStmt->execute([$input['itemClass']]);
        
        if ($checkItemStmt->rowCount() > 0) {
            // Update existing item
            $updateItemQuery = "UPDATE items 
                              SET Item_Display_Name = ?
                              WHERE Item_Class = ?";
            $updateItemStmt = $pdo->prepare($updateItemQuery);
            $updateItemStmt->execute([
                $input['displayName'],
                $input['itemClass']
            ]);
        } else {
            // Get Item_Type from market table for the new item
            $getTypeQuery = "SELECT Market_Item_Type FROM market WHERE Market_item_Id = ?";
            $getTypeStmt = $pdo->prepare($getTypeQuery);
            $getTypeStmt->execute([$input['itemId']]);
            $itemType = $getTypeStmt->fetchColumn();
            
            // Insert new item with Item_Type
            $insertItemQuery = "INSERT INTO items (Item_Class, Item_Display_Name, Item_Type) 
                              VALUES (?, ?, ?)";
            $insertItemStmt = $pdo->prepare($insertItemQuery);
            $insertItemStmt->execute([
                $input['itemClass'],
                $input['displayName'],
                $itemType
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
