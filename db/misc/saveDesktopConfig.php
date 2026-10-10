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

if (!isset($input['shortcuts'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$userId = $_SESSION['user_id'];
$shortcuts = $input['shortcuts'];
$background = $input['background_image'] ?? '';

try {
    $pdo->beginTransaction();

    // 1. Update background
    $checkConfig = $pdo->prepare("SELECT user_id FROM user_home_config WHERE user_id = ?");
    $checkConfig->execute([$userId]);
    if (!$checkConfig->fetch()) {
        $pdo->prepare("INSERT INTO user_home_config (user_id, background_image) VALUES (?, ?)")->execute([$userId, $background]);
    } else {
        $pdo->prepare("UPDATE user_home_config SET background_image = ? WHERE user_id = ?")->execute([$background, $userId]);
    }

    // 2. Wipe existing shortcuts for the user
    $pdo->prepare("DELETE FROM user_desktop_shortcuts WHERE user_id = ?")->execute([$userId]);

    // 3. Insert new shortcuts
    $insertStmt = $pdo->prepare("
        INSERT INTO user_desktop_shortcuts 
        (user_id, page_id, grid_row, grid_col, custom_name, custom_url, custom_icon_path, is_settings_btn) 
        VALUES (?, 0, ?, ?, ?, ?, ?, ?)
    ");

    $hasSettingsBtn = false;

    foreach ($shortcuts as $s) {
        $row = (int)$s['grid_row'];
        $col = (int)$s['grid_col'];
        $name = $s['custom_name'] ?? '';
        $url = $s['custom_url'] ?? '';
        $icon = $s['custom_icon_path'] ?? '';
        $isSettings = !empty($s['is_settings_btn']) ? 1 : 0;
        
        if ($isSettings) $hasSettingsBtn = true;

        $insertStmt->execute([$userId, $row, $col, $name, $url, $icon, $isSettings]);
    }

    // Safety fallback: if somehow the settings button was deleted, re-add it
    if (!$hasSettingsBtn) {
        $insertStmt->execute([$userId, 1, -2, 'Settings', '#', '../images/icons/settings.svg', 1]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

