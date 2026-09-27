<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Insert new team
    $query = "INSERT INTO `team_hierarchy`
    (`Fireteam_Name`,
    `Fireteam_Parent_Id`)
    VALUES
    ('New Team',
    '-1')";

    $stmt = $pdo->prepare($query);
    $stmt->execute();


    $pdo->commit();
    echo json_encode([
        'success' => true,
        'error' => ''
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
