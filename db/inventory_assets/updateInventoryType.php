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

if (!isset($input['inventoryId']) || !isset($input['typeId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Update inventory type
    $updateQuery = "UPDATE inventories SET Inventory_Type = ? WHERE Inventory_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$input['typeId'], $input['inventoryId']]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => ""]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
