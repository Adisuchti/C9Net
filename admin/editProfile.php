<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Get profile ID from URL parameter
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$profileId) {
    header('Location: ../views/roster.php');
    exit();
}

// Fetch profile details
$query = "SELECT * FROM player_profiles WHERE Profile_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: ../views/roster.php');
    exit();
}

// Get all teams
$teamsQuery = "SELECT Fireteam_Id, Fireteam_Name FROM team_hierarchy ORDER BY Fireteam_Name";
$teamsStmt = $pdo->prepare($teamsQuery);
$teamsStmt->execute();
$teams = $teamsStmt->fetchAll();

// Get all users without profiles (except current profile's user)
$usersQuery = "SELECT u.* FROM users u 
               LEFT JOIN player_profiles p ON u.id = p.User_Id 
               WHERE p.Profile_Id IS NULL OR p.Profile_Id = ?
               ORDER BY u.username";
$usersStmt = $pdo->prepare($usersQuery);
$usersStmt->execute([$profileId]);
$users = $usersStmt->fetchAll();

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="editProfile-container">
    <div class="editProfile-header">
        <h2 class="editProfile-title">Edit Profile: <span class="preview-editProfile-1"><?php echo htmlspecialchars($profile['Profile_Name']); ?></span></h2>
    </div>

    <!-- Avatar Upload Section -->
    <div class="editProfile-form-card preview-editProfile-2">
        <div class="preview-editProfile-3">
            <?php 
            $imageBaseUrl = '../images';
            $profileImage = $imageBaseUrl . "/profiles/profile-" . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png";
            $localProfileImage = __DIR__ . "/../images/profiles/profile-" . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png";
            if (!file_exists($localProfileImage)) {
                $profileImage = $imageBaseUrl . "/profiles/default.png";
            }
            ?>
            <img src="<?php echo $profileImage; ?>" alt="Avatar" class="preview-editProfile-avatar" onerror="this.onerror=null; this.src='<?php echo $imageBaseUrl; ?>/profiles/default.png';">
        </div>
        <div class="preview-editProfile-4">
            <h3 class="preview-editProfile-5">Profile Image</h3>
            <form id="ProfileUploadForm" enctype="multipart/form-data" class="preview-editProfile-6">
                <input type="file" id="imageUpload" name="images[]" accept=".png,.PNG" class="preview-editProfile-7">
                <button type="button" onclick="uploadProfileImage()" class="btn-industrial primary preview-editProfile-8">Upload New Image</button>
                <small class="preview-editProfile-9">PNG only. Uploading will replace the current image instantly.</small>
            </form>
        </div>
    </div>

    <div class="editProfile-form-card">
        <form id="profileEditForm">
            <input type="hidden" name="profileId" value="<?php echo $profile['Profile_Id']; ?>">
            
            <div class="editProfile-grid">
                <div class="editProfile-input-group">
                    <label for="profileName">Name</label>
                    <input type="text" id="profileName" name="profileName" class="editProfile-input" value="<?php echo htmlspecialchars($profile['Profile_Name']); ?>" required>
                </div>

                <div class="editProfile-input-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="editProfile-select" required>
                        <option value="Active" <?php echo $profile['Status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $profile['Status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="editProfile-input-group">
                    <label for="callsign">Callsign</label>
                    <input type="text" id="callsign" name="callsign" class="editProfile-input" value="<?php echo htmlspecialchars($profile['Callsign']); ?>">
                </div>

                <div class="editProfile-input-group">
                    <label for="role">Role</label>
                    <input type="text" id="role" name="role" class="editProfile-input" value="<?php echo htmlspecialchars($profile['Role']); ?>">
                </div>

                <div class="editProfile-input-group">
                    <label for="homeland">Homeland</label>
                    <input type="text" id="homeland" name="homeland" class="editProfile-input" value="<?php echo htmlspecialchars($profile['Homeland']); ?>">
                </div>

                <div class="editProfile-input-group">
                    <label for="combatHours">Combat Hours</label>
                    <input type="number" id="combatHours" name="combatHours" class="editProfile-input" value="<?php echo htmlspecialchars($profile['Combat_Hours']); ?>">
                </div>

                <div class="editProfile-input-group">
                    <label for="hireDate">Hire Date</label>
                    <input type="date" id="hireDate" name="hireDate" class="editProfile-input" value="<?php echo $profile['Hire_Date']; ?>">
                </div>

                <div class="editProfile-input-group">
                    <label for="assignment">Team Assignment</label>
                    <select id="assignment" name="assignment" class="editProfile-select">
                        <option value="-1">Unassigned</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?php echo $team['Fireteam_Id']; ?>" <?php echo $profile['Assignment'] == $team['Fireteam_Id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($team['Fireteam_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="editProfile-input-group full-width">
                    <label for="userId">Linked User Account</label>
                    <select id="userId" name="userId" class="editProfile-select">
                        <option value="null">No User Account</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $profile['User_Id'] == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="editProfile-input-group full-width">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="editProfile-textarea" rows="4"><?php echo htmlspecialchars($profile['Description']); ?></textarea>
                </div>
            </div>

            <div class="editProfile-actions">
                <button type="button" onclick="deleteProfile()" class="btn-industrial danger">Delete Profile</button>
                <div class="editProfile-actions-right">
                    <button type="button" onclick="window.location.href='../views/profile.php?id=<?php echo $profileId; ?>'" class="btn-industrial">Cancel</button>
                    <button type="button" onclick="saveProfileChanges()" class="btn-industrial primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function saveProfileChanges() {
        const form = document.getElementById('profileEditForm');
        const data = {
            profileId: form.profileId.value,
            profileName: form.profileName.value,
            status: form.status.value,
            callsign: form.callsign.value,
            assignment: form.assignment.value,
            role: form.role.value,
            homeland: form.homeland.value,
            combatHours: parseInt(form.combatHours.value) || 0,
            hireDate: form.hireDate.value,
            certs: "",
            description: form.description.value,
            userId: parseInt(form.userId.value)
        };

        fetch('../db/profile_social/updateProfile.php', {
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
                    window.location.href = '../views/profile.php?id=' + form.profileId.value;
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

    function deleteProfile() {
        if (!confirm("Are you sure you want to delete this profile? This action cannot be undone.")) {
            return;
        }

        const profileId = document.getElementById('profileEditForm').profileId.value;

        fetch('../db/profile_social/deleteProfile.php', {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({ profileId })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    window.location.href = '../views/roster.php';
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

    function uploadProfileImage() {
        const input = document.getElementById('imageUpload');
        if (!input.files.length) {
            if (typeof showToast === 'function') showToast('Please select an image to upload', 'error');
            else alert('Please select an image to upload');
            return;
        }

        const formData = new FormData();
        for (let file of input.files) {
            formData.append('images[]', file);
        }
        formData.append('profileId', '<?php echo $profile['Profile_Id']; ?>');

        fetch('../db/profile_social/uploadProfileImage.php', {
            method: 'POST',
            headers: { 
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: formData
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    if (typeof showToast === 'function') showToast('Profile image updated successfully', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    if (typeof showToast === 'function') showToast(json.error, 'error');
                    else alert(json.error);
                }
            } catch (e) {
                if (typeof showToast === 'function') showToast('Error uploading image', 'error');
                else alert('Error uploading image');
            }
        })
        .catch(err => {
            if (typeof showToast === 'function') showToast('Error uploading image', 'error');
            else alert('Error uploading image');
        });
    }
</script>

<?php include '../includes/footer.php'; ?>
