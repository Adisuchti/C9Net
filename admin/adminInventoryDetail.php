<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

if (isset($_POST['action']) && $_POST['action'] === 'calculate') {
    // calculateSellingPrice function is missing from the original file context in terms of includes,
    // assuming it exists somewhere or this was legacy. Usually we wouldn't see it without an include.
    // However, keeping this for backward compatibility.
    // $price = calculateSellingPrice($_POST['price'], $_POST['inventory_id'], $pdo);
    // echo $price;
    exit;
}

// Get inventory ID
$inventoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// get Inventory Name from id
$Inventoryquery = "SELECT Inventory_Name, Inventory_Money FROM `inventories` WHERE Inventory_Id = :inventory_id;";
$Inventorystmt = $pdo->prepare($Inventoryquery);
$Inventorystmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
$Inventorystmt->execute();
$inventoryData = $Inventorystmt->fetch();

// Fetch items from the database with the complex join
$query = "SELECT DISTINCT content_items.Content_Item_Id, content_items.Inventory_Id,
    content_items.Item_Class, content_items.Item_Quantity, content_items.Item_Properties, 
    custom_item_types.Custom_Item_Type, market.Selling_Price, IFNULL(items.Item_Display_Name, content_items.Item_Class) as Item_Display_Name
    FROM content_items
    LEFT JOIN items ON items.item_class = content_items.Item_Class
    LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
    LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
    LEFT JOIN item_sorting ON item_sorting.Item_Sorting_Type = item_types.item_classification
    LEFT JOIN market ON market.Market_Item_Class = content_items.Item_Class
    WHERE Inventory_Id = :inventory_id
    ORDER BY item_sorting.Item_Sorting_Number, content_items.Item_Class, content_items.Item_Properties";

