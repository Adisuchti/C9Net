<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Fetch all markets
$query = "SELECT Id, Name FROM markets";
$stmt = $pdo->query($query);
$markets = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="adminMarkets-container">
    <div>
        <h2 class="adminMarkets-header">Market Management</h2>
        <div class="adminMarkets-desc">Manage existing markets or create new ones for the C9 economy.</div>
    </div>

    <div class="adminMarkets-grid">
        <?php foreach ($markets as $market): ?>
            <div class="adminMarkets-card" id="market_card_<?php echo $market['Id']; ?>">
                <div class="adminMarkets-card-header">
                    <span><?php echo htmlspecialchars($market['Name']); ?></span>
                    <span class="preview-markets-1">ID: <?php echo htmlspecialchars($market['Id']); ?></span>
                </div>
                
                <form id="form_<?php echo $market['Id']; ?>" class="preview-markets-form">
                    <input type="hidden" name="market_id" value="<?php echo $market['Id']; ?>">
                    
                    <div class="preview-markets-2">
                        <label for="market_name_<?php echo $market['Id']; ?>" class="preview-markets-lbl">Market Name:</label>
                        <input type="text" id="market_name_<?php echo $market['Id']; ?>" name="market_name" class="adminMarkets-input" value="<?php echo htmlspecialchars($market['Name']); ?>">
                    </div>

                    <div class="adminMarkets-actions">
                        <button type="button" class="btn-industrial danger preview-markets-3" onclick="deleteMarket(<?php echo $market['Id']; ?>)">Delete</button>
                        <button type="button" class="btn-industrial primary preview-markets-4" onclick="updateMarket(<?php echo $market['Id']; ?>)">Update</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>

        <!-- Add New Market Card -->
        <div class="adminMarkets-card adminMarkets-add-card">
            <h3>Add New Market</h3>
            <form class="preview-markets-5">
                <input type="text" id="new_market_name" class="adminMarkets-input" placeholder="Market Name" required>
                <button type="button" class="btn-industrial success preview-markets-6" onclick="addNewMarket()">Create Market</button>
            </form>
        </div>
    </div>
</div>

<script>
    function addNewMarket() {
        const Name = document.getElementById('new_market_name').value;

        if (!Name) {
            showError("Please enter a market name.");
            return;
        }

        const data = { Name : Name };

        fetch("../db/market_assets/addMarket.php", {
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

    function updateMarket(marketId) {
        const form = document.getElementById('form_' + marketId);
        const data = {
            action: 'updateMarket',
            market_id: marketId,
            market_name: form.querySelector('input[name="market_name"]').value
        };

        fetch("../db/market_assets/updateMarket.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast("Market updated successfully!", "success");
                    // Optionally update the header span if we don't reload
                    document.querySelector('#market_card_' + marketId + ' .adminMarkets-card-header span').innerText = data.market_name;
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function deleteMarket(marketId) {
        if(!confirm("Are you sure you want to delete this market?")) return;

        const data = {
            action: 'deleteMarket',
            market_id: marketId
        };

        fetch("../db/market_assets/deleteMarket.php", {
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
