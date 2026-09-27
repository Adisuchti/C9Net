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

if (!isset($input['userId']) || !isset($input['inventoryId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$userId = (int)$input['userId'];
$inventoryId = (int)$input['inventoryId'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if user exists
    $checkUserQuery = "SELECT id FROM users WHERE id = ?";
    $checkUserStmt = $pdo->prepare($checkUserQuery);
    $checkUserStmt->execute([$userId]);
    
    if ($checkUserStmt->rowCount() === 0) {
        throw new Exception("User not found");
    }

    // If inventoryId is -1, we're disabling the inventory access
    if ($inventoryId === -1) {
        // Set inventory_id to null
        $updateQuery = "UPDATE users SET inventory_id = NULL WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$userId]);
    } else {
        // Check if inventory exists
        $checkInvQuery = "SELECT Inventory_Id FROM inventories WHERE Inventory_Id = ?";
        $checkInvStmt = $pdo->prepare($checkInvQuery);
        $checkInvStmt->execute([$inventoryId]);
        
        if ($checkInvStmt->rowCount() === 0) {
            throw new Exception("Inventory not found");
        }

        // Update user's inventory_id
        $updateQuery = "UPDATE users SET inventory_id = ? WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$inventoryId, $userId]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => null]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
