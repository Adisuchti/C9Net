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

if (!isset($input['market_id']) || !isset($input['market_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$marketId = (int)$input['market_id'];
$marketName = trim($input['market_name']);

// Validate market name
if (empty($marketName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Market name cannot be empty']);
    exit();
}

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

    // Check if name is already taken by another market
    $nameCheckQuery = "SELECT Id FROM markets WHERE Name = ? AND Id != ?";
    $nameCheckStmt = $pdo->prepare($nameCheckQuery);
    $nameCheckStmt->execute([$marketName, $marketId]);
    
    if ($nameCheckStmt->rowCount() > 0) {
        throw new Exception("A market with this name already exists");
    }

    // Update market name
    $updateQuery = "UPDATE markets SET Name = ? WHERE Id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$marketName, $marketId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => ""]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
