<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);
$profileId = isset($input['profileId']) ? (int)$input['profileId'] : 0;
$filename = isset($input['filename']) ? $input['filename'] : '';

// Check if user owns this profile
$query = "SELECT User_Id FROM player_profiles WHERE Profile_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile || ($profile['User_Id'] != $_SESSION['user_id'] && $_SESSION['user_id'] != -1)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$filePath = "../../images/profileUploads/profile" . str_pad($profileId, 3, '0', STR_PAD_LEFT) . "/" . $filename;

if (file_exists($filePath) && unlink($filePath)) {
    // Clean up associated description and comments
    $pdo->prepare("DELETE FROM image_descriptions WHERE Profile_Id = ? AND Filename = ?")->execute([$profileId, $filename]);
    $pdo->prepare("DELETE FROM image_comments WHERE Profile_Id = ? AND Filename = ?")->execute([$profileId, $filename]);

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not delete file']);
}
