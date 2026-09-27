<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Fetch all profiles
$query = "SELECT p.*, t.Fireteam_Name FROM player_profiles p LEFT JOIN team_hierarchy t ON p.Assignment = t.Fireteam_Id ORDER BY p.Profile_Name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute();
$profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="manageProfiles-container preview-manageProfiles-1">
    <div class="manageProfiles-header preview-manageProfiles-2">
        <h2 class="manageProfiles-title preview-manageProfiles-3">Manage Profiles</h2>
        <button class="btn-industrial preview-manageProfiles-4" onclick="addPlayer()">+ Add Player</button>
    </div>

    <div class="manageProfiles-grid preview-manageProfiles-5">
        <?php foreach ($profiles as $profile): ?>
            <div class="manageProfiles-card preview-manageProfiles-6">
                <div>
                    <h3 class="preview-manageProfiles-7">
                        <span title="<?php echo htmlspecialchars($profile['Profile_Name']); ?>" class="preview-manageProfiles-8">
                            <?php echo htmlspecialchars($profile['Profile_Name']); ?>
                        </span>
                        <?php if($profile['Status'] === 'Active'): ?>
                            <span class="preview-manageProfiles-9">Active</span>
                        <?php else: ?>
                            <span class="preview-manageProfiles-10"><?php echo htmlspecialchars($profile['Status']); ?></span>
                        <?php endif; ?>
                    </h3>
                    <p class="preview-manageProfiles-11">
                        <strong>Role:</strong> <?php echo htmlspecialchars($profile['Role'] ?: 'None'); ?>
                    </p>
                    <p class="preview-manageProfiles-12">
                        <strong>Team:</strong> <?php echo htmlspecialchars($profile['Fireteam_Name'] ?: 'Unassigned'); ?>
                    </p>
                </div>
                <button class="btn-industrial preview-manageProfiles-13" onclick="window.location.href='editProfile.php?id=<?php echo $profile['Profile_Id']; ?>'">Edit / Assign Profile</button>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($profiles)): ?>
            <div class="preview-manageProfiles-14">
                No profiles found.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function addPlayer() {
        fetch("../db/roster_orbat/addPlayer.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({})
        })
        .then(async function(response) {
            var text = await response.text();
            try {
                var json = JSON.parse(text);
                if (json.success) { 
                    if (typeof showToast === "function") showToast('New player profile created!', 'success');
                    setTimeout(() => location.reload(), 1000);
                }
                else { 
                    if (typeof showError === "function") showError(json.error); 
                    else alert(json.error);
                }
            } catch (e) { 
                if (typeof showError === "function") showError("JSON parse error: " + e.message); 
                else alert("JSON parse error: " + e.message);
            }
        })
        .catch(function(err) { 
            if (typeof showError === "function") showError("Fetch error: " + err.message); 
            else alert("Fetch error: " + err.message);
        });
    }
</script>

<?php include '../includes/footer.php'; ?>
