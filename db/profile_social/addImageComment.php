<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);
$profileId = isset($input['profileId']) ? (int)$input['profileId'] : 0;
$filename = isset($input['filename']) ? $input['filename'] : '';
$commentText = isset($input['commentText']) ? trim($input['commentText']) : '';

if (!$profileId || !$filename || !$commentText) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO image_comments (Profile_Id, Filename, User_Id, Comment_Text)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$profileId, $filename, $_SESSION['user_id'], $commentText]);

    // Return the new comment with user info
    $commentId = $pdo->lastInsertId();
    $fetchStmt = $pdo->prepare("
        SELECT ic.Comment_Id, ic.Comment_Text, ic.Created_At, ic.User_Id,
               u.username, pp.Profile_Name
        FROM image_comments ic
        LEFT JOIN users u ON ic.User_Id = u.id
        LEFT JOIN player_profiles pp ON u.id = pp.User_Id
        WHERE ic.Comment_Id = ?
    ");
    $fetchStmt->execute([$commentId]);
    $comment = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    // Log the activity in web_activity_log
    try {
        $profileNameStmt = $pdo->prepare("SELECT Profile_Name FROM player_profiles WHERE Profile_Id = ?");
        $profileNameStmt->execute([$profileId]);
        $targetProfileName = $profileNameStmt->fetchColumn() ?: 'Unknown';

        $commenterNameStmt = $pdo->prepare("SELECT pp.Profile_Name FROM player_profiles pp WHERE pp.User_Id = ?");
        $commenterNameStmt->execute([$_SESSION['user_id']]);
        $commenterName = $commenterNameStmt->fetchColumn() ?: $_SESSION['username'];

        $activity = "Image comment: " . $commenterName . " commented on " . $targetProfileName . "'s image";
        $link = "/views/profile.php?id=" . $profileId;
        $logQuery = "INSERT INTO web_activity_log (Activity, Link) VALUES (?, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([$activity, $link]);
    } catch (Exception $e) {
        error_log("Failed to log image comment activity: " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'comment' => $comment]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
