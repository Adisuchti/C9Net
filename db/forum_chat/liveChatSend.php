<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['message']) || trim($input['message']) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message required']);
    exit();
}

$userId = $_SESSION['user_id'];
$message = trim($input['message']);

$stmt = $pdo->prepare("INSERT INTO live_chat (Sender, Message) VALUES (?, ?)");
$stmt->execute([$userId, $message]);

echo json_encode(['success' => true]);
?>
