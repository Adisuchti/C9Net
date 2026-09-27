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

if (!isset($input['itemClass']) || !isset($input['quantity']) || !isset($input['inventoryId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$itemClass = $input['itemClass'];
$quantity = (int)$input['quantity'];
$inventoryId = (int)$input['inventoryId'];
$properties = isset($input['properties']) ? $input['properties'] : '';

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if item exists in inventory
    $checkQuery = "SELECT Content_Item_Id, Item_Quantity 
                  FROM content_items 
                  WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$inventoryId, $itemClass, $properties]);
    $existingItem = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingItem) {
        // Update existing item quantity
        $newQuantity = $existingItem['Item_Quantity'] + $quantity;
        $updateQuery = "UPDATE content_items 
                       SET Item_Quantity = ? 
                       WHERE Content_Item_Id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$newQuantity, $existingItem['Content_Item_Id']]);
    } else {
        // Create new item entry
        $insertQuery = "INSERT INTO content_items 
                       (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) 
                       VALUES (?, ?, ?, ?)";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([$inventoryId, $itemClass, $quantity, $properties]);
    }

    // Log the addition
    $logQuery = "INSERT INTO logs 
                 (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                 VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([$inventoryId, $itemClass, $quantity, "Admin action"]);

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
