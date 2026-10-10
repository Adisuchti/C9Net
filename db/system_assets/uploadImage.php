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
    // Check if file was uploaded
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    // Check if filename was provided
    if (!isset($_POST['filename']) || empty($_POST['filename'])) {
        throw new Exception('Filename is required');
    }

    $file = $_FILES['image'];
    $filename = $_POST['filename'];
    $force = isset($_POST['force']) && $_POST['force'] === '1';

    // Convert filename to uppercase and add .PNG extension
    $finalFilename = strtoupper($filename) . '.PNG';
    
    // Determine the target directory based on the selected target
    $target = isset($_POST['target']) ? $_POST['target'] : 'live';
    if ($target === 'preview') {
        $targetDir = __DIR__ . '/../../images/items/';
    } else {
        $targetDir = __DIR__ . '/../../images/items/';
    }
    
    // Ensure target directory exists
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $targetPath = $targetDir . $finalFilename;

    // Check if file already exists
    if (file_exists($targetPath) && !$force) {
        echo json_encode([
            'success' => false,
            'fileExists' => true,
            'existingFile' => $finalFilename
        ]);
        exit();
    }

    // Validate file type
    $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only PNG and JPG images are allowed');
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File size exceeds 5MB limit');
    }

    // If the uploaded file is not PNG, convert it
    if ($mimeType !== 'image/png') {
        // Load the image based on type
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $image = imagecreatefromjpeg($file['tmp_name']);
        } else {
            throw new Exception('Unsupported image format for conversion');
        }

        // Save as PNG
        if (!imagepng($image, $targetPath)) {
            throw new Exception('Failed to convert and save image as PNG');
        }
        imagedestroy($image);
    } else {
        // Move the uploaded PNG file to the target directory
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to move uploaded file');
        }
    }

    // Set proper permissions
    chmod($targetPath, 0644);

    $responsePath = '/images/items/' . $finalFilename;

    echo json_encode([
        'success' => true,
        'savedAs' => $finalFilename,
        'path' => $responsePath
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

