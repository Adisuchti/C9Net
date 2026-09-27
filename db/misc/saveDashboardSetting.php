<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    validateCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['mapId']) || !isset($data['layers'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $mapId = (int)$data['mapId'];
    $layers = $data['layers']; // comma-separated string
    
    // Upsert default_map_id
    $stmt = $pdo->prepare("INSERT INTO dashboard_settings (setting_key, setting_value) VALUES ('default_map_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$mapId, $mapId]);
    
    // Upsert default_layers (replaces old default_layer)
    $stmt = $pdo->prepare("INSERT INTO dashboard_settings (setting_key, setting_value) VALUES ('default_layers', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$layers, $layers]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
