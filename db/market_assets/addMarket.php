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

if (!isset($input['Name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$name = trim($input['Name']);

// Validate name
if (empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Market name cannot be empty']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if market with this name already exists
    $checkQuery = "SELECT Id FROM markets WHERE Name = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$name]);
    
    if ($checkStmt->rowCount() > 0) {
        throw new Exception("A market with this name already exists");
    }

    // Insert new market
    $insertQuery = "INSERT INTO markets (Name) VALUES (?)";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute([$name]);

    $newMarketId = $pdo->lastInsertId();

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null', 'marketId' => $newMarketId]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
