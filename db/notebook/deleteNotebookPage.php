<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing page ID']);
    exit();
}

$id = (int)$input['id'];

if ($id === 1) {
    echo json_encode(['success' => false, 'error' => 'Cannot delete the main page']);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM dashboard_notebook WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
