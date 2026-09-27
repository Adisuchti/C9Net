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

if (!isset($input['profileId']) || !isset($input['commentText'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    $pdo->beginTransaction();

    $query = "INSERT INTO player_comments (Commented_Player_Id, Commenter_Player_User_Id, Comment_Text) 
              VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $input['profileId'],
        $_SESSION['user_id'],
        $input['commentText']
    ]);

    $pdo->commit();

    // Log the activity in web_activity_log
    $activity = "Comment added to profile ID: " . $input['profileId'];
    $link = "/views/profile.php?id=" . urlencode($input['profileId']);
    $logQuery = "INSERT INTO web_activity_log (Activity, Link) VALUES (?, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([$activity, $link]);
    
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
