<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    header('Location: invalidPermissions.php');
    exit();
}

// Get filter from URL parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Get all tables in the database
$tablesQuery = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT IN ('hiddenLogs', 'messages', 'notes')";
$tablesStmt = $pdo->prepare($tablesQuery);
$tablesStmt->execute();
$allTables = $tablesStmt->fetchAll();

// Separate tables by prefix
$phpbbTables = [];
$customTables = [];

foreach ($allTables as $table) {
    $tableName = $table['TABLE_NAME'];
    if (strpos($tableName, 'phpbb_') === 0) {
        $phpbbTables[] = $tableName;
    } else {
        $customTables[] = $tableName;
    }
}

// Filter tables based on selection
$tablesToDisplay = [];
if ($filter === 'phpbb') {
    $tablesToDisplay = $phpbbTables;
} elseif ($filter === 'custom') {
    $tablesToDisplay = $customTables;
} else {
    $tablesToDisplay = array_merge($customTables, $phpbbTables);
}

// Get table schemas for filtered tables
$tableSchemas = [];
foreach ($tablesToDisplay as $tableName) {
    $schemaQuery = "DESCRIBE " . $tableName;
    $schemaStmt = $pdo->prepare($schemaQuery);
    $schemaStmt->execute();
    $tableSchemas[$tableName] = $schemaStmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Insight</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="analytics.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="analytics-container">
        <div class="analytics-header">
            <h1>Database Insight</h1>
            <div class="nav-buttons">
                <button class="btn" onclick="location.href='index.php'">Back to Dashboard</button>
            </div>
        </div>

        <div class="filter-container">
            <span class="filter-label">Filter:</span>
            <button class="btn-filter <?php echo $filter === 'all' ? 'active' : ''; ?>" onclick="location.href='?filter=all'">
                All Tables
            </button>
            <button class="btn-filter <?php echo $filter === 'custom' ? 'active' : ''; ?>" onclick="location.href='?filter=custom'">
                PIMS/C9Web Tables
            </button>
            <button class="btn-filter <?php echo $filter === 'phpbb' ? 'active' : ''; ?>" onclick="location.href='?filter=phpbb'">
                phpBB Tables
            </button>
            <span class="table-count"><?php echo count($tableSchemas); ?> table<?php echo count($tableSchemas) !== 1 ? 's' : ''; ?></span>
        </div>

        <div class="tables-list">
            <?php foreach ($tableSchemas as $tableName => $columns): ?>
            <div class="table-card">
                <div class="table-card-title"><?php echo htmlspecialchars($tableName); ?></div>
                <div class="table-card-info">
                    <strong>Columns:</strong> <?php echo count($columns); ?>
                </div>
                
                <table class="schema-table">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Type</th>
                            <th>Null</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($columns as $column): ?>
                        <tr>
                            <td>
                                <?php 
                                $colName = htmlspecialchars($column['Field']);
                                echo ($column['Key'] === 'PRI') ? '<span class="primary-key">🔑 ' . $colName . '</span>' : $colName;
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($column['Type']); ?></td>
                            <td><?php echo htmlspecialchars($column['Null']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <a href="table-detail.php?table=<?php echo urlencode($tableName); ?>" class="view-details-btn">
                    View Details →
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
