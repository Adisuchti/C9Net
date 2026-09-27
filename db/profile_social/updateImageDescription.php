<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);
$profileId = isset($input['profileId']) ? (int)$input['profileId'] : 0;
$filename = isset($input['filename']) ? $input['filename'] : '';
$description = isset($input['description']) ? trim($input['description']) : '';

if (!$profileId || !$filename) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

// Check if user owns this profile or is admin
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
    // Upsert description
    $stmt = $pdo->prepare("
        INSERT INTO image_descriptions (Profile_Id, Filename, Description, Updated_By)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE Description = VALUES(Description), Updated_By = VALUES(Updated_By)
    ");
    $stmt->execute([$profileId, $filename, $description, $_SESSION['user_id']]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
