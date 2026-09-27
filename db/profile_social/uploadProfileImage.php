<?php
require_once '../connection.php';
require_once '../../includes/auth.php';
require_once '../../db/generateThumbnail.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$profileId = isset($_POST['profileId']) ? (int)$_POST['profileId'] : 0;

// Check if user owns this profile
$query = "SELECT User_Id FROM player_profiles WHERE Profile_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Profile not found']);
    exit();
}

if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$uploadDir = "../../images/profiles/";
$thumbDir = "../../images/profiles/thumbs/";

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!file_exists($thumbDir)) {
    mkdir($thumbDir, 0777, true);
}

$uploaded = array();
$errors = array();

// Check if files were uploaded
if (!isset($_FILES['images']) || !isset($_FILES['images']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'No files uploaded']);
    exit();
}

foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
    if (empty($tmp_name)) continue;
    
    $file_name = $_FILES['images']['name'][$key];
    $file_size = $_FILES['images']['size'][$key];
    $file_tmp = $_FILES['images']['tmp_name'][$key];
    $file_type = $_FILES['images']['type'][$key];
    
    // Validate file size
    if ($file_size > 5242880) { // 5MB limit
        echo json_encode([
            'success' => false, 
            'error' => "$file_name exceeds 5MB"
        ]);
        exit();
    }
    
    // Validate file extension
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if (!in_array($file_ext, ['jpg', 'jpeg', 'png', 'PNG', 'JPG', 'JPEG'])) {
        echo json_encode([
            'success' => false,
            'error' => "$file_name: Only JPG and PNG files are allowed"
        ]);
        exit();
    }

    // Generate filename
    $new_file_name = "profile-" . str_pad($profileId, 3, '0', STR_PAD_LEFT) . "." . $file_ext;
    $full_path = $uploadDir . $new_file_name;
    $thumb_path = $thumbDir . "profile-" . str_pad($profileId, 3, '0', STR_PAD_LEFT) . ".png";

    if (move_uploaded_file($file_tmp, $full_path)) {
        // Generate thumbnail
        if (createThumbnail($full_path, $thumb_path, 150, 150)) {
            $uploaded[] = $new_file_name;
        } else {
            // Still add to uploaded even if thumbnail fails
            $uploaded[] = $new_file_name;
            error_log("Failed to create thumbnail for: $new_file_name");
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Could not upload $file_name"
        ]);
        exit();
    }
}

if (count($uploaded) > 0) {
    echo json_encode([
        'success' => true,
        'uploaded' => $uploaded
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No files were uploaded successfully'
    ]);
}
?>
