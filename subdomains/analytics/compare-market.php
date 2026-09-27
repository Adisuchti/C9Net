<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once 'helpers.php';

// Get available snapshots for dropdowns
$availableSnapshots = getAvailableSnapshots($pdo);

// Determine selected base and target snapshots
// Use 'current' as default if not set
$baseSnapshotId = isset($_GET['base_snapshot']) ? $_GET['base_snapshot'] : 'current';
$targetSnapshotId = isset($_GET['target_snapshot']) ? $_GET['target_snapshot'] : 'current';

// Function to fetch market data based on snapshot ID
function getMarketSnapshotData($pdo, $snapshotId) {
    if ($snapshotId === 'current') {
        $query = "
            SELECT 
                m.Market_Item_Class, 
                COALESCE(i.Item_Display_Name, m.Market_Item_Class) as Display_Name, 
                m.Purchase_Price, 
                m.Selling_Price, 
                m.Available_Quantity
            FROM market m
            LEFT JOIN items i ON i.item_class = m.Market_Item_Class
            WHERE m.Market = 0 OR m.Market IS NULL
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
    } else {
        $query = "
            SELECT 
                ms.Market_Item_Class, 
                COALESCE(i.Item_Display_Name, ms.Market_Item_Class) as Display_Name, 
                ms.Purchase_Price, 
                ms.Selling_Price, 
                ms.Available_Quantity
            FROM analytics_market_snapshot ms
            LEFT JOIN items i ON i.item_class = ms.Market_Item_Class
            WHERE ms.snapshot_id = :snapshot_id 
              AND (ms.Market = 0 OR ms.Market IS NULL)
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':snapshot_id' => (int)$snapshotId]);
    }
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = [];
    foreach ($results as $row) {
        $data[$row['Market_Item_Class']] = $row;
    }
    return $data;
}

$baseData = getMarketSnapshotData($pdo, $baseSnapshotId);
$targetData = getMarketSnapshotData($pdo, $targetSnapshotId);

// Compare data
$addedItems = [];
$removedItems = [];
$changedItems = [];

// Check for additions and changes
foreach ($targetData as $itemClass => $targetItem) {
    if (!isset($baseData[$itemClass])) {
        $addedItems[$itemClass] = $targetItem;
    } else {
        $baseItem = $baseData[$itemClass];
        $isChanged = false;
        
        $changes = [
            'Purchase_Price' => [
                'base' => $baseItem['Purchase_Price'],
                'target' => $targetItem['Purchase_Price'],
                'diff' => $targetItem['Purchase_Price'] - $baseItem['Purchase_Price']
            ],
            'Selling_Price' => [
                'base' => $baseItem['Selling_Price'],
                'target' => $targetItem['Selling_Price'],
                'diff' => $targetItem['Selling_Price'] - $baseItem['Selling_Price']
            ],
            'Available_Quantity' => [
                'base' => $baseItem['Available_Quantity'],
                'target' => $targetItem['Available_Quantity'],
                'diff' => $targetItem['Available_Quantity'] - $baseItem['Available_Quantity']
            ]
        ];
        
        if ($changes['Purchase_Price']['diff'] != 0 || 
            $changes['Selling_Price']['diff'] != 0 || 
            $changes['Available_Quantity']['diff'] != 0) {
            $isChanged = true;
        }
        
        if ($isChanged) {
            $changedItems[$itemClass] = [
                'Display_Name' => $targetItem['Display_Name'],
                'changes' => $changes
            ];
        }
    }
}

// Check for removals
foreach ($baseData as $itemClass => $baseItem) {
    if (!isset($targetData[$itemClass])) {
        $removedItems[$itemClass] = $baseItem;
    }
}

