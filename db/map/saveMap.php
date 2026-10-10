<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Admin only
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

// Check if POST data was dropped (usually due to post_max_size being exceeded)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $maxSize = ini_get('post_max_size');
    echo json_encode(['success' => false, 'error' => "The uploaded files are too large. Maximum allowed size is {$maxSize}."]);
    exit();
}

$uploadDir = __DIR__ . '/../views/Campaign_02/mapData/';

// Ensure upload directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$action = $_POST['action'] ?? '';
$mapId = isset($_POST['map_id']) ? (int)$_POST['map_id'] : 0;
$name = trim($_POST['name'] ?? '');
$sizeKmX = isset($_POST['size_km_x']) ? (float)$_POST['size_km_x'] : null;
$sizeKmY = isset($_POST['size_km_y']) ? (float)$_POST['size_km_y'] : null;
// These will be auto-calculated from uploaded files or sizes
$width = null;
$height = null;
$resolution = null;

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Map name is required']);
    exit();
}

// Check if size_km columns exist in the maps table
$sizeColsExist = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM maps LIKE 'size_km_x'");
    $sizeColsExist = $cols->rowCount() > 0;
} catch (Exception $e) {
    $sizeColsExist = false;
}

// Ensure size_km columns exist (add them if not)
if (!$sizeColsExist) {
    try {
        $pdo->exec("ALTER TABLE maps ADD COLUMN size_km_x DECIMAL(10,2) DEFAULT NULL COMMENT 'Map width in kilometers'");
        $pdo->exec("ALTER TABLE maps ADD COLUMN size_km_y DECIMAL(10,2) DEFAULT NULL COMMENT 'Map height in kilometers'");
        $sizeColsExist = true;
    } catch (Exception $e) {
        // Columns can't be added â€” proceed without them
    }
}

if ($sizeColsExist && ($sizeKmX === null || $sizeKmX <= 0 || $sizeKmY === null || $sizeKmY <= 0)) {
    echo json_encode(['success' => false, 'error' => 'Valid map size in km (x and y) is required']);
    exit();
}

// Helper: save uploaded file to mapData directory
function saveUploadedFile($fileKey, $mapName, $suffix) {
    global $uploadDir;
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // No file uploaded
    }
    $file = $_FILES[$fileKey];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload error for {$fileKey}: code {$file['error']}");
    }

    // Validate file size (50MB limit)
    if ($file['size'] > 50 * 1024 * 1024) {
        throw new Exception("File {$fileKey} is too large (max 50MB)");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Validate extensions
    if ($fileKey === 'svg_file' && $ext !== 'svg') {
        throw new Exception("SVG file must have .svg extension");
    }
    if ($fileKey === 'heightmap_file' && !in_array($ext, ['png', 'jpg', 'jpeg'])) {
        throw new Exception("Heightmap files must be PNG or JPG");
    }

    // Build safe filename
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($mapName));
    $filename = $safeName . $suffix . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception("Failed to save {$fileKey}");
    }

    // Return path relative to web root
    return 'views/Campaign_02/mapData/' . $filename;
}

try {
    if ($action === 'add') {
        // SVG is required for new maps
        if (!isset($_FILES['svg_file']) || $_FILES['svg_file']['error'] === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['success' => false, 'error' => 'SVG file is required for new maps']);
            exit();
        }

        $svgPath = saveUploadedFile('svg_file', $name, '');
        $heightmapPath = saveUploadedFile('heightmap_file', $name, '_heightmap');

        // Auto-calculate pixel dimensions and resolution from the heightmap image
        if ($heightmapPath) {
            $fullPath = __DIR__ . '/../' . $heightmapPath;
            $imgInfo = getimagesize($fullPath);
            if ($imgInfo) {
                $width = $imgInfo[0];
                $height = $imgInfo[1];
                $resolution = ($sizeKmX > 0 && $width > 0) ? round(($sizeKmX * 1000) / $width, 2) : null;
            }
        }
        // If no heightmap, estimate from SVG or use reasonable defaults
        if ($width === null) {
            $width = 0;
            $height = 0;
            $resolution = ($sizeKmX > 0 && $width > 0) ? round(($sizeKmX * 1000) / $width, 2) : 20;
        }

        $stmt = $pdo->prepare("INSERT INTO maps (name, svg_path, heightmap_path, size_km_x, size_km_y, width, height, resolution) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $svgPath, $heightmapPath, $sizeKmX, $sizeKmY, $width, $height, $resolution]);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Map created successfully']);

    } elseif ($action === 'update') {
        if ($mapId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid map ID']);
            exit();
        }

        // Get current map data
        $current = $pdo->prepare("SELECT * FROM maps WHERE id = ?");
        $current->execute([$mapId]);
        $currentMap = $current->fetch(PDO::FETCH_ASSOC);
        if (!$currentMap) {
            echo json_encode(['success' => false, 'error' => 'Map not found']);
            exit();
        }

        // Upload new files if provided (keep old paths if not)
        $svgPath = saveUploadedFile('svg_file', $name, '') ?? $currentMap['svg_path'];
        $heightmapPath = saveUploadedFile('heightmap_file', $name, '_heightmap') ?? $currentMap['heightmap_path'];

        // Auto-calculate pixel dimensions and resolution from the heightmap image
        if ($heightmapPath && $heightmapPath !== $currentMap['heightmap_path']) {
            // New heightmap was uploaded â€” recalculate
            $fullPath = __DIR__ . '/../' . $heightmapPath;
            $imgInfo = getimagesize($fullPath);
            if ($imgInfo) {
                $width = $imgInfo[0];
                $height = $imgInfo[1];
                $resolution = ($sizeKmX > 0 && $width > 0) ? round(($sizeKmX * 1000) / $width, 2) : null;
            }
        } else {
            // Keep existing values from current map
            $width = (int)$currentMap['width'];
            $height = (int)$currentMap['height'];
            $resolution = $currentMap['resolution'];
        }

        $stmt = $pdo->prepare("UPDATE maps SET name = ?, svg_path = ?, heightmap_path = ?, size_km_x = ?, size_km_y = ?, width = ?, height = ?, resolution = ? WHERE id = ?");
        $stmt->execute([$name, $svgPath, $heightmapPath, $sizeKmX, $sizeKmY, $width, $height, $resolution, $mapId]);

        echo json_encode(['success' => true, 'message' => 'Map updated successfully']);

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

