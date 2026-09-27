<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Auto-create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_notebook (
        id INT AUTO_INCREMENT PRIMARY KEY,
        content TEXT NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(255) DEFAULT NULL
    )");

    $stmt = $pdo->prepare("SELECT content, updated_at, updated_by FROM dashboard_notebook WHERE id = 1");
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        // Insert default row
        $pdo->exec("INSERT INTO dashboard_notebook (id, content) VALUES (1, '')");
        echo json_encode(['success' => true, 'content' => '', 'updated_at' => null, 'updated_by' => null]);
    } else {
        echo json_encode([
            'success' => true,
            'content' => $row['content'],
            'updated_at' => $row['updated_at'],
            'updated_by' => $row['updated_by']
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
