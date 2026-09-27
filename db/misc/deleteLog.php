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

if (!isset($input['logId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$logId = (int)$input['logId'];

try {
    // Start transaction
    $pdo->beginTransaction();

    $getLogQuery = "SELECT * FROM logs WHERE Log_Id = ?";
    $getLogStmt = $pdo->prepare($getLogQuery);
    $getLogStmt->execute([$logId]);
    $logEntry = $getLogStmt->fetch(PDO::FETCH_ASSOC);

    // Delete the log entry
    $deleteQuery = "DELETE FROM logs WHERE Log_Id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$logId]);

    $logStmt = $pdo->prepare("
        INSERT INTO hiddenLogs (Comment) 
        VALUES (?)
    ");
    $logMessage = "Log entry with ID '". $logId ."' deleted by admin user '".
    $_SESSION['username'] ."' from ". $_SERVER['REMOTE_ADDR'] ." at ".
    date('Y-m-d H:i:s') . "\n". "Deleted log details: \n" .
    "Item: " . $logEntry['Transaction_Item'] . "\n" .
    "Quantity: " . $logEntry['Transaction_Quantity'] . "\n" .
    "Transaction_Date: " . $logEntry['Transaction_Date'] . "\n" .
    "Transaction_Inventory_Id: " . $logEntry['Transaction_Inventory_Id'] . "\n" .
    "isMarketActivity: " . $logEntry['isMarketActivity'] . "\n" .
    "Comment: " . $logEntry['Comment'];
    $logStmt->execute([$logMessage]);

    if ($deleteStmt->rowCount() === 0) {
        throw new Exception("Log entry not found");
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
