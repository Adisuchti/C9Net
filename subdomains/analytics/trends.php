<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once 'helpers.php';

// Get all available snapshots
$availableSnapshots = getAvailableSnapshots($pdo);

// Get current data as well
$currentNetWorth = getNetWorthData($pdo, null);
$currentAmmo = getAmmoData($pdo, null);
$currentAmmoProcessed = processAmmoData($currentAmmo);
$currentWeaponByClass = getWeaponByClassName($pdo, null);
$currentWeaponByClassProcessed = processWeaponByClassName($currentWeaponByClass);

// Get weapon purchase data from logs
$weaponPurchases = getWeaponPurchasesByWeek($pdo);
$weaponPurchasesProcessed = processWeaponPurchasesByWeek($weaponPurchases);

// Get ammo purchase data from logs
$ammoPurchases = getAmmoPurchasesByWeek($pdo);
$ammoPurchasesProcessed = processAmmoPurchasesByWeek($ammoPurchases);

// Get ammo usage by session (ammunition removed from inventory, grouped by session)
$ammoUsageBySession = getAmmoUsageBySession($pdo);
$ammoUsageBySessionProcessed = processAmmoUsageBySession($ammoUsageBySession);

// Prepare data structures for trends
$trendData = [];

// Add current data first
$trendData[] = [
    'label' => 'Current',
    'date' => date('Y-m-d H:i:s'),
    'netWorth' => $currentNetWorth,
    'ammo' => $currentAmmoProcessed,
    'weaponByClass' => $currentWeaponByClassProcessed
];

// Add snapshot data
foreach ($availableSnapshots as $snapshot) {
    $snapshotNetWorth = getNetWorthData($pdo, $snapshot['snapshot_id']);
    $snapshotAmmo = getAmmoData($pdo, $snapshot['snapshot_id']);
    $snapshotAmmoProcessed = processAmmoData($snapshotAmmo);
    $snapshotWeaponByClass = getWeaponByClassName($pdo, $snapshot['snapshot_id']);
    $snapshotWeaponByClassProcessed = processWeaponByClassName($snapshotWeaponByClass);
    
    $trendData[] = [
        'label' => date('M j, Y H:i', strtotime($snapshot['snapshot_date'])),
        'date' => $snapshot['snapshot_date'],
        'netWorth' => $snapshotNetWorth,
        'ammo' => $snapshotAmmoProcessed,
        'weaponByClass' => $snapshotWeaponByClassProcessed
    ];
}

