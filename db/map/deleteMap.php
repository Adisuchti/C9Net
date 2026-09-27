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

$input = json_decode(file_get_contents('php://input'), true);
$mapId = (int)($input['map_id'] ?? 0);

if ($mapId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid map ID']);
    exit();
}

try {
    // Get map data first
    $stmt = $pdo->prepare("SELECT * FROM maps WHERE id = ?");
    $stmt->execute([$mapId]);
    $map = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$map) {
        echo json_encode(['success' => false, 'error' => 'Map not found']);
        exit();
    }

    $basePath = __DIR__ . '/../';
    $deletedFiles = [];

    // Delete SVG and Heightmap files
    $filesToDelete = [$map['svg_path'], $map['heightmap_path']];
    foreach ($filesToDelete as $filePath) {
        if (!empty($filePath)) {
            $fullPath = $basePath . $filePath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $deletedFiles[] = $filePath;
            }
        }
    }

    // Also delete old typemap_path if it still exists (cleanup from old schema)
    if (!empty($map['typemap_path'])) {
        $fullPath = $basePath . $map['typemap_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
            $deletedFiles[] = $map['typemap_path'];
        }
    }

    // Delete pre-rendered tiles in FunkySvgViewer format
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $map['name']);
    $funkyRenderDir = __DIR__ . '/../funkySvgViewer/rasterizationData/' . $safeName;
    if (is_dir($funkyRenderDir)) {
        // Recursively delete the entire render directory
        function recursiveDelete($dir) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                is_dir($path) ? recursiveDelete($path) : unlink($path);
            }
            return rmdir($dir);
        }
        recursiveDelete($funkyRenderDir);
        $deletedFiles[] = 'funkySvgViewer/rasterizationData/' . $safeName . '/';
    }

    // Also delete old-style pre-rendered LODs if they exist (cleanup)
    $oldRenderDir = __DIR__ . '/../views/Campaign_02/mapData/render/' . $safeName;
    if (is_dir($oldRenderDir)) {
        $files = glob($oldRenderDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($oldRenderDir);
        $deletedFiles[] = 'views/Campaign_02/mapData/render/' . $safeName . '/';
    }

    // Delete map drawings
    $drawingsStmt = $pdo->prepare("DELETE FROM map_drawings WHERE map_id = ?");
    $drawingsStmt->execute([$mapId]);
    $drawingsDeleted = $drawingsStmt->rowCount();

    // Delete the map record
    $deleteStmt = $pdo->prepare("DELETE FROM maps WHERE id = ?");
    $deleteStmt->execute([$mapId]);

    echo json_encode([
        'success' => true,
        'message' => "Map '{$map['name']}' deleted",
        'filesDeleted' => $deletedFiles,
        'drawingsDeleted' => $drawingsDeleted
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
