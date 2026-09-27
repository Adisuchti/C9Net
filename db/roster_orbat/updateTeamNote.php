<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

$teamId = isset($data['team_id']) ? (int)$data['team_id'] : 0;
$content = isset($data['content']) ? $data['content'] : '';

if ($teamId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid team ID']);
    exit();
}

try {
    // Verify user is a member of the team
    $userProfileStmt = $pdo->prepare("SELECT Profile_Id FROM player_profiles WHERE User_Id = ? AND Assignment = ?");
    $userProfileStmt->execute([$_SESSION['user_id'], $teamId]);
    if (!$userProfileStmt->fetch()) {
        throw new Exception("You are not a member of this squad and cannot edit its notebook.");
    }

    $stmt = $pdo->prepare("INSERT INTO team_notebooks (team_id, content, updated_by) VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)");
    $stmt->execute([$teamId, $content, $_SESSION['username']]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
