<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Only admins can delete wiki pages
if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['page_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Page ID is required']);
    exit();
}

$pageId = intval($input['page_id']);

try {
    // Get page info for logging
    $stmt = $pdo->prepare("SELECT Title FROM wiki_pages WHERE Page_Id = ?");
    $stmt->execute([$pageId]);
    $page = $stmt->fetch();

    if (!$page) {
        echo json_encode(['success' => false, 'error' => 'Page not found']);
        exit();
    }

    // Delete page (children will cascade due to FK)
    $deleteStmt = $pdo->prepare("DELETE FROM wiki_pages WHERE Page_Id = ?");
    $deleteStmt->execute([$pageId]);

    // Log
    $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
    $logStmt->execute(["Wiki page deleted: '{$page['Title']}' (ID: $pageId) by user '" . $_SESSION['username'] . "'"]); 

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
