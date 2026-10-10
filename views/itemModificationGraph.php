<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../db/interchangeableGraphSchema.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

ensureInterchangeableGraphTables($pdo);

// Get current tier from admin parameter (supports both legacy and current naming)
$tierQuery = "SELECT Var_Value
            FROM condition_variables
            WHERE Var_Name IN ('current_tier', 'Current_Tier')
            ORDER BY CASE WHEN Var_Name = 'current_tier' THEN 0 ELSE 1 END
            LIMIT 1";
$tierStmt = $pdo->prepare($tierQuery);
$tierStmt->execute();
$currentTier = (int)($tierStmt->fetchColumn() ?: 1);

// Fetch all market items up to current tier
$itemsQuery = "SELECT
                m.Market_Item_Class as item_class,
                IFNULL(i.Item_Display_Name, m.Market_Item_Class) as display_name,
                MIN(m.tier) as tier
            FROM market m
            LEFT JOIN items i ON i.item_class = m.Market_Item_Class
            WHERE m.tier <= :currentTier
            GROUP BY m.Market_Item_Class, IFNULL(i.Item_Display_Name, m.Market_Item_Class)
            ORDER BY display_name";
$itemsStmt = $pdo->prepare($itemsQuery);
$itemsStmt->execute(['currentTier' => $currentTier]);
$allItems = $itemsStmt->fetchAll();

// Create a set of visible item classes for filtering
$visibleItemClasses = [];
foreach ($allItems as $item) {
    $visibleItemClasses[$item['item_class']] = [
        'display_name' => $item['display_name'],
        'tier' => $item['tier']
    ];
}

// Fetch all legacy interchangeable items (within visible items only)
$interchangeableQuery = "SELECT i.*, 
                               IFNULL(items1.Item_Display_Name, i.Base_Item_Class) as Base_Display_Name,
                               IFNULL(items2.Item_Display_Name, i.Interchangable_Item_Class) as Interchangable_Display_Name
                        FROM interchangable_items i
                        LEFT JOIN items items1 ON items1.item_class = i.Base_Item_Class
                        LEFT JOIN items items2 ON items2.item_class = i.Interchangable_Item_Class
                        ORDER BY i.Base_Item_Class, i.Interchangable_Item_Class";
$interchangeableStmt = $pdo->query($interchangeableQuery);
$interchangeableItems = $interchangeableStmt->fetchAll();

// Fetch all directed routes (within visible items only)
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

// Build legacy graph edges (filtered to visible items)
$legacyByBase = [];
foreach ($interchangeableItems as $legacyItem) {
    $base = $legacyItem['Base_Item_Class'];
    $target = $legacyItem['Interchangable_Item_Class'];
    
    // Only include if both items are visible
    if (!isset($visibleItemClasses[$base]) || !isset($visibleItemClasses[$target])) {
        continue;
    }
    
    $cost = (float)$legacyItem['Change_Cost'];

    if (!isset($legacyByBase[$base])) {
        $legacyByBase[$base] = [];
    }
    $legacyByBase[$base][$target] = $cost;
}

$legacyGraphEdges = [];
$legacyEdgeSeen = [];

$addLegacyEdge = function ($source, $target, $cost) use (&$legacyGraphEdges, &$legacyEdgeSeen): void {
    if ($source === $target) {
        return;
    }

    $key = $source . '|' . $target;
    if (isset($legacyEdgeSeen[$key])) {
        return;
    }

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
        // Base -> variant and variant -> base use the row's configured cost.
        $addLegacyEdge($base, $targetClass, $targetCost);
        $addLegacyEdge($targetClass, $base, $targetCost);
    }

    $targetClasses = array_keys($targets);
    foreach ($targetClasses as $sourceVariant) {
        foreach ($targetClasses as $targetVariant) {
            if ($sourceVariant === $targetVariant) {
                continue;
            }

            // Variant -> variant follows existing logic: cost comes from target variant row.
            $addLegacyEdge($sourceVariant, $targetVariant, $targets[$targetVariant]);
        }
    }
}

