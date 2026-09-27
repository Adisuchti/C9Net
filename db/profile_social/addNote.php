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

if (!isset($input['profileId']) || !isset($input['content']) || !isset($input['public'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if user owns this profile
    $query = "SELECT User_Id FROM player_profiles WHERE Profile_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$input['profileId']]);
    $profile = $stmt->fetch();

    if (!$profile || $profile['User_Id'] != $_SESSION['user_id']) {
        throw new Exception('Unauthorized');
    }

    $query = "INSERT INTO notes (Note_Profile_Id, Note_Content, Note_Public) 
              VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $input['profileId'],
        $input['content'],
        $input['public']
    ]);

    $pdo->commit();

    if($input['public']) {
        // Log the activity in web_activity_log
        $activity = "Note added to profile ID: " . $input['profileId'];
        $link = "/views/profile.php?id=" . urlencode($input['profileId']);
        $logQuery = "INSERT INTO web_activity_log (Activity, Link) VALUES (?, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([$activity, $link]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
