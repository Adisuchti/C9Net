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

if (!isset($input['profileId']) || !isset($input['description'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$profileId = (int)$input['profileId'];
$description = trim($input['description']);

// Check if user owns this profile or is an admin
$query = "SELECT User_Id FROM player_profiles WHERE Profile_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile || ($profile['User_Id'] != $_SESSION['user_id'] && $_SESSION['user_id'] != -1)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $updateQuery = "UPDATE player_profiles SET Description = ? WHERE Profile_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$description, $profileId]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
