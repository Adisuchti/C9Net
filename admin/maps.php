<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Admin only
if (!isLoggedIn() || $_SESSION['username'] !== 'admin') {
    redirectToLogin();
}

// Get all maps
$mapsQuery = "SELECT * FROM maps ORDER BY name";
$mapsStmt = $pdo->query($mapsQuery);
$maps = $mapsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check file existence for each map
$basePath = realpath(__DIR__ . '/../../..') . '/';
foreach ($maps as &$map) {
    $svgFull = !empty($map['svg_path']) ? $basePath . str_replace('\\', '/', $map['svg_path']) : '';
    $heightmapFull = !empty($map['heightmap_path']) ? $basePath . str_replace('\\', '/', $map['heightmap_path']) : '';

    $map['svg_exists'] = $svgFull !== '' && file_exists($svgFull);
    $map['heightmap_exists'] = $heightmapFull !== '' && file_exists($heightmapFull);

    // Get file sizes
    $map['svg_size'] = $map['svg_exists'] ? filesize($svgFull) : 0;
    $map['heightmap_size'] = $map['heightmap_exists'] ? filesize($heightmapFull) : 0;

    // Check for pre-rendered tiles in FunkySvgViewer format
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $map['name']);
    $funkyManifestPath = __DIR__ . '/../funkySvgViewer/rasterizationData/' . $safeName . '/manifest.json';
    $map['hasPreRender'] = file_exists($funkyManifestPath);
    if ($map['hasPreRender']) {
        $manifest = json_decode(file_get_contents($funkyManifestPath), true);
        $map['preRenderInfo'] = $manifest;
    }
}
unset($map);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="adminMaps-container">
    <div class="adminMaps-header">
        <h2>Map Management</h2>
        <button class="btn-industrial success" onclick="openAddOverlay()">+ Add New Map</button>
    </div>

    <?php if (empty($maps)): ?>
        <div class="adminMaps-no-data">No maps found. Click "Add New Map" to create one.</div>
    <?php else: ?>
        <div class="adminMaps-grid">
            <?php foreach ($maps as $map): ?>
                <div class="adminMaps-card" id="map-card-<?php echo $map['id']; ?>">
                    <div class="adminMaps-card-header">
                        <div>
                            <h3><?php echo htmlspecialchars($map['name']); ?></h3>
                            <span class="adminMaps-id">ID: <?php echo $map['id']; ?></span>
                        </div>
                    </div>

                    <div class="adminMaps-meta">
                        <div><strong>Size:</strong> <?php echo number_format((float)($map['size_km_x'] ?? 0), 1); ?> &times; <?php echo number_format((float)($map['size_km_y'] ?? 0), 1); ?> km</div>
                        <div><strong>Area:</strong> <?php echo number_format((float)($map['size_km_x'] ?? 0) * (float)($map['size_km_y'] ?? 0), 1); ?> kmÂ²</div>
                        <div><strong>Pixels:</strong> <?php echo (int)($map['width'] ?? 0); ?> &times; <?php echo (int)($map['height'] ?? 0); ?> px</div>
                        <div><strong>Res:</strong> <?php echo (int)($map['resolution'] ?? 20); ?> m/px</div>
                    </div>

                    <div class="adminMaps-files">
                        <!-- SVG -->
                        <?php if ($map['svg_exists']): ?>
                            <span class="adminMaps-badge exists">
                                ✓ SVG
                                <span class="adminMaps-size">(<?php echo number_format($map['svg_size'] / 1024 / 1024, 1); ?>MB)</span>
                                <a href="<?php echo $mainAppUrl; ?>/<?php echo htmlspecialchars($map['svg_path']); ?>" download title="Download SVG">⬇</a>
                            </span>
                        <?php elseif (!empty($map['svg_path'])): ?>
                            <span class="adminMaps-badge missing">✗ SVG (Missing)</span>
                        <?php else: ?>
                            <span class="adminMaps-badge missing">✗ No SVG</span>
                        <?php endif; ?>

                        <!-- Heightmap -->
                        <?php if ($map['heightmap_exists']): ?>
                            <span class="adminMaps-badge exists">
                                ✓ Heightmap
                                <span class="adminMaps-size">(<?php echo number_format($map['heightmap_size'] / 1024 / 1024, 1); ?>MB)</span>
                                <a href="<?php echo $mainAppUrl; ?>/<?php echo htmlspecialchars($map['heightmap_path']); ?>" download title="Download Heightmap">⬇</a>
                            </span>
                        <?php elseif (!empty($map['heightmap_path'])): ?>
                            <span class="adminMaps-badge missing">✗ Heightmap (Missing)</span>
                        <?php else: ?>
                            <span class="adminMaps-badge missing">— No Heightmap</span>
                        <?php endif; ?>

                        <!-- Pre-rendered tiles -->
                        <?php if ($map['hasPreRender']): ?>
                            <span class="adminMaps-badge rendered">
                                ✓ Tiles (<?php echo $map['preRenderInfo']['numLevels'] ?? '?'; ?>L, <?php echo $map['preRenderInfo']['totalTiles'] ?? '?'; ?> tiles)
                            </span>
                        <?php else: ?>
                            <span class="adminMaps-badge missing">— Not Pre-rendered</span>
                        <?php endif; ?>
                    </div>

                    <div class="adminMaps-actions">
                        <button class="btn-industrial primary" onclick='openEditOverlay(<?php echo json_encode($map); ?>)'>Edit</button>
                        <button class="btn-industrial danger" onclick="deleteMap(<?php echo $map['id']; ?>, '<?php echo addslashes($map['name']); ?>')">Delete</button>
                        <?php if ($map['svg_exists']): ?>
                            <a class="btn-industrial success" href="<?php echo $mainAppUrl; ?>/<?php echo htmlspecialchars($map['svg_path']); ?>" download class="preview-maps-dl">⬇ SVG</a>
                        <?php endif; ?>
                        <?php if ($map['heightmap_exists']): ?>
                            <a class="btn-industrial success" href="<?php echo $mainAppUrl; ?>/<?php echo htmlspecialchars($map['heightmap_path']); ?>" download class="preview-maps-dl">⬇ Heightmap</a>
                        <?php endif; ?>
                    </div>

                    <div class="adminMaps-timestamps">
                        Created: <?php echo $map['created_at'] ?? '—'; ?>
                        <?php if (!empty($map['updated_at']) && $map['updated_at'] !== $map['created_at']): ?>
                            &nbsp;|&nbsp; Updated: <?php echo $map['updated_at']; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add Map Overlay -->
