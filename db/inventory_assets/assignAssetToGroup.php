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

validateCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
$assetId = $input['assetId'] ?? 0;
$groupId = $input['groupId'] ?? 0;

if (!$assetId || !$groupId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID and Group ID are required']);
    exit();
}

try {
    // Remove existing assignment if any
    $deleteQuery = "DELETE FROM asset_assignments WHERE Asset_Id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$assetId]);
    
    // Get max sorting value for this group
    $maxSortQuery = "SELECT COALESCE(MAX(Sorting), 0) as max_sort FROM asset_assignments WHERE Group_Id = ?";
    $maxSortStmt = $pdo->prepare($maxSortQuery);
    $maxSortStmt->execute([$groupId]);
    $maxSort = $maxSortStmt->fetch()['max_sort'];
    
    // Add new assignment
    $insertQuery = "INSERT INTO asset_assignments (Group_Id, Asset_Id, Sorting) VALUES (?, ?, ?)";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute([$groupId, $assetId, $maxSort + 1]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
