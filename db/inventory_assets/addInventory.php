<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['name']) || !isset($input['money'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$name = $input['name'];
$money = floatval($input['money']);

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if inventory with this name already exists
    $checkQuery = "SELECT Inventory_Id FROM inventories WHERE Inventory_Name = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$name]);
    
    if ($checkStmt->rowCount() > 0) {
        throw new Exception("An inventory with this name already exists");
    }

    // Insert new inventory
    $insertQuery = "INSERT INTO inventories (Inventory_Name, Inventory_Money, Inventory_Market_Saturation, Inventory_Type) 
                   VALUES (?, ?, 0, 1)";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute([$name, $money]);

    $newInventoryId = $pdo->lastInsertId();

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => "null"]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