$stmt = $pdo->prepare($query);
$stmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?><div class="inventory-preview-container preview-adminInventoryDetail-1">
    
    <!-- Left Pane: 30% -->
    <div class="inv-left-pane">
        <!-- User Info / Inventory Widget -->
        <div class="user-info-widget preview-adminInventoryDetail-2">
            <div class="user-info-details preview-adminInventoryDetail-3">
                <h2 class="preview-adminInventoryDetail-4">Inventory</h2>
                <span class="user-info-role preview-adminInventoryDetail-5">
                    '<?php echo htmlspecialchars($inventoryData['Inventory_Name'] ?? 'Unknown'); ?>'
                </span>
                <a href="inventories.php" class="btn-industrial preview-adminInventoryDetail-6">Back to Inventories</a>
            </div>
        </div>

        <!-- Add Item Widget -->
        <div class="user-balance-widget preview-adminInventoryDetail-7">
            <h3 class="preview-adminInventoryDetail-8">Add New Item</h3>
            <div class="adminInvDetail-row preview-adminInventoryDetail-9">
                <strong class="preview-adminInventoryDetail-10">Item Class:</strong>
                <input type="text" id="item_class" placeholder="Enter item class" class="adminInvDetail-input full-width">
            </div>
            <div class="adminInvDetail-row preview-adminInventoryDetail-11">
                <strong class="preview-adminInventoryDetail-12">Quantity:</strong>
                <input type="number" id="quantity_add" value="1" min="1" class="adminInvDetail-input full-width">
            </div>
            <div class="adminInvDetail-row preview-adminInventoryDetail-13">
                <strong class="preview-adminInventoryDetail-14">Properties (Optional):</strong>
                <input type="number" id="item_properties" placeholder="Enter properties" class="adminInvDetail-input full-width">
            </div>
            <button class="btn-industrial primary preview-adminInventoryDetail-15" onclick="addItem()">Add to Inventory</button>
        </div>
    </div>

    <!-- Right Pane: 70% -->
    <div class="inv-right-pane">
        
        <?php
        // Group items by Custom_Item_Type first
        $groupedItems = [];
        foreach ($items as $item) {
            $type = $item['Custom_Item_Type'] ?? 'Unknown';
            if (!isset($groupedItems[$type])) {
                $groupedItems[$type] = [];
            }
            $groupedItems[$type][] = $item;
        }

        // Reorder categories
        $categoryOrder = ['Primary_Weapon', 'Sidearm', 'Launcher', 'Melee', 'T-Doll', 'Ammo', 'Attachments', 'Equipment', 'Throwables'];
        $orderedGroups = [];
        foreach ($categoryOrder as $cat) {
            if (isset($groupedItems[$cat])) {
                $orderedGroups[$cat] = $groupedItems[$cat];
                unset($groupedItems[$cat]);
            }
        }
        foreach ($groupedItems as $cat => $typeItems) {
            if ($cat !== 'Unknown') $orderedGroups[$cat] = $typeItems;
        }
        if (isset($groupedItems['Unknown'])) {
            $orderedGroups['Unknown'] = $groupedItems['Unknown'];
        }
        $groupedItems = $orderedGroups;
        ?>

        <!-- Category Navigation -->
        <div class="inv-categories-wrapper preview-adminInventoryDetail-16">
            <div class="inv-categories preview-adminInventoryDetail-17">
                <?php 
                $firstCategory = true;
                foreach ($groupedItems as $type => $typeItems): 
                    $iconName = strtolower(str_replace(' ', '_', $type));
                    $iconPath = __DIR__ . '/../images/icons/categories/' . $iconName . '.svg';
                ?>
                    <button class="inv-category-btn <?php echo $firstCategory ? 'active' : ''; ?>" data-target="cat-<?php echo htmlspecialchars($type); ?>" onclick="switchCategory(this)" title="<?php echo htmlspecialchars($type); ?>">
                        <?php 
                        if (file_exists($iconPath)) {
                            include $iconPath;
                        } else {
                            include __DIR__ . '/../images/icons/categories/unknown.svg';
                        }
                        ?>
                    </button>
                <?php 
                $firstCategory = false;
                endforeach; 
                
                if (empty($groupedItems)) {
                    echo "<button class='inv-category-btn active'>?</button>";
                }
                ?>
            </div>
        </div>

        <!-- Items Container -->
        <?php 
        $firstCategory = true;
        foreach ($groupedItems as $type => $typeItems): 
        ?>
            <div class="inv-items-container is-grid-container <?php echo $firstCategory ? 'active-container' : ''; ?>" id="cat-<?php echo htmlspecialchars($type); ?>">
                <?php foreach ($typeItems as $item): ?>
                    <div class="inv-card preview-adminInventoryDetail-18">
                        <?php if ($item['Item_Quantity'] > 1): ?>
                            <div class="inv-card-qty-badge"><?php echo $item['Item_Quantity']; ?>x</div>
                        <?php endif; ?>
                        
                        <div class="inv-card-image inv-card-img-nonweapon preview-adminInventoryDetail-19">
                            <?php
                                $imagePath = "/images/items/" . strtoupper(htmlspecialchars($item['Item_Class'])) . ".PNG";
                                $defaultImage = "/images/items/" . strtoupper(htmlspecialchars($item['Custom_Item_Type'])) . ".PNG";
                                $fileCheckPath = __DIR__ . "/../images/items/" . strtoupper(htmlspecialchars($item['Item_Class'])) . ".PNG";
                                $finalImageUrl = file_exists($fileCheckPath) ? $imagePath : $defaultImage;
                            ?>
                            <img src="<?php echo $finalImageUrl; ?>" 
                                 alt="<?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Item_Class']); ?>"
                                 title="<?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Item_Class']); ?>" 
                                 loading="lazy"
                                 onerror="this.src='/images/placeholder.PNG'"
                                 class="preview-adminInvDetail-img">
                            
                            <div class="preview-adminInventoryDetail-20">
                                <h3 class="preview-adminInventoryDetail-21"><?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Item_Class']); ?></h3>
                                <small class="preview-adminInventoryDetail-22"><?php echo htmlspecialchars($item['Item_Class']); ?></small>
                            </div>
                        </div>
                        
                        <div class="adminInvDetail-content preview-adminInventoryDetail-23">
                            <div class="adminInvDetail-row preview-adminInventoryDetail-24">
                                <strong class="preview-adminInventoryDetail-25">Qty:</strong>
                                <div class="adminInvDetail-control preview-adminInventoryDetail-26">
                                    <input type="number" id="quantity_<?php echo $item['Content_Item_Id']; ?>" value="<?php echo htmlspecialchars($item['Item_Quantity']); ?>" min="0" class="adminInvDetail-input preview-adminInvDetail-input">
                                    <button class="btn-industrial preview-adminInventoryDetail-27" onclick="updateQuantity(<?php echo $item['Content_Item_Id']; ?>)">Set</button>
                                </div>
                            </div>
                            
                            <div class="adminInvDetail-row preview-adminInventoryDetail-28">
                                <strong class="preview-adminInventoryDetail-29">Prop:</strong>
                                <div class="adminInvDetail-control preview-adminInventoryDetail-30">
                                    <input type="number" id="state_<?php echo $item['Content_Item_Id']; ?>" value="<?php echo htmlspecialchars($item['Item_Properties']); ?>" min="0" class="adminInvDetail-input preview-adminInvDetail-input">
                                    <button class="btn-industrial preview-adminInventoryDetail-31" onclick="updateState(<?php echo $item['Content_Item_Id']; ?>)">Set</button>
                                </div>
                            </div>
                            
                            <div class="preview-adminInventoryDetail-32">
                                <button class="btn-industrial danger preview-adminInventoryDetail-33" onclick="removeItemQuantity(<?php echo $item['Content_Item_Id']; ?>)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="preview-adminInventoryDetail-34"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php 
        $firstCategory = false;
        endforeach; 
        
        if (empty($groupedItems)) {
            echo "<div class='inv-items-container inv-empty-msg'>No items found.</div>";
        }
        ?>
    </div>
