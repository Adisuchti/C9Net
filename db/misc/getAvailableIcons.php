<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

function getSvgsInDir($dir, $basePath, &$results) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                getSvgsInDir($path, $basePath, $results);
            } else {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
                    // return path relative to views (e.g. '../images/icons/...')
                    $relativePath = str_replace($basePath, '../images/', $path);
                    $results[] = $relativePath;
                }
            }
        }
    }
}

$icons = [];
$basePath = realpath(__DIR__ . '/../../images') . '\\'; // or '/' depending on OS, but str_replace is tricky with slashes.
// Let's use a safer approach for relative paths
$basePath = realpath(__DIR__ . '/../../images');

function scanForSvgs($dir, $prefix, &$results) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $path = $dir . '/' . $file;
            $relPath = $prefix . '/' . $file;
            if (is_dir($path)) {
                scanForSvgs($path, $relPath, $results);
            } else {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
                    $results[] = '../images/' . $relPath;
                }
            }
        }
    }
}

$iconsDir = __DIR__ . '/../../images/icons';
$rolesDir = __DIR__ . '/../../images/roles';

scanForSvgs($iconsDir, 'icons', $icons);
scanForSvgs($rolesDir, 'roles', $icons);

echo json_encode(['success' => true, 'icons' => $icons]);
?>
