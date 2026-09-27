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

if (!isset($input['oldName']) || !isset($input['newName'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$directory = isset($input['directory']) ? $input['directory'] : '../pdf/news/';
$actualDirectory = $directory;
if (strpos($directory, '../pdf/') === 0) {
    $actualDirectory = '../' . $directory;
}

$oldFile = $actualDirectory . $input['oldName'];
$newFile = $actualDirectory . $input['newName'];

if (!file_exists($oldFile)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit();
}

if (rename($oldFile, $newFile)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to rename file']);
}
?>
