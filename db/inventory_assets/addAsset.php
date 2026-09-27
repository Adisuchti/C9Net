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
    if(isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        // Skip validateCsrfToken if we can't easily modify JS to send it right now, 
        // wait, we can just use validateCsrfToken(); if the js sends it.
        // dashboard.php might not send CSRF by default for its fetches.
        // Let's keep validateCsrfToken() as it was in the original script.
    }
    validateCsrfToken();

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['name']) || !isset($data['className']) || !isset($data['quantity'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $name = $data['name'];
    $className = $data['className'];
    $quantity = (int)$data['quantity'];
    $ammo = isset($data['ammo']) && $data['ammo'] !== '' ? (int)$data['ammo'] : null;
    $health = isset($data['health']) && $data['health'] !== '' ? (float)$data['health'] : null;
    $fuel = isset($data['fuel']) && $data['fuel'] !== '' ? (float)$data['fuel'] : null;
    $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (int)$data['group_id'] : null;
    
    $query = "INSERT INTO website_assets (Name, ClassName, Quantity, Ammo, Health, Fuel) 
              VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$name, $className, $quantity, $ammo, $health, $fuel]);
    
    $newAssetId = $pdo->lastInsertId();
    
    // Handle asset group assignment
    if ($groupId) {
        $maxSortQuery = "SELECT COALESCE(MAX(Sorting), 0) as max_sort FROM asset_assignments WHERE Group_Id = ?";
        $maxSortStmt = $pdo->prepare($maxSortQuery);
        $maxSortStmt->execute([$groupId]);
        $maxSort = $maxSortStmt->fetch()['max_sort'];
        
        $assignQuery = "INSERT INTO asset_assignments (Group_Id, Asset_Id, Sorting) VALUES (?, ?, ?)";
        $assignStmt = $pdo->prepare($assignQuery);
        $assignStmt->execute([$groupId, $newAssetId, $maxSort + 1]);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
