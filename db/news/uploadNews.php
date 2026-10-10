<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

if (!isset($_FILES['pdf'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit();
}

$file = $_FILES['pdf'];
$uploadDir = $_POST['directory'];

$allowedDirs = ['../pdf/docs/', '../pdf/news/'];
if (!in_array($uploadDir, $allowedDirs)) {
    echo json_encode(['success' => false, 'error' => 'Invalid directory']);
    exit();
}

$actualUploadDir = '../' . $uploadDir;

if (!file_exists($actualUploadDir)) {
    mkdir($actualUploadDir, 0777, true);
}

$allowedTypes = [
    'application/pdf' => 'pdf',
    'image/png' => 'png',
    'video/mp4' => 'mp4'
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!array_key_exists($mimeType, $allowedTypes)) {
    echo json_encode([
        'success' => false, 
        'error' => 'File must be a PDF, PNG or MP4'
    ]);
    exit();
}

// Add file size limit for videos (e.g., 200MB)
if ($file['size'] > 209715200) {
    echo json_encode([
        'success' => false, 
        'error' => 'File must be under 100MB'
    ]);
    exit();
}

$cleanFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$cleanFilename = str_replace(' ', '_', $cleanFilename);

if (move_uploaded_file($file['tmp_name'], $actualUploadDir . $cleanFilename)) {
     try {
        // Log the activity in web_activity_log
        $activity = "Docs uploaded: " . $cleanFilename;
        $linksub = "";
        if($uploadDir === '../pdf/news/') {
            $linksub = "/views/docs.php?mode=news";
        } elseif($uploadDir === '../pdf/docs/') {
            $linksub = "/views/docs.php?mode=docs";
        }
        $link = $linksub . "&file=" . urlencode($cleanFilename);

        $logQuery = "INSERT INTO web_activity_log (Activity, Link) VALUES (?, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([$activity, $link]);
        
        echo json_encode(['success' => true, 'filename' => $cleanFilename]);
    } catch (Exception $e) {
        // File was uploaded successfully, but logging failed
        // Don't fail the entire operation, just log the error
        error_log("Failed to log news upload activity: " . $e->getMessage());
        echo json_encode(['success' => true, 'filename' => $cleanFilename]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
}
?>
