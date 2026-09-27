<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['receiver']) || !isset($input['title']) || !isset($input['content'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    // Get sender's profile ID
    $query = "SELECT Profile_Id FROM player_profiles WHERE User_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        throw new Exception('Sender profile not found');
    }

    $pdo->beginTransaction();

    $query = "INSERT INTO messages (
        Message_Sender,
        Message_Receiver,
        Message_Title,
        Message_Content
    ) VALUES (?, ?, ?, ?)";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $profile['Profile_Id'],
        $input['receiver'],
        $input['title'],
        $input['content']
    ]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
