<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

$pdo->beginTransaction();

// Function to recursively get team hierarchy
function getTeamHierarchy($parentId = -1) {
    global $pdo;
    $query = "SELECT h.*, 
        (SELECT COUNT(*) FROM team_hierarchy WHERE Fireteam_Parent_Id = h.Fireteam_Id) as has_children
        FROM team_hierarchy h 
        WHERE h.Fireteam_Parent_Id = ?
        ORDER BY h.Sorting ASC, h.Fireteam_Name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$parentId]);
    return $stmt->fetchAll();
}

$hierarchy = getTeamHierarchy();

// Fetch all inventories with their team assignment and profile info
$inventoriesQuery = "SELECT i.Inventory_Id, i.Inventory_Name, i.Inventory_Money, i.Inventory_Type, 
                            pp.Assignment, pp.Profile_Name, pp.Profile_Id
                     FROM inventories i
                     LEFT JOIN users u ON i.Inventory_Id = u.inventory_id
                     LEFT JOIN player_profiles pp ON u.id = pp.User_Id";
$inventoriesStmt = $pdo->prepare($inventoriesQuery);
$inventoriesStmt->execute();
$allInventories = $inventoriesStmt->fetchAll();

$inventoriesByTeam = [];
$unassignedInventories = [];
foreach ($allInventories as $inv) {
    if ($inv['Assignment']) {
        $inventoriesByTeam[$inv['Assignment']][] = $inv;
    } else {
        $unassignedInventories[] = $inv;
    }
}

$inventoryTypesQuery = "SELECT Inventory_Type_Id, Inventory_Type_Name FROM inventory_types ORDER BY Inventory_Type_Name";
$inventoryTypesStmt = $pdo->prepare($inventoryTypesQuery);
$inventoryTypesStmt->execute();
$inventoryTypes = $inventoryTypesStmt->fetchAll();

$pdo->commit();

// Re-assign $inventories for bulk actions
$inventories = $allInventories;

