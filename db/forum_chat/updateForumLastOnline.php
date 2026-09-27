<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (isset($_SESSION['user_id'])) {
    $updateQuery = "UPDATE custom_phpbb_lastonline SET last_online = NOW() WHERE user_id = :user_id";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute(['user_id' => $_SESSION['user_id']]);
}

// Return 200 OK (required for sendBeacon)
http_response_code(200);
?>
