<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$postId = isset($_POST['postId']) ? (int)$_POST['postId'] : 0;
if (!$postId || !isset($_FILES['media'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing postId or file']);
    exit();
}

$file = $_FILES['media'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['png', 'jpg', 'jpeg', 'gif', 'mp4', 'webm'];

if (!in_array($ext, $allowed)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
    exit();
}

$mediaType = in_array($ext, ['mp4', 'webm']) ? 'video' : 'image';
$targetPath = "../../images/forum/" . $postId . "." . $ext;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Optionally update media type in DB
    $stmt = $pdo->prepare("UPDATE forum_posts SET Media_Type = ? WHERE Id = ?");
    $stmt->execute([$mediaType, $postId]);
    echo json_encode(['success' => true, 'filename' => $postId . "." . $ext]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
}
?>
