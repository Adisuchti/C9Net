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

if (!isset($input['itemId']) || !isset($input['state'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$itemId = (int)$input['itemId'];
$state = (int)$input['state'];

try {
    $pdo->beginTransaction();

    // Check if item exists
    $checkQuery = "SELECT Content_Item_Id, Inventory_Id, Item_Class, Item_Properties, Item_Quantity
                  FROM content_items 
                  WHERE Content_Item_Id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$itemId]);
    $item = $checkStmt->fetch();

    if (!$item) {
        throw new Exception("Item not found");
    }

    // Update the item properties
    $updateQuery = "UPDATE content_items 
                   SET Item_Properties = ? 
                   WHERE Content_Item_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$state, $itemId]);

    // Log the state change
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?), (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $item['Inventory_Id'],
        $item['Item_Class'],
        -$item['Item_Quantity'],
        "Admin action",
        $item['Inventory_Id'],
        $item['Item_Class'],
        $item['Item_Quantity'],
        "Admin action"
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => '']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
