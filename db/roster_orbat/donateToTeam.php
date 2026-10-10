<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

$teamId = isset($data['team_id']) ? (int)$data['team_id'] : 0;
$amount = isset($data['amount']) ? (float)$data['amount'] : 0;

if ($teamId <= 0 || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid team or amount']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Verify user has enough money in their personal inventory
    $userInventoryId = $_SESSION['inventory_id'] ?? 0;
    if (!$userInventoryId) {
        throw new Exception("User inventory not found");
    }

    $moneyStmt = $pdo->prepare("SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE");
    $moneyStmt->execute([$userInventoryId]);
    $userMoney = $moneyStmt->fetchColumn();

    if ($userMoney < $amount) {
        throw new Exception("Insufficient funds");
    }

    // Get team inventory ID
    $teamStmt = $pdo->prepare("SELECT team_inventory_id FROM team_hierarchy WHERE Fireteam_Id = ?");
    $teamStmt->execute([$teamId]);
    $teamInventoryId = $teamStmt->fetchColumn();

    if (!$teamInventoryId) {
        throw new Exception("Team inventory not found");
    }

    // Check team inventory exists and lock it
    $teamMoneyStmt = $pdo->prepare("SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE");
    $teamMoneyStmt->execute([$teamInventoryId]);
    $teamMoney = $teamMoneyStmt->fetchColumn();

    if ($teamMoney === false) {
        throw new Exception("Team inventory record does not exist in inventories table");
    }

    // Deduct from user
    $deductStmt = $pdo->prepare("UPDATE inventories SET Inventory_Money = Inventory_Money - ? WHERE Inventory_Id = ?");
    $deductStmt->execute([$amount, $userInventoryId]);

    // Add to team
    $addStmt = $pdo->prepare("UPDATE inventories SET Inventory_Money = Inventory_Money + ? WHERE Inventory_Id = ?");
    $addStmt->execute([$amount, $teamInventoryId]);

    // Log the transaction internally
    $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
    $logMessage = "User " . $_SESSION['username'] . " donated " . $amount . " credits to team ID " . $teamId;
    $logStmt->execute([$logMessage]);

    // Log the transaction in the public logs
    $publicLogQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, 'MONEY', ?, 1, ?), (?, 'MONEY', ?, 1, ?)";
    $publicLogStmt = $pdo->prepare($publicLogQuery);
    $publicLogStmt->execute([
        $userInventoryId,
        -$amount,
        "Donation to Team",
        $teamInventoryId,
        $amount,
        "Donation from " . $_SESSION['username']
    ]);
    
    // Update session var if it exists
    if (isset($_SESSION['inventory_money'])) {
        $_SESSION['inventory_money'] -= $amount;
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

