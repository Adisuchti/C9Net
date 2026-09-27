<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $stmt = $pdo->query("SELECT id, title, content, updated_at, updated_by FROM dashboard_notebook ORDER BY id ASC");
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pages)) {
        // Insert default row if completely empty
        $pdo->exec("INSERT INTO dashboard_notebook (id, title, content) VALUES (1, 'Main', '')");
        $pages = [['id' => 1, 'title' => 'Main', 'content' => '', 'updated_at' => null, 'updated_by' => null]];
    }

    echo json_encode(['success' => true, 'pages' => $pages]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
