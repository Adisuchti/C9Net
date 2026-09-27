<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['threadId']) || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$threadId = (int)$input['threadId'];
$message = trim($input['message']);
$userId = $_SESSION['user_id'];

// Generate a random unique Id
function generateRandomId($pdo) {
    $randomInt31 = random_int(0, 0x7FFFFFFF);
    //check if id already in use
    $stmtPosts = $pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE Id = ?");
    $stmtThreads = $pdo->prepare("SELECT COUNT(*) FROM forum_threads WHERE Id = ?");
    $stmtPosts->execute([$randomInt31]);
    $stmtThreads->execute([$randomInt31]);
    while ($stmtPosts->fetchColumn() > 0 || $stmtThreads->fetchColumn() > 0) {
        $randomInt31 = random_int(0, 0x7FFFFFFF);
        $stmtPosts->execute([$randomInt31]);
        $stmtThreads->execute([$randomInt31]);
    }
    return $randomInt31;
}

try {
    $pdo->beginTransaction();

    $randomId = generateRandomId($pdo);

    // Insert post
    $postQuery = "INSERT INTO forum_posts (ID, Sender_User_Id, Message, Parent_Thread_Id) VALUES (?, ?, ?, ?)";
    $postStmt = $pdo->prepare($postQuery);
    $postStmt->execute([$randomId, $userId, $message, $threadId]);
    $postId = $pdo->lastInsertId();

    $pdo->commit();
    echo json_encode(['success' => true, 'postId' => $postId, 'randomId' => $randomId]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
