<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);
$commentId = isset($input['commentId']) ? (int)$input['commentId'] : 0;

if (!$commentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing comment ID']);
    exit();
}

// Get the comment to check ownership
$stmt = $pdo->prepare("SELECT User_Id FROM image_comments WHERE Comment_Id = ?");
$stmt->execute([$commentId]);
$comment = $stmt->fetch();

if (!$comment) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Comment not found']);
    exit();
}

// Only comment author or admin can delete
if ($comment['User_Id'] != $_SESSION['user_id'] && $_SESSION['user_id'] != -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM image_comments WHERE Comment_Id = ?");
    $stmt->execute([$commentId]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
