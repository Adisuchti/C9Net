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

if (!isset($input['header']) || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$header = trim($input['header']);
$message = trim($input['message']);
$userId = $_SESSION['user_id'];

if ($header === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Header and message cannot be empty']);
    exit();
}

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

    // Insert thread
    $threadQuery = "INSERT INTO forum_threads (Id, Header, Created_By_UserId) VALUES (?, ?, ?)";
    $threadStmt = $pdo->prepare($threadQuery);
    $threadStmt->execute([$randomId, $header, $userId]);
    $threadId = $pdo->lastInsertId();

    // Insert initial post
    $postQuery = "INSERT INTO forum_posts (Id, Sender_User_Id, Message, Parent_Thread_Id) VALUES (?, ?, ?, ?)";
    $postStmt = $pdo->prepare($postQuery);
    $postStmt->execute([$randomId, $userId, $message, $threadId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'threadId' => $randomId, 'randomId' => $randomId]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
