<?php
require_once '../../db/connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    header('Location: invalidPermissions.php');
    exit();
}

// Get table name from query parameter
if (!isset($_GET['table']) || empty($_GET['table'])) {
    die('Table name not specified.');
}

$tableName = $_GET['table'];

// Validate table name (prevent SQL injection)
$validTables = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll();
$validTableNames = array_map(fn($t) => $t['TABLE_NAME'], $validTables);

if (!in_array($tableName, $validTableNames)) {
    die('Invalid table name.');
}

// Get table schema
$schemaQuery = "DESCRIBE " . $tableName;
$schemaStmt = $pdo->prepare($schemaQuery);
$schemaStmt->execute();
$columns = $schemaStmt->fetchAll();

// Find primary key
$primaryKey = null;
foreach ($columns as $column) {
    if ($column['Key'] === 'PRI') {
        $primaryKey = $column['Field'];
        break;
    }
}

// Get all data from table, sorted by primary key (descending)
$dataQuery = "SELECT * FROM " . $tableName;
if ($primaryKey) {
    $dataQuery .= " ORDER BY " . $primaryKey . " DESC";
}

$dataStmt = $pdo->prepare($dataQuery);
$dataStmt->execute();
$tableData = $dataStmt->fetchAll();

// Get row count
$countQuery = "SELECT COUNT(*) as count FROM " . $tableName;
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute();
$rowCount = $countStmt->fetch()['count'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Table Detail - <?php echo htmlspecialchars($tableName); ?></title>
    <link rel="stylesheet" href="../../styles/styles.css?v=<?php echo time(); ?>">
    <style>
        .analytics-container {
            max-width: 1600px;
            margin: 20px auto;
            padding: 20px;
        }

        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .analytics-header h1 {
            margin: 0;
            font-size: 2em;
        }

        .table-info {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-label {
            font-weight: bold;
            color: #666;
        }

        .info-value {
            color: #2e8b57;
            font-weight: bold;
        }

        .nav-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            background-color: #2e8b57;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 1em;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #1f5a39;
        }

        .btn-secondary {
            background-color: #4169E1;
        }

        .btn-secondary:hover {
            background-color: #2a4db9;
        }

        .table-container {
            background-color: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
            max-height: 800px;
            overflow-y: auto;
        }

        .table-title {
            font-size: 1.3em;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            font-size: 0.95em;
        }

        th {
            background-color: #2e8b57;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            word-break: break-word;
        }

        tr:hover {
            background-color: #f9f9f9;
        }

        .primary-key-cell {
            color: #d9534f;
            font-weight: bold;
        }

        .null-value {
            color: #999;
            font-style: italic;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 1.2em;
        }

        @media (max-width: 768px) {
            .table-info {
                flex-direction: column;
                gap: 10px;
            }

            .analytics-header {
                flex-direction: column;
                align-items: flex-start;
            }

            table {
                font-size: 0.8em;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="analytics-container">
        <div class="analytics-header">
            <h1>📋 <?php echo htmlspecialchars($tableName); ?></h1>
            <div class="nav-buttons">
                <button class="btn btn-secondary" onclick="location.href='database-insight.php'">Back to Insight</button>
                <button class="btn" onclick="location.href='index.php'">Dashboard</button>
            </div>
        </div>

        <div class="table-info">
            <div class="info-item">
                <span class="info-label">Total Rows:</span>
                <span class="info-value"><?php echo number_format($rowCount); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Columns:</span>
                <span class="info-value"><?php echo count($columns); ?></span>
            </div>
            <?php if ($primaryKey): ?>
            <div class="info-item">
                <span class="info-label">Primary Key:</span>
                <span class="info-value"><?php echo htmlspecialchars($primaryKey); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <?php if (count($tableData) > 0): ?>
                <div class="table-title">Table Data (sorted by <?php echo htmlspecialchars($primaryKey ?? 'first column'); ?> DESC)</div>
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                            <th>
                                <?php 
                                $colName = htmlspecialchars($column['Field']);
                                echo ($column['Key'] === 'PRI') ? '🔑 ' . $colName : $colName;
                                ?>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableData as $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): 
                                $colName = $column['Field'];
                                $value = $row[$colName];
                                $isPrimaryKey = $column['Key'] === 'PRI';
                            ?>
                            <td <?php echo $isPrimaryKey ? 'class="primary-key-cell"' : ''; ?>>
                                <?php 
                                if ($value === null) {
                                    echo '<span class="null-value">NULL</span>';
                                } else if (is_bool($value)) {
                                    echo $value ? 'true' : 'false';
                                } else {
                                    $displayValue = htmlspecialchars((string)$value);
                                    if (strlen($displayValue) > 100) {
                                        $displayValue = substr($displayValue, 0, 100) . '...';
                                    }
                                    echo $displayValue;
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">No data in this table</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
