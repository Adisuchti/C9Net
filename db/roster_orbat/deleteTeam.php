<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['teamId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$teamId = (int)$input['teamId'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if team exists
    $checkQuery = "SELECT Fireteam_Id FROM team_hierarchy WHERE Fireteam_Id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$teamId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("Team not found");
    }

    // Update all child teams to have no parent
    $updateChildrenQuery = "UPDATE team_hierarchy SET Fireteam_Parent_Id = -1 WHERE Fireteam_Parent_Id = ?";
    $updateChildrenStmt = $pdo->prepare($updateChildrenQuery);
    $updateChildrenStmt->execute([$teamId]);

    // Unassign all players from this team
    $unassignPlayersQuery = "UPDATE player_profiles SET Assignment = 1 WHERE Assignment = ?";
    $unassignPlayersStmt = $pdo->prepare($unassignPlayersQuery);
    $unassignPlayersStmt->execute([$teamId]);

    // Delete the team
    $deleteQuery = "DELETE FROM team_hierarchy WHERE Fireteam_Id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$teamId]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
