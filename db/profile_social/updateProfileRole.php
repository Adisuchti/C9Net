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

if (!isset($input['profileId']) || !isset($input['role'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$validRoles = ["Contractor", "Officer", "squadleader", "rifle", "medic", "machinegunner", "marksman", "crew", "T-Doll"];
if (!in_array($input['role'], $validRoles) && $input['role'] !== '') {
    // Allow keeping it as it is if it's already an invalid role, but technically they can only submit valid roles from the UI
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if user owns this profile or is admin
    $query = "SELECT User_Id FROM player_profiles WHERE Profile_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$input['profileId']]);
    $profile = $stmt->fetch();

    if (!$profile || ($profile['User_Id'] != $_SESSION['user_id'] && $_SESSION['user_id'] != -1)) {
        throw new Exception('Unauthorized');
    }

    $query = "UPDATE player_profiles SET Role = ? WHERE Profile_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $input['role'],
        $input['profileId']
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
