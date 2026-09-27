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

if (!isset($input['noteId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if user owns the profile this note belongs to
    $query = "SELECT n.*, p.User_Id 
              FROM notes n 
              JOIN player_profiles p ON n.Note_Profile_Id = p.Profile_Id 
              WHERE n.Note_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$input['noteId']]);
    $note = $stmt->fetch();

    if (!$note || $note['User_Id'] != $_SESSION['user_id']) {
        throw new Exception('Unauthorized');
    }

    $query = "DELETE FROM notes WHERE Note_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$input['noteId']]);

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
