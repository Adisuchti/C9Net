<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit();
}

$file = $_FILES['image'];
$uploadDir = '../../images/wiki/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$allowedTypes = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/gif' => 'gif',
    'image/webp' => 'webp'
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!array_key_exists($mimeType, $allowedTypes)) {
    echo json_encode([
        'success' => false,
        'error' => 'File must be an image (PNG, JPG, GIF, WebP)'
    ]);
    exit();
}

// 10MB limit
if ($file['size'] > 10485760) {
    echo json_encode([
        'success' => false,
        'error' => 'Image must be under 10MB'
    ]);
    exit();
}

// Clean filename: replace spaces with underscores, strip unsafe chars
$cleanFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$cleanFilename = str_replace(' ', '_', $cleanFilename);

// If file already exists, append a number
$baseName = pathinfo($cleanFilename, PATHINFO_FILENAME);
$extension = pathinfo($cleanFilename, PATHINFO_EXTENSION);
$finalFilename = $cleanFilename;
$counter = 2;
while (file_exists($uploadDir . $finalFilename)) {
    $finalFilename = $baseName . '_' . $counter . '.' . $extension;
    $counter++;
}

if (move_uploaded_file($file['tmp_name'], $uploadDir . $finalFilename)) {
    // Log
    try {
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Wiki image uploaded: '$finalFilename' by user '" . $_SESSION['username'] . "'"]);
    } catch (Exception $e) {
        error_log("Failed to log wiki image upload: " . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'filename' => $finalFilename,
        'url' => '/images/wiki/' . $finalFilename
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
}
?>
