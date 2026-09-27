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

if (!isset($input['inventoryId']) || !isset($input['newName'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$inventoryId = (int)$input['inventoryId'];
$newName = trim($input['newName']);

// Validate new name
if (empty($newName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Name cannot be empty']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if inventory exists
    $checkQuery = "SELECT Inventory_Id FROM inventories WHERE Inventory_Id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$inventoryId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("Inventory not found");
    }

    // Check if name is already taken
    $nameCheckQuery = "SELECT Inventory_Id FROM inventories WHERE Inventory_Name = ? AND Inventory_Id != ?";
    $nameCheckStmt = $pdo->prepare($nameCheckQuery);
    $nameCheckStmt->execute([$newName, $inventoryId]);
    
    if ($nameCheckStmt->rowCount() > 0) {
        throw new Exception("An inventory with this name already exists");
    }

    // Update inventory name
    $updateQuery = "UPDATE inventories SET Inventory_Name = ? WHERE Inventory_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$newName, $inventoryId]);

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
