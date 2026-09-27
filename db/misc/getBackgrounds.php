<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$backgroundsDir = '../../images/homeBackground/';
$backgrounds = [];

if (is_dir($backgroundsDir)) {
    $files = scandir($backgroundsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'])) {
                if (strpos($file, '.thumb.') === false) { // Don't return thumbnails as separate backgrounds
                    // Return path relative to the domain root (e.g. /images/...)
                    // We'll just return a relative path from the views directory which is '../images/homeBackground/...'
                    $backgrounds[] = '../images/homeBackground/' . $file;
                }
            }
        }
    }
}

echo json_encode(['success' => true, 'backgrounds' => $backgrounds]);
?>
