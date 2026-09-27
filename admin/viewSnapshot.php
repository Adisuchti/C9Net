<?php
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/auth.php';

// Check if the user is logged in and is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Get snapshot ID from query parameter
$snapshotId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$snapshotId) {
    die('No snapshot ID provided');
}

// Get snapshot info
$snapshotStmt = $pdo->prepare("SELECT * FROM analytics_snapshots WHERE snapshot_id = :id");
$snapshotStmt->execute([':id' => $snapshotId]);
$snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);

if (!$snapshot) {
    die('Snapshot not found');
}

// Get counts
$contentItemsStmt = $pdo->prepare("SELECT COUNT(*) as count FROM analytics_content_items_snapshot WHERE snapshot_id = :id");
$contentItemsStmt->execute([':id' => $snapshotId]);
$contentItemsCount = $contentItemsStmt->fetch(PDO::FETCH_ASSOC)['count'];

$inventoriesStmt = $pdo->prepare("SELECT COUNT(*) as count FROM analytics_inventories_snapshot WHERE snapshot_id = :id");
$inventoriesStmt->execute([':id' => $snapshotId]);
$inventoriesCount = $inventoriesStmt->fetch(PDO::FETCH_ASSOC)['count'];

$marketStmt = $pdo->prepare("SELECT COUNT(*) as count FROM analytics_market_snapshot WHERE snapshot_id = :id");
$marketStmt->execute([':id' => $snapshotId]);
$marketCount = $marketStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get sample data
$sampleContentItems = $pdo->prepare("SELECT * FROM analytics_content_items_snapshot WHERE snapshot_id = :id LIMIT 10");
$sampleContentItems->execute([':id' => $snapshotId]);
$contentItemsSample = $sampleContentItems->fetchAll(PDO::FETCH_ASSOC);

$sampleInventories = $pdo->prepare("SELECT * FROM analytics_inventories_snapshot WHERE snapshot_id = :id LIMIT 10");
$sampleInventories->execute([':id' => $snapshotId]);
$inventoriesSample = $sampleInventories->fetchAll(PDO::FETCH_ASSOC);

