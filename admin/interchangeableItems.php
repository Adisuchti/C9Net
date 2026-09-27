<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../db/market_assets/interchangeableGraphSchema.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

ensureInterchangeableGraphTables($pdo);

// Fetch all interchangeable items
$interchangeableQuery = "SELECT i.*, 
                               IFNULL(items1.Item_Display_Name, i.Base_Item_Class) as Base_Display_Name,
                               IFNULL(items2.Item_Display_Name, i.Interchangable_Item_Class) as Interchangable_Display_Name
                        FROM interchangable_items i
                        LEFT JOIN items items1 ON items1.item_class = i.Base_Item_Class
                        LEFT JOIN items items2 ON items2.item_class = i.Interchangable_Item_Class
                        ORDER BY i.Base_Item_Class, i.Interchangable_Item_Class";
$interchangeableStmt = $pdo->query($interchangeableQuery);
$interchangeableItems = $interchangeableStmt->fetchAll();

// Fetch all items for dropdown
$itemsQuery = "SELECT item_class, IFNULL(Item_Display_Name, item_class) as display_name 
               FROM items 
               ORDER BY Item_Display_Name";
$itemsStmt = $pdo->query($itemsQuery);
$allItems = $itemsStmt->fetchAll();

// Fetch all directed (non-transitive) routes
$directedRoutesQuery = "SELECT r.Route_Id,
                               r.Source_Item_Class,
                               r.Target_Item_Class,
                               r.Change_Cost,
                               IFNULL(src.Item_Display_Name, r.Source_Item_Class) as Source_Display_Name,
                               IFNULL(dst.Item_Display_Name, r.Target_Item_Class) as Target_Display_Name
                        FROM interchangeable_item_routes r
                        LEFT JOIN items src ON src.item_class = r.Source_Item_Class
                        LEFT JOIN items dst ON dst.item_class = r.Target_Item_Class
                        ORDER BY r.Source_Item_Class, r.Target_Item_Class";
$directedRoutesStmt = $pdo->query($directedRoutesQuery);
$directedRoutes = $directedRoutesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch saved graph layout positions
$layoutQuery = "SELECT Item_Class, Grid_X, Grid_Y FROM interchangeable_item_layout";
$layoutStmt = $pdo->query($layoutQuery);
$layoutRows = $layoutStmt->fetchAll(PDO::FETCH_ASSOC);

$graphLayout = [];
foreach ($layoutRows as $layoutRow) {
    $graphLayout[$layoutRow['Item_Class']] = [
        'x' => (int)$layoutRow['Grid_X'],
        'y' => (int)$layoutRow['Grid_Y']
    ];
}

$itemDisplayMap = [];
foreach ($allItems as $item) {
    $itemDisplayMap[$item['item_class']] = $item['display_name'];
}

// Build legacy graph edges to reflect existing behavior (base-group transitive swaps)
$legacyByBase = [];
foreach ($interchangeableItems as $legacyItem) {
    $base = $legacyItem['Base_Item_Class'];
    $target = $legacyItem['Interchangable_Item_Class'];
    $cost = (float)$legacyItem['Change_Cost'];

    if (!isset($legacyByBase[$base])) {
        $legacyByBase[$base] = [];
    }
    $legacyByBase[$base][$target] = $cost;
}

$legacyGraphEdges = [];
$legacyEdgeSeen = [];
$addLegacyEdge = function ($source, $target, $cost) use (&$legacyGraphEdges, &$legacyEdgeSeen): void {
    if ($source === $target) return;

    $key = $source . '|' . $target;
    if (isset($legacyEdgeSeen[$key])) return;

    $legacyEdgeSeen[$key] = true;
    $legacyGraphEdges[] = [
        'source' => $source,
        'target' => $target,
        'cost' => (float)$cost,
        'type' => 'legacy'
    ];
};

foreach ($legacyByBase as $base => $targets) {
    foreach ($targets as $targetClass => $targetCost) {
        $addLegacyEdge($base, $targetClass, $targetCost);
        $addLegacyEdge($targetClass, $base, $targetCost);
    }
    $targetClasses = array_keys($targets);
    foreach ($targetClasses as $sourceVariant) {
        foreach ($targetClasses as $targetVariant) {
            if ($sourceVariant === $targetVariant) continue;
            $addLegacyEdge($sourceVariant, $targetVariant, $targets[$targetVariant]);
        }
    }
}

