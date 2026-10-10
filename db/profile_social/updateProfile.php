<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['profileId']) || !isset($data['profileName'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

$profileId = (int)$data['profileId'];
$profileName = trim($data['profileName']);
$status = trim($data['status'] ?? 'Active');
$callsign = trim($data['callsign'] ?? '');
$assignment = $data['assignment'] == '-1' ? null : (int)$data['assignment'];
$role = trim($data['role'] ?? '');
$homeland = trim($data['homeland'] ?? '');
$combatHours = (int)($data['combatHours'] ?? 0);
$hireDate = trim($data['hireDate'] ?? '');
$certs = trim($data['certs'] ?? '');
$description = trim($data['description'] ?? '');
$userId = isset($data['userId']) && is_numeric($data['userId']) ? (int)$data['userId'] : null;

try {
    // If a userId is assigned, ensure it's not already linked to another profile
    if ($userId !== null) {
        $checkQuery = "SELECT Profile_Id FROM player_profiles WHERE User_Id = ? AND Profile_Id != ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$userId, $profileId]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'This user account is already linked to another profile.']);
            exit();
        }
    }

    $query = "UPDATE player_profiles SET 
                Profile_Name = ?, 
                Status = ?, 
                Callsign = ?, 
                Assignment = ?, 
                Role = ?, 
                Homeland = ?, 
                Combat_Hours = ?, 
                Hire_Date = ?, 
                Certs = ?, 
                Description = ?, 
                User_Id = ? 
              WHERE Profile_Id = ?";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $profileName,
        $status,
        $callsign,
        $assignment,
        $role,
        $homeland,
        $combatHours,
        empty($hireDate) ? null : $hireDate,
        $certs,
        $description,
        $userId,
        $profileId
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("updateProfile error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>

