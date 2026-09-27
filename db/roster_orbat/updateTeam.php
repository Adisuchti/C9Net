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

$rawInput = file_get_contents("php://input");
try {
    $logStmt = $pdo->prepare("INSERT INTO system_query_log (query_text, user_id, ip_address) VALUES (?, ?, ?)");
    $logStmt->execute(["updateTeam.php payload: " . $rawInput, $_SESSION['user_id'] ?? null, $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Exception $e) {}
error_log("updateTeam.php payload: " . $rawInput);
$input = json_decode($rawInput, true);

// Validate required fields
if (!isset($input['teamId']) || !isset($input['name']) || !isset($input['parentId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$teamId = (int)$input['teamId'];
$name = trim($input['name']);
$parentId = (int)$input['parentId'];
$sorting = isset($input['sorting']) ? (int)$input['sorting'] : 0;

try {
    $pdo->beginTransaction();

    // Check if team exists
    $checkQuery = "SELECT Fireteam_Id FROM team_hierarchy WHERE Fireteam_Id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$teamId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("Team not found");
    }

    // Check for circular reference
    if ($parentId > 0) {
        $currentId = $parentId;
        $visited = [$teamId];
        
        while ($currentId > 0) {
            if (in_array($currentId, $visited)) {
                throw new Exception("Circular team hierarchy detected");
            }
            $visited[] = $currentId;
            
            $parentQuery = "SELECT Fireteam_Parent_Id FROM team_hierarchy WHERE Fireteam_Id = ?";
            $parentStmt = $pdo->prepare($parentQuery);
            $parentStmt->execute([$currentId]);
            $parent = $parentStmt->fetch();
            
            if (!$parent) break;
            $currentId = (int)$parent['Fireteam_Parent_Id'];
        }
    }

    // Update team
    $color = isset($input['color']) ? trim($input['color']) : null;
    
    $hasLeader = array_key_exists('leader_player_id', $input);
    $hasInventory = array_key_exists('team_inventory_id', $input);
    $hasShortDesignation = array_key_exists('short_designation', $input);
    
    $updateQuery = "UPDATE team_hierarchy 
                   SET Fireteam_Name = ?,
                       Fireteam_Parent_Id = ?,
                        Sorting = ?,
                        Fireteam_Color = ?";
                        
    $params = [
        $name,
        $parentId,
        $sorting,
        $color
    ];
    
    if ($hasLeader) {
        $updateQuery .= ", leader_player_id = ?";
        if (isset($input['leader_player_id']) && $input['leader_player_id'] !== "" && $input['leader_player_id'] !== null && $input['leader_player_id'] !== "null") {
            $leaderIdInt = (int)$input['leader_player_id'];
            $params[] = $leaderIdInt > 0 ? $leaderIdInt : null;
        } else {
            $params[] = null;
        }
    }
    
    if ($hasInventory) {
        $updateQuery .= ", team_inventory_id = ?";
        $params[] = isset($input['team_inventory_id']) && $input['team_inventory_id'] !== "" ? (int)$input['team_inventory_id'] : null;
    }

    if ($hasShortDesignation) {
        $updateQuery .= ", short_designation = ?";
        $params[] = trim($input['short_designation']);
    }
    
    $updateQuery .= " WHERE Fireteam_Id = ?";
    $params[] = $teamId;

    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute($params);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => '']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
