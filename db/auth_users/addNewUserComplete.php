<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - admin only']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

// Extract all fields
$username = isset($input['username']) ? trim($input['username']) : '';
$password = isset($input['password']) ? $input['password'] : '';
$startingMoney = isset($input['startingMoney']) ? (int)$input['startingMoney'] : 0;
$inventoryName = isset($input['inventoryName']) ? trim($input['inventoryName']) : '';
$steamUserId = isset($input['steamUserId']) ? trim($input['steamUserId']) : '';

// Validate required fields
$errors = [];
if (empty($username)) $errors[] = 'Username is required';
if (empty($password)) $errors[] = 'Password is required';
if (empty($inventoryName)) $errors[] = 'Inventory name is required';
if (empty($steamUserId)) $errors[] = 'Steam User ID is required';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
    exit();
}

try {
    $pdo->beginTransaction();

    // Step 1: Create the inventory
    $addInventoryQuery = "INSERT INTO inventories (Inventory_Name, Inventory_Money, Inventory_Market_Saturation) 
                          VALUES (?, ?, 0)";
    $addInventoryStmt = $pdo->prepare($addInventoryQuery);
    $addInventoryStmt->execute([$inventoryName, $startingMoney]);
    $inventoryId = (int)$pdo->lastInsertId();

    // Step 2: Create the user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $addUserQuery = "INSERT INTO users (username, password, inventory_id) VALUES (?, ?, ?)";
    $addUserStmt = $pdo->prepare($addUserQuery);
    $addUserStmt->execute([$username, $passwordHash, $inventoryId]);
    $userId = (int)$pdo->lastInsertId();

    // Step 3: Create the player profile (Assignment = 1 = default team, same as addPlayer.php)
    $addProfileQuery = "INSERT INTO player_profiles (
        Profile_Name, Status, Callsign, Role, Assignment, Homeland, 
        Combat_Hours, Hire_Date, Certs, Description, User_Id
    ) VALUES (
        ?, 'ACTIVE', '', '', 1, 'Unknown',
        0, CURDATE(), '', 'No description available.', ?
    )";
    $addProfileStmt = $pdo->prepare($addProfileQuery);
    $addProfileStmt->execute([$username, $userId]);
    $profileId = (int)$pdo->lastInsertId();

    // Create profile image directory
    $uploadDir = "../../images/profileUploads/profile" . str_pad($profileId, 3, '0', STR_PAD_LEFT);
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Step 4: Add inventory permission with the Steam User ID
    $addPermissionQuery = "INSERT INTO permissions (Inventory_Id, Player_Id) VALUES (?, ?)";
    $addPermissionStmt = $pdo->prepare($addPermissionQuery);
    $addPermissionStmt->execute([$inventoryId, $steamUserId]);

    // Log the money transaction if starting money > 0
    if ($startingMoney > 0) {
        $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                    VALUES (?, 'MONEY', ?, 1, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([$inventoryId, $startingMoney, "Initial balance set by admin"]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'error' => null,
        'data' => [
            'userId' => $userId,
            'inventoryId' => $inventoryId,
            'profileId' => $profileId,
            'steamUserId' => $steamUserId
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Check for duplicate username
    if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
        if (strpos($e->getMessage(), 'username') !== false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Username already taken. Please choose a different one.']);
            exit();
        }
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
