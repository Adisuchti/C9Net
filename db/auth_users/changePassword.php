<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['currentPassword']) || !isset($input['newPassword'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get user's current password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($input['currentPassword'], $user['password'])) {
        throw new Exception('Current password is incorrect');
    }

    // Update password
    $newPasswordHash = password_hash($input['newPassword'], PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $updateStmt->execute([$newPasswordHash, $_SESSION['user_id']]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => "null"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
