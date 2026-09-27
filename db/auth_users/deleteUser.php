<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['userId'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$userId = (int)$input['userId'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if user exists
    $checkQuery = "SELECT id FROM users WHERE id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$userId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("User not found");
    }

    // Delete user's permissions
    $deletePermissionsQuery = "DELETE FROM permissions WHERE Player_Id IN 
                             (SELECT CAST(id as CHAR) FROM users WHERE id = ?)";
    $deletePermissionsStmt = $pdo->prepare($deletePermissionsQuery);
    $deletePermissionsStmt->execute([$userId]);

    // Delete user's admin status if they are admin
    $deleteAdminQuery = "DELETE FROM admins WHERE PlayerId IN 
                        (SELECT CAST(id as CHAR) FROM users WHERE id = ?)";
    $deleteAdminStmt = $pdo->prepare($deleteAdminQuery);
    $deleteAdminStmt->execute([$userId]);

    // Delete the user
    $deleteUserQuery = "DELETE FROM users WHERE id = ?";
    $deleteUserStmt = $pdo->prepare($deleteUserQuery);
    $deleteUserStmt->execute([$userId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
