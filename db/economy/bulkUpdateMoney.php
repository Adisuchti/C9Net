<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - please login']);
    exit();
}

// Only admin can use this endpoint
if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - admin only']);
    exit();
}

// Read JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['inventoryIds']) || !is_array($input['inventoryIds']) || !isset($input['amount'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

$inventoryIds = $input['inventoryIds'];
$amount = (float)$input['amount'];
$actionType = $amount >= 0 ? "Bulk Payout" : "Bulk Punishment";

try {
    $pdo->beginTransaction();

    // Prepare statements for reuse
    $getMoneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $getMoneyStmt = $pdo->prepare($getMoneyQuery);

    $updateQuery = "UPDATE inventories SET Inventory_Money = ? WHERE Inventory_Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);

    $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, 'MONEY', ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);

    foreach ($inventoryIds as $inventoryId) {
        $inventoryId = (int)$inventoryId;
        
        // Get current money
        $getMoneyStmt->execute([$inventoryId]);
        $currentMoney = $getMoneyStmt->fetchColumn();

        if ($currentMoney === false) {
            // If inventory not found, we skip it or could fail the whole transaction. 
            // Failing whole transaction is safer to avoid partial updates.
            throw new Exception("Inventory ID $inventoryId not found.");
        }

        $newBalance = (float)$currentMoney + $amount;

        // Prevent negative balance
        if ($newBalance < 0) {
            $newBalance = 0;
        }

        // Update money
        $updateStmt->execute([$newBalance, $inventoryId]);

        // Log the action
        $logStmt->execute([$inventoryId, $amount, $actionType]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
