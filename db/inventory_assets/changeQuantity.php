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

if (!isset($input['itemId']) || !isset($input['quantity'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$itemId = (int)$input['itemId'];
$newQuantity = (int)$input['quantity'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get current item details
    $getItemQuery = "SELECT Content_Item_Id, Inventory_Id, Item_Class, Item_Quantity 
                     FROM content_items 
                     WHERE Content_Item_Id = ?";
    $getItemStmt = $pdo->prepare($getItemQuery);
    $getItemStmt->execute([$itemId]);
    $item = $getItemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception("Item not found");
    }

    if ($newQuantity < 0) {
        throw new Exception("Quantity cannot be negative");
    }

    // If new quantity is 0, delete the item
    if ($newQuantity === 0) {
        $deleteQuery = "DELETE FROM content_items WHERE Content_Item_Id = ?";
        $deleteStmt = $pdo->prepare($deleteQuery);
        $deleteStmt->execute([$itemId]);
    } else {
        // Update the quantity
        $updateQuery = "UPDATE content_items 
                       SET Item_Quantity = ? 
                       WHERE Content_Item_Id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$newQuantity, $itemId]);
    }

    // Log the change
    $quantityDifference = $newQuantity - $item['Item_Quantity'];
    $logQuery = "INSERT INTO logs 
                 (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                 VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $item['Inventory_Id'],
        $item['Item_Class'],
        $quantityDifference,
        "Admin action"
    ]);

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