<div class="adminMaps-modal-overlay" id="addOverlay">
    <div class="adminMaps-modal">
        <h3>Add New Map</h3>
        <form id="addMapForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">

            <div class="adminMaps-form-row">
                <label>Map Name <span class="required">*</span></label>
                <input type="text" name="name" class="adminMaps-input" required placeholder="e.g. Altis">
            </div>

            <div class="adminMaps-form-dimensions">
                <div class="adminMaps-form-row">
                    <label>Width (km) <span class="required">*</span></label>
                    <input type="number" name="size_km_x" class="adminMaps-input" required min="0.1" step="0.1" placeholder="30.0">
                </div>
                <div class="adminMaps-form-row">
                    <label>Height (km) <span class="required">*</span></label>
                    <input type="number" name="size_km_y" class="adminMaps-input" required min="0.1" step="0.1" placeholder="30.0">
                </div>
            </div>

            <div class="adminMaps-form-row">
                <label>SVG File <span class="required">*</span></label>
                <input type="file" name="svg_file" accept=".svg" class="adminMaps-input" required>
                <div class="adminMaps-file-hint">Vector map file (.svg)</div>
            </div>

            <div class="adminMaps-form-row">
                <label>Heightmap (PNG/JPG) <span class="required">*</span></label>
                <input type="file" name="heightmap_file" accept=".png,.jpg,.jpeg" class="adminMaps-input" required>
                <div class="adminMaps-file-hint">Required — pixel dimensions & resolution are extracted from this image</div>
            </div>

            <div class="adminMaps-modal-actions">
                <button type="button" class="btn-industrial" onclick="closeOverlay('addOverlay')">Cancel</button>
                <button type="submit" class="btn-industrial success map-form-submit">Create Map</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Map Overlay -->
