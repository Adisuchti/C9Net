<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['name']) || !isset($data['quantity'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $name = $data['name'];
    $quantity = (int)$data['quantity'];
    
    $query = "INSERT INTO resources (Name, Quantity) VALUES (?, ?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$name, $quantity]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
