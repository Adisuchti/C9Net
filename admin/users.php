<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Fetch all inventories
$query = "SELECT id, username, password, created_at, inventory_id FROM users";
$stmt = $pdo->query($query);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT Inventory_Id, Inventory_Name FROM inventories";
$stmt = $pdo->query($query);
$inventories = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="users-container">
    <div class="users-header">
        <h2 class="users-title">User Management</h2>
        <a href="addNewUser.php" class="btn-industrial primary">Advanced User Setup</a>
    </div>

    <!-- Password Reset Modal Overlay -->
    <div id="reset-password-overlay" class="users-modal-overlay preview-users-1">
        <div class="users-modal">
            <h3>Reset User Password</h3>
            <form id="resetPasswordForm">
                <input type="hidden" id="resetUserId">
                <div class="users-control-group preview-users-2">
                    <label for="newPassword">New Password:</label>
                    <input type="password" id="newPassword" class="users-input" required>
                </div>
                <div class="users-control-group">
                    <label for="confirmPassword">Confirm Password:</label>
                    <input type="password" id="confirmPassword" class="users-input" required>
                </div>
                <div class="users-modal-actions">
                    <button type="button" onclick="hideResetPasswordOverlay()" class="btn-industrial">Cancel</button>
                    <button type="button" onclick="resetPassword()" class="btn-industrial primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="users-grid">
        <?php foreach ($users as $user): ?>
            <div class="users-card">
                <div class="users-card-header">
                    <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                    <span class="preview-users-3">ID: <?php echo htmlspecialchars($user['id']); ?></span>
                </div>
                
                <div class="users-card-details">
                    <p><strong>Created:</strong> <?php echo htmlspecialchars($user['created_at']); ?></p>
                </div>
                
                <div class="users-control-group">
                    <label for="inventory_<?php echo $user['id']; ?>">Assign Inventory:</label>
                    <select class="users-select" id="inventory_<?php echo $user['id']; ?>" onchange="updateExistingUserInventory(<?php echo $user['id']; ?>)">
                        <option value="-1">Disabled</option>
                        <?php foreach ($inventories as $inventory): ?>
                            <option value="<?php echo $inventory['Inventory_Id']; ?>" <?php echo $user['inventory_id'] == $inventory['Inventory_Id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($inventory['Inventory_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="users-card-actions">
                    <button type="button" class="btn-industrial" onclick="showResetPasswordOverlay(<?php echo $user['id']; ?>)">
                        <svg class="btn-icon" viewBox="0 0 24 24"><path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4h2.35l2-2 2 2 2-2 2 2V10h-4.35zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>
                        Reset
                    </button>
                    <button type="button" class="btn-industrial danger" onclick="deleteUser(<?php echo $user['id']; ?>)">
                        <svg class="btn-icon" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                        Delete
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Add Simple User Card -->
        <div class="users-card preview-users-4">
            <div class="users-card-header">
                <h3>Add Simple User</h3>
            </div>
            <div class="users-control-group">
                <label for="new_username">Username:</label>
                <input type="text" id="new_username" class="users-input" required>
            </div>
            <div class="users-control-group">
                <label for="new_password">Password:</label>
                <input type="password" id="new_password" class="users-input" required>
            </div>
            <div class="users-card-actions preview-users-5">
                <button type="button" class="btn-industrial primary preview-users-6" onclick="addNewUser()">Add User</button>
            </div>
        </div>
    </div>
</div>

<script>
    function addNewUser() {
        const username = document.getElementById('new_username').value;
        const password = document.getElementById('new_password').value;

        if (!username || !password) {
            showError("Please fill in all fields.");
            return;
        }

        const data = {
            username : username,
            password : password
        };

        fetch("../db/auth_users/addUser.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
                console.error("Response text:", text);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function deleteUser(userId) {
        if(!confirm("Are you sure you want to delete this user?")) return;
        
        const data = { userId : userId };

        fetch("../db/auth_users/deleteUser.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function updateExistingUserInventory (userId) {
        const inventoryId = document.getElementById('inventory_' + userId).value;
        const data = { userId : userId, inventoryId : inventoryId };

        fetch("../db/inventory_assets/changeWebUserInventory.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast('Inventory updated', 'success');
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function showResetPasswordOverlay(userId) {
        document.getElementById('resetUserId').value = userId;
        document.getElementById('reset-password-overlay').style.display = 'flex';
    }

    function hideResetPasswordOverlay() {
        document.getElementById('reset-password-overlay').style.display = 'none';
        document.getElementById('resetPasswordForm').reset();
    }

    function resetPassword() {
        const form = document.getElementById('resetPasswordForm');
        const userId = form.querySelector('#resetUserId').value;
        const newPassword = form.querySelector('#newPassword').value;
        const confirmPassword = form.querySelector('#confirmPassword').value;

        if (newPassword !== confirmPassword) {
            showError('Passwords do not match');
            return;
        }

        const data = { userId: userId, newPassword: newPassword };

        fetch("../db/auth_users/adminResetPassword.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    hideResetPasswordOverlay();
                    showToast('Password reset successfully!', 'success');
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
</script>

<?php include '../includes/footer.php'; ?>
