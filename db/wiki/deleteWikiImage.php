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

if (!isset($input['filename']) || trim($input['filename']) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Filename is required']);
    exit();
}

$filename = basename($input['filename']); // basename() prevents directory traversal
$filePath = '../../images/wiki/' . $filename;

if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit();
}

if (unlink($filePath)) {
    // Log
    try {
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Wiki image deleted: '$filename' by user '" . $_SESSION['username'] . "'"]);
    } catch (Exception $e) {
        error_log("Failed to log wiki image deletion: " . $e->getMessage());
    }

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete file']);
}
?>