$directedGraphEdges = [];
foreach ($directedRoutes as $route) {
    $directedGraphEdges[] = [
        'id' => (int)$route['Route_Id'],
        'source' => $route['Source_Item_Class'],
        'target' => $route['Target_Item_Class'],
        'cost' => (float)$route['Change_Cost'],
        'type' => 'directed'
    ];
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="adminInterchange-container">
    <div class="adminInterchange-section">
        <h2 class="adminInterchange-header">Item Exchange Configuration</h2>
        <div class="adminInterchange-desc">
            Configure which items can be exchanged for other items and the cost of each exchange.
        </div>

        <div class="adminInterchange-grid">
            <div class="adminInterchange-row header">
                <span>Base Item</span>
                <span></span>
                <span>Target Item</span>
                <span>Cost (Cr)</span>
                <span>Actions</span>
            </div>

            <?php foreach ($interchangeableItems as $item): ?>
                <div class="adminInterchange-row">
                    <div class="item-combobox">
                        <input type="text" class="item-combobox-input" 
                               value="<?php echo htmlspecialchars($item['Base_Display_Name']) . ' (' . htmlspecialchars($item['Base_Item_Class']) . ')'; ?>"
                               data-value="<?php echo htmlspecialchars($item['Base_Item_Class']); ?>"
                               data-id="<?php echo $item['Interchangable_Id']; ?>"
                               data-field="base"
                               onfocus="openCombobox(this)" oninput="filterCombobox(this)">
                        <div class="item-combobox-dropdown"></div>
                    </div>
                    
                    <div class="adminInterchange-arrow">→</div>
                    
                    <div class="item-combobox">
                        <input type="text" class="item-combobox-input" 
                               value="<?php echo htmlspecialchars($item['Interchangable_Display_Name']) . ' (' . htmlspecialchars($item['Interchangable_Item_Class']) . ')'; ?>"
                               data-value="<?php echo htmlspecialchars($item['Interchangable_Item_Class']); ?>"
                               data-id="<?php echo $item['Interchangable_Id']; ?>"
                               data-field="target"
                               onfocus="openCombobox(this)" oninput="filterCombobox(this)">
                        <div class="item-combobox-dropdown"></div>
                    </div>
                    
                    <input type="number" class="adminInterchange-input" value="<?php echo $item['Change_Cost']; ?>" min="0" onchange="updateInterchangeable(<?php echo $item['Interchangable_Id']; ?>, 'cost', this.value)">
                    
                    <button class="btn-industrial danger preview-interchangeableItems-1" onclick="deleteInterchangeable(<?php echo $item['Interchangable_Id']; ?>)">Delete</button>
                </div>
            <?php endforeach; ?>

            <div class="adminInterchange-row adminInterchange-add-row">
                <div class="item-combobox">
                    <input type="text" class="item-combobox-input" id="newBaseItem" placeholder="Search base item..." data-value="" onfocus="openCombobox(this)" oninput="filterCombobox(this)">
                    <div class="item-combobox-dropdown"></div>
                </div>
                
                <div class="adminInterchange-arrow">→</div>
                
                <div class="item-combobox">
                    <input type="text" class="item-combobox-input" id="newTargetItem" placeholder="Search target item..." data-value="" onfocus="openCombobox(this)" oninput="filterCombobox(this)">
                    <div class="item-combobox-dropdown"></div>
                </div>
                
                <input type="number" id="newCost" class="adminInterchange-input" placeholder="Cost" min="0" value="0">
                
                <button class="btn-industrial primary preview-interchangeableItems-2" onclick="addInterchangeable()">Add Exchange</button>
            </div>
        </div>
    </div>

    <!-- Directed Graph Section -->
    <div class="adminInterchange-section">
        <h2 class="adminInterchange-header">Directed Item Modification Graph</h2>
        <div class="adminInterchange-desc">
            Define non-transitive directed modifications with per-direction pricing. Legacy paint rules from the table above are shown in the graph as read-only dashed edges.
        </div>

        <div class="interchangeable-graph-controls">
            <div class="item-combobox">
                <input type="text" class="item-combobox-input" id="newDirectedSourceItem" placeholder="Search source item..." data-value="" onfocus="openCombobox(this)" oninput="filterCombobox(this)">
                <div class="item-combobox-dropdown"></div>
            </div>
            
            <div class="item-combobox">
                <input type="text" class="item-combobox-input" id="newDirectedTargetItem" placeholder="Search target item..." data-value="" onfocus="openCombobox(this)" oninput="filterCombobox(this)">
                <div class="item-combobox-dropdown"></div>
            </div>

            <input type="number" id="newDirectedCost" class="adminInterchange-input" placeholder="Cost" min="0" value="0">
            <button class="btn-industrial primary preview-interchangeableItems-3" onclick="addDirectedRoute()">Add Route</button>
        </div>

        <div class="interchangeable-graph-legend">
            <div class="interchangeable-graph-legend-item">
                <span class="interchangeable-graph-legend-line"></span><span>Directed editable route</span>
            </div>
            <div class="interchangeable-graph-legend-item">
                <span class="interchangeable-graph-legend-line legacy"></span><span>Legacy paint rule (read-only)</span>
            </div>
            <div>Drag nodes to rearrange; layout snaps to a grid and is saved automatically.</div>
        </div>

        <div id="interchangeableGraphViewport" class="interchangeable-graph-viewport">
            <svg id="interchangeableGraphSvg" aria-label="Interchangeable item graph"></svg>
        </div>

        <div class="adminInterchange-grid">
            <div class="adminInterchange-row header">
                <span>Source Item</span>
                <span></span>
                <span>Target Item</span>
                <span>Cost (Cr)</span>
                <span>Actions</span>
            </div>

            <?php if (count($directedRoutes) > 0): ?>
                <?php foreach ($directedRoutes as $route): ?>
                    <div class="adminInterchange-row">
                        <div class="preview-interchangeableItems-4"><?php echo htmlspecialchars($route['Source_Display_Name']) . ' (' . htmlspecialchars($route['Source_Item_Class']) . ')'; ?></div>
                        <div class="adminInterchange-arrow">→</div>
                        <div class="preview-interchangeableItems-5"><?php echo htmlspecialchars($route['Target_Display_Name']) . ' (' . htmlspecialchars($route['Target_Item_Class']) . ')'; ?></div>
                        <input type="number" class="adminInterchange-input" value="<?php echo htmlspecialchars($route['Change_Cost']); ?>" min="0" onchange="updateDirectedCost(<?php echo (int)$route['Route_Id']; ?>, this.value)">
                        <button class="btn-industrial danger preview-interchangeableItems-6" onclick="deleteDirectedRoute(<?php echo (int)$route['Route_Id']; ?>)">Delete</button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="preview-interchangeableItems-7">No directed routes defined yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    const allItems = <?php echo json_encode(array_map(function($item) {
        return ['value' => $item['item_class'], 'label' => $item['display_name'] . ' (' . $item['item_class'] . ')'];
    }, $allItems)); ?>;

    const itemDisplayMap = <?php echo json_encode($itemDisplayMap); ?>;
    const legacyGraphEdges = <?php echo json_encode($legacyGraphEdges); ?>;
    const directedGraphEdges = <?php echo json_encode($directedGraphEdges); ?>;
    const graphLayoutMap = <?php echo json_encode($graphLayout); ?>;

    const graphState = {
        panX: 80, panY: 80, nodeWidth: 220, nodeHeight: 68, gridSize: 40, nodes: {},
        draggingNode: null, dragOffsetX: 0, dragOffsetY: 0, panning: false,
        lastClientX: 0, lastClientY: 0, saveTimer: null, zoom: 1.0,
    };

    let activeCombobox = null;

    function openCombobox(input) {
        closeAllComboboxes();
        activeCombobox = input;
        const dropdown = input.parentElement.querySelector('.item-combobox-dropdown');
        filterCombobox(input);
        dropdown.classList.add('open');
        input.select();
    }

    function filterCombobox(input) {
        const dropdown = input.parentElement.querySelector('.item-combobox-dropdown');
        const filter = input.value.toLowerCase();
        const filtered = allItems.filter(item =>
            item.label.toLowerCase().includes(filter) || item.value.toLowerCase().includes(filter)
        );

        dropdown.innerHTML = '';
        filtered.forEach(item => {
            const option = document.createElement('div');
            option.className = 'item-combobox-option';
            option.textContent = item.label;
            option.dataset.value = item.value;
            option.onmousedown = (e) => {
                e.preventDefault();
                selectComboboxItem(input, item);
            };
            dropdown.appendChild(option);
        });

        if (filtered.length === 0) {
            const noResult = document.createElement('div');
            noResult.className = 'item-combobox-no-result';
            noResult.textContent = 'No items found';
            dropdown.appendChild(noResult);
        }
    }

    function selectComboboxItem(input, item) {
        input.value = item.label;
        input.dataset.value = item.value;
        closeAllComboboxes();

        const id = input.dataset.id;
        const field = input.dataset.field;
        if (id && field) {
            updateInterchangeable(parseInt(id), field, item.value);
        }
    }

    function closeAllComboboxes() {
        document.querySelectorAll('.item-combobox-dropdown').forEach(d => d.classList.remove('open'));
        activeCombobox = null;
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.item-combobox')) closeAllComboboxes();
    });

    document.addEventListener('focusin', (e) => {
        if (!e.target.closest('.item-combobox')) closeAllComboboxes();
    });

    async function apiCall(endpoint, method, data) {
        try {
            const response = await fetch('../db/market_assets/' + endpoint + '.php', {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.error);
            return result;
        } catch (error) {
            showError('Error: ' + error.message);
            throw error;
        }
    }

    async function addInterchangeable() {
        const baseInput = document.getElementById('newBaseItem');
        const targetInput = document.getElementById('newTargetItem');
        const baseItem = baseInput.dataset.value;
        const targetItem = targetInput.dataset.value;
        const cost = document.getElementById('newCost').value;
        
        if (!baseItem || !targetItem) {
            showError('Please select both base and target items');
            return;
        }
        if (baseItem === targetItem) {
            showError('Base and target items cannot be the same');
            return;
        }
        
        await apiCall('updateInterchangeableItems', 'POST', {
            action: 'add', baseItem, targetItem, cost: cost || 0
        });
        location.reload();
    }

    async function updateInterchangeable(id, field, value) {
        await apiCall('updateInterchangeableItems', 'POST', {
            action: 'update', id, field, value
        });
    }

    async function deleteInterchangeable(id) {
        if (!confirm('Are you sure you want to delete this exchange?')) return;
        await apiCall('updateInterchangeableItems', 'POST', { action: 'delete', id });
        location.reload();
    }

    async function addDirectedRoute() {
        const sourceInput = document.getElementById('newDirectedSourceItem');
        const targetInput = document.getElementById('newDirectedTargetItem');
        const sourceItem = sourceInput.dataset.value;
        const targetItem = targetInput.dataset.value;
        const cost = document.getElementById('newDirectedCost').value;

        if (!sourceItem || !targetItem) {
            showError('Please select both source and target items');
            return;
        }
        if (sourceItem === targetItem) {
            showError('Source and target items cannot be the same');
            return;
        }

        await apiCall('updateInterchangeableItems', 'POST', {
            action: 'addDirected', sourceItem, targetItem, cost: cost || 0
        });
        location.reload();
    }

    async function updateDirectedCost(id, value) {
        await apiCall('updateInterchangeableItems', 'POST', {
            action: 'updateDirected', id, field: 'cost', value
        });
    }

    async function deleteDirectedRoute(id) {
        if (!confirm('Are you sure you want to delete this directed route?')) return;
        await apiCall('updateInterchangeableItems', 'POST', { action: 'deleteDirected', id });
        location.reload();
    }

    // Graph Implementation
    function getSvgElement(tagName) { return document.createElementNS('http://www.w3.org/2000/svg', tagName); }
    function formatCost(value) {
        const numberValue = Number(value);
        if (Number.isNaN(numberValue)) return '0';
        return Number.isInteger(numberValue) ? String(numberValue) : numberValue.toFixed(2);
    }
    function truncateText(text, maxLength) {
        if (!text) return '';
        return text.length > maxLength ? text.slice(0, maxLength - 1) + '…' : text;
    }
    function getDisplayName(itemClass) { return itemDisplayMap[itemClass] || itemClass; }
    function getWorldPoint(event) {
        const svg = document.getElementById('interchangeableGraphSvg');
        const rect = svg.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left - graphState.panX) / graphState.zoom,
            y: (event.clientY - rect.top - graphState.panY) / graphState.zoom
        };
    }

    function initializeGraphNodes() {
        const nodeClasses = new Set();
        legacyGraphEdges.forEach(edge => { nodeClasses.add(edge.source); nodeClasses.add(edge.target); });
        directedGraphEdges.forEach(edge => { nodeClasses.add(edge.source); nodeClasses.add(edge.target); });
        const sortedClasses = Array.from(nodeClasses).sort();
        const columns = 6;
        sortedClasses.forEach((itemClass, index) => {
            const savedPosition = graphLayoutMap[itemClass];
            if (savedPosition) {
                graphState.nodes[itemClass] = { x: Number(savedPosition.x) || 0, y: Number(savedPosition.y) || 0 };
            } else {
                graphState.nodes[itemClass] = { x: (index % columns) * 280, y: Math.floor(index / columns) * 130 };
            }
        });
    }

    function getRectBoundaryPoint(centerX, centerY, angle, hw, hh, padding) {
        const cosA = Math.cos(angle);
        const sinA = Math.sin(angle);
        const absCos = Math.abs(cosA);
        const absSin = Math.abs(sinA);
        let radius;
        if (absCos < 1e-9) radius = hh;
        else if (absSin < 1e-9) radius = hw;
        else if (absSin * hw <= absCos * hh) radius = hw / absCos;
        else radius = hh / absSin;
        return {
            x: centerX + cosA * (radius + (padding || 0)),
            y: centerY + sinA * (radius + (padding || 0))
        };
    }

    function drawGraphEdge(parent, edge, bidirectionalPairs, directedPairSet) {
        const source = graphState.nodes[edge.source];
        const target = graphState.nodes[edge.target];
        if (!source || !target) return;

        const sourceCenterX = source.x + graphState.nodeWidth / 2;
        const sourceCenterY = source.y + graphState.nodeHeight / 2;
        const targetCenterX = target.x + graphState.nodeWidth / 2;
        const targetCenterY = target.y + graphState.nodeHeight / 2;

        const dx = targetCenterX - sourceCenterX;
        const dy = targetCenterY - sourceCenterY;
        const distance = Math.sqrt(dx * dx + dy * dy);
        if (distance < 1) return;

        const angle = Math.atan2(dy, dx);
        const hw = graphState.nodeWidth / 2;
        const hh = graphState.nodeHeight / 2;

        const startPt = getRectBoundaryPoint(sourceCenterX, sourceCenterY, angle, hw, hh, 0);
        const endPt = getRectBoundaryPoint(targetCenterX, targetCenterY, angle + Math.PI, hw, hh, 2);
        const startX = startPt.x;
        const startY = startPt.y;
        const endX = endPt.x;
        const endY = endPt.y;

        const hasReverse = bidirectionalPairs.has(edge.target + '|' + edge.source);
        const hasDirectedReverse = !edge.readOnly && directedPairSet.has(edge.target + '|' + edge.source);
        let curveAmount = 0;
        if (hasReverse) curveAmount = 28;
        if (hasDirectedReverse) curveAmount = 48;

        const normalX = -dy / distance;
        const normalY = dx / distance;
        const path = getSvgElement('path');
        let labelX, labelY;

        if (curveAmount !== 0) {
            const controlX = (startX + endX) / 2 + normalX * curveAmount;
            const controlY = (startY + endY) / 2 + normalY * curveAmount;
            path.setAttribute('d', `M ${startX} ${startY} Q ${controlX} ${controlY} ${endX} ${endY}`);
            labelX = (startX + endX) / 2 + normalX * (curveAmount / 2);
            labelY = (startY + endY) / 2 + normalY * (curveAmount / 2);
        } else {
            path.setAttribute('d', `M ${startX} ${startY} L ${endX} ${endY}`);
            labelX = (startX + endX) / 2;
            labelY = (startY + endY) / 2;
        }

        path.setAttribute('class', edge.readOnly ? 'graph-edge-legacy' : 'graph-edge-directed');
        if (!edge.readOnly) path.setAttribute('marker-end', 'url(#arrowhead)');
        else path.setAttribute('marker-end', 'url(#arrowhead-legacy)');
        parent.appendChild(path);

        const gLabel = getSvgElement('g');
        const costText = formatCost(edge.cost) + ' Cr';
        
        const labelBg = getSvgElement('rect');
        labelBg.setAttribute('class', 'graph-edge-label-bg');
        labelBg.setAttribute('x', labelX - 25);
        labelBg.setAttribute('y', labelY - 10);
        labelBg.setAttribute('width', 50);
        labelBg.setAttribute('height', 20);
        gLabel.appendChild(labelBg);

        const labelText = getSvgElement('text');
        labelText.setAttribute('class', 'graph-edge-label');
        labelText.setAttribute('x', labelX);
        labelText.setAttribute('y', labelY);
        labelText.textContent = costText;
        gLabel.appendChild(labelText);
        
        parent.appendChild(gLabel);
    }

    function renderGraph() {
        const svg = document.getElementById('interchangeableGraphSvg');
        svg.innerHTML = '';

        const defs = getSvgElement('defs');
        const marker = getSvgElement('marker');
        marker.setAttribute('id', 'arrowhead');
        marker.setAttribute('markerWidth', '10');
        marker.setAttribute('markerHeight', '7');
        marker.setAttribute('refX', '9');
        marker.setAttribute('refY', '3.5');
        marker.setAttribute('orient', 'auto');
        const polygon = getSvgElement('polygon');
        polygon.setAttribute('points', '0 0, 10 3.5, 0 7');
        polygon.setAttribute('fill', '#2ecc71');
        marker.appendChild(polygon);
        defs.appendChild(marker);

        const markerLegacy = getSvgElement('marker');
        markerLegacy.setAttribute('id', 'arrowhead-legacy');
        markerLegacy.setAttribute('markerWidth', '10');
        markerLegacy.setAttribute('markerHeight', '7');
        markerLegacy.setAttribute('refX', '9');
        markerLegacy.setAttribute('refY', '3.5');
        markerLegacy.setAttribute('orient', 'auto');
        const polygonLegacy = getSvgElement('polygon');
        polygonLegacy.setAttribute('points', '0 0, 10 3.5, 0 7');
        polygonLegacy.setAttribute('fill', '#8a8a8a');
        markerLegacy.appendChild(polygonLegacy);
        defs.appendChild(markerLegacy);
        svg.appendChild(defs);

        const rootGroup = getSvgElement('g');
        rootGroup.setAttribute('transform', `translate(${graphState.panX}, ${graphState.panY}) scale(${graphState.zoom})`);
        svg.appendChild(rootGroup);

        const edgesGroup = getSvgElement('g');
        const nodesGroup = getSvgElement('g');
        rootGroup.appendChild(edgesGroup);
        rootGroup.appendChild(nodesGroup);

        const allEdges = [];
        const bidirectionalPairs = new Set();
        const directedPairSet = new Set();

        legacyGraphEdges.forEach(e => {
            allEdges.push({ ...e, readOnly: true });
            bidirectionalPairs.add(e.source + '|' + e.target);
        });

        directedGraphEdges.forEach(e => {
            allEdges.push({ ...e, readOnly: false });
            directedPairSet.add(e.source + '|' + e.target);
        });

        allEdges.forEach(edge => drawGraphEdge(edgesGroup, edge, bidirectionalPairs, directedPairSet));

        for (const [itemClass, pos] of Object.entries(graphState.nodes)) {
            const g = getSvgElement('g');
            g.setAttribute('transform', `translate(${pos.x}, ${pos.y})`);

            const rect = getSvgElement('rect');
            rect.setAttribute('class', 'graph-node-rect');
            rect.setAttribute('width', graphState.nodeWidth);
            rect.setAttribute('height', graphState.nodeHeight);
            g.appendChild(rect);

            const title = getSvgElement('text');
            title.setAttribute('class', 'graph-node-title');
            title.setAttribute('x', graphState.nodeWidth / 2);
            title.setAttribute('y', 25);
            title.textContent = truncateText(getDisplayName(itemClass), 26);
            g.appendChild(title);

            const subtitle = getSvgElement('text');
            subtitle.setAttribute('class', 'graph-node-class');
            subtitle.setAttribute('x', graphState.nodeWidth / 2);
            subtitle.setAttribute('y', 45);
            subtitle.textContent = truncateText(itemClass, 35);
            g.appendChild(subtitle);

            const hitbox = getSvgElement('rect');
            hitbox.setAttribute('class', 'graph-node-hitbox');
            hitbox.setAttribute('width', graphState.nodeWidth);
            hitbox.setAttribute('height', graphState.nodeHeight);
            hitbox.setAttribute('fill', 'transparent');
            
            hitbox.addEventListener('mousedown', (e) => {
                if (e.button !== 0) return;
                e.stopPropagation();
                graphState.draggingNode = itemClass;
                const pt = getWorldPoint(e);
                graphState.dragOffsetX = pt.x - pos.x;
                graphState.dragOffsetY = pt.y - pos.y;
            });

            g.appendChild(hitbox);
            nodesGroup.appendChild(g);
        }
    }

    async function saveGraphLayout() {
        const payload = [];
        for (const [itemClass, pos] of Object.entries(graphState.nodes)) {
            payload.push({ itemClass: itemClass, x: pos.x, y: pos.y });
        }
        try {
            await apiCall('updateInterchangeableItems', 'POST', { action: 'saveLayout', layout: payload });
        } catch (e) {
            console.error("Failed to save layout", e);
        }
    }

    function scheduleLayoutSave() {
        if (graphState.saveTimer) clearTimeout(graphState.saveTimer);
        graphState.saveTimer = setTimeout(saveGraphLayout, 1000);
    }

    const svgViewport = document.getElementById('interchangeableGraphViewport');
    
    svgViewport.addEventListener('mousedown', (e) => {
        if (e.button === 0 && !graphState.draggingNode) {
            graphState.panning = true;
            graphState.lastClientX = e.clientX;
            graphState.lastClientY = e.clientY;
            svgViewport.classList.add('panning');
        }
    });

    window.addEventListener('mousemove', (e) => {
        if (graphState.draggingNode) {
            const pt = getWorldPoint(e);
            let nx = pt.x - graphState.dragOffsetX;
            let ny = pt.y - graphState.dragOffsetY;
            nx = Math.round(nx / graphState.gridSize) * graphState.gridSize;
            ny = Math.round(ny / graphState.gridSize) * graphState.gridSize;
            graphState.nodes[graphState.draggingNode].x = nx;
            graphState.nodes[graphState.draggingNode].y = ny;
            renderGraph();
        } else if (graphState.panning) {
            const dx = e.clientX - graphState.lastClientX;
            const dy = e.clientY - graphState.lastClientY;
            graphState.panX += dx;
            graphState.panY += dy;
            graphState.lastClientX = e.clientX;
            graphState.lastClientY = e.clientY;
            renderGraph();
        }
    });

    window.addEventListener('mouseup', () => {
        if (graphState.draggingNode) {
            graphState.draggingNode = null;
            scheduleLayoutSave();
        }
        if (graphState.panning) {
            graphState.panning = false;
            svgViewport.classList.remove('panning');
        }
    });

    svgViewport.addEventListener('wheel', (e) => {
        e.preventDefault();
        const zoomDelta = e.deltaY > 0 ? 0.9 : 1.1;
        
        const ptBefore = getWorldPoint(e);
        graphState.zoom = Math.max(0.1, Math.min(graphState.zoom * zoomDelta, 3.0));
        const ptAfter = getWorldPoint(e);
        
        graphState.panX += (ptAfter.x - ptBefore.x) * graphState.zoom;
        graphState.panY += (ptAfter.y - ptBefore.y) * graphState.zoom;
        
        renderGraph();
    }, { passive: false });

    initializeGraphNodes();
    renderGraph();
</script>

<?php include '../includes/footer.php'; ?>