// Helper function to render an inventory item
function renderInventoryItem($inventory, $inventoryTypes) {
    ?>
    <div class="adminInventories-card" data-inventory-id="<?php echo $inventory['Inventory_Id']; ?>" data-name="<?php echo htmlspecialchars($inventory['Inventory_Name']); ?>">
        <div class="adminInventories-card-header">
            <label class="adminInventories-checkbox-label preview-inventories-1">
                <input type="checkbox" class="inventory-checkbox" value="<?php echo $inventory['Inventory_Id']; ?>">
            </label>
            <div>
                <h3>
                    <?php echo htmlspecialchars($inventory['Inventory_Name']); ?>
                </h3>
                <?php if ($inventory['Profile_Name']): ?>
                    <small>(Owned by: <?php echo htmlspecialchars($inventory['Profile_Name']); ?>)</small>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="adminInventories-card-details">
            <p><span>ID:</span> <strong><?php echo $inventory['Inventory_Id']; ?></strong></p>
            <p><span>Money:</span> <strong class="preview-inventories-2"><?php echo number_format($inventory['Inventory_Money'], 2, ".", "'"); ?> Cr</strong></p>
        </div>
        
        <div class="adminInventories-controls">
            <div class="adminInventories-control-row">
                <input step=".01" type="number" placeholder="Amount" class="adminInventories-input" id="amount_<?php echo $inventory['Inventory_Id']; ?>" required>
                <button type="button" class="btn-industrial success" onclick="updateMoney(<?php echo $inventory['Inventory_Id']; ?>)">Give/Take</button>
            </div>
            <div class="adminInventories-control-row">
                <select class="adminInventories-select" id="type_<?php echo $inventory['Inventory_Id']; ?>" onchange="updateInventoryType(<?php echo $inventory['Inventory_Id']; ?>, this.value)">
                    <?php foreach ($inventoryTypes as $type): ?>
                        <option value="<?php echo $type['Inventory_Type_Id']; ?>" <?php echo $inventory['Inventory_Type'] == $type['Inventory_Type_Id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['Inventory_Type_Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="adminInventories-actions">
            <a href="adminInventoryDetail.php?id=<?php echo $inventory['Inventory_Id']; ?>" class="btn-industrial preview-inventories-btn">Details</a>
            <button type="button" class="btn-industrial danger preview-inventories-3" onclick="deleteInventory(<?php echo $inventory['Inventory_Id']; ?>)">Delete</button>
        </div>
    </div>
    <?php
}

// Recursive function to render teams
function renderInventoryHierarchy($teams, $level = 0) {
    global $inventoriesByTeam, $inventoryTypes;
    foreach ($teams as $team) {
        $teamId = $team['Fireteam_Id'];
        $teamInventories = isset($inventoriesByTeam[$teamId]) ? $inventoriesByTeam[$teamId] : [];
        
        echo "<div class='adminInventories-group level-{$level}'>";
        echo "<h2 class='adminInventories-group-header'>" . htmlspecialchars($team['Fireteam_Name']) . "</h2>";
        
        if (!empty($teamInventories)) {
            echo "<div class='adminInventories-grid'>";
            foreach ($teamInventories as $inventory) {
                renderInventoryItem($inventory, $inventoryTypes);
            }
            echo "</div>";
        }
        
        if ($team['has_children']) {
            $children = getTeamHierarchy($teamId);
            renderInventoryHierarchy($children, $level + 1);
        }
        echo "</div>";
    }
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="adminInventories-container">
    <!-- Bulk Action Bar -->
    <div class="adminInventories-bulk-bar">
        <label class="adminInventories-checkbox-label">
            <input type="checkbox" id="selectAllInventories" onclick="toggleAllInventories(this)">
            <span>Select All</span>
        </label>
        <div class="preview-inventories-4"></div>
        <button class="btn-industrial success" onclick="openBulkModal('payout')">Bulk Payout</button>
        <button class="btn-industrial danger" onclick="openBulkModal('punishment')">Bulk Punishment</button>
        <div class="adminInventories-bulk-text">
            Select inventories and choose an action to open the bulk update overlay.
        </div>
    </div>

    <!-- Inventory List -->
    <div>
        <?php 
        renderInventoryHierarchy($hierarchy); 
        
        if (!empty($unassignedInventories)) {
            echo "<div class='adminInventories-group unassigned'>";
            echo "<h2 class='adminInventories-group-header'>Unassigned / Not Synced</h2>";
            echo "<div class='adminInventories-grid'>";
            foreach ($unassignedInventories as $inventory) {
                renderInventoryItem($inventory, $inventoryTypes);
            }
            echo "</div></div>";
        }
        ?>
        
        <div class="adminInventories-group preview-inventories-5">
            <h2 class="adminInventories-group-header">Add New Inventory</h2>
            <div class="preview-inventories-6">
                <div class="adminInventories-control-row">
                    <input type="text" id="new_name" class="adminInventories-input" placeholder="Inventory Name" required>
                </div>
                <div class="adminInventories-control-row">
                    <input type="number" id="new_money" class="adminInventories-input" placeholder="Starting Funds" required>
                </div>
                <button type="button" class="btn-industrial primary preview-inventories-7" onclick="addInventory()">Create Inventory</button>
            </div>
        </div>
    </div>

    <!-- Bulk Action Modal -->
    <div id="bulk-action-modal" class="adminInventories-modal-overlay preview-inventories-8">
        <div class="adminInventories-modal">
            <h3 id="bulk-modal-title">Bulk Action</h3>
            <div class="preview-inventories-9">
                <div class="adminInventories-control-row">
                    <input type="number" id="bulk-amount" step="0.01" class="adminInventories-input" placeholder="Amount">
                </div>
                <div class="adminInventories-selected-list">
                    <p class="preview-inventories-10"><strong>Selected Inventories:</strong></p>
                    <ul id="selected-inventories-list"></ul>
                </div>
            </div>
            <div class="adminInventories-actions preview-inventories-11">
                <button type="button" class="btn-industrial preview-inventories-12" onclick="closeBulkModal()">Cancel</button>
                <button type="button" class="btn-industrial primary preview-inventories-13" onclick="submitBulkAction()">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
    function updateMoney(inventoryId) {
        const quantity = document.getElementById("amount_" + inventoryId).value;
        const data = { inventoryId: inventoryId, amount: quantity };

        fetch("../db/inventory_assets/addMoney.php", {
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

    function deleteInventory(inventoryId) {
        if(!confirm("Are you sure you want to delete this inventory?")) return;
        
        const data = { inventoryId: inventoryId };

        fetch("../db/inventory_assets/deleteInventory.php", {
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

    function addInventory() {
        const name = document.getElementById("new_name").value;
        const money = document.getElementById("new_money").value;
        const data = { name: name, money: money };
        
        fetch("../db/inventory_assets/addInventory.php", {
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

    function updateInventoryType(inventoryId, typeId) {
        const data = { inventoryId: inventoryId, typeId: typeId };

        fetch("../db/inventory_assets/updateInventoryType.php", {
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

    /* Bulk Actions Logic */
    let currentBulkAction = '';

    function toggleAllInventories(source) {
        const checkboxes = document.querySelectorAll('.inventory-checkbox');
        checkboxes.forEach(cb => cb.checked = source.checked);
    }

    function openBulkModal(action) {
        const selectedIds = Array.from(document.querySelectorAll('.inventory-checkbox:checked')).map(cb => cb.value);
        if (selectedIds.length === 0) {
            showError("Please select at least one inventory.");
            return;
        }

        currentBulkAction = action;
        const modal = document.getElementById('bulk-action-modal');
        const title = document.getElementById('bulk-modal-title');
        const amountInput = document.getElementById('bulk-amount');
        const listContainer = document.getElementById('selected-inventories-list');

        title.innerText = action === 'payout' ? 'Bulk Payout' : 'Bulk Punishment';
        amountInput.value = action === 'payout' ? 1000 : 750;

        listContainer.innerHTML = '';
        selectedIds.forEach(id => {
            const item = document.querySelector(`.adminInventories-card[data-inventory-id="${id}"]`);
            const name = item ? item.getAttribute('data-name') : `ID: ${id}`;
            const li = document.createElement('li');
            li.innerText = name;
            listContainer.appendChild(li);
        });

        modal.style.display = 'flex';
    }

    function closeBulkModal() {
        document.getElementById('bulk-action-modal').style.display = 'none';
    }

    function submitBulkAction() {
        const selectedIds = Array.from(document.querySelectorAll('.inventory-checkbox:checked')).map(cb => cb.value);
        const amount = parseFloat(document.getElementById('bulk-amount').value);
        
        if (isNaN(amount)) {
            showError("Please enter a valid amount.");
            return;
        }

        const finalAmount = currentBulkAction === 'punishment' ? -Math.abs(amount) : Math.abs(amount);
        const data = { inventoryIds: selectedIds, amount: finalAmount };

        fetch("../db/inventory_assets/bulkUpdateMoney.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const json = await response.json();
            if (json.success) location.reload();
            else showError("Error: " + json.error);
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
</script>

<?php include '../includes/footer.php'; ?>
