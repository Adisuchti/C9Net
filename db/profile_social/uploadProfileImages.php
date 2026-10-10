<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$profileId = isset($_POST['profileId']) ? (int)$_POST['profileId'] : 0;

// Check if user owns this profile
$query = "SELECT User_Id FROM player_profiles WHERE Profile_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile || $profile['User_Id'] != $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$uploadDir = "../../images/profileUploads/profile" . str_pad($profileId, 3, '0', STR_PAD_LEFT) . "/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Get optional description for the uploaded images
$description = isset($_POST['description']) ? trim($_POST['description']) : '';

$uploaded = array(); // Initialize as array instead of string

foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
    $file_name = $_FILES['images']['name'][$key];
    $file_size = $_FILES['images']['size'][$key];
    $file_tmp = $_FILES['images']['tmp_name'][$key];
    $file_type = $_FILES['images']['type'][$key];
    
    // Validate file
    if ($file_size > 5242880) { // 5MB limit
        echo json_encode([
            'success' => false, 
            'error' => "$file_name exceeds 5MB"
        ]);
        exit();
    }
    
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file_tmp);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png'];
    
    if (!in_array($mimeType, $allowedMimes)) {
        echo json_encode([
            'success' => false,
            'error' => "$file_name: Only JPG and PNG files are allowed"
        ]);
        exit();
    }

    // Generate filename with timestamp prefix for date display
    $new_file_name = date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $file_ext;
    
    if (move_uploaded_file($file_tmp, $uploadDir . $new_file_name)) {
        $uploaded[] = $new_file_name;

        // Save description if provided
        if ($description !== '') {
            $descStmt = $pdo->prepare("INSERT INTO image_descriptions (Profile_Id, Filename, Description, Updated_By) VALUES (?, ?, ?, ?)");
            $descStmt->execute([$profileId, $new_file_name, $description, $_SESSION['user_id']]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Could not upload $file_name"
        ]);
        exit();
    }
}

// Log the upload activity in web_activity_log
if (count($uploaded) > 0) {
    try {
        $profileNameStmt = $pdo->prepare("SELECT Profile_Name FROM player_profiles WHERE Profile_Id = ?");
        $profileNameStmt->execute([$profileId]);
        $profileName = $profileNameStmt->fetchColumn() ?: 'Unknown';

        $imageCount = count($uploaded);
        $activity = "Image uploaded: " . $profileName . " uploaded " . $imageCount . " image" . ($imageCount > 1 ? 's' : '');
        $link = "/views/profile.php?id=" . $profileId;
        $logQuery = "INSERT INTO web_activity_log (Activity, Link) VALUES (?, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([$activity, $link]);
    } catch (Exception $e) {
        error_log("Failed to log image upload activity: " . $e->getMessage());
    }
}

// Return success even if there were some errors, as long as at least one file uploaded
echo json_encode([
    'success' => count($uploaded) > 0,
    'uploaded' => $uploaded,
    'error' => ""
]);
