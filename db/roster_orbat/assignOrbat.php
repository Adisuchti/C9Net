<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'] ?? null;
$canEditDashboard = false;
$allowedUserIds = [-1, 16, 25, 31, 34, 32, 39];
if (in_array($userId, $allowedUserIds)) {
    $canEditDashboard = true;
} else {
    $stmtAuth = $pdo->prepare("SELECT Role FROM player_profiles WHERE User_Id = ?");
    $stmtAuth->execute([$userId]);
    $roles = $stmtAuth->fetchAll(PDO::FETCH_COLUMN);
    if (!empty(array_intersect($roles, ['Officer', 'squadleader']))) {
        $canEditDashboard = true;
    }
}

if (!$canEditDashboard) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['profileId']) || !isset($data['teamId'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $profileId = (int)$data['profileId'];
    $teamId = (int)$data['teamId'];
    $role = isset($data['role']) ? $data['role'] : null;
    
    // Remove any existing assignment for this profile
    $deleteStmt = $pdo->prepare("DELETE FROM orbat_assignments WHERE Profile_Id = ?");
    $deleteStmt->execute([$profileId]);
    
    // Get max sorting for this team
    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(Sorting), 0) + 1 as next_sort FROM orbat_assignments WHERE Team_Id = ?");
    $maxStmt->execute([$teamId]);
    $nextSort = $maxStmt->fetch()['next_sort'];
    
    // Insert new assignment
    $query = "INSERT INTO orbat_assignments (Team_Id, Profile_Id, Role, Sorting) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$teamId, $profileId, $role, $nextSort]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
