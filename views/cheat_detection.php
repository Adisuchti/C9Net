<?php
require_once '../db/connection.php';

// 1. Detect Money Injections
// Looks for positive MONEY transactions that happened in-game (isMarketActivity = 0)
$moneyCheatsQuery = "
    SELECT 
        l.Transaction_Inventory_Id, 
        i.Inventory_Name, 
        l.Transaction_Date, 
        l.Transaction_Quantity, 
        l.Comment
    FROM logs l
    LEFT JOIN inventories i ON l.Transaction_Inventory_Id = i.Inventory_Id
    WHERE l.Transaction_Item = 'MONEY' 
      AND l.isMarketActivity = 0 
      AND l.Transaction_Quantity > 0
      AND l.Transaction_Date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
    ORDER BY l.Transaction_Date DESC
";
$moneyStmt = $pdo->prepare($moneyCheatsQuery);
$moneyStmt->execute();
$moneyCheats = $moneyStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Detect Item Duplication and Looting
// Sums up all in-game activity per item per inventory. 
// If the sum > 0, they uploaded more than they ever retrieved.
$itemCheatsQuery = "
    SELECT 
        l.Transaction_Inventory_Id, 
        i.Inventory_Name, 
        l.Transaction_Item, 
        SUM(l.Transaction_Quantity) as Net_Illicit_Quantity
    FROM logs l
    LEFT JOIN inventories i ON l.Transaction_Inventory_Id = i.Inventory_Id
    WHERE l.isMarketActivity = 0 
      AND l.Transaction_Item != 'MONEY'
      AND l.Transaction_Date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
    GROUP BY l.Transaction_Inventory_Id, l.Transaction_Item
    HAVING Net_Illicit_Quantity > 0
    ORDER BY Net_Illicit_Quantity DESC, i.Inventory_Name ASC
";
$itemStmt = $pdo->prepare($itemCheatsQuery);
$itemStmt->execute();
$itemCheats = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Detect Session-Based Anomalies
// Buckets activity into midday-to-midday (24-hour) windows and shows per-item net gains in each session.
$sessionCheatsQuery = "
    SELECT
        TIMESTAMP(
            DATE(DATE_SUB(l.Transaction_Date, INTERVAL 12 HOUR)),
            '12:00:00'
        ) AS Session_Start,
        MAX(l.Transaction_Date) AS Session_Last_Event,
        l.Transaction_Inventory_Id,
        i.Inventory_Name,
        l.Transaction_Item,
        SUM(l.Transaction_Quantity) AS Net_Unretrieved_Quantity,
        COUNT(*) AS Item_Event_Count
    FROM logs l
    LEFT JOIN inventories i ON l.Transaction_Inventory_Id = i.Inventory_Id
    WHERE l.isMarketActivity = 0
      AND l.Transaction_Item != 'MONEY'
      AND l.Transaction_Date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
    GROUP BY
                DATE(DATE_SUB(l.Transaction_Date, INTERVAL 12 HOUR)),
        l.Transaction_Inventory_Id,
        i.Inventory_Name,
        l.Transaction_Item
    HAVING SUM(l.Transaction_Quantity) > 0
    ORDER BY Session_Start DESC,
             i.Inventory_Name ASC,
             Net_Unretrieved_Quantity DESC
