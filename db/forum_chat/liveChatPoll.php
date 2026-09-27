<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$lastId = isset($_GET['lastId']) ? (int)$_GET['lastId'] : 0;
$timeout = 15; // seconds
$start = time();
$newMessages = [];

while (time() - $start < $timeout) {
    $stmt = $pdo->prepare("SELECT c.Id, c.Message, c.Timestamp, u.username FROM live_chat c LEFT JOIN users u ON c.Sender = u.id WHERE c.Id > ? ORDER BY c.Id ASC");
    $stmt->execute([$lastId]);
    $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($newMessages) > 0) break;
    usleep(400000); // 400ms
}

echo json_encode(['success' => true, 'messages' => $newMessages]);
?>