// Sort by date (oldest to newest for trend view)
usort($trendData, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Prepare money trend data
$labels = [];
$cashData = [];
$materialData = [];
$materialCurrentPricesData = [];
$inventoryCountData = [];

foreach ($trendData as $point) {
    $labels[] = $point['label'];
    
    $totalCash = 0;
    $totalMaterial = 0;
    $totalMaterialSnapshotPrices = 0;
    $totalMaterialCurrentPrices = 0;
    
    foreach ($point['netWorth'] as $inv) {
        $totalCash += $inv['Inventory_Money'];
        $totalMaterial += $inv['Material_Value'];
        $totalMaterialSnapshotPrices += isset($inv['Material_Value_Snapshot']) ? $inv['Material_Value_Snapshot'] : 0;
        $totalMaterialCurrentPrices += isset($inv['Material_Value_Current']) ? $inv['Material_Value_Current'] : 0;
    }
    
    $cashData[] = round($totalCash, 2);
    // Subtract starting funds (6500) from material value
    $materialData[] = round(max(0, $totalMaterialSnapshotPrices - 6500), 2);
    $materialCurrentPricesData[] = round(max(0, $totalMaterialCurrentPrices - 6500), 2);
    // Count inventories for this data point
    $inventoryCountData[] = count($point['netWorth']);
}

// Prepare ammo trend data
// First, collect all unique ammo types across all snapshots
$allAmmoTypes = [];
foreach ($trendData as $point) {
    foreach (array_keys($point['ammo']['byType']) as $type) {
        $allAmmoTypes[$type] = true;
    }
}
$allAmmoTypes = array_keys($allAmmoTypes);
sort($allAmmoTypes);

// Build dataset for each ammo type
$ammoDatasets = [];
foreach ($allAmmoTypes as $type) {
    $data = [];
    foreach ($trendData as $point) {
        $data[] = isset($point['ammo']['byType'][$type]) ? $point['ammo']['byType'][$type] : 0;
    }
    $ammoDatasets[$type] = $data;
}

// Prepare weapon trend data by class name
// Only include Primary Weapons, Sidearms, and Launchers
// Note: These match the actual Custom_Item_Type values from the database
$allowedWeaponCategories = ['Primary_Weapon', 'Sidearm', 'Launcher'];
// Collect all unique weapon classes across all snapshots (filtered by category)
$allWeaponClasses = [];
foreach ($trendData as $point) {
    if (isset($point['weaponByClass']['byClass'])) {
        foreach ($point['weaponByClass']['byClass'] as $itemClass => $weaponInfo) {
            // Only include weapons from allowed categories
            if (isset($weaponInfo['type']) && in_array($weaponInfo['type'], $allowedWeaponCategories)) {
                if (!isset($allWeaponClasses[$itemClass])) {
                    $allWeaponClasses[$itemClass] = $weaponInfo['display_name'];
                }
            }
        }
    }
}

// Build dataset for each weapon class
$weaponDatasets = [];
foreach (array_keys($allWeaponClasses) as $itemClass) {
    $data = [];
    foreach ($trendData as $point) {
        $quantity = 0;
        if (isset($point['weaponByClass']['byClass'][$itemClass]['quantity'])) {
            $quantity = $point['weaponByClass']['byClass'][$itemClass]['quantity'];
        }
        $data[] = $quantity;
    }
    // Use display name as the key for the dataset
    $weaponDatasets[$allWeaponClasses[$itemClass]] = $data;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trends Over Time - Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="analytics.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="analytics-container">
        <div class="analytics-header">
            <h1>Trends Over Time</h1>
            <a href="index.php" class="btn">← Back to Dashboard</a>
        </div>

        <?php if (count($trendData) < 2): ?>
        <div class="info-box">
            <p><strong>Not enough data points</strong></p>
            <p>You need at least one snapshot to view trends. Create snapshots from the <a href="manage.php" class="info-link">Manage Snapshots</a> page.</p>
        </div>
        <?php else: ?>
        <div class="info-box">
            <p><strong>Showing trends across <?php echo count($trendData); ?> data points</strong></p>
            <p>Data includes current state plus <?php echo count($availableSnapshots); ?> historical snapshot(s).</p>
        </div>
        <?php endif; ?>

        <!-- Statistics Summary -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Current Inventory Count</div>
                <div class="stat-value"><?php echo count($currentNetWorth); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Data Points</div>
                <div class="stat-value"><?php echo count($trendData); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Historical Snapshots</div>
                <div class="stat-value"><?php echo count($availableSnapshots); ?></div>
            </div>
        </div>

        <!-- Inventory Count Trends -->
        <div class="chart-section">
            <div class="chart-title">Inventory Count Over Time</div>
            <div class="chart-wrapper">
                <canvas id="inventoryCountChart"></canvas>
            </div>
        </div>

        <!-- Money Trends -->
        <div class="chart-section">
            <div class="chart-title">Money Trends Over Time</div>
            <div class="chart-wrapper">
                <canvas id="moneyTrendChart"></canvas>
            </div>
        </div>

        <!-- Ammo Trends -->
        <div class="chart-section">
            <div class="chart-title">Ammunition Ownership Trends Over Time</div>
            <div class="chart-wrapper">
                <canvas id="ammoTrendChart"></canvas>
            </div>
        </div>

        <!-- Weapon Trends -->
        <div class="chart-section">
            <div class="chart-title">Weapon Ownership Trends Over Time</div>
            <div class="chart-wrapper">
                <canvas id="weaponTrendChart"></canvas>
            </div>
        </div>

        <!-- Weapon Purchases by Week -->
        <div class="chart-section">
            <div class="chart-title">Weapon Purchases by Week</div>
            <div class="chart-wrapper">
                <canvas id="weaponPurchasesChart"></canvas>
            </div>
        </div>

        <!-- Ammo Purchases by Week -->
        <div class="chart-section">
            <div class="chart-title">Ammunition Purchases by Caliber Over Time</div>
            <div class="chart-wrapper">
                <canvas id="ammoPurchasesChart"></canvas>
            </div>
        </div>

        <!-- Ammo Usage by Session -->
        <div class="chart-section">
            <div class="chart-title">Ammunition Usage by Session (Rounds Fired/Retrieved)</div>
            <div class="chart-wrapper">
                <canvas id="ammoUsageBySessionChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        // Trend data
        const labels = <?php echo json_encode($labels); ?>;
        const inventoryCountData = <?php echo json_encode($inventoryCountData); ?>;
        const cashData = <?php echo json_encode($cashData); ?>;
        const materialData = <?php echo json_encode($materialData); ?>;
        const materialCurrentPricesData = <?php echo json_encode($materialCurrentPricesData); ?>;

        // Inventory Count Trend Chart
        const inventoryCountCtx = document.getElementById('inventoryCountChart').getContext('2d');
        new Chart(inventoryCountCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Number of Inventories',
                        data: inventoryCountData,
                        backgroundColor: 'rgba(255, 184, 0, 0.2)',
                        borderColor: '#FFB800',
                        borderWidth: 3,
                        pointBackgroundColor: '#FFB800',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return Math.floor(value);
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Inventories: ' + context.parsed.y;
                            }
                        }
                    }
                }
            }
        });

        // Money Trend Chart
        const moneyCtx = document.getElementById('moneyTrendChart').getContext('2d');
        new Chart(moneyCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Cash Holdings',
                        data: cashData,
                        backgroundColor: '#27AE60',
                        borderColor: '#229954',
                        borderWidth: 2,
                        hoverBackgroundColor: '#2ECC71',
                        hoverBorderColor: '#229954',
                        hoverBorderWidth: 3
                    },
                    {
                        label: 'Material Value (snapshot prices)',
                        data: materialData,
                        backgroundColor: '#3498DB',
                        borderColor: '#2874A6',
                        borderWidth: 2,
                        hoverBackgroundColor: '#5DADE2',
                        hoverBorderColor: '#2874A6',
                        hoverBorderWidth: 3
                    },
                    {
                        label: 'Material Value (calculated using current market)',
                        data: materialCurrentPricesData,
                        backgroundColor: 'rgba(52, 152, 219, 0.4)', // Semi-transparent blue
                        borderColor: '#2874A6',
                        borderWidth: 2,
                        borderDash: [5, 5], // Dashed border to distinguish it
                        hoverBackgroundColor: 'rgba(93, 173, 226, 0.6)',
                        hoverBorderColor: '#2874A6',
                        hoverBorderWidth: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '$' + context.parsed.y.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Ammo trend data
        const ammoLabels = <?php echo json_encode($labels); ?>;
        const ammoDatasets = <?php echo json_encode($ammoDatasets); ?>;

        // Color palette for ammo types - expanded with more vibrant colors
        const ammoColors = {
            '9x21': '#FF6384',
            '9x19': '#36A2EB',
            '45ACP': '#FFCE56',
            '45HP': '#4BC0C0',
            '556x45': '#9966FF',
            '570x28': '#FF9F40',
            '65x39': '#E74C3C',
            '762x39': '#95A5A6',
            '12Gauge': '#1ABC9C',
            '58x42': '#E91E63',
            '762x51': '#F39C12',
            '762x54': '#3498DB',
            '127x54': '#2ECC71',
            '127x55': '#9B59B6',
            '127x99': '#E67E22',
            '127x108': '#C0392B',
            '338': '#16A085',
            '408': '#8E44AD',
            '93x64': '#D35400',
            '650x39': '#2980B9',
            'HE': '#F1C40F'
        };

        // Build datasets for Chart.js
        const ammoChartDatasets = [];
        for (const [type, data] of Object.entries(ammoDatasets)) {
            const color = ammoColors[type] || '#999';
            ammoChartDatasets.push({
                label: type,
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 2,
                hoverBackgroundColor: color + 'CC', // Add slight transparency on hover
                hoverBorderColor: color,
                hoverBorderWidth: 3
            });
        }

        // Ammo Trend Chart
        const ammoCtx = document.getElementById('ammoTrendChart').getContext('2d');
        new Chart(ammoCtx, {
            type: 'bar',
            data: {
                labels: ammoLabels,
                datasets: ammoChartDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' rounds';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString() + ' rounds';
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Weapon trend data
        const weaponLabels = <?php echo json_encode($labels); ?>;
        const weaponDatasets = <?php echo json_encode($weaponDatasets); ?>;

        // Generate colors for each weapon class - expanded color palette
        function generateWeaponColors(count) {
            const baseColors = [
                // Browns and tans (traditional weapon colors)
                '#8B4513', '#CD853F', '#D2691E', '#A0522D', '#DEB887',
                // Reds and oranges
                '#DC143C', '#FF6347', '#FF4500', '#E74C3C', '#C0392B',
                // Yellows and golds
                '#FFD700', '#FFA500', '#FF8C00', '#F39C12', '#F1C40F',
                // Greens
                '#228B22', '#32CD32', '#00FA9A', '#2ECC71', '#27AE60',
                // Blues and cyans
                '#4169E1', '#1E90FF', '#00BFFF', '#3498DB', '#2980B9',
                // Purples and magentas
                '#9370DB', '#8A2BE2', '#9B59B6', '#8E44AD', '#E91E63',
                // Grays and silvers
                '#708090', '#778899', '#95A5A6', '#7F8C8D', '#BDC3C7',
                // Teals and aquas
                '#008B8B', '#20B2AA', '#48D1CC', '#1ABC9C', '#16A085',
                // Additional vibrant colors
                '#FF69B4', '#DA70D6', '#BA55D3', '#EE82EE', '#DDA0DD',
                '#FFDAB9', '#FFE4B5', '#FFEFD5', '#FFE4E1', '#F0E68C',
                '#FA8072', '#E9967A', '#F08080', '#CD5C5C', '#BC8F8F',
                '#D2B48C', '#F5DEB3', '#FFDEAD', '#FFE4C4', '#C19A6B'
            ];
            const colors = [];
            for (let i = 0; i < count; i++) {
                colors.push(baseColors[i % baseColors.length]);
            }
            return colors;
        }

        // Build datasets for Chart.js
        const weaponChartDatasets = [];
        const weaponColorsList = generateWeaponColors(Object.keys(weaponDatasets).length);
        let colorIndex = 0;
        
        for (const [weaponName, data] of Object.entries(weaponDatasets)) {
            const color = weaponColorsList[colorIndex];
            weaponChartDatasets.push({
                label: weaponName,
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 2,
                hoverBackgroundColor: color + 'CC',
                hoverBorderColor: color,
                hoverBorderWidth: 3
            });
            colorIndex++;
        }

        // Weapon Trend Chart
        const weaponCtx = document.getElementById('weaponTrendChart').getContext('2d');
        new Chart(weaponCtx, {
            type: 'bar',
            data: {
                labels: weaponLabels,
                datasets: weaponChartDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' items';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString() + ' items';
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Weapon Purchases by Week Chart
        const weaponPurchasesLabels = <?php echo json_encode($weaponPurchasesProcessed['labels']); ?>;
        const weaponPurchasesDatasets = <?php echo json_encode($weaponPurchasesProcessed['datasets']); ?>;

        // Build datasets for Chart.js
        const weaponPurchasesChartDatasets = [];
        const purchaseColorsList = generateWeaponColors(Object.keys(weaponPurchasesDatasets).length);
        let purchaseColorIndex = 0;
        
        for (const [weaponName, data] of Object.entries(weaponPurchasesDatasets)) {
            const color = purchaseColorsList[purchaseColorIndex];
            weaponPurchasesChartDatasets.push({
                label: weaponName,
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 2,
                hoverBackgroundColor: color + 'CC',
                hoverBorderColor: color,
                hoverBorderWidth: 3
            });
            purchaseColorIndex++;
        }

        // Weapon Purchases Chart
        const weaponPurchasesCtx = document.getElementById('weaponPurchasesChart').getContext('2d');
        new Chart(weaponPurchasesCtx, {
            type: 'bar',
            data: {
                labels: weaponPurchasesLabels,
                datasets: weaponPurchasesChartDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' purchased';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString() + ' purchased';
                                return label;
                            }
                        }
                    }
                }
            }
        });
        // Ammo Purchases by Week Chart
        const ammoPurchasesLabels = <?php echo json_encode($ammoPurchasesProcessed['labels']); ?>;
        const ammoPurchasesDatasets = <?php echo json_encode($ammoPurchasesProcessed['datasets']); ?>;

        // Build datasets for Chart.js
        const ammoPurchasesChartDatasets = [];
        for (const [type, data] of Object.entries(ammoPurchasesDatasets)) {
            const color = ammoColors[type] || '#999';
            ammoPurchasesChartDatasets.push({
                label: type,
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 2,
                hoverBackgroundColor: color + 'CC',
                hoverBorderColor: color,
                hoverBorderWidth: 3
            });
        }

        // Ammo Usage by Session Chart
        const ammoUsageBySessionLabels = <?php echo json_encode($ammoUsageBySessionProcessed['labels']); ?>;
        const ammoUsageBySessionDatasets = <?php echo json_encode($ammoUsageBySessionProcessed['datasets']); ?>;

        // Build datasets for Chart.js
        const ammoUsageBySessionChartDatasets = [];
        for (const [type, data] of Object.entries(ammoUsageBySessionDatasets)) {
            const color = ammoColors[type] || '#999';
            ammoUsageBySessionChartDatasets.push({
                label: type,
                data: data,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 2,
                hoverBackgroundColor: color + 'CC',
                hoverBorderColor: color,
                hoverBorderWidth: 3
            });
        }

        // Ammo Usage by Session Chart
        const ammoUsageBySessionCtx = document.getElementById('ammoUsageBySessionChart').getContext('2d');
        new Chart(ammoUsageBySessionCtx, {
            type: 'bar',
            data: {
                labels: ammoUsageBySessionLabels,
                datasets: ammoUsageBySessionChartDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' rounds';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString() + ' rounds used';
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Ammo Purchases Chart
        const ammoPurchasesCtx = document.getElementById('ammoPurchasesChart').getContext('2d');
        new Chart(ammoPurchasesCtx, {
            type: 'bar',
            data: {
                labels: ammoPurchasesLabels,
                datasets: ammoPurchasesChartDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' rounds';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString() + ' rounds';
                                return label;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
