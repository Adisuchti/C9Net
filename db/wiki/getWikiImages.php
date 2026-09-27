<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$imageDir = '../../images/wiki/';

if (!file_exists($imageDir)) {
    mkdir($imageDir, 0777, true);
    echo json_encode(['success' => true, 'images' => []]);
    exit();
}

$files = array_merge(
    glob($imageDir . "*.png"),
    glob($imageDir . "*.PNG"),
    glob($imageDir . "*.jpg"),
    glob($imageDir . "*.JPG"),
    glob($imageDir . "*.jpeg"),
    glob($imageDir . "*.JPEG"),
    glob($imageDir . "*.gif"),
    glob($imageDir . "*.GIF"),
    glob($imageDir . "*.webp"),
    glob($imageDir . "*.WEBP"),
    glob($imageDir . "*.svg"),
    glob($imageDir . "*.SVG")
);

// Sort by modification time (newest first)
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$images = [];
foreach ($files as $file) {
    $filename = basename($file);
    $images[] = [
        'filename' => $filename,
        'url' => '/images/wiki/' . $filename,
        'size' => filesize($file),
        'modified' => date('Y-m-d H:i:s', filemtime($file))
    ];
}

echo json_encode(['success' => true, 'images' => $images]);
?>
