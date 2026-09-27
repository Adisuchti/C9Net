<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Admin only
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
$mapName = $input['mapName'] ?? ($_GET['mapName'] ?? '');

if (empty($mapName)) {
    echo json_encode(['success' => false, 'error' => 'Missing mapName']);
    exit();
}

$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $mapName);
$renderDir = __DIR__ . '/../funkySvgViewer/rasterizationData/' . $safeName;

if (!is_dir($renderDir)) {
    echo json_encode(['success' => true, 'message' => 'No pre-rendered tiles found for "' . $mapName . '"']);
    exit();
}

try {
    // Recursively delete the entire render directory
    function recursiveDelete($dir) {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? recursiveDelete($path) : unlink($path);
        }
        return rmdir($dir);
    }
    recursiveDelete($renderDir);

    echo json_encode(['success' => true, 'message' => 'Pre-rendered tiles deleted for "' . $mapName . '"']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
