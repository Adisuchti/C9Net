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

    if (!isset($data['content'])) {
        echo json_encode(['success' => false, 'error' => 'Missing content']);
        exit();
    }

    $content = $data['content'];
    $username = $_SESSION['username'];

    // Auto-create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_notebook (
        id INT AUTO_INCREMENT PRIMARY KEY,
        content TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(255) DEFAULT NULL
    )");

    // Upsert: update if exists, insert if not
    $stmt = $pdo->prepare("INSERT INTO dashboard_notebook (id, content, updated_by) VALUES (1, ?, ?)
        ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)");
    $stmt->execute([$content, $username]);

    // Fetch updated timestamp
    $row = $pdo->query("SELECT updated_at FROM dashboard_notebook WHERE id = 1")->fetch();

    echo json_encode(['success' => true, 'updated_at' => $row['updated_at']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
