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

    // Insert new profile
    $query = "INSERT INTO player_profiles (
        Profile_Name,
        Status,
        Callsign,
        Role,
        Assignment,
        Homeland,
        Combat_Hours,
        Hire_Date,
        Certs,
        Description,
        User_Id
    ) VALUES (
        'New Profile',
        'ACTIVE',
        '',
        '',
        1,
        'Unknown',
        0,
        CURDATE(),
        '',
        'No description available.',
        NULL
    )";

    $stmt = $pdo->prepare($query);
    $stmt->execute();

    $newProfileId = $pdo->lastInsertId();

    // Create profile image directory
    $uploadDir = "../../images/profileUploads/profile" . str_pad($newProfileId, 3, '0', STR_PAD_LEFT);
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

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
