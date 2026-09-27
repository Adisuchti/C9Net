<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Admin only
if (!isLoggedIn() || $_SESSION['username'] !== 'admin') {
    redirectToLogin();
}

// Get all maps that have SVG paths
$mapsQuery = "SELECT * FROM maps ORDER BY name";
$mapsStmt = $pdo->query($mapsQuery);
$maps = $mapsStmt->fetchAll(PDO::FETCH_ASSOC);

$svgMaps = array_filter($maps, function($m) {
    return !empty($m['svg_path']);
});

// Check which maps already have pre-rendered tiles (FunkySvgViewer format)
foreach ($svgMaps as &$map) {
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $map['name']);
    $manifestPath = __DIR__ . '/../funkySvgViewer/rasterizationData/' . $safeName . '/manifest.json';
    $map['hasRender'] = false;
    $map['renderInfo'] = null;
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if ($manifest) {
            $map['hasRender'] = true;
            $map['renderInfo'] = $manifest;
        }
    }
}
unset($map);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="adminRender-container">
    <div class="adminRender-header">
        <h2>Pre-render SVG Maps</h2>
        <?php if (!empty($svgMaps)): ?>
            <button class="btn-industrial success" onclick="renderAll()">▶ Render All Maps</button>
        <?php endif; ?>
    </div>

    <div class="adminRender-description">
        <p>
            <strong>What this does:</strong> Converts SVG vector maps into multi-resolution PNG/WEBP tile pyramids
            using the FunkySvgViewer engine. Each pyramid level has 2&times; the detail of the previous one.
        </p>
        <p>
            <strong>Tile Pyramid:</strong> Level 0 covers the entire map in a few tiles. Each higher level
            doubles the resolution. Users automatically see the right level based on their zoom.
        </p>
        <p>
            <strong>Parameters:</strong> Configure tile size, number of levels, and resolutions below.
            Rasterization happens in your browser, then tiles are uploaded to the server.
        </p>
    </div>

    <?php if (empty($svgMaps)): ?>
        <div class="adminRender-no-data">
            No maps with SVG paths found in the database.
        </div>
    <?php else: ?>
        <?php foreach ($svgMaps as $map): ?>
            <div class="adminRender-card" id="card-<?php echo $map['id']; ?>">
                <div class="preview-renderSvgTiles-1">
                    <h3><?php echo htmlspecialchars($map['name']); ?></h3>
                    <?php if ($map['hasRender']): ?>
                        <span class="adminRender-status rendered">✓ Pre-rendered</span>
                    <?php else: ?>
                        <span class="adminRender-status not-rendered">⚠ Not pre-rendered</span>
                    <?php endif; ?>
                </div>

                <div class="adminRender-info">
                    <span><strong>SVG Path:</strong> <?php echo htmlspecialchars($map['svg_path']); ?></span>
                    <span><strong>Real Size:</strong> <?php echo number_format((float)($map['size_km_x'] ?? 0), 1); ?> km &times; <?php echo number_format((float)($map['size_km_y'] ?? 0), 1); ?> km</span>
                    
                    <?php if ($map['hasRender']): ?>
                        <div class="preview-renderSvgTiles-2">
                            <span class="preview-renderSvgTiles-3">Current Render Data:</span><br>
                            <span>Levels: <?php echo $map['renderInfo']['numLevels'] ?? '?'; ?> | Tiles: <?php echo $map['renderInfo']['totalTiles'] ?? '?'; ?> | Tile Size: <?php echo $map['renderInfo']['tileSize'] ?? '?'; ?>px</span><br>
                            <span>Min Lvl: <?php echo $map['renderInfo']['minLevel'] ?? 0; ?> | Max Lvl: <?php echo $map['renderInfo']['maxLevel'] ?? '?'; ?> | Base SVG: <?php echo $map['renderInfo']['svgWidth'] ?? '?'; ?> &times; <?php echo $map['renderInfo']['svgHeight'] ?? '?'; ?> px</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="adminRender-pyramid-settings" id="settings-<?php echo $map['id']; ?>">
                    <div>
                        <label>Tile Size (px)</label>
                        <input type="number" id="tileSize-<?php echo $map['id']; ?>" class="adminRender-input" value="256" min="64" max="1024">
                    </div>
                    <div>
                        <label>Num Levels</label>
                        <input type="number" id="numLevels-<?php echo $map['id']; ?>" class="adminRender-input" value="5" min="1" max="10">
                    </div>
                    <div>
                        <label>Lowest Res (px)</label>
                        <input type="number" id="lowestRes-<?php echo $map['id']; ?>" class="adminRender-input" value="4096" min="64">
                    </div>
                </div>

                <div class="adminRender-actions">
                    <button class="btn-industrial primary render-btn" onclick="renderMap(<?php echo $map['id']; ?>, '<?php echo addslashes($map['name']); ?>', '<?php echo addslashes($map['svg_path']); ?>')">
                        <?php echo $map['hasRender'] ? '↻ Re-render All' : '▶ Render Now'; ?>
                    </button>
                    <?php if ($map['hasRender']): ?>
                        <button class="btn-industrial success render-btn" onclick="renderAdditionalLevel(<?php echo $map['id']; ?>, '<?php echo addslashes($map['name']); ?>', '<?php echo addslashes($map['svg_path']); ?>', <?php echo $map['renderInfo']['maxLevel'] ?? 0; ?>, <?php echo $map['renderInfo']['minLevel'] ?? 0; ?>, <?php echo $map['renderInfo']['tileSize'] ?? 256; ?>, <?php echo $map['renderInfo']['totalTiles'] ?? 0; ?>, <?php echo $map['renderInfo']['numLevels'] ?? 0; ?>, <?php echo $map['renderInfo']['svgWidth'] ?? 0; ?>, <?php echo $map['renderInfo']['svgHeight'] ?? 0; ?>)">
                            ➕ Add Next Level
                        </button>
                        <button class="btn-industrial danger render-btn" onclick="deleteRender('<?php echo addslashes($map['name']); ?>', <?php echo $map['id']; ?>)">
                            Delete Renders
                        </button>
                    <?php endif; ?>
                </div>

                <div class="adminRender-progress" id="progress-<?php echo $map['id']; ?>">
                    <div class="adminRender-progress-bar">
                        <div class="adminRender-progress-fill" id="fill-<?php echo $map['id']; ?>"></div>
                    </div>
                    <span class="adminRender-progress-text" id="text-<?php echo $map['id']; ?>">Initializing...</span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script type="module">
    import { FunkySvgViewer } from '../funkySvgViewer/src/index.js';

    window._viewerInstances = {};

    function setProgress(mapId, percent, text) {
        const progress = document.getElementById('progress-' + mapId);
        const fill = document.getElementById('fill-' + mapId);
        const textEl = document.getElementById('text-' + mapId);
        progress.style.display = 'block';
        fill.style.width = percent + '%';
        textEl.textContent = text;
    }

    window.renderMap = async function(mapId, mapName, svgPath) {
        const card = document.getElementById('card-' + mapId);
        const buttons = card.querySelectorAll('.render-btn');
        buttons.forEach(b => b.disabled = true);

        try {
            const tileSize = parseInt(document.getElementById('tileSize-' + mapId).value) || 256;
            const numLevels = parseInt(document.getElementById('numLevels-' + mapId).value) || 5;
            const lowestRes = parseInt(document.getElementById('lowestRes-' + mapId).value) || 4096;

            let container = document.getElementById('renderTarget-' + mapId);
            if (!container) {
                container = document.createElement('div');
                container.id = 'renderTarget-' + mapId;
                container.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:800px;height:600px;';
                document.body.appendChild(container);
            }

            setProgress(mapId, 5, 'Creating viewer...');

            if (window._viewerInstances[mapId]) {
                window._viewerInstances[mapId].destroy();
            }

            const viewer = new FunkySvgViewer('#' + container.id, {
                svg: '../' + svgPath,
                tileSize: tileSize,
                numLevels: numLevels,
                lowestRes: lowestRes,
                preRenderAll: true,
                background: '#ccc',
                onPreloadProgress: (pct) => {
                    setProgress(mapId, Math.round(5 + pct * 0.45), `Rasterizing tiles... ${Math.round(pct)}%`);
                },
            });

            window._viewerInstances[mapId] = viewer;

            viewer.on('loadstart', () => {
                setProgress(mapId, 5, 'Loading SVG...');
            });

            viewer.on('load', (d) => {
                setProgress(mapId, 5, `SVG loaded: ${d.width}&times;${d.height}`);
            });

            viewer.on('preloadprogress', ({ done, total }) => {
                setProgress(mapId, Math.round(5 + (done / total) * 45), `Rasterizing ${done}/${total} tiles...`);
            });

            await viewer.mount();

            setProgress(mapId, 50, 'Uploading tiles to server...');

            const safeName = mapName.replace(/[^a-zA-Z0-9_\-]/g, '_');
            const tileCount = viewer._pyramid.totalTiles();
            let uploaded = 0;

            const uploadQueue = [];
            for (let lvl = viewer._pyramid.minLevel; lvl <= viewer._pyramid.maxLevel; lvl++) {
                const cols = viewer._pyramid.colsAt(lvl);
                const rows = viewer._pyramid.rowsAt(lvl);
                for (let row = 0; row < rows; row++) {
                    for (let col = 0; col < cols; col++) {
                        const canvas = viewer._cache.getSync
                            ? viewer._cache.getSync(lvl, col, row)
                            : viewer._cache.get(lvl, col, row);
                        if (!canvas || canvas instanceof Promise) continue;
                        uploadQueue.push({ lvl, col, row, canvas });
                    }
                }
            }

            const CONCURRENCY = 10;
            let currentIndex = 0;
            
            const uploadWorker = async () => {
                while (currentIndex < uploadQueue.length) {
                    const { lvl, col, row, canvas } = uploadQueue[currentIndex++];
                    const blob = await new Promise(res => canvas.toBlob(res, 'image/webp', 0.85));
                    if (!blob) continue;

                    const fd = new FormData();
                    fd.append('svgName', safeName);
                    fd.append('level', lvl);
                    fd.append('col', col);
                    fd.append('row', row);
                    fd.append('tile', blob, 'tile.webp');

                    try {
                        const resp = await fetch('../funkySvgViewer/admin/save-tiles.php', {
                            method: 'POST',
                            body: fd
                        });
                        const json = await resp.json();
                        if (json.ok) {
                            uploaded++;
                            const pct = Math.round(50 + (uploaded / uploadQueue.length) * 45);
                            setProgress(mapId, pct, `Uploaded ${uploaded}/${uploadQueue.length} tiles`);
                        }
                    } catch (e) {
                        console.warn('Upload failed for tile', lvl, col, row, e);
                    }
                }
            };
            
            const workers = Array.from({ length: CONCURRENCY }, () => uploadWorker());
            await Promise.all(workers);

            const manifest = {
                svgName: safeName,
                svgWidth: viewer._pyramid.svgWidth,
                svgHeight: viewer._pyramid.svgHeight,
                tileSize: viewer._pyramid.tileSize,
                minLevel: viewer._pyramid.minLevel,
                maxLevel: viewer._pyramid.maxLevel,
                numLevels: viewer._pyramid.numLevels,
                totalTiles: uploadQueue.length,
                tileFormat: 'webp',
            };

            try {
                await fetch('../funkySvgViewer/admin/save-tiles.php?manifest=1', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(manifest),
                });
            } catch (e) {
                console.warn('Manifest save failed', e);
            }

            setProgress(mapId, 100, `✓ Done! ${uploaded} tiles rendered and saved.`);
            setTimeout(() => location.reload(), 2000);

        } catch (err) {
            console.error('Render error:', err);
            setProgress(mapId, 0, '✗ Error: ' + err.message);
            buttons.forEach(b => b.disabled = false);
        }
    };

    window.renderAdditionalLevel = async function(mapId, mapName, svgPath, currentMaxLevel, currentMinLevel, currentTileSize, currentTotalTiles, currentNumLevels, currentSvgWidth, currentSvgHeight) {
        const targetLevel = currentMaxLevel + 1;
        const card = document.getElementById('card-' + mapId);
        const buttons = card.querySelectorAll('.render-btn');
        buttons.forEach(b => b.disabled = true);

        try {
            let container = document.getElementById('renderTarget-' + mapId);
            if (!container) {
                container = document.createElement('div');
                container.id = 'renderTarget-' + mapId;
                container.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:800px;height:600px;';
                document.body.appendChild(container);
            }

            setProgress(mapId, 5, 'Creating viewer for Level ' + targetLevel + '...');

            if (window._viewerInstances[mapId]) {
                window._viewerInstances[mapId].destroy();
            }

            const viewer = new FunkySvgViewer('#' + container.id, {
                svg: '../' + svgPath,
                tileSize: currentTileSize,
                minLevel: targetLevel,
                numLevels: 1,
                preRenderAll: true,
                background: '#ccc',
                onPreloadProgress: (pct) => {
                    setProgress(mapId, Math.round(5 + pct * 0.45), `Rasterizing Level ${targetLevel}... ${Math.round(pct)}%`);
                },
            });

            window._viewerInstances[mapId] = viewer;
            await viewer.mount();

            setProgress(mapId, 50, 'Uploading new tiles to server...');

            const safeName = mapName.replace(/[^a-zA-Z0-9_\-]/g, '_');
            let uploaded = 0;
            const uploadQueue = [];
            const cols = viewer._pyramid.colsAt(targetLevel);
            const rows = viewer._pyramid.rowsAt(targetLevel);

            for (let row = 0; row < rows; row++) {
                for (let col = 0; col < cols; col++) {
                    const canvas = viewer._cache.getSync
                        ? viewer._cache.getSync(targetLevel, col, row)
                        : viewer._cache.get(targetLevel, col, row);
                    if (!canvas || canvas instanceof Promise) continue;
                    uploadQueue.push({ lvl: targetLevel, col, row, canvas });
                }
            }

            const CONCURRENCY = 10;
            let currentIndex = 0;
            
            const uploadWorker = async () => {
                while (currentIndex < uploadQueue.length) {
                    const { lvl, col, row, canvas } = uploadQueue[currentIndex++];
                    const blob = await new Promise(res => canvas.toBlob(res, 'image/webp', 0.85));
                    if (!blob) continue;

                    const fd = new FormData();
                    fd.append('svgName', safeName);
                    fd.append('level', lvl);
                    fd.append('col', col);
                    fd.append('row', row);
                    fd.append('tile', blob, 'tile.webp');

                    try {
                        const resp = await fetch('../funkySvgViewer/admin/save-tiles.php', { method: 'POST', body: fd });
                        const json = await resp.json();
                        if (json.ok) {
                            uploaded++;
                            const pct = Math.round(50 + (uploaded / uploadQueue.length) * 45);
                            setProgress(mapId, pct, `Uploaded ${uploaded}/${uploadQueue.length} tiles`);
                        }
                    } catch (e) {
                        console.warn('Upload failed', e);
                    }
                }
            };
            
            await Promise.all(Array.from({ length: CONCURRENCY }, () => uploadWorker()));

            const manifest = {
                svgName: safeName,
                svgWidth: currentSvgWidth || viewer._pyramid.svgWidth,
                svgHeight: currentSvgHeight || viewer._pyramid.svgHeight,
                tileSize: currentTileSize,
                minLevel: currentMinLevel,
                maxLevel: targetLevel,
                numLevels: currentNumLevels + 1,
                totalTiles: currentTotalTiles + uploadQueue.length,
                tileFormat: 'webp',
            };

            try {
                await fetch('../funkySvgViewer/admin/save-tiles.php?manifest=1', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(manifest),
                });
            } catch (e) {}

            setProgress(mapId, 100, `✓ Done! Added ${uploaded} tiles for Level ${targetLevel}.`);
            setTimeout(() => location.reload(), 2000);

        } catch (err) {
            console.error('Render error:', err);
            setProgress(mapId, 0, '✗ Error: ' + err.message);
            buttons.forEach(b => b.disabled = false);
        }
    };

    window.deleteRender = async function(mapName, mapId) {
        if (!confirm('Delete all pre-rendered tiles for "' + mapName + '"?')) return;

        try {
            const resp = await fetch('../db/map_assets/deleteSvgRender.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                },
                credentials: 'include',
                body: JSON.stringify({ mapName })
            });
            const json = await resp.json();
            if (json.success) {
                location.reload();
            } else {
                showError('Delete failed: ' + (json.error || 'Unknown error'));
            }
        } catch (err) {
            showError('Error: ' + err.message);
        }
    };

    window.renderAll = async function() {
        const cards = document.querySelectorAll('.adminRender-card');
        for (const card of cards) {
            const btn = card.querySelector('.render-btn.primary');
            if (btn && btn.onclick) {
                btn.click();
                await new Promise(resolve => setTimeout(resolve, 3000));
            }
        }
    };
</script>

<?php include '../includes/footer.php'; ?>
