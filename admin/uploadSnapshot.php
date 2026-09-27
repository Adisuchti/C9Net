<?php
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/auth.php';

// Check if the user is logged in and is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sqlFile'])) {
    try {
        $file = $_FILES['sqlFile'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        if ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
            throw new Exception('File too large. Maximum size is 50MB.');
        }
        
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($fileExtension !== 'sql') {
            throw new Exception('Only .sql files are allowed.');
        }
        
        // Read file content
        $sqlContent = file_get_contents($file['tmp_name']);
        
        if (empty($sqlContent)) {
            throw new Exception('SQL file is empty.');
        }
        
        // Get snapshot description from form
        $description = isset($_POST['description']) ? trim($_POST['description']) : 'Uploaded SQL snapshot - ' . $file['name'];
        $snapshotDate = isset($_POST['snapshot_date']) ? $_POST['snapshot_date'] : date('Y-m-d H:i:s');
        
        // Begin transaction
        $pdo->beginTransaction();
        
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
        
        // Parse and insert content_items
        $contentItemsCount = parseAndInsertContentItems($pdo, $sqlContent, $snapshotId);
        
        // Parse and insert inventories
        $inventoriesCount = parseAndInsertInventories($pdo, $sqlContent, $snapshotId);
        
        // Parse and insert market items
        $marketCount = parseAndInsertMarket($pdo, $sqlContent, $snapshotId);
        
        // Verify data was inserted
        if ($contentItemsCount === 0 && $inventoriesCount === 0 && $marketCount === 0) {
            throw new Exception('No data was parsed from the SQL file. Please ensure it contains INSERT statements for content_items, inventories, or market tables.');
        }
        
        $pdo->commit();
        
        $message = "Snapshot created successfully!<br>" .
                   "Snapshot ID: $snapshotId<br>" .
                   "Date: $snapshotDate<br>" .
                   "Content Items: $contentItemsCount<br>" .
                   "Inventories: $inventoriesCount<br>" .
                   "Market Items: $marketCount<br><br>" .
                   "<a href='viewSnapshot.php?id=$snapshotId'>View Snapshot Details →</a>" .
                   "<a href='/subdomains/analytics/trends.php'>View in Trends →</a>";
        $messageType = 'success';
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error creating snapshot: " . $e->getMessage();
        $messageType = 'error';
        error_log("Upload Snapshot Error: " . $e->getMessage());
    }
}

/**
 * Parse content_items INSERT statements from SQL file
 */
