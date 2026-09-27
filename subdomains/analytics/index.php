<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once 'helpers.php';

// Check if viewing a snapshot
$snapshotId = isset($_GET['snapshot']) ? (int)$_GET['snapshot'] : null;
$availableSnapshots = getAvailableSnapshots($pdo);

// Get selected snapshot details if viewing historical data
$selectedSnapshot = null;
if ($snapshotId) {
    foreach ($availableSnapshots as $snapshot) {
        if ($snapshot['snapshot_id'] == $snapshotId) {
            $selectedSnapshot = $snapshot;
            break;
        }
    }
}

// Get net worth statistics (either current or historical)
$netWorthData = getNetWorthData($pdo, $snapshotId);

// Legacy query for reference (no longer used)
$netWorthQuery_OLD = "
    SELECT 
        i.Inventory_Name,
        i.Inventory_Money,
        COALESCE(SUM(m.Purchase_Price * ci.Item_Quantity), 0) AS Material_Value,
        (i.Inventory_Money + COALESCE(SUM(m.Purchase_Price * ci.Item_Quantity), 0) - 6500) AS Total_Net_Worth
    FROM inventories i
    LEFT JOIN content_items ci ON ci.Inventory_Id = i.Inventory_Id
    LEFT JOIN market m ON m.Market_Item_Class = ci.Item_Class
    WHERE m.Market = 0 AND i.Inventory_Name NOT LIKE '%testing%'
    GROUP BY i.Inventory_Name, i.Inventory_Money
    ORDER BY Total_Net_Worth DESC
";

// Calculate totals
$totalMoney = 0;
$totalMaterialValue = 0;
$totalNetWorth = 0;

foreach ($netWorthData as $row) {
    $totalMoney += $row['Inventory_Money'];
    $totalMaterialValue += $row['Material_Value'];
    $totalNetWorth += $row['Total_Net_Worth'];
}

// Get ammo statistics (either current or historical)
$ammoData = getAmmoData($pdo, $snapshotId);

// Legacy query for reference (no longer used)
$ammoQuery_OLD = "
    SELECT 
        i.Inventory_Name,
        CASE
            WHEN ci.Item_Class LIKE '%9x21%' THEN '9x21'
            WHEN ci.Item_Class LIKE '%9x19%' THEN '9x19'
            WHEN ci.Item_Class LIKE '%45ACP%' THEN '45ACP'
            WHEN ci.Item_Class LIKE '%45HP%' THEN '45HP'
            WHEN ci.Item_Class LIKE '%556x45%' THEN '556x45'
            WHEN ci.Item_Class LIKE '%570x28%' THEN '570x28'
            WHEN ci.Item_Class LIKE '%65x39%' THEN '65x39'
            WHEN ci.Item_Class LIKE '%762x39%' THEN '762x39'
            WHEN ci.Item_Class LIKE '%12Gauge%' THEN '12Gauge'
            WHEN ci.Item_Class LIKE '%58x42%' THEN '58x42'
            WHEN ci.Item_Class LIKE '%762x51%' THEN '762x51'
            WHEN ci.Item_Class LIKE '%762x54%' THEN '762x54'
            WHEN ci.Item_Class LIKE '%127x54%' THEN '127x54'
            WHEN ci.Item_Class LIKE '%127x55%' THEN '127x55'
            WHEN ci.Item_Class LIKE '%127x99%' THEN '127x99'
            WHEN ci.Item_Class LIKE '%127x108%' THEN '127x108'
            WHEN ci.Item_Class LIKE '%338%' THEN '338'
            WHEN ci.Item_Class LIKE '%408%' THEN '408'
            WHEN ci.Item_Class LIKE '%93x64%' THEN '93x64'
            WHEN ci.Item_Class LIKE '%650x39%' THEN '650x39'
            WHEN ci.Item_Class LIKE '%HE%' THEN 'HE'
            ELSE NULL
        END AS Ammo_Type,
        SUM(CAST(ci.Item_Quantity AS UNSIGNED) * CAST(COALESCE(ci.Item_Properties, 0) AS UNSIGNED)) AS Total_Quantity
    FROM 
        inventories i
    LEFT JOIN content_items ci ON ci.Inventory_Id = i.Inventory_Id
    WHERE
        i.Inventory_Id != 1
        AND CASE
            WHEN ci.Item_Class LIKE '%9x21%' THEN '9x21'
            WHEN ci.Item_Class LIKE '%9x19%' THEN '9x19'
            WHEN ci.Item_Class LIKE '%45ACP%' THEN '45ACP'
            WHEN ci.Item_Class LIKE '%45HP%' THEN '45HP'
            WHEN ci.Item_Class LIKE '%556x45%' THEN '556x45'
            WHEN ci.Item_Class LIKE '%570x28%' THEN '570x28'
            WHEN ci.Item_Class LIKE '%65x39%' THEN '65x39'
            WHEN ci.Item_Class LIKE '%762x39%' THEN '762x39'
            WHEN ci.Item_Class LIKE '%12Gauge%' THEN '12Gauge'
            WHEN ci.Item_Class LIKE '%58x42%' THEN '58x42'
            WHEN ci.Item_Class LIKE '%762x51%' THEN '762x51'
            WHEN ci.Item_Class LIKE '%762x54%' THEN '762x54'
            WHEN ci.Item_Class LIKE '%127x54%' THEN '127x54'
            WHEN ci.Item_Class LIKE '%127x55%' THEN '127x55'
            WHEN ci.Item_Class LIKE '%127x99%' THEN '127x99'
            WHEN ci.Item_Class LIKE '%127x108%' THEN '127x108'
            WHEN ci.Item_Class LIKE '%338%' THEN '338'
            WHEN ci.Item_Class LIKE '%408%' THEN '408'
            WHEN ci.Item_Class LIKE '%93x64%' THEN '93x64'
            WHEN ci.Item_Class LIKE '%650x39%' THEN '650x39'
            WHEN ci.Item_Class LIKE '%HE%' THEN 'HE'
            ELSE NULL
        END IS NOT NULL
    GROUP BY 
        i.Inventory_Name, Ammo_Type
    ORDER BY 
        i.Inventory_Name ASC, Total_Quantity DESC
