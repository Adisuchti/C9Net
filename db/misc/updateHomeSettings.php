<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['setting']) || !isset($input['value'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$setting = $input['setting'];
$value = (int)$input['value'];
$userId = $_SESSION['user_id'];

$allowedSettings = [
    'notify_images',
    'notify_docs',
    'notify_news',
    'notify_notes',
    'notify_forum',
    'notify_messages',
    'medication'
];

if (!in_array($setting, $allowedSettings)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid setting']);
    exit();
}

try {
    // Check if record exists, if not insert it first (though home.php should already do this)
    $checkStmt = $pdo->prepare("SELECT user_id FROM user_home_config WHERE user_id = ?");
    $checkStmt->execute([$userId]);
    if (!$checkStmt->fetch()) {
        $insertStmt = $pdo->prepare("INSERT INTO user_home_config (user_id) VALUES (?)");
        $insertStmt->execute([$userId]);
    }

    $updateQuery = "UPDATE user_home_config SET `$setting` = ? WHERE user_id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$value, $userId]);

    if ($setting === 'medication') {
        unset($_SESSION['has_medication']);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