<div class="adminMaps-modal-overlay" id="editOverlay">
    <div class="adminMaps-modal">
        <h3>Edit Map</h3>
        <form id="editMapForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="map_id" id="editMapId">

            <div class="adminMaps-form-row">
                <label>Map Name <span class="required">*</span></label>
                <input type="text" name="name" id="editName" class="adminMaps-input" required>
            </div>

            <div class="adminMaps-form-dimensions">
                <div class="adminMaps-form-row">
                    <label>Width (km) <span class="required">*</span></label>
                    <input type="number" name="size_km_x" id="editSizeKmX" class="adminMaps-input" required min="0.1" step="0.1">
                </div>
                <div class="adminMaps-form-row">
                    <label>Height (km) <span class="required">*</span></label>
                    <input type="number" name="size_km_y" id="editSizeKmY" class="adminMaps-input" required min="0.1" step="0.1">
                </div>
            </div>

            <div class="adminMaps-form-row">
                <label>SVG File</label>
                <input type="file" name="svg_file" accept=".svg" class="adminMaps-input">
                <div class="adminMaps-current-file" id="editCurrentSvg"></div>
                <div class="adminMaps-file-hint">Leave empty to keep current file</div>
            </div>

            <div class="adminMaps-form-row">
                <label>Heightmap (PNG/JPG)</label>
                <input type="file" name="heightmap_file" accept=".png,.jpg,.jpeg" class="adminMaps-input">
                <div class="adminMaps-current-file" id="editCurrentHeightmap"></div>
                <div class="adminMaps-file-hint">Leave empty to keep current file</div>
            </div>

            <div class="adminMaps-modal-actions">
                <button type="button" class="btn-industrial" onclick="closeOverlay('editOverlay')">Cancel</button>
                <button type="submit" class="btn-industrial success map-form-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddOverlay() {
    document.getElementById('addMapForm').reset();
    document.getElementById('addOverlay').classList.add('active');
}

function openEditOverlay(map) {
    document.getElementById('editMapId').value = map.id;
    document.getElementById('editName').value = map.name;
    document.getElementById('editSizeKmX').value = map.size_km_x || '';
    document.getElementById('editSizeKmY').value = map.size_km_y || '';
    document.getElementById('editCurrentSvg').textContent = map.svg_path ? 'Current: ' + map.svg_path : 'No file';
    document.getElementById('editCurrentHeightmap').textContent = map.heightmap_path ? 'Current: ' + map.heightmap_path : 'No file';

    document.getElementById('editOverlay').classList.add('active');
}

function closeOverlay(id) {
    document.getElementById(id).classList.remove('active');
}

// Close overlay on backdrop click
document.querySelectorAll('.adminMaps-modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});

// Add map form submit
document.getElementById('addMapForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('.map-form-submit');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';

    try {
        const resp = await fetch('../db/map_assets/saveMap.php', {
            method: 'POST',
            body: formData
        });
        const json = await resp.json();

        if (json.success) {
            showToast(json.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(json.error, 'error');
        }
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Map';
    }
});

// Edit map form submit
document.getElementById('editMapForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('.map-form-submit');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';

    try {
        const resp = await fetch('../db/map_assets/saveMap.php', {
            method: 'POST',
            body: formData
        });
        const json = await resp.json();

        if (json.success) {
            showToast(json.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(json.error, 'error');
        }
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save Changes';
    }
});

// Delete map
async function deleteMap(mapId, mapName) {
    if (!confirm('Delete map "' + mapName + '"?\n\nThis will also delete all associated files and map drawings.')) return;

    try {
        const resp = await fetch('../db/map_assets/deleteMap.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ map_id: mapId })
        });
        const json = await resp.json();

        if (json.success) {
            showToast(json.message, 'success');
            const card = document.getElementById('map-card-' + mapId);
            if (card) card.style.display = 'none';
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(json.error, 'error');
        }
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}
</script>

<?php include '../includes/footer.php'; ?>
