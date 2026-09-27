<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['profileId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing profile ID']);
    exit();
}

$profileId = (int)$input['profileId'];

try {
    $pdo->beginTransaction();

    // Get profile info for cleanup
    $query = "SELECT Profile_Id FROM player_profiles WHERE Profile_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch();

    if (!$profile) {
        throw new Exception('Profile not found');
    }

    // Delete related records first
    
    // Delete notes
    $query = "DELETE FROM notes WHERE Note_Profile_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$profileId]);

    // Delete comments
    $query = "DELETE FROM player_comments WHERE Commented_Player_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$profileId]);

    // Delete messages where profile is sender or receiver
    $query = "DELETE FROM messages WHERE Message_Sender = ? OR Message_Receiver = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$profileId, $profileId]);

    // Delete profile images directory
    $imageDir = "../../images/profileUploads/profile" . str_pad($profileId, 3, '0', STR_PAD_LEFT);
    if (file_exists($imageDir)) {
        array_map('unlink', glob("$imageDir/*.*"));
        rmdir($imageDir);
    }

    // Finally delete the profile
    $query = "DELETE FROM player_profiles WHERE Profile_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$profileId]);

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