";
$sessionStmt = $pdo->prepare($sessionCheatsQuery);
$sessionStmt->execute();
$sessionCheats = $sessionStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. For each session anomaly, check if an admin remedied the item after the session ended
// Remediation = admin action (isMarketActivity = 1, Comment = 'Admin action') with negative quantity
// at any point after the session's last event
$remediationQuery = "
    SELECT
        l.Transaction_Inventory_Id,
        l.Transaction_Item,
        TIMESTAMP(
            DATE(DATE_SUB(base.Session_Last_Event, INTERVAL 12 HOUR)),
            '12:00:00'
        ) AS Session_Start,
        SUM(l.Transaction_Quantity) AS Remedied_Quantity,
        MIN(l.Transaction_Date) AS First_Remedy_Date
    FROM logs l
    INNER JOIN (
        SELECT
            Transaction_Inventory_Id,
            Transaction_Item,
            TIMESTAMP(
                DATE(DATE_SUB(Transaction_Date, INTERVAL 12 HOUR)),
                '12:00:00'
            ) AS Session_Start,
            MAX(Transaction_Date) AS Session_Last_Event
        FROM logs
        WHERE isMarketActivity = 0
          AND Transaction_Item != 'MONEY'
          AND Transaction_Date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        GROUP BY
            DATE(DATE_SUB(Transaction_Date, INTERVAL 12 HOUR)),
            Transaction_Inventory_Id,
            Transaction_Item
        HAVING SUM(Transaction_Quantity) > 0
    ) base ON l.Transaction_Inventory_Id = base.Transaction_Inventory_Id
           AND l.Transaction_Item = base.Transaction_Item
           AND l.Transaction_Date > base.Session_Last_Event
    WHERE l.isMarketActivity = 1
      AND l.Comment = 'Admin action'
      AND l.Transaction_Quantity < 0
    GROUP BY l.Transaction_Inventory_Id, l.Transaction_Item, base.Session_Start
";
$remediationStmt = $pdo->prepare($remediationQuery);
$remediationStmt->execute();
$remediationRows = $remediationStmt->fetchAll(PDO::FETCH_ASSOC);

// Build a lookup: [session_start][inventory_id][item] => remediation info
$remediationMap = [];
foreach ($remediationRows as $r) {
    $remediationMap[$r['Session_Start']][$r['Transaction_Inventory_Id']][$r['Transaction_Item']] = $r;
}

$sessionGroups = [];
foreach ($sessionCheats as $row) {
    $sessionKey = $row['Session_Start'];
    if (!isset($sessionGroups[$sessionKey])) {
        $sessionGroups[$sessionKey] = [
            'Session_Start' => $row['Session_Start'],
            'Session_Last_Event' => $row['Session_Last_Event'],
            'rows' => []
        ];
    }

    if ($row['Session_Last_Event'] > $sessionGroups[$sessionKey]['Session_Last_Event']) {
        $sessionGroups[$sessionKey]['Session_Last_Event'] = $row['Session_Last_Event'];
    }

    $sessionGroups[$sessionKey]['rows'][] = $row;
}

