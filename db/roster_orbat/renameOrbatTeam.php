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
    
    if (!isset($data['id']) || !isset($data['name'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $id = (int)$data['id'];
    $name = $data['name'];
    
    $query = "UPDATE orbat_teams SET Team_Name = ? WHERE Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$name, $id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