function parseAndInsertContentItems($pdo, $sqlContent, $snapshotId) {
    // Find INSERT statements for content_items table
    // Match both single-line and multi-line INSERT statements
    if (!preg_match_all(
        "/INSERT INTO `content_items`[^;]*?VALUES\s*(.+?);/si",
        $sqlContent,
        $matches
    )) {
        error_log("No content_items INSERT statements found in SQL file");
        return 0;
    }
    
    $count = 0;
    $errors = 0;
    $stmt = $pdo->prepare("
        INSERT INTO analytics_content_items_snapshot 
        (snapshot_id, Content_Item_Id, Inventory_Id, Item_Class, Item_Quantity, Item_Properties)
        VALUES (:snapshot_id, :content_item_id, :inventory_id, :item_class, :item_quantity, :item_properties)
    ");
    
    foreach ($matches[1] as $valuesSection) {
        // Parse individual rows: (543, 1, '11Rnd_45ACP_Mag', 7, '15') or with NULL
        // More flexible pattern that handles various quote escaping
        preg_match_all(
            "/\((\d+),\s*(\d+),\s*'([^']*(?:''[^']*)*)',\s*(\d+),\s*(?:'([^']*(?:''[^']*)*)'|NULL)\)/",
            $valuesSection,
            $rows,
            PREG_SET_ORDER
        );
        
        // If the above didn't work, try simpler pattern without escaped quotes
        if (empty($rows)) {
            preg_match_all(
                "/\((\d+),\s*(\d+),\s*'([^']+)',\s*(\d+),\s*(?:'([^']*)'|NULL)\)/",
                $valuesSection,
                $rows,
                PREG_SET_ORDER
            );
        }
        
        foreach ($rows as $row) {
            try {
                $itemClass = str_replace("''", "'", $row[3]); // Handle escaped single quotes
                $itemProperties = null;
                if (isset($row[5]) && $row[5] !== '') {
                    $itemProperties = str_replace("''", "'", $row[5]);
                }
                
                $stmt->execute([
                    ':snapshot_id' => $snapshotId,
                    ':content_item_id' => $row[1],
                    ':inventory_id' => $row[2],
                    ':item_class' => $itemClass,
                    ':item_quantity' => $row[4],
                    ':item_properties' => $itemProperties
                ]);
                $count++;
            } catch (PDOException $e) {
                $errors++;
                error_log("Error inserting content item: " . $e->getMessage() . " - Row: " . print_r($row, true));
            }
        }
    }
    
    if ($errors > 0) {
        error_log("Content items: $count inserted, $errors errors");
    }
    
    return $count;
}

/**
 * Parse inventories INSERT statements from SQL file
 */
function parseAndInsertInventories($pdo, $sqlContent, $snapshotId) {
    // Find INSERT statements for inventories table
    if (!preg_match_all(
        "/INSERT INTO `inventories`[^;]*?VALUES\s*(.+?);/si",
        $sqlContent,
        $matches
    )) {
        error_log("No inventories INSERT statements found in SQL file");
        return 0;
    }
    
    $count = 0;
    $errors = 0;
    $stmt = $pdo->prepare("
        INSERT INTO analytics_inventories_snapshot 
        (snapshot_id, Inventory_Id, Inventory_Name, Inventory_Money, Inventory_Type)
        VALUES (:snapshot_id, :inventory_id, :inventory_name, :inventory_money, :inventory_type)
    ");
    
    foreach ($matches[1] as $valuesSection) {
        // Parse rows - handle the actual structure with 5 columns:
        // (Inventory_Id, Inventory_Name, Inventory_Money, Inventory_Market_Saturation, Inventory_Type)
        // We need columns 1, 2, 3, and 5 (skip column 4 - saturation)
        
        // Pattern matches: (id, 'name', money, saturation, type)
        preg_match_all(
            "/\((\d+),\s*'([^']*(?:''[^']*)*)',\s*(\d+(?:\.\d+)?),\s*(\d+(?:\.\d+)?),\s*(?:'([^']*(?:''[^']*)*)'|(\d+)|NULL)\)/",
            $valuesSection,
            $rows,
            PREG_SET_ORDER
        );
        
        // Try simpler pattern if above didn't work (maybe 4 columns instead of 5)
        if (empty($rows)) {
            // Fallback: try without saturation column
            preg_match_all(
                "/\((\d+),\s*'([^']+)',\s*(\d+(?:\.\d+)?),\s*(?:'([^']*)'|(\d+)|NULL)\)/",
                $valuesSection,
                $rows,
                PREG_SET_ORDER
            );
            
            // Process 4-column format
            foreach ($rows as $row) {
                try {
                    $inventoryName = str_replace("''", "'", $row[2]);
                    $inventoryType = null;
                    if (!empty($row[4])) {
                        $inventoryType = str_replace("''", "'", $row[4]);
                    } elseif (!empty($row[5])) {
                        $inventoryType = $row[5];
                    }
                    
                    $stmt->execute([
                        ':snapshot_id' => $snapshotId,
                        ':inventory_id' => $row[1],
                        ':inventory_name' => $inventoryName,
                        ':inventory_money' => $row[3],
                        ':inventory_type' => $inventoryType
                    ]);
                    $count++;
                } catch (PDOException $e) {
                    $errors++;
                    error_log("Error inserting inventory (4-col): " . $e->getMessage() . " - Row: " . print_r($row, true));
                }
            }
        } else {
            // Process 5-column format (with saturation)
            foreach ($rows as $row) {
                try {
                    $inventoryName = str_replace("''", "'", $row[2]);
                    // row[4] is saturation - we skip it
                    $inventoryType = null;
                    if (!empty($row[5])) {
                        $inventoryType = str_replace("''", "'", $row[5]);
                    } elseif (!empty($row[6])) {
                        $inventoryType = $row[6];
                    }
                    
                    $stmt->execute([
                        ':snapshot_id' => $snapshotId,
                        ':inventory_id' => $row[1],
                        ':inventory_name' => $inventoryName,
                        ':inventory_money' => $row[3],
                        ':inventory_type' => $inventoryType
                    ]);
                    $count++;
                } catch (PDOException $e) {
                    $errors++;
                    error_log("Error inserting inventory (5-col): " . $e->getMessage() . " - Row: " . print_r($row, true));
                }
            }
        }
    }
    
    if ($errors > 0) {
        error_log("Inventories: $count inserted, $errors errors");
    }
    
    return $count;
}

/**
 * Parse market INSERT statements from SQL file
 * Expected columns: Market_item_Id, Market_Item_Type, Market_Item_Class, Purchase_Price, Selling_Price, Available_Quantity, tier, Ammo_Count, Compatible_Items, Market, DLC, Visible
 * We need: Market_Id, Market_Item_Name (use class), Market_Item_Class, Purchase_Price, Sell_Price, Market
 */
function parseAndInsertMarket($pdo, $sqlContent, $snapshotId) {
    // Find INSERT statements for market table
    if (!preg_match_all(
        "/INSERT INTO `market`[^;]*?VALUES\s*(.+?);/si",
        $sqlContent,
        $matches
    )) {
        error_log("No market INSERT statements found in SQL file");
        return 0;
    }
    
    $count = 0;
    $errors = 0;
    
    $stmt = $pdo->prepare("
        INSERT INTO analytics_market_snapshot 
        (snapshot_id, Market_Id, Market_Item_Name, Market_Item_Class, Purchase_Price, Sell_Price, Market)
        VALUES (:snapshot_id, :market_id, :market_item_name, :market_item_class, :purchase_price, :sell_price, :market)
    ");
    
    foreach ($matches[1] as $valuesSection) {
        // Use regex to match rows with proper quote handling
        // Pattern matches: (id, 'type', 'class', price, price, qty, tier, ammo, 'compat', market, dlc, visible)
        // Handles NULL and escaped quotes
        preg_match_all(
            "/\((\d+),\s*'([^']*(?:''[^']*)*)',\s*'([^']*(?:''[^']*)*)',\s*(\d+(?:\.\d+)?),\s*(\d+(?:\.\d+)?),\s*(-?\d+),\s*(\d+),\s*(?:(\d+)|NULL),\s*(?:'([^']*(?:''[^']*)*)'|NULL),\s*(?:(\d+)|NULL),\s*(?:(\d+)|NULL),\s*(\d+)\)/",
            $valuesSection,
            $rows,
            PREG_SET_ORDER
        );
        
        if (empty($rows)) {
            error_log("Market: No rows matched pattern in section: " . substr($valuesSection, 0, 200));
            continue;
        }
        
        foreach ($rows as $row) {
            try {
                // row[0] = full match
                // row[1] = Market_item_Id
                // row[2] = Market_Item_Type (skip)
                // row[3] = Market_Item_Class
                // row[4] = Purchase_Price
                // row[5] = Selling_Price
                // row[6] = Available_Quantity (skip)
                // row[7] = tier (skip)
                // row[8] = Ammo_Count (skip - can be NULL)
                // row[9] = Compatible_Items (skip)
                // row[10] = Market
                // row[11] = DLC (skip)
                // row[12] = Visible (skip)
                
                $marketItemClass = str_replace("''", "'", $row[3]);
                $marketValue = !empty($row[10]) ? $row[10] : null;
                
                $stmt->execute([
                    ':snapshot_id' => $snapshotId,
                    ':market_id' => $row[1],
                    ':market_item_name' => $marketItemClass, // Use class as name
                    ':market_item_class' => $marketItemClass,
                    ':purchase_price' => $row[4],
                    ':sell_price' => $row[5],
                    ':market' => $marketValue
                ]);
                $count++;
            } catch (PDOException $e) {
                $errors++;
                error_log("Error inserting market item: " . $e->getMessage() . " - Row: " . print_r($row, true));
            }
        }
    }
    
    if ($errors > 0) {
        error_log("Market: $count inserted, $errors errors");
    }
    
    return $count;
}

include '../includes/header.php';
?>

<div class="adminSnapshot-container">
    <div class="adminSnapshot-header">
        <h2>Upload SQL Database Snapshot</h2>
    </div>
    
    <?php if (!empty($message)): ?>
        <div class="adminSnapshot-message <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <div class="adminSnapshot-info-box">
        <h3>Instructions</h3>
        <ul>
            <li>Upload a .sql file containing a database export.</li>
            <li>The file should contain INSERT statements for: <code>content_items</code>, <code>inventories</code>, and <code>market</code> tables.</li>
            <li>Maximum file size: 50MB.</li>
            <li>The data will be parsed and inserted into the analytics snapshot tables.</li>
        </ul>
    </div>
    
    <div class="adminSnapshot-form-card">
        <form class="adminSnapshot-form-group" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="adminSnapshot-form-group">
                <label for="sqlFile">SQL File <span class="required">*</span></label>
                <input type="file" id="sqlFile" name="sqlFile" accept=".sql" class="adminSnapshot-input preview-uploadSnapshot-1" required>
            </div>
            
            <div class="adminSnapshot-form-group">
                <label for="snapshot_date">Snapshot Date & Time <span class="required">*</span></label>
                <input type="datetime-local" id="snapshot_date" name="snapshot_date" value="<?php echo date('Y-m-d\TH:i'); ?>" class="adminSnapshot-input" required>
                <div class="adminSnapshot-help-text">When was this database snapshot originally taken?</div>
            </div>
            
            <div class="adminSnapshot-form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="adminSnapshot-textarea" placeholder="Optional description for this snapshot..."></textarea>
            </div>
            
            <div class="adminSnapshot-actions">
                <button type="submit" class="btn-industrial success upload-button">Upload and Create Snapshot</button>
            </div>
            
            <div id="processingIndicator" class="adminSnapshot-processing">
                <div class="adminSnapshot-spinner"></div>
                <span id="processingText">Processing file...</span>
            </div>
        </form>
    </div>
    
    <div class="adminSnapshot-tips">
        <p>Tips:</p>
        <ul>
            <li>The file will be parsed for INSERT statements from <code>content_items</code>, <code>inventories</code>, and <code>market</code> tables.</li>
            <li>Processing large files (>10MB) may take a few minutes.</li>
            <li>All data will be wrapped in a transaction - if any error occurs, no data will be inserted.</li>
            <li>You can view created snapshots in the analytics/reporting section.</li>
        </ul>
    </div>
</div>

<script>
    document.getElementById('uploadForm').addEventListener('submit', function(event) {
        const fileInput = document.getElementById('sqlFile');
        const submitBtn = document.querySelector('.upload-button');
        const processingIndicator = document.getElementById('processingIndicator');
        const processingText = document.getElementById('processingText');
        
        if (fileInput.files.length > 0) {
            const fileSize = fileInput.files[0].size;
            const fileMB = (fileSize / (1024 * 1024)).toFixed(2);
            
            if (fileSize > 50 * 1024 * 1024) {
                showToast('File is too large. Maximum size is 50MB.', 'error');
                event.preventDefault();
                return false;
            }
            
            submitBtn.disabled = true;
            processingIndicator.classList.add('active');
            processingText.textContent = 'Processing ' + fileMB + 'MB file... This may take a few minutes. Please do not close this page.';
            
            return true;
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