";

// Process ammo data
$ammoProcessed = processAmmoData($ammoData);
$ammoByType = $ammoProcessed['byType'];
$ammoByTypeAndInventory = $ammoProcessed['byTypeAndInventory'];

// Get weapon statistics (either current or historical)
$weaponData = getWeaponData($pdo, $snapshotId);

// Process weapon data
$weaponProcessed = processWeaponData($weaponData);
$weaponByType = $weaponProcessed['byType'];
$weaponByTypeAndInventory = $weaponProcessed['byTypeAndInventory'];

// Get weapon by class name statistics
$weaponClassData = getWeaponByClassName($pdo, $snapshotId);

// Process weapon class data
$weaponClassProcessed = processWeaponByClassName($weaponClassData);
$weaponByClass = $weaponClassProcessed['byClass'];
$weaponByTypeAndClass = $weaponClassProcessed['byTypeAndClass'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="analytics.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="analytics-container">
        <div class="analytics-header">
            <h1>📊 Analytics Dashboard</h1>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div class="snapshot-selector">
                    <label for="snapshotSelect">View Data:</label>
                    <select id="snapshotSelect" onchange="changeSnapshot(this.value)">
                        <option value="">Current (Live Data)</option>
                        <?php foreach ($availableSnapshots as $snapshot): ?>
                        <option value="<?php echo $snapshot['snapshot_id']; ?>" 
                                <?php echo ($snapshotId == $snapshot['snapshot_id']) ? 'selected' : ''; ?>>
                            <?php echo date('Y-m-d H:i', strtotime($snapshot['snapshot_date'])); ?>
                            <?php if ($snapshot['snapshot_description']): ?>
                                - <?php echo htmlspecialchars($snapshot['snapshot_description']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a href="trends.php" class="btn btn-secondary">View Trends</a>
                <a href="compare-market.php" class="btn btn-secondary">Compare Market</a>
                <a href="manage.php" class="btn btn-secondary">Manage Snapshots</a>
            </div>
        </div>

        <?php if ($selectedSnapshot): ?>
        <div class="snapshot-info">
            <h3>📅 Viewing Historical Snapshot</h3>
            <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($selectedSnapshot['snapshot_date'])); ?></p>
            <?php if ($selectedSnapshot['snapshot_description']): ?>
            <p><strong>Description:</strong> <?php echo htmlspecialchars($selectedSnapshot['snapshot_description']); ?></p>
            <?php endif; ?>
            <p><em>This is a historical snapshot. <a href="index.php" style="color: #2e8b57; font-weight: bold;">View Current Data</a></em></p>
        </div>
        <?php endif; ?>

        <div class="section-title">
            <img src="/images/MONEY.PNG" alt="Money" class="section-icon" onerror="this.style.display='none'">
            <h2>Money Overview</h2>
        </div>

        <!-- Statistics Summary -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Inventory Money</div>
                <div class="stat-value currency">$<?php echo number_format($totalMoney, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Material Value</div>
                <div class="stat-value currency">$<?php echo number_format($totalMaterialValue, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Net Worth (Minus Starting Funds)</div>
                <div class="stat-value currency">$<?php echo number_format($totalNetWorth, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Number of Inventories</div>
                <div class="stat-value"><?php echo count($netWorthData); ?></div>
            </div>
        </div>

        <!-- Charts and Tables -->
        <div class="charts-grid">
            <!-- Net Worth Pie Chart -->
            <div class="chart-container">
                <div class="chart-title">Net Worth Distribution</div>
                <canvas id="netWorthChart"></canvas>
            </div>

            <!-- Net Worth Bar Chart -->
            <div class="chart-container">
                <div class="chart-title">Inventory Breakdown</div>
                <canvas id="breakdownChart"></canvas>
            </div>
        </div>

        <!-- Net Worth Table -->
        <div class="table-container">
            <div class="table-title">Detailed Net Worth Analysis</div>
            <table>
                <thead>
                    <tr>
                        <th>Inventory Name</th>
                        <th>Cash on Hand</th>
                        <th>Material Value</th>
                        <th>Total Net Worth (Minus Starting Funds)</th>
                        <th>% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($netWorthData as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['Inventory_Name']); ?></td>
                        <td class="currency">$<?php echo number_format($row['Inventory_Money'], 2); ?></td>
                        <td class="currency">$<?php echo number_format($row['Material_Value'], 2); ?></td>
                        <td class="currency">$<?php echo number_format($row['Total_Net_Worth'], 2); ?></td>
                        <td><?php echo round(($row['Total_Net_Worth'] / $totalNetWorth) * 100, 2); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Ammo Statistics Section -->
        <div class="section-title">
            <img src="/images/AMMO.PNG" alt="Ammunition" class="section-icon" onerror="this.style.display='none'">
            <h2>Ammunition Analysis</h2>
        </div>

        <!-- Ammo Charts -->
        <div class="charts-grid">
            <!-- Ammo Type Pie Chart -->
            <div class="chart-container">
                <div class="chart-title">Total Rounds by Caliber</div>
                <canvas id="ammoTypeChart"></canvas>
            </div>

            <!-- Ammo Distribution Pie Chart -->
            <div class="chart-container">
                <div class="chart-title" id="ammoDistTitle">Ammo Quantity by Inventory</div>
                <canvas id="ammoDistChart"></canvas>
            </div>
        </div>

        <!-- Ammo Type Selector -->
        <div class="ammo-selector">
            <label for="ammoTypeSelect">Select Caliber:</label>
            <select id="ammoTypeSelect" onchange="updateAmmoDistribution()">
                <option value="">-- Choose a caliber --</option>
                <?php foreach (array_keys($ammoByType) as $ammoType): ?>
                <option value="<?php echo htmlspecialchars($ammoType); ?>">
                    <?php echo htmlspecialchars($ammoType); ?> (<?php echo number_format($ammoByType[$ammoType]); ?> rounds)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Ammo Data Table -->
        <div class="table-container">
            <div class="table-title">Current Ammunition Inventory</div>
            <table>
                <thead>
                    <tr>
                        <th>Inventory</th>
                        <?php 
                        $ammoTypes = array_keys($ammoByType);
                        foreach ($ammoTypes as $type): 
                        ?>
                        <th><?php echo htmlspecialchars($type); ?></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $inventories = array_unique(array_map(fn($r) => $r['Inventory_Name'], $ammoData));
                    sort($inventories);
                    foreach ($inventories as $inventory):
                        $inventoryTotal = 0;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($inventory); ?></strong></td>
                        <?php 
                        foreach ($ammoTypes as $type):
                            $quantity = isset($ammoByTypeAndInventory[$type][$inventory]) ? $ammoByTypeAndInventory[$type][$inventory] : 0;
                            $inventoryTotal += $quantity;
                        ?>
                        <td class="currency"><?php echo number_format($quantity); ?></td>
                        <?php endforeach; ?>
                        <td class="currency"><strong><?php echo number_format($inventoryTotal); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Weapon Statistics Section -->
        <div class="section-title">
            <img src="/images/PRIMARY_WEAPON.PNG" alt="Weapons" class="section-icon" onerror="this.style.display='none'">
            <h2>Weapon Ownership Analysis</h2>
        </div>

        <!-- Weapon Charts -->
        <div class="charts-grid">
            <!-- Weapon Type Pie Chart -->
            <div class="chart-container">
                <div class="chart-title">Total Items by Type</div>
                <canvas id="weaponTypeChart"></canvas>
            </div>

            <!-- Weapon Distribution Pie Chart -->
            <div class="chart-container">
                <div class="chart-title" id="weaponDistTitle">Item Distribution by Type</div>
                <canvas id="weaponDistChart"></canvas>
            </div>
        </div>

        <!-- Weapon Type Selector -->
        <div class="ammo-selector">
            <label for="weaponTypeSelect">Select Item Type:</label>
            <select id="weaponTypeSelect" onchange="updateWeaponDistribution()">
                <option value="">-- Choose an item type --</option>
                <?php foreach (array_keys($weaponByType) as $weaponType): ?>
                <option value="<?php echo htmlspecialchars($weaponType); ?>">
                    <?php echo htmlspecialchars($weaponType); ?> (<?php echo number_format($weaponByType[$weaponType]); ?> items)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Weapon Data Table -->
        <div class="table-container">
            <div class="table-title">Weapon Inventory by Class</div>
            <table>
                <thead>
                    <tr>
                        <th>Weapon Type</th>
                        <th>Class Name</th>
                        <th>Display Name</th>
                        <th>Total Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($weaponByTypeAndClass as $type => $weapons):
                        foreach ($weapons as $itemClass => $weaponInfo):
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($type); ?></strong></td>
                        <td><?php echo htmlspecialchars($itemClass); ?></td>
                        <td><?php echo htmlspecialchars($weaponInfo['display_name']); ?></td>
                        <td class="currency"><?php echo number_format($weaponInfo['quantity']); ?></td>
                    </tr>
                    <?php 
                        endforeach;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Market Section: Primary, Secondary, Attachments -->
        <div class="section-title" style="margin-top: 48px;">
            <img src="/images/SHOP.PNG" alt="Market" class="section-icon" onerror="this.style.display='none'">
            <h2>Market Overview</h2>
        </div>
        <div class="stats-grid" style="margin-bottom: 24px;">
            <?php
            // Fetch interchangeable items to create a mapping from variant to base item
            $interchangeableMap = [];
            $stmt = $pdo->query("SELECT Base_Item_Class, Interchangable_Item_Class FROM interchangable_items");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $interchangeableMap[$row['Interchangable_Item_Class']] = $row['Base_Item_Class'];
            }

            // Fetch market items that are primary weapons, sidearms, or attachments
            $marketQuery = "
                SELECT
                    m.Market_Item_Class,
                    m.Available_Quantity,
                    cit.Custom_Item_Type
                FROM market m
                LEFT JOIN items i ON i.item_class = m.Market_Item_Class
                LEFT JOIN item_types it ON it.Item_Type_Id = i.Item_Type
                LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = it.item_classification
                WHERE cit.Custom_Item_Type IN ('Primary_Weapon', 'Sidearm', 'Attachments')
            ";
            $marketStmt = $pdo->query($marketQuery);
            $marketItems = $marketStmt->fetchAll(PDO::FETCH_ASSOC);

            // Group items by their base class if they are interchangeable
            $marketSummary = [
                'Primary_Weapon' => [],
                'Sidearm' => [],
                'Attachments' => [],
            ];

            foreach ($marketItems as $item) {
                $baseClass = $interchangeableMap[$item['Market_Item_Class']] ?? $item['Market_Item_Class'];
                $type = $item['Custom_Item_Type'];

                if (isset($marketSummary[$type])) {
                    if (!isset($marketSummary[$type][$baseClass])) {
                        $marketSummary[$type][$baseClass] = 0;
                    }
                    // Use -1 to signify unlimited quantity
                    if ($item['Available_Quantity'] == -1) {
                        $marketSummary[$type][$baseClass] = -1;
                    } elseif ($marketSummary[$type][$baseClass] != -1) {
                        $marketSummary[$type][$baseClass] += $item['Available_Quantity'];
                    }
                }
            }

            // Calculate final counts and quantities
            $primaryCount = count($marketSummary['Primary_Weapon']);
            $sidearmCount = count($marketSummary['Sidearm']);
            $attachmentCount = count($marketSummary['Attachments']);

            $primaryAvailableCount = 0;
            foreach ($marketSummary['Primary_Weapon'] as $qty) {
                if ($qty > 0 || $qty == -1) {
                    $primaryAvailableCount++;
                }
            }

            $sidearmAvailableCount = 0;
            foreach ($marketSummary['Sidearm'] as $qty) {
                if ($qty > 0 || $qty == -1) {
                    $sidearmAvailableCount++;
                }
            }
            
            $attachmentAvailableCount = 0;
            foreach ($marketSummary['Attachments'] as $qty) {
                if ($qty > 0 || $qty == -1) {
                    $attachmentAvailableCount++;
                }
            }

            // Calculate total exchangeable skins for items on the market
            $exchangeableSkins = [
                'Primary_Weapon' => 0,
                'Sidearm' => 0,
                'Attachments' => 0,
            ];
            $processedBases = [];
            foreach ($marketItems as $item) {
                $baseClass = $interchangeableMap[$item['Market_Item_Class']] ?? $item['Market_Item_Class'];
                $type = $item['Custom_Item_Type'];

                if (isset($exchangeableSkins[$type]) && !isset($processedBases[$baseClass])) {
                    $skinCountQuery = "SELECT COUNT(DISTINCT Interchangable_Item_Class) FROM interchangable_items WHERE Base_Item_Class = :base_class";
                    $skinStmt = $pdo->prepare($skinCountQuery);
                    $skinStmt->execute([':base_class' => $baseClass]);
                    $count = $skinStmt->fetchColumn();
                    $exchangeableSkins[$type] += $count;
                    $processedBases[$baseClass] = true;
                }
            }
            ?>
            <div class="stat-card">
                <div class="stat-label">Primary Weapons</div>
                <div class="stat-value">
                    <?php echo $primaryCount; ?> types<br>
                    <span style="font-size: 0.95em;">
                        <em style="font-size: 0.85em; color: #ccc;">
                            <?php echo $primaryAvailableCount; ?> available
                        </em>
                    </span>
                    <span>
                        <br><em style="font-size: 0.85em; color: #ccc;">
                            <?php echo $exchangeableSkins['Primary_Weapon']; ?> exchangeable skins
                        </em>
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Secondary Weapons</div>
                <div class="stat-value">
                    <?php echo $sidearmCount; ?> types<br>
                    <span style="font-size: 0.95em;">
                        <em style="font-size: 0.85em; color: #ccc;">
                            <?php echo $sidearmAvailableCount; ?> available
                        </em>
                    </span>
                    <span>
                        <br><em style="font-size: 0.85em; color: #ccc;">
                            <?php echo $exchangeableSkins['Sidearm']; ?> exchangeable skins
                        </em>
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Attachments</div>
                <div class="stat-value">
                    <?php echo $attachmentCount; ?> types<br>
                    <span style="font-size: 0.95em;">
                        <em style="font-size: 0.85em; color: #ccc;">
                            <?php echo $attachmentAvailableCount; ?> available
                        </em>
                    </span>
                    <span>
                        <br><em style="font-size: 0.85em; color: #ccc;">
                            <?php echo $exchangeableSkins['Attachments']; ?> exchangeable skins
                        </em>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Change snapshot view
        function changeSnapshot(snapshotId) {
            if (snapshotId === '') {
                window.location.href = 'index.php';
            } else {
                window.location.href = 'index.php?snapshot=' + snapshotId;
            }
        }

        // Prepare data for charts
        const inventoryNames = <?php echo json_encode(array_map(fn($r) => $r['Inventory_Name'], $netWorthData)); ?>;
        const netWorthValues = <?php echo json_encode(array_map(fn($r) => round($r['Total_Net_Worth'], 2), $netWorthData)); ?>;
        const moneyValues = <?php echo json_encode(array_map(fn($r) => round($r['Inventory_Money'], 2), $netWorthData)); ?>;
        const materialValues = <?php echo json_encode(array_map(fn($r) => round($r['Material_Value'], 2), $netWorthData)); ?>;

        // Color palette
        const colors = [
            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
            '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384'
        ];

        // Pie Chart - Net Worth Distribution
        const pieCtx = document.getElementById('netWorthChart').getContext('2d');
        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: inventoryNames,
                datasets: [{
                    data: netWorthValues,
                    backgroundColor: colors.slice(0, inventoryNames.length),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f0f0f0',
                            font: {
                                size: 13,
                                weight: 600
                            }
                        }
                    },
                    tooltip: {
                        titleColor: '#f0f0f0',
                        bodyColor: '#f0f0f0',
                        backgroundColor: 'rgba(26, 26, 26, 0.95)',
                        borderColor: '#FFB800',
                        borderWidth: 2
                    }
                }
            }
        });

        // Bar Chart - Inventory Breakdown
        const barCtx = document.getElementById('breakdownChart').getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: inventoryNames,
                datasets: [
                    {
                        label: 'Cash on Hand',
                        data: moneyValues,
                        backgroundColor: '#2e8b57'
                    },
                    {
                        label: 'Material Value',
                        data: materialValues,
                        backgroundColor: '#4169E1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            color: '#f0f0f0',
                            font: {
                                size: 9,
                                weight: 600
                            },
                            maxRotation: 90,
                            minRotation: 45,
                            autoSkip: false
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#f0f0f0',
                            font: {
                                size: 12,
                                weight: 600
                            },
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f0f0f0',
                            font: {
                                size: 13,
                                weight: 600
                            }
                        }
                    },
                    tooltip: {
                        titleColor: '#f0f0f0',
                        bodyColor: '#f0f0f0',
                        backgroundColor: 'rgba(26, 26, 26, 0.95)',
                        borderColor: '#FFB800',
                        borderWidth: 2
                    }
                }
            }
        });

        // Prepare ammo data for charts
        const ammoTypeData = <?php echo json_encode($ammoByType); ?>;
        const ammoTypeLabels = Object.keys(ammoTypeData);
        const ammoTypeValues = Object.values(ammoTypeData);
        
        // Full ammo dataset for distribution
        const fullAmmoData = <?php echo json_encode($ammoByTypeAndInventory); ?>;

        // Color palette for ammo
        const ammoColors = [
            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
            '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384',
            '#FF1744', '#F57F17', '#00BCD4', '#00E676', '#651FFF'
        ];

        // Pie Chart - Ammo by Type
        const ammoTypeCtx = document.getElementById('ammoTypeChart').getContext('2d');
        const ammoTypeChart = new Chart(ammoTypeCtx, {
            type: 'pie',
            data: {
                labels: ammoTypeLabels,
                datasets: [{
                    data: ammoTypeValues,
                    backgroundColor: ammoColors.slice(0, ammoTypeLabels.length),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f0f0f0',
                            font: {
                                size: 13,
                                weight: 600
                            }
                        }
                    },
                    tooltip: {
                        titleColor: '#f0f0f0',
                        bodyColor: '#f0f0f0',
                        backgroundColor: 'rgba(26, 26, 26, 0.95)',
                        borderColor: '#FFB800',
                        borderWidth: 2
                    }
                }
            }
        });

        // Placeholder distribution chart
        const ammoDistCtx = document.getElementById('ammoDistChart').getContext('2d');
        let ammoDistChart = new Chart(ammoDistCtx, {
            type: 'pie',
            data: {
                labels: ['Please select a caliber'],
                datasets: [{
                    data: [100],
                    backgroundColor: ['#ccc'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f0f0f0',
                            font: {
                                size: 13,
                                weight: 600
                            }
                        }
                    },
                    tooltip: {
                        titleColor: '#f0f0f0',
                        bodyColor: '#f0f0f0',
                        backgroundColor: 'rgba(26, 26, 26, 0.95)',
                        borderColor: '#FFB800',
                        borderWidth: 2
                    }
                }
            }
        });

        function updateAmmoDistribution() {
            const selectedAmmo = document.getElementById('ammoTypeSelect').value;
            
            if (!selectedAmmo) {
                ammoDistChart.data.labels = ['Please select a caliber'];
                ammoDistChart.data.datasets[0].data = [100];
                ammoDistChart.data.datasets[0].backgroundColor = ['#ccc'];
                ammoDistChart.update();
                return;
            }

            // Get data for selected ammo type across all inventories
            const inventoryData = fullAmmoData[selectedAmmo] || {};
            const labels = Object.keys(inventoryData).sort();
            const data = labels.map(inventory => inventoryData[inventory] || 0);

            // Update chart title
            document.getElementById('ammoDistTitle').textContent = `${selectedAmmo} Distribution by Inventory`;

            // Update chart
            ammoDistChart.data.labels = labels;
            ammoDistChart.data.datasets[0].data = data;
            ammoDistChart.data.datasets[0].backgroundColor = ammoColors.slice(0, labels.length);
            ammoDistChart.update();
        }

        // Prepare weapon data for charts
        const weaponTypeData = <?php echo json_encode($weaponByType); ?>;
        const weaponTypeLabels = Object.keys(weaponTypeData);
        const weaponTypeValues = Object.values(weaponTypeData);
        
        // Full weapon dataset for distribution by class
        const fullWeaponByTypeAndClass = <?php echo json_encode($weaponByTypeAndClass); ?>;

        // Color palette for weapons
        const weaponColors = [
            '#8B4513', '#CD853F', '#D2691E', '#A0522D', '#B8860B',
            '#DAA520', '#FFD700', '#F4A460', '#DEB887', '#BC8F8F',
            '#8B7355', '#A0826D', '#C19A6B', '#E6C9A8', '#D2B48C'
        ];

        // Pie Chart - Weapon by Type
        const weaponTypeCtx = document.getElementById('weaponTypeChart').getContext('2d');
        const weaponTypeChart = new Chart(weaponTypeCtx, {
            type: 'pie',
            data: {
                labels: weaponTypeLabels,
                datasets: [{
                    data: weaponTypeValues,
                    backgroundColor: weaponColors.slice(0, weaponTypeLabels.length),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f0f0f0',
                            font: {
                                size: 13,
                                weight: 600
                            }
                        }
                    },
                    tooltip: {
                        titleColor: '#f0f0f0',
                        bodyColor: '#f0f0f0',
                        backgroundColor: 'rgba(26, 26, 26, 0.95)',
                        borderColor: '#FFB800',
                        borderWidth: 2
                    }
                }
            }
        });

        // Placeholder weapon distribution chart
        const weaponDistCtx = document.getElementById('weaponDistChart').getContext('2d');
        let weaponDistChart = new Chart(weaponDistCtx, {
            type: 'pie',
            data: {
                labels: ['Please select a weapon type'],
                datasets: [{
                    data: [100],
                    backgroundColor: ['#ccc'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f0f0f0',
                            font: {
                                size: 13,
                                weight: 600
                            }
                        }
                    },
                    tooltip: {
                        titleColor: '#f0f0f0',
                        bodyColor: '#f0f0f0',
                        backgroundColor: 'rgba(26, 26, 26, 0.95)',
                        borderColor: '#FFB800',
                        borderWidth: 2
                    }
                }
            }
        });

        function updateWeaponDistribution() {
            const selectedWeapon = document.getElementById('weaponTypeSelect').value;
            
            if (!selectedWeapon) {
                weaponDistChart.data.labels = ['Please select a weapon type'];
                weaponDistChart.data.datasets[0].data = [100];
                weaponDistChart.data.datasets[0].backgroundColor = ['#ccc'];
                weaponDistChart.update();
                return;
            }

            // Get data for selected weapon type - show by class name
            const weaponsOfType = fullWeaponByTypeAndClass[selectedWeapon] || {};
            const labels = [];
            const data = [];
            
            for (const [itemClass, weaponInfo] of Object.entries(weaponsOfType)) {
                labels.push(weaponInfo.display_name);
                data.push(weaponInfo.quantity);
            }

            // Update chart title
            document.getElementById('weaponDistTitle').textContent = `${selectedWeapon} - Distribution by Class`;

            // Update chart
            weaponDistChart.data.labels = labels;
            weaponDistChart.data.datasets[0].data = data;
            weaponDistChart.data.datasets[0].backgroundColor = weaponColors.slice(0, labels.length);
            weaponDistChart.update();
        }
    </script>
</body>
</html>
