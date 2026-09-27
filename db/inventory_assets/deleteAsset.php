<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Check if user has permission
$userId = $_SESSION['user_id'] ?? null;
$canEditDashboard = false;
$isAdmin = ($_SESSION['username'] === 'admin');

$allowedUserIds = [-1, 16, 25, 31, 34, 32, 39];
if (in_array($userId, $allowedUserIds)) {
    $canEditDashboard = true;
} else {
    $stmt = $pdo->prepare("SELECT Role FROM player_profiles WHERE User_Id = ?");
    $stmt->execute([$userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty(array_intersect($roles, ['Officer', 'squadleader']))) {
        $canEditDashboard = true;
    }
}

if (!isLoggedIn() || (!$canEditDashboard && !$isAdmin)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing asset ID']);
        exit();
    }
    
    $id = (int)$data['id'];
    
    $query = "DELETE FROM website_assets WHERE Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
