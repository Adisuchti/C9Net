<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['filename'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing filename']);
    exit();
}

$directory = isset($input['directory']) ? $input['directory'] : '../pdf/news/';
$actualDirectory = $directory;
if (strpos($directory, '../pdf/') === 0) {
    $actualDirectory = '../' . $directory;
}

$file = $actualDirectory . $input['filename'];

if (!file_exists($file)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit();
}

if (unlink($file)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete file']);
}
?>
