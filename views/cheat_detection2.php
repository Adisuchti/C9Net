<?php
require_once '../db/connection.php';

// Count all unique users who have submitted addon data.
$totalUsersQuery = "
    SELECT COUNT(DISTINCT `SteamUid`) AS Total_Users
    FROM addon_list
";
$totalUsersStmt = $pdo->prepare($totalUsersQuery);
$totalUsersStmt->execute();
$totalUsers = (int) ($totalUsersStmt->fetchColumn() ?: 0);

// 1. Any mod not installed by every user (ignore hash by truncating at ':').
$partialMods = [];
if ($totalUsers > 0) {
    $partialModsQuery = "
        SELECT
            p.Mod_Name,
            COUNT(DISTINCT p.SteamUid) AS Users_With_Mod,
            GROUP_CONCAT(DISTINCT p.SteamUid ORDER BY p.SteamUid SEPARATOR ', ') AS User_List
        FROM (
            SELECT
                `SteamUid` AS SteamUid,
                TRIM(
                    CASE
                        WHEN INSTR(`Mod`, CHAR(58)) > 0 THEN LEFT(`Mod`, INSTR(`Mod`, CHAR(58)) - 1)
                        ELSE `Mod`
                    END
                ) AS Mod_Name
            FROM addon_list
            WHERE `Mod` IS NOT NULL
              AND `Mod` <> ''
        ) p
        GROUP BY p.Mod_Name
        HAVING COUNT(DISTINCT p.SteamUid) < ?
        ORDER BY Users_With_Mod ASC, p.Mod_Name ASC
    ";
    $partialModsStmt = $pdo->prepare($partialModsQuery);
    $partialModsStmt->bindValue(1, $totalUsers, PDO::PARAM_INT);
    $partialModsStmt->execute();
    $partialMods = $partialModsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Hashes unique to a single user for the same mod (potentially user-edited).
$uniqueHashesQuery = "
    SELECT
        d.Mod_Name,
        d.Hash_Value,
        GROUP_CONCAT(DISTINCT d.SteamUid ORDER BY d.SteamUid SEPARATOR ', ') AS User_List
    FROM (
        SELECT DISTINCT
            `SteamUid` AS SteamUid,
            TRIM(LEFT(`Mod`, INSTR(`Mod`, CHAR(58)) - 1)) AS Mod_Name,
            TRIM(SUBSTRING(`Mod`, INSTR(`Mod`, CHAR(58)) + 1)) AS Hash_Value
        FROM addon_list
        WHERE INSTR(`Mod`, CHAR(58)) > 0
          AND LOWER(TRIM(`SteamUid`)) <> 'server'
    ) d
    INNER JOIN (
        SELECT
            u.Mod_Name,
            u.Hash_Value
        FROM (
            SELECT DISTINCT
                `SteamUid` AS SteamUid,
                TRIM(LEFT(`Mod`, INSTR(`Mod`, CHAR(58)) - 1)) AS Mod_Name,
                TRIM(SUBSTRING(`Mod`, INSTR(`Mod`, CHAR(58)) + 1)) AS Hash_Value
            FROM addon_list
            WHERE INSTR(`Mod`, CHAR(58)) > 0
              AND LOWER(TRIM(`SteamUid`)) <> 'server'
        ) u
        GROUP BY u.Mod_Name, u.Hash_Value
        HAVING COUNT(DISTINCT u.SteamUid) = 1
    ) unique_hash ON unique_hash.Mod_Name = d.Mod_Name
                 AND unique_hash.Hash_Value = d.Hash_Value
    GROUP BY d.Mod_Name, d.Hash_Value
    ORDER BY d.Mod_Name ASC, d.Hash_Value ASC
";
$uniqueHashesStmt = $pdo->prepare($uniqueHashesQuery);
$uniqueHashesStmt->execute();
$uniqueHashes = $uniqueHashesStmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/error.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C9 - Cheat Detection (Modlists)</title>
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
        .cheat-table th, .cheat-table td { padding: 10px; text-align: left; border-bottom: 1px solid #333; color: #ddd; vertical-align: top; }
        .cheat-table th { background: #222; font-weight: bold; }
        .warning-text { color: #ffaa00; font-size: 0.9em; margin-bottom: 15px; }
        .mod-name { color: #99ddff; word-break: break-word; }
        .hash-value { color: #ffcc88; font-family: monospace; word-break: break-all; }
    </style>
</head>
<body>
    <div class="cheat-container">
        <h1>Modlist Integrity Checks</h1>

        <div class="cheat-section">
            <h2>Mods Not Installed By Everyone</h2>
            <p class="warning-text">
                Ignores hash values and compares only the mod path/name (text before <code>:</code>). Total users detected: <strong><?= htmlspecialchars((string) $totalUsers) ?></strong>.
            </p>

            <?php if ($totalUsers === 0): ?>
                <p>No addon_list data found yet.</p>
            <?php elseif (count($partialMods) > 0): ?>
                <table class="cheat-table">
                    <thead>
                        <tr>
                            <th>Mod (Without Hash)</th>
                            <th>Users With Mod</th>
                            <th>User List</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($partialMods as $row): ?>
                            <tr>
                                <td class="mod-name"><?= htmlspecialchars($row['Mod_Name']) ?></td>
                                <td><?= htmlspecialchars($row['Users_With_Mod']) ?> / <?= htmlspecialchars((string) $totalUsers) ?></td>
                                <td><?= htmlspecialchars($row['User_List'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Every detected mod appears on every user.</p>
            <?php endif; ?>
        </div>

        <div class="cheat-section">
            <h2>Unique Hashes Per Mod (Single-User Hashes)</h2>
            <p class="warning-text">
                Shows hash values that are present for only one user on a given mod name. These can indicate user-edited mod files.
            </p>

            <?php if (count($uniqueHashes) > 0): ?>
                <table class="cheat-table">
                    <thead>
                        <tr>
                            <th>Mod (Without Hash)</th>
                            <th>Unique Hash</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uniqueHashes as $row): ?>
                            <tr>
                                <td class="mod-name"><?= htmlspecialchars($row['Mod_Name']) ?></td>
                                <td class="hash-value"><?= htmlspecialchars($row['Hash_Value']) ?></td>
                                <td><?= htmlspecialchars($row['User_List']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No single-user unique hashes detected.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

<?php include '../includes/footer.php'; ?>