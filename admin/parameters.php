<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Fetch inventory types
$inventoryTypesQuery = "SELECT * FROM inventory_types ORDER BY Inventory_Type_Name";
$inventoryTypesStmt = $pdo->query($inventoryTypesQuery);
$inventoryTypes = $inventoryTypesStmt->fetchAll();

// Fetch item type limits
$limitsQuery = "SELECT l.*, t.Inventory_Type_Name 
                FROM item_type_inventory_limit l
                LEFT JOIN inventory_types t ON l.Inventory_Type = t.Inventory_Type_Id
                ORDER BY t.Inventory_Type_Name, l.Item_Type";
$limitsStmt = $pdo->query($limitsQuery);
$limits = $limitsStmt->fetchAll();

// Fetch condition variables
$varsQuery = "SELECT * FROM condition_variables ORDER BY Var_Name";
$varsStmt = $pdo->query($varsQuery);
$variables = $varsStmt->fetchAll();

// Fetch admins with their player IDs
$adminsQuery = "SELECT * FROM admins ORDER BY PlayerId";
$adminsStmt = $pdo->query($adminsQuery);
$admins = $adminsStmt->fetchAll();

// Fetch permissions
$permissionsQuery = "SELECT p.*, i.Inventory_Name 
                    FROM permissions p
                    LEFT JOIN inventories i ON p.Inventory_Id = i.Inventory_Id
                    ORDER BY i.Inventory_Name, p.Player_Id";
$permissionsStmt = $pdo->query($permissionsQuery);
$permissions = $permissionsStmt->fetchAll();

// Fetch calendar editors
$calendarEditorsQuery = "SELECT ce.Editor_Id, ce.User_Id, u.username
                         FROM calendar_editors ce
                         LEFT JOIN users u ON ce.User_Id = u.id
                         ORDER BY u.username";
$calendarEditorsStmt = $pdo->query($calendarEditorsQuery);
$calendarEditors = $calendarEditorsStmt->fetchAll();

// Fetch planning permissions
$planningPermissionsQuery = "SELECT pp.id, pp.user_id, u.username
                           FROM planning_permissions pp
                           LEFT JOIN users u ON pp.user_id = u.id
                           ORDER BY u.username";
$planningPermissionsStmt = $pdo->query($planningPermissionsQuery);
$planningPermissions = $planningPermissionsStmt->fetchAll();

