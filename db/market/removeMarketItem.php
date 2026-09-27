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

if (!isset($input['itemId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$itemId = (int)$input['itemId'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // delete the item
    $deleteItemQuery = "DELETE FROM items WHERE item_class = 
                        (SELECT Market_Item_Class FROM market WHERE Market_item_Id = ?)";
    $deleteItemStmt = $pdo->prepare($deleteItemQuery);
    $deleteItemStmt->execute([$itemId]);

    // Delete the market item
    $deleteQuery = "DELETE FROM market WHERE Market_item_Id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$itemId]);

    if ($deleteStmt->rowCount() === 0) {
        throw new Exception("Market item not found");
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