// Build directed edges (filtered to visible items)
$directedGraphEdges = [];
foreach ($directedRoutes as $route) {
    $source = $route['Source_Item_Class'];
    $target = $route['Target_Item_Class'];
    
    // Only include if both items are visible
    if (!isset($visibleItemClasses[$source]) || !isset($visibleItemClasses[$target])) {
        continue;
    }
    
    $directedGraphEdges[] = [
        'source' => $source,
        'target' => $target,
        'cost' => (float)$route['Change_Cost'],
        'type' => 'directed'
    ];
}

// Combine all edges
$allEdges = array_merge($legacyGraphEdges, $directedGraphEdges);

// Collect all used items (nodes) from edges
$nodeSet = [];
foreach ($allEdges as $edge) {
    $nodeSet[$edge['source']] = true;
    $nodeSet[$edge['target']] = true;
}

// Build node positions (use existing layout or default)
$nodePositions = [];
$gridSize = 120;
$defaultX = 0;
$defaultY = 0;

foreach ($nodeSet as $itemClass => $dummy) {
    if (isset($graphLayout[$itemClass])) {
        $nodePositions[$itemClass] = [
            'x' => $graphLayout[$itemClass]['x'],
            'y' => $graphLayout[$itemClass]['y'],
            'display_name' => $visibleItemClasses[$itemClass]['display_name'] ?? $itemClass
        ];
    } else {
        // Default layout: arrange in a grid
        $nodePositions[$itemClass] = [
            'x' => $defaultX,
            'y' => $defaultY,
            'display_name' => $visibleItemClasses[$itemClass]['display_name'] ?? $itemClass
        ];
        $defaultX += $gridSize;
        if ($defaultX > 1200) {
            $defaultX = 0;
            $defaultY += $gridSize;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Modification Graph</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #1a1a1a;
            color: #ddd;
            overflow: hidden;
            height: 100vh;
        }

        .container {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .header {
            padding: 15px 20px;
            background: #2a2a2a;
            border-bottom: 1px solid #444;
            flex-shrink: 0;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .header p {
            margin: 5px 0 0 0;
            font-size: 13px;
            color: #aaa;
        }

        #graphContainer {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: #111;
        }

        canvas {
            display: block;
            cursor: grab;
        }

        canvas:active {
            cursor: grabbing;
        }

        .controls {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(42, 42, 42, 0.95);
            border: 1px solid #444;
            border-radius: 5px;
            padding: 10px;
            font-size: 12px;
            z-index: 100;
        }

        .controls p {
            margin: 0 0 8px 0;
            white-space: nowrap;
        }

        .zoom-level {
            font-weight: bold;
            color: #ff9900;
        }

        .legend {
            position: absolute;
            bottom: 15px;
            left: 15px;
            background: rgba(42, 42, 42, 0.95);
            border: 1px solid #444;
            border-radius: 5px;
            padding: 10px;
            font-size: 12px;
            z-index: 100;
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin: 5px 0;
        }

        .legend-line {
            width: 30px;
            height: 2px;
            margin-right: 8px;
        }

        .legacy-edge {
            background: #666;
        }

        .directed-edge {
            background: #ff9900;
        }

        .info {
            position: absolute;
            top: 100px;
            right: 15px;
            background: rgba(42, 42, 42, 0.95);
            border: 1px solid #444;
            border-radius: 5px;
            padding: 10px;
            font-size: 12px;
            z-index: 100;
            max-width: 250px;
        }

        .info h3 {
            margin: 0 0 5px 0;
            color: #ff9900;
        }

        .info p {
            margin: 3px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Item Modification Graph</h1>
            <p>All weapons you can currently convert into and their associated costs</p>
        </div>

        <div id="graphContainer">
            <canvas id="graphCanvas"></canvas>

            <div class="controls">
                <p>Zoom: <span id="zoomLevel" class="zoom-level">100%</span></p>
            </div>
            
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-line legacy-edge"></div>
                    <span>Paint Variants (Bidirectional)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-line directed-edge"></div>
                    <span>Modifications (Directed)</span>
                </div>
            </div>

            <div class="info" id="nodeInfo" style="display: none;">
                <h3 id="nodeInfoTitle"></h3>
                <p id="nodeInfoContent"></p>
            </div>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('graphCanvas');
        const ctx = canvas.getContext('2d');

        // Setup canvas
        function resizeCanvas() {
            const rect = canvas.parentElement.getBoundingClientRect();
            canvas.width = rect.width;
            canvas.height = rect.height;
        }
        resizeCanvas();
        window.addEventListener('resize', () => {
            resizeCanvas();
            draw();
        });

        // Graph data
        const nodes = <?php echo json_encode($nodePositions); ?>;
        const edges = <?php echo json_encode($allEdges); ?>;

        // Graph state
        const graphState = {
            panX: 0,
            panY: 0,
            zoom: 1,
            isDragging: false,
            dragStartX: 0,
            dragStartY: 0,
            dragStartPanX: 0,
            dragStartPanY: 0,
            selectedNode: null
        };

        // Node dimensions
        const nodeWidth = 120;
        const nodeHeight = 60;

        // Canvas event handlers
        canvas.addEventListener('mousedown', (e) => {
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) / graphState.zoom - graphState.panX;
            const y = (e.clientY - rect.top) / graphState.zoom - graphState.panY;

            // Check if clicking on a node
            for (const [itemClass, node] of Object.entries(nodes)) {
                const left = node.x - nodeWidth / 2;
                const top = node.y - nodeHeight / 2;
                if (x >= left && x <= left + nodeWidth && y >= top && y <= top + nodeHeight) {
                    graphState.selectedNode = itemClass;
                    updateNodeInfo(itemClass, node);
                    return;
                }
            }

            // Otherwise, start panning
            graphState.isDragging = true;
            graphState.dragStartX = e.clientX;
            graphState.dragStartY = e.clientY;
            graphState.dragStartPanX = graphState.panX;
            graphState.dragStartPanY = graphState.panY;
        });

        canvas.addEventListener('mousemove', (e) => {
            if (graphState.isDragging) {
                const deltaX = (e.clientX - graphState.dragStartX) / graphState.zoom;
                const deltaY = (e.clientY - graphState.dragStartY) / graphState.zoom;
                graphState.panX = graphState.dragStartPanX + deltaX;
                graphState.panY = graphState.dragStartPanY + deltaY;
                draw();
            }
        });

        canvas.addEventListener('mouseup', () => {
            graphState.isDragging = false;
        });

        canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            const rect = canvas.getBoundingClientRect();
            const mouseScreenX = e.clientX - rect.left;
            const mouseScreenY = e.clientY - rect.top;
            const worldX = mouseScreenX / graphState.zoom - graphState.panX;
            const worldY = mouseScreenY / graphState.zoom - graphState.panY;

            const zoomFactor = e.deltaY > 0 ? 0.9 : 1.1;
            const newZoom = Math.max(0.15, Math.min(4, graphState.zoom * zoomFactor));

            // Adjust pan to zoom at mouse position
            graphState.panX = mouseScreenX / newZoom - worldX;
            graphState.panY = mouseScreenY / newZoom - worldY;

            graphState.zoom = newZoom;
            const zoomLabel = document.getElementById('zoomLevel');
            if (zoomLabel) {
                zoomLabel.textContent = Math.round(graphState.zoom * 100) + '%';
            }
            draw();
        });

        function updateNodeInfo(itemClass, node) {
            const incomingEdges = edges.filter(e => e.target === itemClass);
            const outgoingEdges = edges.filter(e => e.source === itemClass);

            let info = `Can convert FROM:<br>`;
            if (incomingEdges.length > 0) {
                incomingEdges.forEach(edge => {
                    const sourceName = nodes[edge.source]?.display_name || edge.source;
                    info += `• ${sourceName} (${edge.cost.toLocaleString()} Cr)<br>`;
                });
            } else {
                info += '(None)<br>';
            }

            info += `<br>Can convert TO:<br>`;
            if (outgoingEdges.length > 0) {
                outgoingEdges.forEach(edge => {
                    const targetName = nodes[edge.target]?.display_name || edge.target;
                    info += `• ${targetName} (${edge.cost.toLocaleString()} Cr)<br>`;
                });
            } else {
                info += '(None)<br>';
            }

            document.getElementById('nodeInfoTitle').textContent = node.display_name;
            document.getElementById('nodeInfoContent').innerHTML = info;
            document.getElementById('nodeInfo').style.display = 'block';
        }

        function getRectBoundaryPoint(rectX, rectY, rectW, rectH, pointX, pointY) {
            const dx = pointX - rectX;
            const dy = pointY - rectY;
            const angle = Math.atan2(dy, dx);

            let t = Math.abs(rectW / (2 * Math.cos(angle)));
            let pointOnRect = {
                x: rectX + t * Math.cos(angle),
                y: rectY + t * Math.sin(angle)
            };

            const rectTop = rectY - rectH / 2;
            const rectBottom = rectY + rectH / 2;
            const rectLeft = rectX - rectW / 2;
            const rectRight = rectX + rectW / 2;

            if (pointOnRect.y > rectBottom) {
                t = Math.abs(rectH / (2 * Math.sin(angle)));
                pointOnRect = {
                    x: rectX + t * Math.cos(angle),
                    y: rectY + t * Math.sin(angle)
                };
            } else if (pointOnRect.y < rectTop) {
                t = Math.abs(rectH / (2 * Math.sin(angle)));
                pointOnRect = {
                    x: rectX + t * Math.cos(angle),
                    y: rectY + t * Math.sin(angle)
                };
            }

            if (pointOnRect.x > rectRight) {
                t = Math.abs(rectW / (2 * Math.cos(angle)));
                pointOnRect = {
                    x: rectX + t * Math.cos(angle),
                    y: rectY + t * Math.sin(angle)
                };
            } else if (pointOnRect.x < rectLeft) {
                t = Math.abs(rectW / (2 * Math.cos(angle)));
                pointOnRect = {
                    x: rectX + t * Math.cos(angle),
                    y: rectY + t * Math.sin(angle)
                };
            }

            return pointOnRect;
        }

        function draw() {
            ctx.fillStyle = '#111';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.save();
            ctx.translate(graphState.panX * graphState.zoom, graphState.panY * graphState.zoom);
            ctx.scale(graphState.zoom, graphState.zoom);

            // Draw edges
            edges.forEach(edge => {
                const source = nodes[edge.source];
                const target = nodes[edge.target];
                if (!source || !target) {
                    return;
                }

                // Group edges: same source/target pair
                const reverseEdge = edges.find(e => 
                    e.source === edge.target && 
                    e.target === edge.source &&
                    e !== edge
                );

                const hasReverse = !!reverseEdge;
                const isDirectedPair = edge.type === 'directed' && reverseEdge && reverseEdge.type === 'directed';
                let curveMagnitude = 0;
                if (hasReverse) {
                    curveMagnitude = isDirectedPair ? 48 : 30;
                }

                ctx.strokeStyle = edge.type === 'legacy' ? '#666' : '#ff9900';
                ctx.lineWidth = 2;

                ctx.beginPath();
                const startPoint = getRectBoundaryPoint(
                    source.x, source.y, nodeWidth, nodeHeight,
                    target.x, target.y
                );
                const endPoint = getRectBoundaryPoint(
                    target.x, target.y, nodeWidth, nodeHeight,
                    source.x, source.y
                );

                const midX = (startPoint.x + endPoint.x) / 2;
                const midY = (startPoint.y + endPoint.y) / 2;
                const dx = endPoint.x - startPoint.x;
                const dy = endPoint.y - startPoint.y;
                const distance = Math.sqrt(dx * dx + dy * dy);
                if (distance < 1) {
                    return;
                }
                const perpX = -dy / distance;
                const perpY = dx / distance;

                let curveDirection = 0;
                let curvePerpX = perpX;
                let curvePerpY = perpY;
                if (hasReverse) {
                    // Use a canonical pair orientation so opposite edges are forced to opposite sides.
                    const firstKey = edge.source < edge.target ? edge.source : edge.target;
                    const secondKey = edge.source < edge.target ? edge.target : edge.source;
                    const firstNode = nodes[firstKey];
                    const secondNode = nodes[secondKey];
                    const pairDx = secondNode.x - firstNode.x;
                    const pairDy = secondNode.y - firstNode.y;
                    const pairDist = Math.sqrt(pairDx * pairDx + pairDy * pairDy);
                    if (pairDist > 0) {
                        curvePerpX = -pairDy / pairDist;
                        curvePerpY = pairDx / pairDist;
                    }
                    curveDirection = edge.source === firstKey ? 1 : -1;
                }

                const controlX = midX + curvePerpX * curveMagnitude * curveDirection;
                const controlY = midY + curvePerpY * curveMagnitude * curveDirection;

                ctx.moveTo(startPoint.x, startPoint.y);
                if (curveMagnitude === 0) {
                    ctx.lineTo(endPoint.x, endPoint.y);
                } else {
                    ctx.quadraticCurveTo(controlX, controlY, endPoint.x, endPoint.y);
                }
                ctx.stroke();

                // Draw arrowhead using path tangent at the end so curved edges show direction clearly.
                const arrowSize = 17;
                let angle;
                if (curveMagnitude === 0) {
                    angle = Math.atan2(dy, dx);
                } else {
                    // Quadratic bezier tangent at t=1 is proportional to (end - control).
                    angle = Math.atan2(endPoint.y - controlY, endPoint.x - controlX);
                }
                ctx.fillStyle = edge.type === 'legacy' ? '#666' : '#ff9900';
                ctx.beginPath();
                ctx.moveTo(endPoint.x, endPoint.y);
                ctx.lineTo(endPoint.x - arrowSize * Math.cos(angle - Math.PI / 6), endPoint.y - arrowSize * Math.sin(angle - Math.PI / 6));
                ctx.lineTo(endPoint.x - arrowSize * Math.cos(angle + Math.PI / 6), endPoint.y - arrowSize * Math.sin(angle + Math.PI / 6));
                ctx.closePath();
                ctx.fill();
                ctx.strokeStyle = '#111';
                ctx.lineWidth = 1;
                ctx.stroke();

                // Draw cost label
                ctx.fillStyle = '#fff';
                ctx.font = '12px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                const labelX = curveMagnitude === 0 ? midX : controlX;
                const labelY = curveMagnitude === 0 ? midY : controlY;
                ctx.fillText(edge.cost.toLocaleString() + ' Cr', labelX, labelY);
            });

            // Draw nodes
            Object.entries(nodes).forEach(([itemClass, node]) => {
                const isSelected = itemClass === graphState.selectedNode;
                ctx.fillStyle = isSelected ? '#ff9900' : '#2a2a2a';
                ctx.strokeStyle = isSelected ? '#ffcc00' : '#666';
                ctx.lineWidth = isSelected ? 3 : 2;

                ctx.fillRect(node.x - nodeWidth / 2, node.y - nodeHeight / 2, nodeWidth, nodeHeight);
                ctx.strokeRect(node.x - nodeWidth / 2, node.y - nodeHeight / 2, nodeWidth, nodeHeight);

                ctx.fillStyle = '#ddd';
                ctx.font = 'bold 12px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(node.display_name, node.x, node.y);
            });

            ctx.restore();
        }

        // Initial draw
        draw();
    </script>
</body>
</html>