// Get inventory money totals
$moneyTotals = $pdo->prepare("
    SELECT 
        SUM(Inventory_Money) as total_money,
        COUNT(*) as inventory_count
    FROM analytics_inventories_snapshot 
    WHERE snapshot_id = :id
");
$moneyTotals->execute([':id' => $snapshotId]);
$moneyData = $moneyTotals->fetch(PDO::FETCH_ASSOC);

// Get ammo counts (items with 'Mag' or 'Rnd' in the name)
$ammoQuery = $pdo->prepare("
    SELECT 
        Item_Class,
        SUM(Item_Quantity) as total_quantity
    FROM analytics_content_items_snapshot 
    WHERE snapshot_id = :id 
    AND (Item_Class LIKE '%Mag%' OR Item_Class LIKE '%Rnd%' OR Item_Class LIKE '%Round%')
    GROUP BY Item_Class
    ORDER BY total_quantity DESC
    LIMIT 20
");
$ammoQuery->execute([':id' => $snapshotId]);
$ammoData = $ammoQuery->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="adminViewSnap-container">
    <div class="adminViewSnap-header">
        <h2>Snapshot #<?php echo $snapshot['snapshot_id']; ?></h2>
        <div class="adminViewSnap-meta">
            <span><strong>Date:</strong> <?php echo $snapshot['snapshot_date']; ?></span>
            <span><strong>Description:</strong> <?php echo htmlspecialchars($snapshot['snapshot_description']); ?></span>
            <span><strong>Created:</strong> <?php echo $snapshot['created_at']; ?></span>
        </div>
    </div>
    
    <div class="adminViewSnap-stats-grid">
        <div class="adminViewSnap-stat-box">
            <div class="adminViewSnap-stat-label">Content Items</div>
            <div class="adminViewSnap-stat-value"><?php echo number_format($contentItemsCount); ?></div>
        </div>
        <div class="adminViewSnap-stat-box">
            <div class="adminViewSnap-stat-label">Inventories</div>
            <div class="adminViewSnap-stat-value"><?php echo number_format($inventoriesCount); ?></div>
        </div>
        <div class="adminViewSnap-stat-box">
            <div class="adminViewSnap-stat-label">Market Items</div>
            <div class="adminViewSnap-stat-value"><?php echo number_format($marketCount); ?></div>
        </div>
        <div class="adminViewSnap-stat-box gold">
            <div class="adminViewSnap-stat-label">Total Money</div>
            <div class="adminViewSnap-stat-value"><?php echo number_format($moneyData['total_money'] ?? 0); ?> Cr</div>
        </div>
    </div>
    
    <?php if ($contentItemsCount === 0 && $inventoriesCount === 0): ?>
        <div class="adminViewSnap-warning">
            ⚠ï¸ <strong>Warning:</strong> This snapshot has no content items or inventories. 
            This will cause empty data in the trends view. The SQL file may not have contained the expected INSERT statements.
        </div>
    <?php endif; ?>
    
    <div class="adminViewSnap-section">
        <h3>ðŸ’° Money by Inventory</h3>
        <?php if ($inventoriesCount > 0): ?>
            <table class="adminViewSnap-table">
                <thead>
                    <tr>
                        <th>Inventory ID</th>
                        <th>Name</th>
                        <th>Money</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventoriesSample as $inv): ?>
                        <tr>
                            <td><?php echo $inv['Inventory_Id']; ?></td>
                            <td><?php echo htmlspecialchars($inv['Inventory_Name']); ?></td>
                            <td><?php echo number_format($inv['Inventory_Money']); ?> Cr</td>
                            <td><?php echo $inv['Inventory_Type'] ?? 'N/A'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($inventoriesCount > 10): ?>
                <div class="adminViewSnap-footer-text">Showing 10 of <?php echo number_format($inventoriesCount); ?> inventories</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="adminViewSnap-no-data">No inventory data in this snapshot.</div>
        <?php endif; ?>
    </div>
    
    <div class="adminViewSnap-section">
        <h3>ðŸ”« Top Ammunition (by quantity)</h3>
        <?php if (!empty($ammoData)): ?>
            <table class="adminViewSnap-table">
                <thead>
                    <tr>
                        <th>Item Class</th>
                        <th>Total Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ammoData as $ammo): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ammo['Item_Class']); ?></td>
                            <td><?php echo number_format($ammo['total_quantity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="adminViewSnap-no-data">No ammunition data found in this snapshot.</div>
        <?php endif; ?>
    </div>
    
    <div class="adminViewSnap-section">
        <h3>ðŸ“¦ Sample Content Items</h3>
        <?php if ($contentItemsCount > 0): ?>
            <table class="adminViewSnap-table">
                <thead>
                    <tr>
                        <th>Item ID</th>
                        <th>Inventory ID</th>
                        <th>Item Class</th>
                        <th>Quantity</th>
                        <th>Properties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contentItemsSample as $item): ?>
                        <tr>
                            <td><?php echo $item['Content_Item_Id']; ?></td>
                            <td><?php echo $item['Inventory_Id']; ?></td>
                            <td><?php echo htmlspecialchars($item['Item_Class']); ?></td>
                            <td><?php echo $item['Item_Quantity']; ?></td>
                            <td><?php echo htmlspecialchars($item['Item_Properties'] ?? 'NULL'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($contentItemsCount > 10): ?>
                <div class="adminViewSnap-footer-text">Showing 10 of <?php echo number_format($contentItemsCount); ?> content items</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="adminViewSnap-no-data">No content items in this snapshot.</div>
        <?php endif; ?>
    </div>
    
    <div class="adminViewSnap-actions">
        <button class="btn-industrial" onclick="window.location.href='uploadSnapshot.php'">â† Back to Upload</button>
        <button class="btn-industrial primary" onclick="window.location.href='/subdomains/analytics/trends.php'">View in Trends →</button>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
