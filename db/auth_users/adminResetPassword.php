<?php
require_once '../connection.php';
require_once '../../includes/auth.php';
require_once '../includes/debug.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['userId']) || !isset($input['newPassword'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if user exists first
    $checkStmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
    $checkStmt->execute([$input['userId']]);
    $user = $checkStmt->fetch();

    if (!$user) {
        throw new Exception('User not found');
    }

    // Update password
    $newPasswordHash = password_hash($input['newPassword'], PASSWORD_DEFAULT);
    
    // Verify the hash was created successfully
    if ($newPasswordHash === false) {
        throw new Exception('Password hashing failed');
    }

    $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $success = $updateStmt->execute([$newPasswordHash, $input['userId']]);

    if (!$success) {
        throw new Exception('Failed to update password');
    }

    // Verify the update
    $verifyStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $verifyStmt->execute([$input['userId']]);
    $updatedUser = $verifyStmt->fetch();

    if (!password_verify($input['newPassword'], $updatedUser['password'])) {
        throw new Exception('Password verification failed after update');
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'error' => null,
        'message' => 'Password successfully reset'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>
