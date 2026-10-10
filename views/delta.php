<?php
require_once '../db/connection.php';

// Session-Based Delta: Shows net quantity change per session (noon-to-noon GMT) for all items.
// Unlike cheat_detection, this shows ALL items with activity, not only those with net > 0.

$deltaQuery = "
    SELECT
        TIMESTAMP(
            DATE(DATE_SUB(l.Transaction_Date, INTERVAL 12 HOUR)),
            '12:00:00'
        ) AS Session_Start,
        MIN(l.Transaction_Date) AS Session_First_Event,
        MAX(l.Transaction_Date) AS Session_Last_Event,
        l.Transaction_Inventory_Id,
        i.Inventory_Name,
        l.Transaction_Item,
        SUM(l.Transaction_Quantity) AS Net_Delta,
        COUNT(*) AS Item_Event_Count,
        SUM(CASE WHEN l.Transaction_Quantity > 0 THEN l.Transaction_Quantity ELSE 0 END) AS Total_Uploaded,
        SUM(CASE WHEN l.Transaction_Quantity < 0 THEN l.Transaction_Quantity ELSE 0 END) AS Total_Retrieved
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
    HAVING Net_Delta != 0
    ORDER BY Session_Start DESC,
             i.Inventory_Name ASC,
             l.Transaction_Item ASC
";
$deltaStmt = $pdo->prepare($deltaQuery);
$deltaStmt->execute();
$deltaRows = $deltaStmt->fetchAll(PDO::FETCH_ASSOC);

// Group into sessions
$sessionGroups = [];
foreach ($deltaRows as $row) {
    $sessionKey = $row['Session_Start'];
    if (!isset($sessionGroups[$sessionKey])) {
        $sessionGroups[$sessionKey] = [
            'Session_Start'    => $row['Session_Start'],
            'Session_First_Event' => $row['Session_First_Event'],
            'Session_Last_Event'  => $row['Session_Last_Event'],
            'rows' => []
        ];
    }

    // Keep the earliest first event and latest last event for the session summary
    if ($row['Session_First_Event'] < $sessionGroups[$sessionKey]['Session_First_Event']) {
        $sessionGroups[$sessionKey]['Session_First_Event'] = $row['Session_First_Event'];
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
    <title>C9 - Session Delta</title>
    <link rel="stylesheet" href="/styles/styles.css?t=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link href="../favicon.ico" rel="icon" type="image/x-icon">
    <style>
        .delta-container { padding: 20px; max-width: 1200px; margin: 0 auto; }
        .delta-section { margin-bottom: 40px; background: rgba(20, 20, 20, 0.8); padding: 20px; border-radius: 8px; border-left: 4px solid #99ddff; }
        .delta-section h2 { color: #99ddff; margin-top: 0; }
        .delta-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .delta-table th, .delta-table td { padding: 10px; text-align: left; border-bottom: 1px solid #333; color: #ddd; }
        .delta-table th { background: #222; font-weight: bold; }
        .warning-text { color: #ffaa00; font-size: 0.9em; margin-bottom: 15px; }
        .delta-positive { color: #44cc44; font-weight: bold; }
        .delta-negative { color: #ff6666; font-weight: bold; }
        .delta-zero { color: #888; }
        .session-header { margin-top: 30px; color: #ffaa00; }
        .session-range { color: #888; font-size: 0.85em; font-weight: normal; }
        .summary-row { background: #1a1a2e; }
    </style>
</head>
<body>
    <div class="delta-container">
        <h1>Session Delta (Last 60 Days)</h1>
        <p class="warning-text">
            Shows net item quantity changes per 24-hour session window (noon-to-noon GMT) for all in-game activity.
            Positive = more uploaded than retrieved. Negative = more retrieved than uploaded.
        </p>

        <?php if (count($sessionGroups) > 0): ?>
            <?php foreach ($sessionGroups as $session): ?>
                <?php
                    $sessionNetTotal = 0;
                    foreach ($session['rows'] as $r) {
                        $sessionNetTotal += $r['Net_Delta'];
                    }
                ?>
                <h3 class="session-header">
                    Session: <?= htmlspecialchars($session['Session_Start']) ?> GMT
                    <span class="session-range">
                        (<?= htmlspecialchars($session['Session_First_Event']) ?> – <?= htmlspecialchars($session['Session_Last_Event']) ?>)
                        · <?= count($session['rows']) ?> items
                        · Net: <span class="<?= $sessionNetTotal > 0 ? 'delta-positive' : ($sessionNetTotal < 0 ? 'delta-negative' : 'delta-zero') ?>">
                            <?= $sessionNetTotal >= 0 ? '+' : '' ?><?= htmlspecialchars((string) $sessionNetTotal) ?>
                        </span>
                    </span>
                </h3>
                <table class="delta-table">
                    <thead>
                        <tr>
                            <th>Inventory</th>
                            <th>Item Class</th>
                            <th>Events</th>
                            <th>Uploaded</th>
                            <th>Retrieved</th>
                            <th>Net Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($session['rows'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['Inventory_Name'] ?? 'Unknown ID: ' . $row['Transaction_Inventory_Id']) ?></td>
                                <td><?= htmlspecialchars($row['Transaction_Item']) ?></td>
                                <td><?= htmlspecialchars((string) $row['Item_Event_Count']) ?></td>
                                <td style="color: #44cc44;">+<?= htmlspecialchars((string) $row['Total_Uploaded']) ?></td>
                                <td style="color: #ff6666;"><?= htmlspecialchars((string) $row['Total_Retrieved']) ?></td>
                                <td class="<?= $row['Net_Delta'] > 0 ? 'delta-positive' : ($row['Net_Delta'] < 0 ? 'delta-negative' : 'delta-zero') ?>">
                                    <?= $row['Net_Delta'] >= 0 ? '+' : '' ?><?= htmlspecialchars((string) $row['Net_Delta']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No session delta data available for the last 60 days.</p>
        <?php endif; ?>

        <p style="margin-top: 24px;">
            <a href="/views/cheat_detection.php" style="color: #ffaa00;">← Back to Cheat Detection</a>
        </p>
    </div>
</body>
</html>

<?php include '../includes/footer.php'; ?>