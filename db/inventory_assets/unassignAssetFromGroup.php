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

if (!$assetId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
    exit();
}

try {
    $query = "DELETE FROM asset_assignments WHERE Asset_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$assetId]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
