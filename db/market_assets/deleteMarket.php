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

if (!isset($input['market_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$marketId = (int)$input['market_id'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if market exists
    $checkQuery = "SELECT Id FROM markets WHERE Id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$marketId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("Market not found");
    }

    // Check if there are any market items associated with this market
    $itemCheckQuery = "SELECT COUNT(*) FROM market WHERE Market_Id = ?";
    $itemCheckStmt = $pdo->prepare($itemCheckQuery);
    $itemCheckStmt->execute([$marketId]);
    $itemCount = $itemCheckStmt->fetchColumn();

    if ($itemCount > 0) {
        throw new Exception("Cannot delete market: $itemCount items are still associated with this market");
    }

    // Delete the market
    $deleteMarketQuery = "DELETE FROM markets WHERE Id = ?";
    $deleteMarketStmt = $pdo->prepare($deleteMarketQuery);
    $deleteMarketStmt->execute([$marketId]);

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
