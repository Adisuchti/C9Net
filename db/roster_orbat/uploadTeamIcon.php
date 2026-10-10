<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

try {
    if (!isset($_POST['teamId']) || empty($_POST['teamId'])) {
        throw new Exception('Team ID is required');
    }

    $teamId = (int)$_POST['teamId'];

    if (!isset($_FILES['icon']) || $_FILES['icon']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES['icon'];
    
    // Validate file type
    $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only PNG and JPG images are allowed.');
    }

    // Validate file size (max 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('File size exceeds 2MB limit.');
    }

    $targetDir = __DIR__ . '/../../images/teams/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Team icons are stored as <teamId>.png
    $targetPath = $targetDir . $teamId . '.png';

    // If the uploaded file is not PNG, convert it
    if ($mimeType !== 'image/png') {
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $image = imagecreatefromjpeg($file['tmp_name']);
        } else {
            throw new Exception('Unsupported image format for conversion.');
        }

        // Save as PNG
        if (!imagepng($image, $targetPath)) {
            throw new Exception('Failed to convert and save image as PNG.');
        }
        imagedestroy($image);
    } else {
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to move uploaded file.');
        }
    }

    chmod($targetPath, 0644);

    echo json_encode([
        'success' => true,
        'path' => '../images/teams/' . $teamId . '.png?t=' . time()
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

