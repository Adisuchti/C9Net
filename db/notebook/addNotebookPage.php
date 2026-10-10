<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['title']) || trim($input['title']) === '') {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit();
}

$title = trim($input['title']);
$username = $_SESSION['username'] ?? 'unknown';

try {
    $stmt = $pdo->prepare("INSERT INTO dashboard_notebook (title, content, updated_by) VALUES (?, '', ?)");
    $stmt->execute([$title, $username]);
    $newId = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'id' => $newId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

