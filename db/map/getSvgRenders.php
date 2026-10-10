<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$mapName = $_GET['mapName'] ?? '';

if (empty($mapName)) {
    echo json_encode(['success' => false, 'error' => 'Missing mapName']);
    exit();
}

$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $mapName);
$manifestPath = __DIR__ . '/../funkySvgViewer/rasterizationData/' . $safeName . '/manifest.json';

if (!file_exists($manifestPath)) {
    echo json_encode(['success' => true, 'hasRenders' => false]);
    exit();
}

$manifest = json_decode(file_get_contents($manifestPath), true);

if (!$manifest) {
    echo json_encode(['success' => true, 'hasRenders' => false]);
    exit();
}

echo json_encode([
    'success' => true,
    'hasRenders' => true,
    'manifestUrl' => '/funkySvgViewer/rasterizationData/' . $safeName . '/manifest.json',
    'manifest' => $manifest
]);

