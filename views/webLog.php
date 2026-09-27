<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

$admin = false;

// Check if user is admin
if ($_SESSION['user_id'] == -1) {
    $admin = true;
}

$totalCountQuery = "SELECT COUNT(*) as total FROM web_activity_log";
$totalStmt = $pdo->query($totalCountQuery);
$totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

$numberOfRows = isset($_GET['numberOfRows']) ? $_GET['numberOfRows'] : 50;
if($numberOfRows === 'all') {
    $numberOfRows = null;
} else {
    $numberOfRows = (int)$numberOfRows;
}

// Build and execute logs query
$logsQuery = "SELECT Id, Timestamp, Activity, Link
              FROM web_activity_log";

$logsQuery .= " ORDER BY Timestamp DESC";

if ($numberOfRows !== null) {
    $logsQuery .= " LIMIT :numberOfRows";
}
$logsStmt = $pdo->prepare($logsQuery);
if ($numberOfRows !== null) {
    $logsStmt->bindValue(':numberOfRows', $numberOfRows, PDO::PARAM_INT);
}
$logsStmt->execute();
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
?>

<div class="logs-preview-container preview-container">
    <!-- Filter Form -->
    <form method="GET" class="log-filter-form">
        <div>
            <select name="numberOfRows" onchange="updateFilter(this.value)">
                <?php
                for ($i = 50; $i <= $totalCount; $i += 50):
                ?>
                    <option value="<?php echo $i; ?>" <?php echo $numberOfRows == $i ? 'selected' : ''; ?>>
                        <?php echo $i; ?> Rows
                    </option>
                <?php
                endfor; 
                ?>
                <option value="all" <?= $numberOfRows === null ? 'selected' : '' ?>>All Rows</option>
            </select>
            <button type="submit" class="btn-industrial">Filter</button>
        </div>
        <div class="ml-auto">
            <button type="button" onclick="location.href='logs.php'" class="btn-industrial">Market Logs</button>
        </div>
    </form>
    
    <div class="logs-table-container">
    <table class="logs-table">
        <thead>
            <tr>
                <th>Date GMT</th>
                <th>Activity</th>
                <th>Link</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr class="<?= $marketActivityClass ?>">
                    <td><?= htmlspecialchars($log['Timestamp']) ?></td>
                    <td><?= htmlspecialchars($log['Activity']) ?></td>
                    <td>https://cinder9.com<?= htmlspecialchars($log['Link']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">
                    Showing <?= count($logs) ?> of <?= $totalCount ?> logs
                </td>
            </tr>
        </tfoot>
    </table>
    </div>

    <script>

        function updateFilter(selectedValue) {
            const form = document.querySelector('.filter-form');
        }
    </script>
</div>

<?php include '../includes/footer.php'; ?>

