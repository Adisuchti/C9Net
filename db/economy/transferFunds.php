<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['targetInventoryId']) || !isset($input['amount'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$targetInventoryId = (int)$input['targetInventoryId'];
$amount = (int)$input['amount'];
$sourceInventoryId = isset($input['sourceInventoryId']) ? (int)$input['sourceInventoryId'] : $_SESSION['inventory_id'];

if (!isUserAuthorizedForInventory($pdo, $_SESSION['user_id'], $sourceInventoryId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized for this inventory']);
    exit();
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get source inventory money
    $sourceQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE";
    $sourceStmt = $pdo->prepare($sourceQuery);
    $sourceStmt->execute([$sourceInventoryId]);
    $sourceMoney = $sourceStmt->fetchColumn();

    if ($sourceMoney === false) {
        throw new Exception("Source inventory not found");
    }

    // Check if source has enough money
    if ($sourceMoney < $amount) {
        throw new Exception("Insufficient funds");
    }

    // Get target inventory
    $targetQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE";
    $targetStmt = $pdo->prepare($targetQuery);
    $targetStmt->execute([$targetInventoryId]);
    $targetMoney = $targetStmt->fetchColumn();

    if ($targetMoney === false) {
        throw new Exception("Target inventory not found");
    }

    // Update source inventory
    $updateSourceQuery = "UPDATE inventories 
                         SET Inventory_Money = Inventory_Money - ? 
                         WHERE Inventory_Id = ?";
    $updateSourceStmt = $pdo->prepare($updateSourceQuery);
    $updateSourceStmt->execute([$amount, $sourceInventoryId]);

    // Update target inventory
    $updateTargetQuery = "UPDATE inventories 
                         SET Inventory_Money = Inventory_Money + ? 
                         WHERE Inventory_Id = ?";
    $updateTargetStmt = $pdo->prepare($updateTargetQuery);
    $updateTargetStmt->execute([$amount, $targetInventoryId]);

    // Log the transfer
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, 'MONEY', ?, 1, ?), (?, 'MONEY', ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $sourceInventoryId,
        -$amount,
        "Transfer",
        $targetInventoryId,
        $amount,
        "Transfer"
    ]);

    // Update session money if needed
    if ($sourceInventoryId == $_SESSION['inventory_id']) {
        $_SESSION['inventory_money'] -= $amount;
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
