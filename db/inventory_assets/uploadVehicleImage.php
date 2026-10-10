<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Check if user has permission
// In dashboard.php, we use a specific set of users for $canEditDashboard, but the backend script needs a robust check.
// We will allow if user is admin OR if user has $canEditDashboard roles, but to be simple we check isLoggedIn.
// Ideally, we should replicate the $canEditDashboard logic here.
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$userId = $_SESSION['user_id'] ?? null;
$canEditDashboard = false;
$isAdmin = ($_SESSION['username'] === 'admin');

$allowedUserIds = [-1, 16, 25, 31, 34, 32, 39];
if (in_array($userId, $allowedUserIds)) {
    $canEditDashboard = true;
} else {
    $stmt = $pdo->prepare("SELECT Role FROM player_profiles WHERE User_Id = ?");
    $stmt->execute([$userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty(array_intersect($roles, ['Officer', 'squadleader']))) {
        $canEditDashboard = true;
    }
}

if (!$canEditDashboard && !$isAdmin) {
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit();
}

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
    
    // Vehicles directory
    $targetDir = __DIR__ . '/../../images/vehicles/';
    
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
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $image = imagecreatefromjpeg($file['tmp_name']);
        } else {
            throw new Exception('Unsupported image format for conversion');
        }

        if (!imagepng($image, $targetPath)) {
            throw new Exception('Failed to convert and save image as PNG');
        }
        imagedestroy($image);
    } else {
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to move uploaded file');
        }
    }

    chmod($targetPath, 0644);

    $responsePath = '/images/vehicles/' . $finalFilename;

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

