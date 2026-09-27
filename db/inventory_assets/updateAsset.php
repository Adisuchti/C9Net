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
    
    if (!isset($data['id']) || !isset($data['name']) || !isset($data['className']) || !isset($data['quantity'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $id = (int)$data['id'];
    $name = $data['name'];
    $className = $data['className'];
    $quantity = (int)$data['quantity'];
    $ammo = isset($data['ammo']) && $data['ammo'] !== '' ? (int)$data['ammo'] : null;
    $health = isset($data['health']) && $data['health'] !== '' ? (float)$data['health'] : null;
    $fuel = isset($data['fuel']) && $data['fuel'] !== '' ? (float)$data['fuel'] : null;
    $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (int)$data['group_id'] : null;
    
    $query = "UPDATE website_assets 
              SET Name = ?, ClassName = ?, Quantity = ?, Ammo = ?, Health = ?, Fuel = ? 
              WHERE Id = ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$name, $className, $quantity, $ammo, $health, $fuel, $id]);
    
    // Handle asset group assignment
    // First remove any existing assignment
    $deleteQuery = "DELETE FROM asset_assignments WHERE Asset_Id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$id]);
    
    // Then assign to new group if provided
    if ($groupId) {
        $maxSortQuery = "SELECT COALESCE(MAX(Sorting), 0) as max_sort FROM asset_assignments WHERE Group_Id = ?";
        $maxSortStmt = $pdo->prepare($maxSortQuery);
        $maxSortStmt->execute([$groupId]);
        $maxSort = $maxSortStmt->fetch()['max_sort'];
        
        $assignQuery = "INSERT INTO asset_assignments (Group_Id, Asset_Id, Sorting) VALUES (?, ?, ?)";
        $assignStmt = $pdo->prepare($assignQuery);
        $assignStmt->execute([$groupId, $id, $maxSort + 1]);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