</div>

<script>
    function switchCategory(btn) {
        document.querySelectorAll('.inv-category-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        document.querySelectorAll('.inv-items-container').forEach(c => c.classList.remove('active-container'));
        
        const targetId = btn.getAttribute('data-target');
        const targetContainer = document.getElementById(targetId);
        if (targetContainer) {
            targetContainer.classList.add('active-container');
        }
    }

    function removeItemQuantity(itemId) {
        if (!confirm("Are you sure you want to delete this item?")) return;
        
        let x = document.getElementById("quantity_" + itemId);
        const data = { quantity: x.value, itemId: itemId, targetInventoryId: <?php echo $inventoryId; ?> };

        fetch("../db/inventory_assets/removeItem.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) location.reload();
                else showError(json.error);
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function updateQuantity(itemId) {
        const quantity = document.getElementById('quantity_' + itemId).value;
        const data = { itemId: itemId, quantity: parseInt(quantity), inventoryId: <?php echo $inventoryId; ?> };
        
        fetch('../db/inventory_assets/changeQuantity.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) location.reload();
                else showError(json.error);
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function updateState(itemId) {
        const state = document.getElementById('state_' + itemId).value;
        const data = { itemId: itemId, state: parseInt(state), inventoryId: <?php echo $inventoryId; ?> };
        
        fetch('../db/inventory_assets/changeState.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) location.reload();
                else showError(json.error);
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function addItem() {
        const itemClass = document.getElementById('item_class').value;
        const quantity = document.getElementById('quantity_add').value;
        const properties = document.getElementById('item_properties').value || '';

        if (!itemClass) {
            showError("Item Class is required");
            return;
        }

        const data = {
            itemClass: itemClass,
            quantity: parseInt(quantity),
            properties: properties,
            inventoryId: <?php echo $inventoryId; ?>
        };

        fetch('../db/inventory_assets/addItemToInventory.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) location.reload();
                else showError(json.error);
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
</script>

<?php include '../includes/footer.php'; ?>