// Helper to format snapshot name
function getSnapshotName($id, $snapshots) {
    if ($id === 'current') return 'Current (Live Data)';
    foreach ($snapshots as $s) {
        if ($s['snapshot_id'] == $id) {
            $desc = $s['snapshot_description'] ? ' - ' . htmlspecialchars($s['snapshot_description']) : '';
            return date('Y-m-d H:i', strtotime($s['snapshot_date'])) . $desc;
        }
    }
    return 'Unknown';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Comparison</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="analytics.css?v=<?php echo time(); ?>">
    <style>
        .diff-positive { color: #00E676; font-weight: bold; }
        .diff-negative { color: #FF1744; font-weight: bold; }
        .diff-neutral { color: #888; }
        
        .compare-form {
            background-color: #1a1a1a;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            border: 1px solid #333;
            display: flex;
            gap: 20px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .compare-form .form-group {
            margin-bottom: 0;
            flex: 1;
            min-width: 250px;
        }
        
        .compare-form select {
            width: 100%;
            padding: 10px;
            background-color: #2a2a2a;
            color: #f0f0f0;
            border: 1px solid #444;
            border-radius: 4px;
            font-family: 'Oxanium', sans-serif;
        }
        
        .compare-form button {
            padding: 10px 20px;
            height: 42px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: bold;
        }
        
        .badge-added { background-color: rgba(0, 230, 118, 0.2); color: #00E676; border: 1px solid rgba(0, 230, 118, 0.5); }
        .badge-removed { background-color: rgba(255, 23, 68, 0.2); color: #FF1744; border: 1px solid rgba(255, 23, 68, 0.5); }
        .badge-changed { background-color: rgba(54, 162, 235, 0.2); color: #36A2EB; border: 1px solid rgba(54, 162, 235, 0.5); }
        
        .table-container th.col-price, .table-container td.col-price {
            text-align: right;
            padding-right: 20px;
        }
    </style>
</head>
<body>
    <div class="analytics-container">
        <div class="analytics-header">
            <h1>⚖️ Market Comparison</h1>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a href="index.php" class="btn btn-secondary">← Back to Dashboard</a>
            </div>
        </div>
        
        <div class="compare-form">
            <form method="GET" style="display: flex; gap: 20px; width: 100%; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group">
                    <label for="base_snapshot">Base Snapshot (Older):</label>
                    <select id="base_snapshot" name="base_snapshot">
                        <option value="current" <?php echo $baseSnapshotId === 'current' ? 'selected' : ''; ?>>Current (Live Data)</option>
                        <?php foreach ($availableSnapshots as $snapshot): ?>
                        <option value="<?php echo $snapshot['snapshot_id']; ?>" 
                                <?php echo $baseSnapshotId == $snapshot['snapshot_id'] ? 'selected' : ''; ?>>
                            <?php echo date('Y-m-d H:i', strtotime($snapshot['snapshot_date'])); ?>
                            <?php if ($snapshot['snapshot_description']): ?>
                                - <?php echo htmlspecialchars($snapshot['snapshot_description']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="target_snapshot">Target Snapshot (Newer):</label>
                    <select id="target_snapshot" name="target_snapshot">
                        <option value="current" <?php echo $targetSnapshotId === 'current' ? 'selected' : ''; ?>>Current (Live Data)</option>
                        <?php foreach ($availableSnapshots as $snapshot): ?>
                        <option value="<?php echo $snapshot['snapshot_id']; ?>" 
                                <?php echo $targetSnapshotId == $snapshot['snapshot_id'] ? 'selected' : ''; ?>>
                            <?php echo date('Y-m-d H:i', strtotime($snapshot['snapshot_date'])); ?>
                            <?php if ($snapshot['snapshot_description']): ?>
                                - <?php echo htmlspecialchars($snapshot['snapshot_description']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn">Compare</button>
            </form>
        </div>
        
        <div class="stats-grid" style="margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-label">Changed Items</div>
                <div class="stat-value" style="color: #36A2EB;"><?php echo count($changedItems); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Added Items</div>
                <div class="stat-value" style="color: #00E676;"><?php echo count($addedItems); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Removed Items</div>
                <div class="stat-value" style="color: #FF1744;"><?php echo count($removedItems); ?></div>
            </div>
        </div>
        
        <?php if ($baseSnapshotId === $targetSnapshotId): ?>
            <div class="alert alert-error" style="background-color: rgba(255, 184, 0, 0.1); border-left-color: #FFB800;">
                ⚠ You are comparing the same snapshot. There will be no differences.
            </div>
        <?php endif; ?>
        
        <?php
        // Helper to format differences
        function formatDiff($diff, $isQuantity = false, $invertColors = false) {
            if ($diff == 0) return '<span class="diff-neutral">-</span>';
            
            $sign = $diff > 0 ? '+' : '';
            $prefix = $isQuantity ? '' : '$';
            $value = $isQuantity ? $diff : number_format(abs($diff), 2);
            $formattedValue = $sign . ($diff < 0 && !$isQuantity ? '-$' . number_format(abs($diff), 2) : $prefix . $value);
            
            $positiveClass = $invertColors ? 'diff-negative' : 'diff-positive';
            $negativeClass = $invertColors ? 'diff-positive' : 'diff-negative';
            
            $class = $diff > 0 ? $positiveClass : $negativeClass;
            return "<span class=\"$class\">$formattedValue</span>";
        }
        
        // Helper to format quantity
        function formatQty($qty) {
            if ($qty == -1) return 'Unlimited';
            return number_format($qty);
        }
        
        // Custom diff formatter for quantity since -1 implies unlimited
        function formatQtyDiff($base, $target) {
            if ($base == $target) return '<span class="diff-neutral">-</span>';
            if ($base == -1) return '<span class="diff-negative">Limited (' . $target . ')</span>';
            if ($target == -1) return '<span class="diff-positive">Unlimited</span>';
            
            $diff = $target - $base;
            return formatDiff($diff, true);
        }
        ?>
        
        <!-- Changed Items -->
        <?php if (!empty($changedItems)): ?>
        <div class="table-container">
            <div class="table-title">
                Changed Items 
                <span class="badge badge-changed" style="margin-left: 10px;"><?php echo count($changedItems); ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Class</th>
                        <th class="col-price">Base Purchase Price</th>
                        <th class="col-price">Target Purchase Price</th>
                        <th class="col-price">Purchase Diff</th>
                        <th class="col-price">Base Selling Price</th>
                        <th class="col-price">Target Selling Price</th>
                        <th class="col-price">Selling Diff</th>
                        <th class="col-price">Base Qty</th>
                        <th class="col-price">Target Qty</th>
                        <th class="col-price">Qty Diff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($changedItems as $itemClass => $data): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($data['Display_Name']); ?></strong></td>
                        <td style="font-size: 0.85em; color: #aaa;"><?php echo htmlspecialchars($itemClass); ?></td>
                        
                        <td class="col-price currency">$<?php echo number_format($data['changes']['Purchase_Price']['base'], 2); ?></td>
                        <td class="col-price currency">$<?php echo number_format($data['changes']['Purchase_Price']['target'], 2); ?></td>
                        <td class="col-price"><?php echo formatDiff($data['changes']['Purchase_Price']['diff'], false, true); ?></td>
                        
                        <td class="col-price currency">$<?php echo number_format($data['changes']['Selling_Price']['base'], 2); ?></td>
                        <td class="col-price currency">$<?php echo number_format($data['changes']['Selling_Price']['target'], 2); ?></td>
                        <td class="col-price"><?php echo formatDiff($data['changes']['Selling_Price']['diff']); ?></td>
                        
                        <td class="col-price"><?php echo formatQty($data['changes']['Available_Quantity']['base']); ?></td>
                        <td class="col-price"><?php echo formatQty($data['changes']['Available_Quantity']['target']); ?></td>
                        <td class="col-price"><?php echo formatQtyDiff($data['changes']['Available_Quantity']['base'], $data['changes']['Available_Quantity']['target']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Added Items -->
        <?php if (!empty($addedItems)): ?>
        <div class="table-container" style="margin-top: 32px;">
            <div class="table-title">
                Added to Market 
                <span class="badge badge-added" style="margin-left: 10px;"><?php echo count($addedItems); ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Class</th>
                        <th class="col-price">Purchase Price</th>
                        <th class="col-price">Selling Price</th>
                        <th class="col-price">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($addedItems as $itemClass => $item): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($item['Display_Name']); ?></strong></td>
                        <td style="font-size: 0.85em; color: #aaa;"><?php echo htmlspecialchars($itemClass); ?></td>
                        <td class="col-price currency">$<?php echo number_format($item['Purchase_Price'], 2); ?></td>
                        <td class="col-price currency">$<?php echo number_format($item['Selling_Price'], 2); ?></td>
                        <td class="col-price"><?php echo formatQty($item['Available_Quantity']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Removed Items -->
        <?php if (!empty($removedItems)): ?>
        <div class="table-container" style="margin-top: 32px;">
            <div class="table-title">
                Removed from Market 
                <span class="badge badge-removed" style="margin-left: 10px;"><?php echo count($removedItems); ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Class</th>
                        <th class="col-price">Last Purchase Price</th>
                        <th class="col-price">Last Selling Price</th>
                        <th class="col-price">Last Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($removedItems as $itemClass => $item): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($item['Display_Name']); ?></strong></td>
                        <td style="font-size: 0.85em; color: #aaa;"><?php echo htmlspecialchars($itemClass); ?></td>
                        <td class="col-price currency">$<?php echo number_format($item['Purchase_Price'], 2); ?></td>
                        <td class="col-price currency">$<?php echo number_format($item['Selling_Price'], 2); ?></td>
                        <td class="col-price"><?php echo formatQty($item['Available_Quantity']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if (empty($changedItems) && empty($addedItems) && empty($removedItems)): ?>
        <div style="text-align: center; padding: 40px; background-color: #1a1a1a; border-radius: 8px; margin-top: 20px;">
            <div style="font-size: 3em; margin-bottom: 10px;">📉</div>
            <h3>No Changes Found</h3>
            <p style="color: #888;">The selected snapshots have identical market data.</p>
        </div>
        <?php endif; ?>
        
    </div>
</body>
</html>
