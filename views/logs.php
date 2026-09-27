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

$totalCountQuery = "SELECT COUNT(*) as total FROM logs WHERE Transaction_Quantity != 0";
$totalStmt = $pdo->query($totalCountQuery);
$totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Fetch all inventories for the filter dropdown
$inventoryQuery = "SELECT Inventory_Id, Inventory_Name FROM inventories";
$inventoryStmt = $pdo->query($inventoryQuery);
$inventories = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected inventory filter
$selectedInventory = isset($_GET['inventory']) ? $_GET['inventory'] : '';

// Get selected activity type filter
$selectedActivityType = isset($_GET['activityType']) ? $_GET['activityType'] : "";

$numberOfRows = isset($_GET['numberOfRows']) ? $_GET['numberOfRows'] : 50;
if($numberOfRows === 'all') {
    $numberOfRows = null;
} else {
    $numberOfRows = (int)$numberOfRows;
}

// Build and execute logs query
$logsQuery = "SELECT Log_Id, Transaction_Date, Inventory_Name, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment
              FROM logs
              LEFT JOIN inventories ON Transaction_Inventory_Id = Inventory_Id 
              WHERE 1 = 1";

if ($selectedInventory) {
    $logsQuery .= " AND Inventory_Name = :inventory";
}

if ($selectedActivityType !== "") {
    $logsQuery .= " AND isMarketActivity = :activityType";
}

$logsQuery .= " ORDER BY Transaction_Date DESC";

if ($numberOfRows !== null) {
    $logsQuery .= " LIMIT :numberOfRows";
}

$logsStmt = $pdo->prepare($logsQuery);
if ($selectedInventory) {
    $logsStmt->bindParam(':inventory', $selectedInventory);
}
if ($selectedActivityType !== "") {
    $logsStmt->bindParam(':activityType', $selectedActivityType);
}
if ($numberOfRows !== null) {
    $logsStmt->bindParam(':numberOfRows', $numberOfRows, PDO::PARAM_INT);
}

$logsStmt->execute();
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
?>

<div class="logs-preview-container preview-container">
    <!-- Filter Form -->
    <form method="GET" class="log-filter-form preview-logs-1">
        <div class="preview-logs-2">
            <?php if ($admin) : ?>
            <!-- <label for="selectAll">
                <input type="checkbox" id="selectAll"> Select All
            </label> -->
            <?php endif; ?>
            <select name="inventory" onchange="updateFilter(this.value)">
                <option value="">All Inventories</option>
                <?php foreach ($inventories as $inventory): ?>
                    <option value="<?= htmlspecialchars($inventory['Inventory_Name']) ?>"
                            <?= $selectedInventory === $inventory['Inventory_Name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($inventory['Inventory_Name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="activityType" onchange="updateFilter(this.value)">
                <option value="">All Activity</option>
                <option value="1" <?= $selectedActivityType === '1' ? 'selected' : '' ?>>Market Activity</option>
                <option value="0" <?= $selectedActivityType === '0' ? 'selected' : '' ?>>In-Game Activity</option>
            </select>
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
            <button type="button" onclick="location.href='webLog.php'" class="btn-industrial">Web Log</button>
        </div>
    </form>
    
    <div class="logs-table-container">
    <!--
    <?php // if ($admin) : ?>
        <button type="submit" class="log-revert-btn">Revert Selected Transactions</button>
    <?php // endif; ?>
    -->
    <!-- Logs Table -->
    <table class="logs-table">
        <thead>
            <tr>
                <?php // if($admin) : ?>
                    <!-- <th>Select</th> -->
                <?php // endif; ?>
                <th>Date GMT</th>
                <th>Inventory</th>
                <th>Item</th>
                <th>Comment</th>
                <th>Quantity</th>
                <?php if($admin) : ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <?php
                $marketActivityClass = "";
                if($log['isMarketActivity'] == 1) {
                    $marketActivityClass = "log-market-activity";
                }
                ?>
                <tr class="<?= $marketActivityClass ?>">
                    <!--
                    <?php // if($admin) : ?>
                        <td>
                            <input type="checkbox" name="selected_logs[]" TODO make it so that admins can revert transactions
                                    value="<? //= $log['Log_Id'] ?>">
                        </td>
                    <?php // endif; ?>
                    -->
                    <?php 
                    $quantityClass = "";
                    if($log['Transaction_Quantity'] < 0) {
                        $quantityClass = "log-negative-quantity";
                    } elseif($log['Transaction_Quantity'] > 0) {
                        $quantityClass = "log-positive-quantity";
                    }
                    ?>
                    <?php
                        // Display date in user's local timezone plus 50 years
                        $date = new DateTime($log['Transaction_Date']);
                        $date->modify('+50 years');
                    ?>
                    <td><?= htmlspecialchars($date->format('H:i:s - d.m.Y')) ?></td>
                    <td><?= htmlspecialchars($log['Inventory_Name']) ?></td>
                    <td><?= htmlspecialchars($log['Transaction_Item']) ?></td>
                    <td><?= htmlspecialchars($log['Comment']) ?></td>
                    <td class="<?= $quantityClass ?>"><?= htmlspecialchars($log['Transaction_Quantity']) ?></td>
                    <?php if($admin) : ?>
                        <td class="admin-log-actions">
                            <button onclick="deleteLog(<?= $log['Log_Id'] ?>)" class="log-delete-button">
                                Delete
                            </button>
                        </td>
                    <?php endif; ?>
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
        // Select all checkbox functionality
        //document.getElementById('selectAll').addEventListener('change', function() {
        //    const checkboxes = document.getElementsByName('selected_logs[]');
        //    checkboxes.forEach(checkbox => checkbox.checked = this.checked);
        //});

        function updateFilter(selectedValue) {
            const form = document.querySelector('.filter-form');
        }

        function deleteLog(logId) {
            if (confirm('Are you sure you want to delete this log entry?')) {
                const data = { logId: logId };
                fetch('../db/deleteLog.php', {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(data)
                })
                .then(async response => {
                    const text = await response.text();
                    console.log("Raw response:", text);
                    try {
                        const json = JSON.parse(text);
                        console.log("Parsed JSON:", json);
                        if (json.success) {
                            location.reload();
                        } else {
                            showError(json.error);
                            console.error("Error:", json.error);
                            console.error("Response text:", text);
                        }
                    } catch (e) {
                        showError("JSON parse error: " + e.message);
                        console.error("JSON parse error: ", e);
                        console.error("Response text:", text);
                    }
                })
                .catch(err => showError("Fetch error: " + err.message));
            }
        }
    </script>
</div>

<?php include '../includes/footer.php'; ?>

