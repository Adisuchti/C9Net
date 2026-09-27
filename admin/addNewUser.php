<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="addNewUser-container">
    <div class="addNewUser-header">
        <h2 class="users-title">Add New User</h2>
        <p>Create a complete user setup in one step. This will create the user account, inventory with starting balance, player profile (assigned to default team), and inventory permissions automatically.</p>
    </div>

    <!-- Account Section -->
    <div class="addNewUser-section">
        <h3>Account Credentials</h3>
        <div class="addNewUser-grid">
            <div class="addNewUser-input-group">
                <label for="username">Username</label>
                <input type="text" id="username" class="addNewUser-input" placeholder="Enter username" required>
            </div>
            <div class="addNewUser-input-group">
                <label for="password">Password</label>
                <input type="password" id="password" class="addNewUser-input" placeholder="Enter password" required>
            </div>
            <div class="addNewUser-input-group">
                <label for="steamUserId">Steam User ID</label>
                <input type="text" id="steamUserId" class="addNewUser-input" placeholder="Enter Steam User ID" required>
            </div>
        </div>
    </div>

    <!-- Inventory Section -->
    <div class="addNewUser-section">
        <h3>Inventory Setup</h3>
        <div class="addNewUser-grid">
            <div class="addNewUser-input-group">
                <label for="inventoryName">Inventory Name</label>
                <input type="text" id="inventoryName" class="addNewUser-input" placeholder="Enter inventory name" required>
            </div>
            <div class="addNewUser-input-group">
                <label for="startingMoney">Starting Money (Credits)</label>
                <input type="number" id="startingMoney" class="addNewUser-input" value="0" min="0" step="1" placeholder="0">
            </div>
        </div>
        <p class="preview-addNewUser-1">
            The profile will be automatically assigned to the default roster team (Assignment = 1). You can re-assign it later from the Roster page.
        </p>
    </div>

    <!-- Actions -->
    <div class="addNewUser-actions">
        <button onclick="window.location.reload()" class="btn-industrial">Reset Form</button>
        <button id="submitBtn" onclick="submitNewUser()" class="btn-industrial primary">Create User</button>
    </div>

    <!-- Result Display -->
    <div id="resultBox" class="addNewUser-result preview-addNewUser-2"></div>
</div>

<script>
    async function submitNewUser() {
        const submitBtn = document.getElementById('submitBtn');
        const resultBox = document.getElementById('resultBox');
        
        // Gather form data
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        const inventoryName = document.getElementById('inventoryName').value.trim();
        const startingMoney = document.getElementById('startingMoney').value;
        const steamUserId = document.getElementById('steamUserId').value.trim();

        // Client-side validation
        const errors = [];
        if (!username) errors.push('Username is required');
        if (!password) errors.push('Password is required');
        if (!inventoryName) errors.push('Inventory name is required');
        if (!steamUserId) errors.push('Steam User ID is required');

        if (errors.length > 0) {
            showError(errors.join('<br>'));
            return;
        }

        if (password.length < 4) {
            showError('Password must be at least 4 characters');
            return;
        }

        // Disable button during submission
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Creating...';
        resultBox.style.display = 'none';

        const data = {
            username: username,
            password: password,
            startingMoney: parseInt(startingMoney) || 0,
            inventoryName: inventoryName,
            steamUserId: steamUserId
        };

        try {
            const response = await fetch('../db/auth_users/addNewUserComplete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                // Show success with details
                resultBox.style.display = 'block';
                resultBox.style.background = 'rgba(46, 204, 113, 0.1)';
                resultBox.style.border = '1px solid rgba(46, 204, 113, 0.3)';
                resultBox.style.color = 'var(--success)';
                resultBox.innerHTML = `
                    <h4>✓ User Created Successfully</h4>
                    <p class="preview-addNewUser-3"><strong>Username:</strong> ${escapeHtml(username)}</p>
                    <p class="preview-addNewUser-4"><strong>User ID:</strong> <code>${result.data.userId}</code></p>
                    <p class="preview-addNewUser-5"><strong>Inventory ID:</strong> <code>${result.data.inventoryId}</code></p>
                    <p class="preview-addNewUser-6"><strong>Profile ID (Player ID):</strong> <code>${result.data.profileId}</code></p>
                    <p class="preview-addNewUser-7"><strong>Starting Money:</strong> ${parseInt(startingMoney) || 0} Credits</p>
                    <p class="preview-addNewUser-8"><strong>Steam User ID:</strong> <code>${escapeHtml(result.data.steamUserId)}</code></p>
                    <p class="preview-addNewUser-9"><strong>Default Team:</strong> Assignment 1 (default)</p>
                `;
                
                // Clear the form
                document.getElementById('username').value = '';
                document.getElementById('password').value = '';
                document.getElementById('inventoryName').value = '';
                document.getElementById('startingMoney').value = '0';
                document.getElementById('steamUserId').value = '';
            } else {
                resultBox.style.display = 'block';
                resultBox.style.background = 'rgba(231, 76, 60, 0.1)';
                resultBox.style.border = '1px solid rgba(231, 76, 60, 0.3)';
                resultBox.style.color = 'var(--danger)';
                resultBox.innerHTML = `<h4>✗ Error</h4><p class="preview-addNewUser-10">${escapeHtml(result.error)}</p>`;
            }
        } catch (err) {
            resultBox.style.display = 'block';
            resultBox.style.background = 'rgba(231, 76, 60, 0.1)';
            resultBox.style.border = '1px solid rgba(231, 76, 60, 0.3)';
            resultBox.style.color = 'var(--danger)';
            resultBox.innerHTML = `<h4>✗ Error</h4><p class="preview-addNewUser-11">Network error: ${escapeHtml(err.message)}</p>`;
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Create User';
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Allow Enter key to submit the form
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && document.activeElement && document.activeElement.tagName === 'INPUT') {
            e.preventDefault();
            submitNewUser();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
