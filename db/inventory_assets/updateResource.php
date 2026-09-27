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
    
    if (!isset($data['id']) || !isset($data['name']) || !isset($data['quantity'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $id = (int)$data['id'];
    $name = $data['name'];
    $quantity = (int)$data['quantity'];
    
    $query = "UPDATE resources SET Name = ?, Quantity = ? WHERE Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$name, $quantity, $id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
