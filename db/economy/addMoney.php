<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Only admin can add money
if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - admin only']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$amount = isset($input['amount']) ? (int)$input['amount'] : 0;
$inventoryId = isset($input['inventoryId']) ? (int)$input['inventoryId'] : 0;

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get current inventory money
    $getMoneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $getMoneyStmt = $pdo->prepare($getMoneyQuery);
    $getMoneyStmt->execute([$inventoryId]);
    $currentMoney = $getMoneyStmt->fetchColumn();

    if ($currentMoney === false) {
        throw new Exception("Inventory not found");
    }

    // Calculate new balance
    $newBalance = $currentMoney + $amount;

    // Don't allow negative balance
    if ($newBalance < 0) {
        throw new Exception("Insufficient funds");
    }

    // Update inventory money
    $updateQuery = "UPDATE inventories SET Inventory_Money = ? WHERE Inventory_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$newBalance, $inventoryId]);

    // Log the transaction
    $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, 'MONEY', ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([$inventoryId, $amount,
    "Admin or Company action"]);

    if($_SESSION['user_id'] !== -1) {
        // If this is the current user's inventory, update the session
        if ($_SESSION['inventory_id'] == $inventoryId) {
            $_SESSION['inventory_money'] = $newBalance;
        }
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'error' => 'null']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