include '../includes/error.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C9 - Cheat Detection</title>
    <link rel="stylesheet" href="/styles/styles.css?t=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link href="../favicon.ico" rel="icon" type="image/x-icon">
    <style>
        .cheat-container { padding: 20px; max-width: 1200px; margin: 0 auto; }
        .cheat-section { margin-bottom: 40px; background: rgba(20, 20, 20, 0.8); padding: 20px; border-radius: 8px; border-left: 4px solid #ff4444; }
        .cheat-section h2 { color: #ff4444; margin-top: 0; }
        .cheat-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .cheat-table th, .cheat-table td { padding: 10px; text-align: left; border-bottom: 1px solid #333; color: #ddd; }
        .cheat-table th { background: #222; font-weight: bold; }
        .warning-text { color: #ffaa00; font-size: 0.9em; margin-bottom: 15px; }
        .remedied-badge { color: #44cc44; font-weight: bold; }
        .not-remedied-badge { color: #ff4444; }
    </style>
</head>
<body>
    <div class="cheat-container">
        <h1>Anomaly & Cheat Detection for the Last 60 Days</h1>
        
        <div class="cheat-section">
            <h2>Suspicious Money Activity</h2>
            <p class="warning-text">Flags any logs where MONEY was added to an inventory from an in-game source (not the market) within the last 60 days.</p>
            
            <?php if (count($moneyCheats) > 0): ?>
                <table class="cheat-table">
                    <thead>
                        <tr>
                            <th>Date (GMT)</th>
                            <th>Inventory Name</th>
                            <th>Quantity Uploaded</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($moneyCheats as $cheat): ?>
                            <tr>
                                <td><?= htmlspecialchars($cheat['Transaction_Date']) ?></td>
                                <td><?= htmlspecialchars($cheat['Inventory_Name'] ?? 'Unknown ID: ' . $cheat['Transaction_Inventory_Id']) ?></td>
                                <td style="color: #ff4444;">+<?= htmlspecialchars($cheat['Transaction_Quantity']) ?></td>
                                <td><?= htmlspecialchars($cheat['Comment'] ?? 'None') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No suspicious money injections detected.</p>
            <?php endif; ?>
        </div>

        <div class="cheat-section">
            <h2>Illegal Looting & Duplication</h2>
            <p class="warning-text">Flags players whose lifetime in-game uploads for a specific item exceed their lifetime in-game retrievals. (i.e. bringing back items they didn't take in) within the last 60 days.</p>
            
            <?php if (count($itemCheats) > 0): ?>
                <table class="cheat-table">
                    <thead>
                        <tr>
                            <th>Inventory Name</th>
                            <th>Item Class</th>
                            <th>Net Illicit Quantity Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itemCheats as $cheat): ?>
                            <tr>
                                <td><?= htmlspecialchars($cheat['Inventory_Name'] ?? 'Unknown ID: ' . $cheat['Transaction_Inventory_Id']) ?></td>
                                <td><?= htmlspecialchars($cheat['Transaction_Item']) ?></td>
                                <td style="color: #ff4444;">+<?= htmlspecialchars($cheat['Net_Illicit_Quantity']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No illegal item looting or duplication detected.</p>
            <?php endif; ?>
        </div>

        <div class="cheat-section">
            <h2>Session-Based Anomalies (24-Hour Windows)</h2>
            <p class="warning-text">Subdivides anomalies by noon-to-noon (GMT) 24-hour sessions and lists, per inventory, which items had more uploads than retrievals during that same session.</p>

            <?php if (count($sessionGroups) > 0): ?>
                <?php foreach ($sessionGroups as $session): ?>
                    <h3 style="margin-top: 30px; color: #ffaa00;">
                        Session: <?= htmlspecialchars($session['Session_Start']) ?> to <?= htmlspecialchars($session['Session_Last_Event']) ?> GMT
                    </h3>
                    <table class="cheat-table">
                        <thead>
                            <tr>
                                <th>Inventory Name</th>
                                <th>Item Class</th>
                                <th>Net Unretrieved Uploads</th>
                                <th>Events For Item</th>
                                <th>Admin Remedied?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($session['rows'] as $cheat):
                                $remedy = $remediationMap[$session['Session_Start']][$cheat['Transaction_Inventory_Id']][$cheat['Transaction_Item']] ?? null;
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($cheat['Inventory_Name'] ?? 'Unknown ID: ' . $cheat['Transaction_Inventory_Id']) ?></td>
                                    <td><?= htmlspecialchars($cheat['Transaction_Item']) ?></td>
                                    <td style="color: #ff4444;">+<?= htmlspecialchars($cheat['Net_Unretrieved_Quantity']) ?></td>
                                    <td><?= htmlspecialchars($cheat['Item_Event_Count']) ?></td>
                                    <td>
                                        <?php if ($remedy): ?>
                                            <span class="remedied-badge">&#10003; Yes (<?= htmlspecialchars($remedy['Remedied_Quantity']) ?> at <?= htmlspecialchars($remedy['First_Remedy_Date']) ?>)</span>
                                        <?php else: ?>
                                            <span class="not-remedied-badge">&#10007; No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No session-based anomalies detected.</p>
            <?php endif; ?>
        </div>

        <p style="margin-top: 24px;">
            <a href="/views/cheat_detection2.php" style="color: #99ddff; font-weight: bold;">Open Modlist Cheat Detection</a>
            &nbsp;&nbsp;|&nbsp;&nbsp;
            <a href="/views/delta.php" style="color: #ffaa00; font-weight: bold;">Open Session Delta</a>
        </p>
    </div>
</body>
</html>

<?php include '../includes/footer.php'; ?>