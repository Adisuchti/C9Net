<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';

// Fetch all teams
$teamsQuery = "SELECT Fireteam_Id, Fireteam_Name, Fireteam_Parent_Id, Sorting, Fireteam_Color, leader_player_id, team_inventory_id, short_designation
               FROM team_hierarchy 
               ORDER BY Sorting ASC, Fireteam_Name ASC";
$teamsStmt = $pdo->prepare($teamsQuery);
$teamsStmt->execute();
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all players for leader dropdown
$playersQuery = "SELECT Profile_Id, Profile_Name FROM player_profiles ORDER BY Profile_Name ASC";
$playersStmt = $pdo->prepare($playersQuery);
$playersStmt->execute();
$players = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all inventories for team inventory dropdown
$invQuery = "SELECT Inventory_Id, Inventory_Name FROM inventories ORDER BY Inventory_Name ASC";
$invStmt = $pdo->prepare($invQuery);
$invStmt->execute();
$inventories = $invStmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>



<div class="editHierarchy-container">
    <div class="editHierarchy-header">
        <h2 class="editHierarchy-title">Edit Team Hierarchy</h2>
        <button onclick="addNewTeam()" class="btn-industrial">Add New Team</button>
    </div>
    
    <div class="editHierarchy-grid">
        <?php foreach ($teams as $team): ?>
            <div class="editHierarchy-team-card" id="team-card-<?php echo $team['Fireteam_Id']; ?>">
                <!-- Hidden inputs for data preservation -->
                <input type="hidden" class="team-parent-id" value="<?php echo $team['Fireteam_Parent_Id'] !== null ? $team['Fireteam_Parent_Id'] : -1; ?>">
                
                <div class="editHierarchy-field-group editHierarchy-field-group-wide">
                    <span class="editHierarchy-field-label">Team Name</span>
                    <input type="text" class="editHierarchy-input team-name" 
                           value="<?php echo htmlspecialchars($team['Fireteam_Name']); ?>"
                           onchange="triggerUpdate(this, <?php echo $team['Fireteam_Id']; ?>)">
                </div>
                
                <div class="editHierarchy-field-group editHierarchy-field-group-narrow">
                    <span class="editHierarchy-field-label">Short</span>
                    <input type="text" class="editHierarchy-input team-short-designation" 
                           value="<?php echo htmlspecialchars($team['short_designation'] ?? ''); ?>"
                           onchange="triggerUpdate(this, <?php echo $team['Fireteam_Id']; ?>)"
                           maxlength="10" placeholder="e.g. HQ">
                </div>
                
                <div class="editHierarchy-field-group">
                    <span class="editHierarchy-field-label">Leader</span>
                    <select class="editHierarchy-select team-leader" onchange="triggerUpdate(this, <?php echo $team['Fireteam_Id']; ?>)">
                        <option value="">-- No Leader --</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?php echo $player['Profile_Id']; ?>" <?php echo ($team['leader_player_id'] == $player['Profile_Id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($player['Profile_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="editHierarchy-field-group">
                    <span class="editHierarchy-field-label">Shared Inventory</span>
                    <select class="editHierarchy-select team-inventory" onchange="triggerUpdate(this, <?php echo $team['Fireteam_Id']; ?>)">
                        <option value="">-- No Inventory --</option>
                        <?php foreach ($inventories as $inv): ?>
                            <option value="<?php echo $inv['Inventory_Id']; ?>" <?php echo ($team['team_inventory_id'] == $inv['Inventory_Id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($inv['Inventory_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="editHierarchy-field-group editHierarchy-field-group-narrow">
                    <span class="editHierarchy-field-label">Sort</span>
                    <input type="number" class="editHierarchy-input team-sorting" 
                           value="<?php echo $team['Sorting']; ?>"
                           onchange="triggerUpdate(this, <?php echo $team['Fireteam_Id']; ?>)">
                </div>
                
                <div class="editHierarchy-field-group editHierarchy-field-group-fixed">
                    <span class="editHierarchy-field-label">Color</span>
                    <input type="color" class="editHierarchy-color-picker team-color" 
                           value="<?php echo htmlspecialchars($team['Fireteam_Color'] ?: '#3498db'); ?>"
                           onchange="triggerUpdate(this, <?php echo $team['Fireteam_Id']; ?>)">
                </div>
                
                <div class="editHierarchy-field-group" style="width: 170px;">
                    <span class="editHierarchy-field-label">Icon</span>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <input type="file" id="icon-upload-<?php echo $team['Fireteam_Id']; ?>" accept=".png,.jpg,.jpeg" style="ont-size: 0.8em; color: var(--text-primary);">
                        <button onclick="uploadTeamIcon(<?php echo $team['Fireteam_Id']; ?>)" class="btn-industrial" style="padding: 4px 8px; font-size: 0.8em;" title="Upload Icon">Up</button>
                    </div>
                </div>
                
                <button onclick="deleteTeam(<?php echo $team['Fireteam_Id']; ?>)" class="editHierarchy-btn-delete" title="Delete Team">&times;</button>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    function triggerUpdate(el, teamId) {
        let card = el.closest('.editHierarchy-team-card');
        if (!card) card = document.getElementById('team-card-' + teamId);
        if (!card) return;
        
        const name = card.querySelector('input.team-name').value;
        const shortDesignation = card.querySelector('input.team-short-designation').value;
        const selectElement = card.querySelector('select.team-leader');
        const leaderId = selectElement.value;
        const inventoryId = card.querySelector('select.team-inventory').value;
        const sorting = card.querySelector('input.team-sorting').value;
        const color = card.querySelector('input.team-color').value;
        const parentId = card.querySelector('input.team-parent-id').value;
        
        if (!name) {
            showError('Team name cannot be empty');
            return;
        }

        const data = {
            teamId: teamId,
            name: name,
            short_designation: shortDesignation,
            sorting: parseInt(sorting) || 0,
            parentId: parseInt(parentId) || -1,
            color: color || '',
            leader_player_id: leaderId ? leaderId : null,
            team_inventory_id: inventoryId ? inventoryId : null
        };

        //console log
        console.log("updateTeam.php update data: " + JSON.stringify(data));

        fetch("../db/roster_orbat/updateTeam.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    if (typeof showToast === "function") {
                        showToast("Team updated successfully", "success");
                    }
                } else {
                    if (typeof showError === "function") {
                        showError(json.error);
                    }
                }
            } catch (e) {
                if (typeof showError === "function") {
                    showError("JSON parse error: " + e.message);
                }
                console.error("Response text:", text);
            }
        })
        .catch(err => {
            if (typeof showError === "function") {
                showError("Fetch error: " + err.message);
            }
        });
    }

    function deleteTeam(teamId) {
        if (!confirm('Are you sure you want to delete this team? All assigned players will be unassigned.')) {
            return;
        }

        const data = {
            teamId: teamId
        };

        fetch("../db/roster_orbat/deleteTeam.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    if (typeof showError === "function") showError(json.error);
                }
            } catch (e) {
                if (typeof showError === "function") showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => {
            if (typeof showError === "function") showError("Fetch error: " + err.message);
        });
    }

    function addNewTeam() {
        fetch("../db/roster_orbat/addTeam.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            }
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    if (typeof showError === "function") showError(json.error);
                }
            } catch (e) {
                if (typeof showError === "function") showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => {
            if (typeof showError === "function") showError("Fetch error: " + err.message);
        });
    }

    function uploadTeamIcon(teamId) {
        const fileInput = document.getElementById('icon-upload-' + teamId);
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            if (typeof showError === "function") showError('Please select an image file first.');
            return;
        }

        const formData = new FormData();
        formData.append('teamId', teamId);
        formData.append('icon', fileInput.files[0]);

        fetch('../db/roster_orbat/uploadTeamIcon.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    if (typeof showToast === 'function') {
                        showToast('Team icon uploaded successfully', 'success');
                    } else {
                        alert('Team icon uploaded successfully');
                    }
                    fileInput.value = '';
                } else {
                    if (typeof showError === "function") showError(json.error || 'Upload failed');
                }
            } catch (e) {
                if (typeof showError === "function") showError('Parse error: ' + e.message);
                console.error(text);
            }
        })
        .catch(err => {
            if (typeof showError === "function") showError('Upload error: ' + err.message);
        });
    }
</script>

<?php include '../includes/footer.php'; ?>


