<?php

require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once 'helpers.php';

// Simple admin login screen
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (authenticate($username, $password) && $_SESSION['user_id'] === -1) {
            header('Location: manage.php');
            exit();
        } else {
            $loginError = 'Invalid admin credentials.';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="analytics.css?v=<?php echo time(); ?>">
    </head>
    <body>
        <div class="login-container">
            <h2>Admin Login</h2>
            <?php if ($loginError): ?>
                <div class="error">✗ <?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" name="admin_login" class="btn">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Handle snapshot creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_snapshot'])) {
    try {
        $pdo->beginTransaction();
        
        $description = isset($_POST['description']) ? trim($_POST['description']) : 'Manual snapshot';
        $snapshotDate = date('Y-m-d H:i:s');
        
        // Create snapshot record
        $snapshotStmt = $pdo->prepare("
            INSERT INTO analytics_snapshots (snapshot_date, snapshot_description)
            VALUES (:snapshot_date, :description)
        ");
        
        $snapshotStmt->execute([
            ':snapshot_date' => $snapshotDate,
            ':description' => $description
        ]);
        
        $snapshotId = $pdo->lastInsertId();
        
        // Save content_items snapshot
        $contentItemsStmt = $pdo->prepare("
            INSERT INTO analytics_content_items_snapshot 
            (snapshot_id, Content_Item_Id, Inventory_Id, Item_Class, Item_Quantity, Item_Properties)
            SELECT 
                :snapshot_id,
                Content_Item_Id,
                Inventory_Id,
                Item_Class,
                Item_Quantity,
                Item_Properties
            FROM content_items
        ");
        $contentItemsStmt->execute([':snapshot_id' => $snapshotId]);
        
        // Save inventories snapshot
        $inventoriesStmt = $pdo->prepare("
            INSERT INTO analytics_inventories_snapshot 
            (snapshot_id, Inventory_Id, Inventory_Name, Inventory_Money, Inventory_Type)
            SELECT 
                :snapshot_id,
                Inventory_Id,
                Inventory_Name,
                Inventory_Money,
                Inventory_Type
            FROM inventories
        ");
        $inventoriesStmt->execute([':snapshot_id' => $snapshotId]);
        
        // Save market snapshot
        $marketStmt = $pdo->prepare("
            INSERT INTO analytics_market_snapshot 
            (snapshot_id, Market_item_Id, Market_Item_Class, Purchase_Price, Selling_Price, Market_Item_Type, Available_Quantity, market_description, tier, Ammo_Count, Compatible_Items, Market, DLC, Visible)
            SELECT 
                :snapshot_id,
                Market_item_Id,
                Market_Item_Class,
                Purchase_Price,
                Selling_Price,
                Market_Item_Type,
                Available_Quantity,
                market_description,
                tier,
                Ammo_Count,
                Compatible_Items,
                Market,
                DLC,
                Visible
            FROM market
        ");
        $marketStmt->execute([':snapshot_id' => $snapshotId]);
        
        $pdo->commit();
        
        $successMessage = "Snapshot created successfully! (ID: $snapshotId)";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMessage = "Error creating snapshot: " . $e->getMessage();
    }
}

// Handle snapshot deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_snapshot'])) {
    try {
        $deleteId = (int)$_POST['snapshot_id'];
        $stmt = $pdo->prepare("DELETE FROM analytics_snapshots WHERE snapshot_id = :id");
        $stmt->execute([':id' => $deleteId]);
        $successMessage = "Snapshot deleted successfully!";
    } catch (Exception $e) {
        $errorMessage = "Error deleting snapshot: " . $e->getMessage();
    }
}

// Get all snapshots
$snapshots = getAvailableSnapshots($pdo);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Snapshot Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="analytics.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="manager-container">
        <div class="page-header">
            <h1>Snapshot Manager</h1>
            <a href="index.php" class="btn">← Back to Analytics</a>
        </div>

        <?php if (isset($successMessage)): ?>
        <div class="alert alert-success">
            ✓ <?php echo htmlspecialchars($successMessage); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($errorMessage)): ?>
        <div class="alert alert-error">
            ✗ <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        <?php endif; ?>

        <div class="create-form">
            <h2>Create New Snapshot</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="description">Description (Optional):</label>
                    <input type="text" 
                           id="description" 
                           name="description" 
                           placeholder="e.g., Before major update, End of month, etc."
                           maxlength="255">
                </div>
                <button type="submit" name="create_snapshot" class="btn">Create Snapshot Now</button>
            </form>
        </div>

        <div class="snapshots-table">
            <h2>Existing Snapshots</h2>
            <?php if (empty($snapshots)): ?>
                <p>No snapshots yet. Create your first snapshot above!</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($snapshots as $snapshot): ?>
                    <tr>
                        <td><?php echo $snapshot['snapshot_id']; ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($snapshot['snapshot_date'])); ?></td>
                        <td><?php echo htmlspecialchars($snapshot['snapshot_description'] ?: 'N/A'); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($snapshot['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="index.php?snapshot=<?php echo $snapshot['snapshot_id']; ?>" 
                                   class="btn btn-small">View</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this snapshot? This cannot be undone.');">
                                    <input type="hidden" name="snapshot_id" value="<?php echo $snapshot['snapshot_id']; ?>">
                                    <button type="submit" name="delete_snapshot" class="btn btn-small btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