// Fetch all users for the calendar editors dropdown
$allUsersQuery = "SELECT id, username FROM users ORDER BY username";
$allUsersStmt = $pdo->query($allUsersQuery);
$allUsers = $allUsersStmt->fetchAll();

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="adminParameters-container">
    <div>
        <h2 class="preview-parameters-1">System Parameters</h2>
        <div class="adminParameters-desc">Global configuration values for the C9 IntraNet.</div>
    </div>
    
    <div class="adminParameters-section">
        <h3>Inventory Types</h3>
        <div class="adminParameters-desc">Configure the different types of inventories in the system.</div>
        <div class="adminParameters-grid">
            <?php foreach ($inventoryTypes as $type): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group">
                        <input type="text" class="adminParameters-input"
                               value="<?php echo htmlspecialchars($type['Inventory_Type_Name']); ?>"
                               onchange="updateInventoryType(<?php echo $type['Inventory_Type_Id']; ?>, this.value)">
                        <button onclick="deleteInventoryType(<?php echo $type['Inventory_Type_Id']; ?>)" class="btn-industrial danger">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <input type="text" id="newInventoryType" class="adminParameters-input" placeholder="New inventory type name">
                    <button onclick="addInventoryType()" class="btn-industrial success">Add Type</button>
                </div>
            </div>
        </div>
    </div>

    <div class="adminParameters-section">
        <h3>Item Type Limits</h3>
        <div class="adminParameters-desc">Set maximum quantity limits for specific item types in different inventory categories.</div>
        <div class="adminParameters-grid">
            <?php foreach ($limits as $limit): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group">
                        <select class="adminParameters-select" onchange="updateLimit(<?php echo $limit['Item_Type_Limit_Id']; ?>, 'type', this.value)">
                            <?php foreach ($inventoryTypes as $type): ?>
                                <option value="<?php echo $type['Inventory_Type_Id']; ?>" <?php echo $limit['Inventory_Type'] == $type['Inventory_Type_Id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['Inventory_Type_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="adminParameters-input" value="<?php echo htmlspecialchars($limit['Item_Type']); ?>"
                               onchange="updateLimit(<?php echo $limit['Item_Type_Limit_Id']; ?>, 'itemType', this.value)" placeholder="Item type">
                        <input type="number" class="adminParameters-input" value="<?php echo $limit['Item_Limit']; ?>" min="0"
                               onchange="updateLimit(<?php echo $limit['Item_Type_Limit_Id']; ?>, 'limit', this.value)" placeholder="Quantity limit">
                        <button onclick="deleteLimit(<?php echo $limit['Item_Type_Limit_Id']; ?>)" class="btn-industrial danger">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <select id="newLimitType" class="adminParameters-select">
                        <?php foreach ($inventoryTypes as $type): ?>
                            <option value="<?php echo $type['Inventory_Type_Id']; ?>"><?php echo htmlspecialchars($type['Inventory_Type_Name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="newLimitItemType" class="adminParameters-input" placeholder="Item type">
                    <input type="number" id="newLimitValue" class="adminParameters-input" placeholder="Quantity limit" min="0">
                    <button onclick="addLimit()" class="btn-industrial success">Add Limit</button>
                </div>
            </div>
        </div>
    </div>

    <div class="adminParameters-section">
        <h3>Condition Variables</h3>
        <div class="adminParameters-desc">Configure system-wide numeric variables.</div>
        <div class="adminParameters-grid">
            <?php foreach ($variables as $var): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group">
                        <input type="text" class="adminParameters-input" value="<?php echo htmlspecialchars($var['Var_Name']); ?>"
                               onchange="updateVariable(<?php echo $var['Var_Id']; ?>, 'name', this.value)" placeholder="Variable name">
                        <input type="number" class="adminParameters-input" value="<?php echo $var['Var_Value']; ?>"
                               onchange="updateVariable(<?php echo $var['Var_Id']; ?>, 'value', this.value)" placeholder="Value">
                        <button onclick="deleteVariable(<?php echo $var['Var_Id']; ?>)" class="btn-industrial danger">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <input type="text" id="newVarName" class="adminParameters-input" placeholder="Variable name">
                    <input type="number" id="newVarValue" class="adminParameters-input" placeholder="Value">
                    <button onclick="addVariable()" class="btn-industrial success">Add Variable</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="adminParameters-section">
        <h3>Admins</h3>
        <div class="adminParameters-desc">Manage admin access to the system via Player ID.</div>
        <div class="adminParameters-grid">
            <?php foreach ($admins as $admin): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group">
                        <input type="text" class="adminParameters-input" value="<?php echo htmlspecialchars($admin['PlayerId']); ?>"
                               onchange="updateAdmin(<?php echo $admin['AdminId']; ?>, this.value)" placeholder="Player ID">
                        <button onclick="deleteAdmin(<?php echo $admin['AdminId']; ?>)" class="btn-industrial danger">Remove</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <input type="text" id="newAdminId" class="adminParameters-input" placeholder="Enter Player ID">
                    <button onclick="addAdmin()" class="btn-industrial success">Add Admin</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="adminParameters-section">
        <h3>Item Images Upload</h3>
        <div class="adminParameters-desc">Upload and manage item images. All image names will be stored in uppercase with .PNG extension.</div>
        <div class="adminParameters-grid">
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group preview-parameters-2">
                    <div class="preview-parameters-3">
                        <label class="preview-parameters-4"><input type="radio" name="uploadTarget" value="live" checked> Live Site (/images)</label>
                        <label class="preview-parameters-5"><input type="radio" name="uploadTarget" value="preview"> Preview Site (/preview/images/items)</label>
                    </div>
                    <div class="preview-parameters-6">
                        <input type="text" id="imageFileName" class="adminParameters-input" placeholder="Image filename (without extension)">
                        <input type="file" id="imageFile" accept="image/png,image/jpg,image/jpeg" class="adminParameters-input preview-parameters-7" onchange="previewImage()">
                    </div>
                    <div id="imagePreview" class="preview-parameters-8">
                        <p class="preview-parameters-9">Preview:</p>
                        <img id="previewImg" src="" alt="Preview" class="preview-parameters-10">
                        <p id="previewFilename" class="preview-parameters-11"></p>
                    </div>
                    <button onclick="uploadImage()" class="btn-industrial primary preview-parameters-12">Upload Image</button>
                </div>
            </div>
        </div>
    </div>

    <div class="adminParameters-section">
        <h3>Calendar Editors</h3>
        <div class="adminParameters-desc">Manage which users are authorized to create, edit, and delete calendar events. Admins always have access.</div>
        <div class="adminParameters-grid">
            <?php foreach ($calendarEditors as $editor): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group preview-parameters-13">
                        <span class="preview-parameters-14"><?php echo htmlspecialchars($editor['username'] ?? 'User #' . $editor['User_Id']); ?></span>
                        <button onclick="deleteCalendarEditor(<?php echo $editor['Editor_Id']; ?>)" class="btn-industrial danger">Remove</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <select id="newCalendarEditorUser" class="adminParameters-select">
                        <option value="">-- Select User --</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="addCalendarEditor()" class="btn-industrial success">Add Editor</button>
                </div>
            </div>
        </div>
    </div>

    <div class="adminParameters-section">
        <h3>Inventory Permissions</h3>
        <div class="adminParameters-desc">Manage user access to specific inventories.</div>
        <div class="adminParameters-grid">
            <?php foreach ($permissions as $perm): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group">
                        <select class="adminParameters-select" onchange="updatePermission(<?php echo $perm['Permission_Id']; ?>, 'inventory', this.value)">
                            <?php
                            $inventoriesStmt = $pdo->query("SELECT * FROM inventories ORDER BY Inventory_Name");
                            while ($inventory = $inventoriesStmt->fetch()): ?>
                                <option value="<?php echo $inventory['Inventory_Id']; ?>" <?php echo $perm['Inventory_Id'] == $inventory['Inventory_Id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($inventory['Inventory_Name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="text" class="adminParameters-input" value="<?php echo htmlspecialchars($perm['Player_Id']); ?>"
                               onchange="updatePermission(<?php echo $perm['Permission_Id']; ?>, 'player', this.value)">
                        <button onclick="deletePermission(<?php echo $perm['Permission_Id']; ?>)" class="btn-industrial danger">Remove</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <select id="newPermInventory" class="adminParameters-select">
                        <?php
                        $inventoriesStmt = $pdo->query("SELECT * FROM inventories ORDER BY Inventory_Name");
                        while ($inventory = $inventoriesStmt->fetch()): ?>
                            <option value="<?php echo $inventory['Inventory_Id']; ?>"><?php echo htmlspecialchars($inventory['Inventory_Name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                    <input type="text" id="newPermPlayerId" class="adminParameters-input" placeholder="Enter Player ID">
                    <button onclick="addPermission()" class="btn-industrial success">Add Permission</button>
                </div>
            </div>
        </div>
    </div>

    <div class="adminParameters-section">
        <h3>Planning Permissions</h3>
        <div class="adminParameters-desc">Users with planning permission can use map drawing tools (Symbols, Arrows, Zones, Text).</div>
        <div class="adminParameters-grid">
            <?php foreach ($planningPermissions as $pp): ?>
                <div class="adminParameters-item">
                    <div class="adminParameters-input-group preview-parameters-15">
                        <span class="preview-parameters-16"><?php echo htmlspecialchars($pp['username'] ?? 'Unknown User'); ?></span>
                        <button onclick="deletePlanningPermission(<?php echo $pp['id']; ?>)" class="btn-industrial danger">Remove</button>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="adminParameters-item adminParameters-add">
                <div class="adminParameters-input-group">
                    <select id="newPlanningUser" class="adminParameters-select">
                        <option value="">-- Select User --</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="addPlanningPermission()" class="btn-industrial success">Add Permission</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    async function apiCall(endpoint, method, data) {
        try {
            const response = await fetch('../db/system_assets/' + endpoint + '.php', {
                method: method,
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });
            const text = await response.text();
            try {
                const result = JSON.parse(text);
                if (!result.success) throw new Error(result.error);
                return result;
            } catch (e) {
                throw new Error("Parse Error: " + text);
            }
        } catch (error) {
            showError('Error: ' + error.message);
            throw error;
        }
    }

    // Inventory Types
    async function addInventoryType() {
        const name = document.getElementById('newInventoryType').value;
        if (!name) return;
        await apiCall('updateParameters', 'POST', { action: 'addInventoryType', name: name });
        location.reload();
    }
    async function updateInventoryType(id, name) {
        await apiCall('updateParameters', 'POST', { action: 'updateInventoryType', id: id, name: name });
        showToast('Updated successfully', 'success');
    }
    async function deleteInventoryType(id) {
        if (!confirm('Are you sure you want to delete this inventory type?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deleteInventoryType', id: id });
        location.reload();
    }

    // Item Type Limits
    async function addLimit() {
        const type = document.getElementById('newLimitType').value;
        const itemType = document.getElementById('newLimitItemType').value;
        const limit = document.getElementById('newLimitValue').value;
        if (!type || !itemType || !limit) return;
        await apiCall('updateParameters', 'POST', { action: 'addLimit', type: type, itemType: itemType, limit: limit });
        location.reload();
    }
    async function updateLimit(id, field, value) {
        await apiCall('updateParameters', 'POST', { action: 'updateLimit', id: id, field: field, value: value });
        showToast('Updated successfully', 'success');
    }
    async function deleteLimit(id) {
        if (!confirm('Are you sure you want to delete this limit?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deleteLimit', id: id });
        location.reload();
    }

    // Condition Variables
    async function addVariable() {
        const name = document.getElementById('newVarName').value;
        const value = document.getElementById('newVarValue').value;
        if (!name || !value) return;
        await apiCall('updateParameters', 'POST', { action: 'addVariable', name: name, value: value });
        location.reload();
    }
    async function updateVariable(id, field, value) {
        await apiCall('updateParameters', 'POST', { action: 'updateVariable', id: id, field: field, value: value });
        showToast('Updated successfully', 'success');
    }
    async function deleteVariable(id) {
        if (!confirm('Are you sure you want to delete this variable?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deleteVariable', id: id });
        location.reload();
    }

    // Admins
    async function addAdmin() {
        const playerId = document.getElementById('newAdminId').value;
        if (!playerId) return;
        await apiCall('updateParameters', 'POST', { action: 'addAdmin', playerId: playerId });
        location.reload();
    }
    async function updateAdmin(id, playerId) {
        await apiCall('updateParameters', 'POST', { action: 'updateAdmin', id: id, playerId: playerId });
        showToast('Updated successfully', 'success');
    }
    async function deleteAdmin(id) {
        if (!confirm('Are you sure you want to delete this admin?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deleteAdmin', id: id });
        location.reload();
    }

    // Permissions
    async function addPermission() {
        const inventoryId = document.getElementById('newPermInventory').value;
        const playerId = document.getElementById('newPermPlayerId').value;
        if (!inventoryId || !playerId) return;
        await apiCall('updateParameters', 'POST', { action: 'addPermission', inventoryId: inventoryId, playerId: playerId });
        location.reload();
    }
    async function updatePermission(id, field, value) {
        await apiCall('updateParameters', 'POST', { action: 'updatePermission', id: id, field: field, value: value });
        showToast('Updated successfully', 'success');
    }
    async function deletePermission(id) {
        if (!confirm('Are you sure you want to delete this permission?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deletePermission', id: id });
        location.reload();
    }

    // Calendar Editors
    async function addCalendarEditor() {
        const userId = document.getElementById('newCalendarEditorUser').value;
        if (!userId) return;
        await apiCall('updateParameters', 'POST', { action: 'addCalendarEditor', userId: userId });
        location.reload();
    }
    async function deleteCalendarEditor(id) {
        if (!confirm('Remove this calendar editor?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deleteCalendarEditor', id: id });
        location.reload();
    }

    // Planning Permissions
    async function addPlanningPermission() {
        const userId = document.getElementById('newPlanningUser').value;
        if (!userId) return;
        await apiCall('updateParameters', 'POST', { action: 'addPlanningPermission', userId: userId });
        location.reload();
    }
    async function deletePlanningPermission(id) {
        if (!confirm('Remove this planning permission?')) return;
        await apiCall('updateParameters', 'POST', { action: 'deletePlanningPermission', id: id });
        location.reload();
    }

    // Image Upload Functions
    function previewImage() {
        const fileInput = document.getElementById('imageFile');
        const filenameInput = document.getElementById('imageFileName');
        const preview = document.getElementById('imagePreview');
        const previewImg = document.getElementById('previewImg');
        const previewFilename = document.getElementById('previewFilename');

        if (fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            const reader = new FileReader();

            if (!filenameInput.value) {
                const originalName = file.name.split('.')[0];
                filenameInput.value = originalName;
            }

            reader.onload = function(e) {
                previewImg.src = e.target.result;
                const finalName = filenameInput.value.toUpperCase() + '.PNG';
                previewFilename.textContent = 'Will be saved as: ' + finalName;
                preview.style.display = 'block';
            }
            reader.readAsDataURL(file);
        }
    }

    async function uploadImage(force = false) {
        const fileInput = document.getElementById('imageFile');
        const filenameInput = document.getElementById('imageFileName');

        if (!fileInput.files || !fileInput.files[0]) {
            showError('Please select an image file');
            return;
        }
        if (!filenameInput.value) {
            showError('Please enter a filename');
            return;
        }

        const target = document.querySelector('input[name="uploadTarget"]:checked').value;

        const formData = new FormData();
        formData.append('image', fileInput.files[0]);
        formData.append('filename', filenameInput.value);
        formData.append('force', force ? '1' : '0');
        formData.append('target', target);

        try {
            const response = await fetch('../db/system_assets/uploadImage.php', {
                method: 'POST',
                headers: { 
                    'X-CSRF-Token': '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                },
                credentials: 'include',
                body: formData
            });

            const text = await response.text();
            try {
                const result = JSON.parse(text);
                if (result.success) {
                    showToast('Image uploaded successfully as: ' + result.savedAs, 'success');
                    fileInput.value = '';
                    filenameInput.value = '';
                    document.getElementById('imagePreview').style.display = 'none';
                } else if (result.fileExists) {
                    if (confirm('An image with the name "' + result.existingFile + '" already exists. Do you want to replace it?')) {
                        await uploadImage(true);
                    }
                } else {
                    showError('Error: ' + result.error);
                }
            } catch (e) {
                showError("Parse Error: " + text);
            }
        } catch (error) {
            showError('Upload failed: ' + error.message);
        }
    }
</script>

<?php include '../includes/footer.php'; ?>
