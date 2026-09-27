<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';

require_once '../includes/imageHelper.php';
$hasMedication = getMedicationStatus($pdo, $_SESSION['user_id']);

// Get profile ID from URL parameter
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$profileId) {
    $ownProfileQuery = "SELECT Profile_Id FROM player_profiles WHERE User_Id = ? LIMIT 1";
    $ownProfileStmt = $pdo->prepare($ownProfileQuery);
    $ownProfileStmt->execute([$_SESSION['user_id']]);
    $ownProfileId = $ownProfileStmt->fetchColumn();
    
    if ($ownProfileId) {
        header('Location: profile.php?id=' . $ownProfileId);
    } else {
        header('Location: roster.php');
    }
    exit();
}

// Fetch profile details
$query = "SELECT * FROM player_profiles WHERE Profile_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: roster.php');
    exit();
}

$inventoryQuery = "SELECT users.inventory_id FROM users
LEFT JOIN player_profiles ON users.id = player_profiles.User_Id
WHERE player_profiles.Profile_Id = ?;";
$inventoryStmt = $pdo->prepare($inventoryQuery);

$commentsQuery = "SELECT c.*, u.username, pp.Profile_Name 
                FROM player_comments c
                LEFT JOIN users u ON c.Commenter_Player_User_Id = u.id
                LEFT JOIN player_profiles pp ON u.id = pp.User_Id
                WHERE c.Commented_Player_Id = ?
                ORDER BY c.Comment_Id DESC";
$commentsStmt = $pdo->prepare($commentsQuery);
$commentsStmt->execute([$profileId]);
$comments = $commentsStmt->fetchAll();

$inventoryStmt->execute([$profileId]);
$inventory = $inventoryStmt->fetch();

$money = 0;
if ($inventory && $inventory['inventory_id']) {
    $moneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $moneyStmt = $pdo->prepare($moneyQuery);
    $moneyStmt->execute([$inventory['inventory_id']]);
    $money = $moneyStmt->fetchColumn();
}

$loadoutItems = [];
if ($inventory && $inventory['inventory_id']) {
    $loadoutQuery = "SELECT DISTINCT
        ci.Content_Item_Id,
        ci.Inventory_Id,
        ci.Item_Class,
        ci.Item_Quantity,
        ci.Item_Properties,
        m.Market_Item_Id,
        cit.Custom_Item_Type,
        m.Selling_Price,
        IFNULL(i.Item_Display_Name, ci.Item_Class) AS Item_Display_Name
        FROM content_items ci
        LEFT JOIN items i ON i.item_class = ci.Item_Class
        LEFT JOIN item_types it ON it.Item_Type_Id = i.Item_Type
        LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = it.item_classification
        LEFT JOIN item_sorting isort ON isort.Item_Sorting_Type = it.item_classification
        LEFT JOIN (
            SELECT Market_Item_Class, MIN(Market_Item_Id) AS Market_Item_Id
            FROM market
            GROUP BY Market_Item_Class
        ) mm ON mm.Market_Item_Class = ci.Item_Class
        LEFT JOIN market m ON m.Market_Item_Id = mm.Market_Item_Id
        WHERE ci.Inventory_Id = ?
        AND (cit.Custom_Item_Type = 'Primary_Weapon' OR cit.Custom_Item_Type = 'Sidearm' OR cit.Custom_Item_Type = 'Launcher')
        ORDER BY isort.Item_Sorting_Number, ci.Item_Class, ci.Item_Properties;";
    $loadoutStmt = $pdo->prepare($loadoutQuery);
    $loadoutStmt->execute([$inventory['inventory_id']]);
    $loadoutItems = $loadoutStmt->fetchAll();
}

$logStmt = $pdo->prepare("
    INSERT INTO hiddenLogs (Comment) 
    VALUES (?)
");
$logMessage = "Preview Profile page viewed for profile ID '". $profileId ."' (". $profile['Profile_Name'] .") by user '". $_SESSION['username'] ."' from ". $_SERVER['REMOTE_ADDR'] ." at ". date('Y-m-d H:i:s');
$logStmt->execute([$logMessage]);

// Fetch notes
$notesQuery = "SELECT * FROM notes 
            WHERE Note_Profile_Id = ? 
            AND (Note_Public = 1 OR ? = ?)
            ORDER BY Note_Date DESC";
$notesStmt = $pdo->prepare($notesQuery);
$notesStmt->execute([
    $profile['Profile_Id'],
    $_SESSION['user_id'],
    $profile['User_Id']
]);
$notes = $notesStmt->fetchAll();

// Fetch images
$galleryPath = __DIR__ . "/../images/profileUploads/profile" . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT);
$images = [];
if (file_exists($galleryPath)) {
    $images = array_merge(
        glob($galleryPath . "/*.[jJ][pP][gG]"),
        glob($galleryPath . "/*.[jJ][pP][eE][gG]"),
        glob($galleryPath . "/*.[pP][nN][gG]")
    );
}

usort($images, function($a, $b) {
    $pattern = '/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/';
    $hasDateA = preg_match($pattern, basename($a), $matchesA);
    $hasDateB = preg_match($pattern, basename($b), $matchesB);
    if ($hasDateA && $hasDateB) return strcmp($matchesB[1], $matchesA[1]);
    if ($hasDateA) return -1;
    if ($hasDateB) return 1;
    return strcmp(basename($a), basename($b));
});

include '../includes/header.php';
?>

<div class="profile-preview-container">
    <div class="profile-left-pane">
        <div class="profile-left-top">
            <div class="profile-left-avatar-section">
                <?php 
                $profileImage = $imageBaseUrl . "/profiles/profile-" . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png";
                $localProfileImage = __DIR__ . "/../images/profiles/profile-" . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png";
                if (!file_exists($localProfileImage)) {
                    $profileImage = $imageBaseUrl . "/profiles/default.png";
                }
                ?>
                <img src="<?php echo $profileImage; ?>" alt="Profile Image" class="profile-left-avatar profile-avatar-img" onerror="this.onerror=null; this.src='<?php echo $imageBaseUrl; ?>/profiles/default.png';">
                
                <div class="profile-left-status <?php echo strtolower($profile['Status']); ?>">
                    <?php echo htmlspecialchars($profile['Status']); ?>
                </div>
            </div>
            
            <div class="profile-left-details-section">
                <h1 class="profile-name-title">
                    <?php echo htmlspecialchars($profile['Profile_Name']); ?>
                </h1>
                <?php if ($profile['Callsign']): ?>
                    <div class="profile-callsign">
                        "<?php echo htmlspecialchars($profile['Callsign']); ?>"
                    </div>
                <?php else: ?>
                    <div class="profile-callsign-spacer"></div>
                <?php endif; ?>
                
                <div class="profile-compact-list">
                    <?php 
                        $canEditProfile = (isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $profile['User_Id'] || $_SESSION['user_id'] == -1));
                    ?>
                    <div class="profile-stat-row">
                        <span class="profile-stat-label">Role</span>
                        <?php 
                        $roleLower = strtolower($profile['Role'] ?? '');
                        $validRolesLower = array_map('strtolower', ["Officer", "squadleader", "rifle", "medic", "machinegunner", "marksman", "crew", "T-Doll"]);
                        $iconHtml = '';
                        if (in_array($roleLower, $validRolesLower)) {
                            $svgPath = __DIR__ . "/../images/roles/" . $roleLower . ".svg";
                            if (file_exists($svgPath)) {
                                $iconHtml = '<span class="role-icon-wrapper preview-profile-1">' . file_get_contents($svgPath) . '</span>';
                            }
                        }
                        ?>
                        <?php if ($canEditProfile): ?>
                            <div class="custom-role-dropdown-container preview-profile-2">
                                <div class="custom-role-select preview-profile-3" onclick="toggleRoleDropdown(event)">
                                    <div id="custom-role-display-content" class="preview-profile-4">
                                        <?php 
                                        if ($iconHtml) {
                                            echo $iconHtml;
                                        } else {
                                            echo '<span class="role-icon-wrapper preview-profile-5"></span>';
                                        }
                                        
                                        $validRoles = ["Officer", "squadleader", "rifle", "medic", "machinegunner", "marksman", "crew", "T-Doll"];
                                        $displayNames = ["Officer", "Squadleader", "Rifle", "Medic", "Machinegunner", "Marksman", "Crew", "T-Doll"];
                                        
                                        $currentRole = $profile['Role'];
                                        $displayRole = 'Contractor';
                                        if ($currentRole) {
                                            $idx = array_search(strtolower($currentRole), array_map('strtolower', $validRoles));
                                            if ($idx !== false) {
                                                $displayRole = $displayNames[$idx];
                                            } else {
                                                $displayRole = $currentRole;
                                            }
                                        }
                                        echo '<span>' . htmlspecialchars($displayRole) . '</span>';
                                        ?>
                                    </div>
                                    <span class="preview-profile-6">&#9660;</span>
                                </div>
                                
                                <div id="custom-role-options" class="preview-profile-7">
                                    <div class="custom-role-option preview-profile-8" onclick="selectRoleOption('Contractor', event)" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background=''">
                                        <span class="role-icon-wrapper preview-profile-9"></span>
                                        <span>Contractor</span>
                                    </div>
                                    <?php
                                    foreach ($validRoles as $index => $roleVal) {
                                        $roleValLower = strtolower($roleVal);
                                        $svgPath = __DIR__ . "/../images/roles/" . $roleValLower . ".svg";
                                        $optIcon = '<span class="role-icon-wrapper preview-profile-10"></span>';
                                        if (file_exists($svgPath)) {
                                            $optIcon = '<span class="role-icon-wrapper preview-profile-11">' . file_get_contents($svgPath) . '</span>';
                                        }
                                        
                                        $isSelected = (strtolower($currentRole) === $roleValLower);
                                        $bgStyle = $isSelected ? 'background: rgba(255, 255, 255, 0.1);' : '';
                                        
                                        echo '<div class="custom-role-option preview-profile-12" onclick="selectRoleOption(\'' . htmlspecialchars($roleVal) . '\', event)" onmouseover="this.style.background=\'rgba(255,255,255,0.1)\'" onmouseout="this.style.background=\'' . ($isSelected ? 'rgba(255, 255, 255, 0.1)' : '') . '\'">';
                                        echo $optIcon;
                                        echo '<span>' . htmlspecialchars($displayNames[$index]) . '</span>';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php elseif ($profile['Role']): ?>
                            <div class="preview-profile-13">
                                <?php echo $iconHtml; ?>
                                <span class="profile-role-value"><?php echo htmlspecialchars($profile['Role']); ?></span>
                            </div>
                        <?php else: ?>
                            <span class="profile-role-unassigned">Contractor</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($profile['Homeland']): ?>
                        <div class="profile-stat-row">
                            <span class="profile-stat-label">Homeland</span>
                            <span class="profile-stat-value"><?php echo htmlspecialchars($profile['Homeland']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($profile['Combat_Hours']): ?>
                        <div class="profile-stat-row">
                            <span class="profile-stat-label">Combat Hours</span>
                            <span class="profile-stat-value"><?php echo number_format($profile['Combat_Hours']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($profile['Hire_Date']): ?>
                        <div class="profile-stat-row">
                            <span class="profile-stat-label">Hire Date</span>
                            <span class="profile-stat-value"><?php echo date('F j, Y', strtotime($profile['Hire_Date'])); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($inventory && $inventory['inventory_id']): ?>
                        <div class="profile-stat-row">
                            <span class="profile-stat-label">Balance</span>
                            <span class="profile-stat-value-gold"><?php echo number_format($money, 2, ".", "'"); ?> Cr</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-left-info">


            <?php $isOwner = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $profile['User_Id']); ?>
            <?php if ($profile['Description'] || $isOwner): ?>
                <div class="section-header-preview profile-section-header">
                    <h2>Description</h2>
                    <?php if ($isOwner): ?>
                        <button id="btn-edit-desc" class="btn-industrial profile-edit-btn" onclick="toggleEditDescription()">Edit</button>
                    <?php endif; ?>
                </div>
                <div id="description-display" class="profile-desc-display">
                    <?php echo $profile['Description'] ? nl2br(htmlspecialchars($profile['Description'])) : '<span class="profile-desc-empty">No description provided.</span>'; ?>
                </div>
                <?php if ($isOwner): ?>
                    <div id="description-edit" class="d-none">
                        <textarea id="description-input" class="profile-desc-textarea"><?php echo htmlspecialchars($profile['Description'] ?? ''); ?></textarea>
                        <div class="profile-desc-actions">
                            <button class="btn-industrial" onclick="toggleEditDescription()">Cancel</button>
                            <button class="btn-industrial primary" onclick="saveDescription()">Save</button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="section-header-preview profile-section-header profile-section-header-mt">
                <h2>Loadout</h2>
                <?php if ($inventory && $inventory['inventory_id']): ?>
                    <a href="inventory.php?id=<?php echo $inventory['inventory_id']; ?>" class="btn-industrial profile-edit-btn">View Inventory</a>
                <?php endif; ?>
            </div>
            
            <?php if (empty($loadoutItems)): ?>
                <div class="profile-empty-msg-sm">No weapons equipped.</div>
            <?php else: ?>
                <div class="inv-items-container active-container is-weapon-container profile-weapons-container">
                    <?php foreach ($loadoutItems as $item): ?>
                        <?php for ($i = 0; $i < max(1, (int)$item['Item_Quantity']); $i++): ?>
                            <div class="inv-card profile-weapon-card">
                                <div class="inv-card-image profile-weapon-image">
                                    <?php
                                        $imgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $item['Item_Class'], $item['Custom_Item_Type']);
                                        $imagePath = $imgPaths['imagePath'];
                                        $defaultImage = $imgPaths['defaultImage'];
                                        $localImagePath = $imgPaths['fileCheckPath'];
                                    ?>
                                    <img src="<?php echo file_exists($localImagePath) ? $imagePath : $defaultImage; ?>" 
                                         alt="<?php echo htmlspecialchars($item['Item_Display_Name']); ?>"
                                         title="<?php echo htmlspecialchars($item['Item_Display_Name']); ?> (<?php echo htmlspecialchars(str_replace('_', ' ', $item['Custom_Item_Type'])); ?>)"
                                         loading="lazy">
                                </div>
                            </div>
                        <?php endfor; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="profile-right-pane">
        <!-- Notes Section -->
        <div class="profile-half-section">
            <div class="section-header-preview" style="align-items: center;">
                <div style="display: flex; gap: 15px; align-items: baseline;">
                    <h2 id="tab-notes" style="cursor: pointer; transition: opacity 0.2s; opacity: 1; margin: 0;" onclick="toggleNotesComments('notes')">Notes</h2>
                    <h2 style="opacity: 0.3; margin: 0; font-size: 1em;">|</h2>
                    <h2 id="tab-comments" style="cursor: pointer; transition: opacity 0.2s; opacity: 0.5; margin: 0;" onclick="toggleNotesComments('comments')">Comments</h2>
                </div>
                <button class="btn-industrial" id="btn-show-notes" onclick="openNotesModal()">
                    <svg class="btn-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 2.41L17.59 9H13V4.41zM18 20H6V4h5v7h7v9z"/></svg>
                    Show Notes
                </button>
            </div>
            
            <!-- Notes Content -->
            <div id="content-notes" style="display: flex; flex-direction: column; overflow: hidden; height: 100%;">
                <div class="preview-content-list">
                    <?php if (empty($notes)): ?>
                        <div class="profile-empty-msg">No notes available.</div>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="preview-note-item" onclick="openAndScrollToNote(<?php echo $note['Note_Id']; ?>)">
                                <div class="preview-note-date">
                                    <?php
                                        $date = new DateTime($note['Note_Date']);
                                        $date->modify('+50 years');
                                        echo $date->format('F j, Y g:i A'); 
                                    ?>
                                    <?php if (!$note['Note_Public']): ?>
                                        <span class="profile-private-tag">(Private)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="preview-note-content">
                                    <?php echo nl2br(htmlspecialchars($note['Note_Content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Comments Content -->
            <div id="content-comments" style="display: none; padding: 15px; overflow-y: auto; height: 100%;">
                <div class="profile-comments-section" style="margin-top: 0; margin-bottom: 30px;">
                    <?php if (isLoggedIn()): ?>
                        <form id="commentForm" class="preview-form-row profile-note-form profile-comment-form">
                            <textarea id="commentText" placeholder="Write a comment..." rows="3" required></textarea>
                            <div class="profile-note-form-actions" style="justify-content: flex-end;">
                                <button type="button" onclick="addComment()" class="btn-industrial primary profile-comment-submit">Post Comment</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div class="profile-comments-list">
                        <?php
                        if (empty($comments)): ?>
                        <div class="profile-comments-empty">No comments yet.</div>
                        <?php else:
                            foreach ($comments as $comment): ?>
                                <div class="preview-note-item profile-full-note profile-comment" style="cursor: default; padding: 15px;" data-id="<?php echo $comment['Comment_Id']; ?>">
                                    <div class="preview-note-date" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                        <span class="profile-comment-author" style="font-weight: bold; color: var(--corp-blue-light);"><?php echo htmlspecialchars($comment['Profile_Name'] ?? $comment['username']); ?> <span style="font-weight: normal; color: var(--text-secondary); margin-left: 5px;"><?php echo htmlspecialchars($comment['Comment_Date']); ?></span></span>
                                        <?php if ($_SESSION['user_id'] === -1): ?>
                                            <button onclick="deleteComment(<?php echo $comment['Comment_Id']; ?>)" 
                                                    style="background: transparent; border: none; color: #e74c3c; cursor: pointer; font-size: 1.2rem; line-height: 1; padding: 0;">&times;</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="preview-note-content profile-comment-text">
                                        <?php echo nl2br(htmlspecialchars($comment['Comment_Text'])); ?>
                                    </div>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </div>
                </div>
            </div>
        </div>


        <!-- Images Section -->
        <div class="profile-half-section">
            <div class="section-header-preview">
                <h2>Gallery</h2>
                <button class="btn-industrial" onclick="openImagesModal()">
                    <svg class="btn-icon" viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                    Show Images
                </button>
            </div>
            <div class="preview-content-list">
                <?php if (empty($images)): ?>
                    <div class="profile-empty-msg">No images uploaded.</div>
                <?php else: ?>
                    <?php foreach ($images as $image): ?>
                        <img src="<?php echo $imageBaseUrl . '/profileUploads/profile' . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT) . '/' . basename($image); ?>" 
                             alt="Gallery Image" class="preview-image-item profile-gallery-image" loading="lazy" onclick="showOverlay(this.src, '<?php echo basename($image); ?>')">
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<!-- Notes Modal -->
<div id="notesModal" class="preview-modal-overlay">
    <div class="preview-modal-content">
        <div class="preview-modal-header">
            <h2>All Notes</h2>
            <button class="preview-modal-close" onclick="closeNotesModal()">&times;</button>
        </div>
        <div class="preview-modal-body">
            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $profile['User_Id']): ?>
                <div class="preview-form-row profile-note-form">
                    <textarea id="noteContent" placeholder="Write a new note..." rows="3"></textarea>
                    <div class="profile-note-form-actions">
                        <label class="profile-note-public-label">
                            <input type="checkbox" id="notePublic" checked> Public
                        </label>
                        <button class="btn-industrial primary" onclick="addNote()">Add Note</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="full-notes-list">
                <?php if (empty($notes)): ?>
                    <div class="profile-empty-msg">No notes available.</div>
                <?php else: ?>
                    <?php foreach ($notes as $note): ?>
                        <div class="preview-note-item profile-full-note" id="modal-note-<?php echo $note['Note_Id']; ?>">
                            <div class="profile-full-note-header">
                                <div class="preview-note-date">
                                    <?php
                                        $date = new DateTime($note['Note_Date']);
                                        $date->modify('+50 years');
                                        echo $date->format('F j, Y g:i A'); 
                                    ?>
                                    <?php if (!$note['Note_Public']): ?>
                                        <span class="profile-private-tag">(Private)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $profile['User_Id']): ?>
                                    <button onclick="deleteNote(<?php echo $note['Note_Id']; ?>)" class="profile-delete-btn">&times;</button>
                                <?php endif; ?>
                            </div>
                            <div class="preview-note-content">
                                <?php echo nl2br(htmlspecialchars($note['Note_Content'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Images Modal -->
<div id="imagesModal" class="preview-modal-overlay">
    <div class="preview-modal-content profile-large-modal">
        <div class="preview-modal-header">
            <h2>Gallery</h2>
            <button class="preview-modal-close" onclick="closeImagesModal()">&times;</button>
        </div>
        <div class="preview-modal-body">
            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $profile['User_Id']): ?>
                <div class="preview-form-row profile-image-form">
                    <input type="file" id="imageUpload" accept=".jpg,.jpeg,.png,.PNG,.JPG,.JPEG" multiple class="profile-file-input">
                    <input type="text" id="imageDescription" placeholder="Image description (optional)" class="profile-image-desc-input">
                    <button class="btn-industrial primary" onclick="uploadImages()">Upload</button>
                </div>
            <?php endif; ?>
            
            <div class="full-images-list">
                <?php if (empty($images)): ?>
                    <div class="profile-empty-msg">No images uploaded.</div>
                <?php else: ?>
                    <?php foreach ($images as $image): ?>
                        <?php $imgSrc = $imageBaseUrl . '/profileUploads/profile' . str_pad($profile['Profile_Id'], 3, '0', STR_PAD_LEFT) . '/' . basename($image); ?>
                        <div class="profile-image-wrapper">
                            <img src="<?php echo $imgSrc; ?>" 
                                 alt="Gallery Image" loading="lazy"
                                 onclick="showOverlay('<?php echo $imgSrc; ?>', '<?php echo basename($image); ?>')">
                            <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $profile['User_Id'] || $_SESSION['user_id'] == -1)): ?>
                                <button onclick="deleteImage('<?php echo basename($image); ?>')" class="profile-image-delete-btn">&times;</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Full Image Overlay -->
<div id="imageOverlay" class="profile-image-overlay">
    <button class="profile-image-overlay-close" onclick="closeOverlay()">&times;</button>
    <div class="profile-image-overlay-content">
        <img id="overlayImage" src="" alt="Full size image">
        <div class="profile-image-overlay-details">
            <div class="profile-image-overlay-description">
                <h4>Description</h4>
                <p id="overlayDescription" class="overlay-description-text">No description.</p>
                <div id="overlayDescriptionEdit" class="overlay-description-edit preview-profile-14">
                    <textarea id="overlayDescriptionInput" placeholder="Add a description..."></textarea>
                    <div class="preview-profile-15">
                        <button onclick="saveImageDescription()" class="overlay-desc-save-btn">Save</button>
                        <button onclick="cancelEditDescription()" class="overlay-desc-cancel-btn">Cancel</button>
                    </div>
                </div>
                <button id="overlayEditDescBtn" onclick="editDescription()" class="overlay-desc-edit-btn preview-profile-16">Edit Description</button>
            </div>
            <div class="profile-image-overlay-comments">
                <h4>Comments</h4>
                <div id="overlayCommentsList" class="overlay-comments-list"></div>
                <?php if (isLoggedIn()): ?>
                <div class="overlay-comment-form">
                    <textarea id="overlayCommentInput" placeholder="Write a comment..."></textarea>
                    <button onclick="postImageComment()" class="overlay-comment-submit">Post</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    const profileId = <?php echo $profile['Profile_Id']; ?>;

    // Modals
    function openNotesModal() {
        document.getElementById('notesModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeNotesModal() {
        document.getElementById('notesModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function openAndScrollToNote(noteId) {
        openNotesModal();
        setTimeout(() => {
            const noteEl = document.getElementById('modal-note-' + noteId);
            if (noteEl) {
                noteEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                document.querySelectorAll('.highlighted-note').forEach(el => el.classList.remove('highlighted-note'));
                noteEl.classList.add('highlighted-note');
                setTimeout(() => {
                    noteEl.classList.remove('highlighted-note');
                }, 3000);
            }
        }, 100);
    }
    
    function openImagesModal() {
        document.getElementById('imagesModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeImagesModal() {
        document.getElementById('imagesModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }

    // Close on outside click
    document.querySelectorAll('.preview-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
    });
    
    // Notes API
    function addNote() {
        const content = document.getElementById('noteContent').value;
        const isPublic = document.getElementById('notePublic').checked ? 1 : 0;
        if (!content.trim()) return alert('Note content is required.');
        
        fetch('../db/profile_social/addNote.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({profileId: profileId, content: content, public: isPublic})
        }).then(r => r.json()).then(data => {
            if (data.success) location.reload();
            else alert(data.error);
        });
    }
    function deleteNote(id) {
        if (!confirm('Delete note?')) return;
        fetch('../db/profile_social/deleteNote.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({noteId: id})
        }).then(r => r.json()).then(data => {
            if (data.success) location.reload();
            else alert(data.error);
        });
    }

    // Images API
    function uploadImages() {
        const input = document.getElementById('imageUpload');
        if (!input.files.length) return alert('Select images first.');
        
        const desc = document.getElementById('imageDescription').value;
        const formData = new FormData();
        for (let file of input.files) formData.append('images[]', file);
        formData.append('profileId', profileId);
        formData.append('description', desc);
        
        fetch('../db/profile_social/uploadProfileImages.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) location.reload();
            else alert(data.error);
        });
    }
    function deleteImage(filename) {
        if (!confirm('Delete image?')) return;
        fetch('../db/profile_social/deleteProfileImage.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({profileId: profileId, filename: filename})
        }).then(r => r.json()).then(data => {
            if (data.success) location.reload();
            else alert(data.error);
        });
    }

    // Role API
    function updateRole(newRole) {
        fetch('../db/profile_social/updateProfileRole.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({profileId: profileId, role: newRole})
        }).then(r => r.json()).then(data => {
            if (!data.success) {
                alert(data.error);
            }
            location.reload();
        }).catch(err => {
            alert('Error updating role');
            location.reload();
        });
    }

    function toggleRoleDropdown(e) {
        if(e) e.stopPropagation();
        const opts = document.getElementById('custom-role-options');
        if (opts) {
            opts.style.display = opts.style.display === 'none' ? 'block' : 'none';
        }
    }

    function selectRoleOption(newRole, event) {
        if(event) event.stopPropagation();
        const opts = document.getElementById('custom-role-options');
        if (opts) opts.style.display = 'none';
        updateRole(newRole);
    }

    document.addEventListener('click', function(e) {
        const container = document.querySelector('.custom-role-dropdown-container');
        if (container && !container.contains(e.target)) {
            const opts = document.getElementById('custom-role-options');
            if (opts) opts.style.display = 'none';
        }
    });
    // Description API
    function toggleEditDescription() {
        const display = document.getElementById('description-display');
        const edit = document.getElementById('description-edit');
        const btn = document.getElementById('btn-edit-desc');
        if (edit.style.display !== 'block') {
            display.style.display = 'none';
            edit.style.display = 'block';
            btn.style.display = 'none';
        } else {
            display.style.display = 'block';
            edit.style.display = 'none';
            btn.style.display = 'block';
        }
    }

    function saveDescription() {
        const newDesc = document.getElementById('description-input').value;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        
        fetch('../db/profile_social/updateProfileDescription.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ profileId: profileId, description: newDesc })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Failed to update description');
            }
        }).catch(err => {
            alert('Error updating description');
        });
    }

    let currentOverlayFilename = '';
    const currentUserId = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : -999; ?>;

    function showOverlay(imageSrc, filename) {
        const overlay = document.getElementById('imageOverlay');
        const overlayImage = document.getElementById('overlayImage');
        overlayImage.src = imageSrc;
        currentOverlayFilename = filename;
        overlay.classList.add('active');

        const isOwner = <?php echo (isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $profile['User_Id'] || $_SESSION['user_id'] == -1)) ? 'true' : 'false'; ?>;
        
        document.getElementById('overlayEditDescBtn').style.display = isOwner ? 'inline-block' : 'none';
        document.getElementById('overlayDescriptionEdit').style.display = 'none';
        document.getElementById('overlayDescription').style.display = 'block';

        loadImageData(profileId, filename);
        document.addEventListener('keydown', handleEscKey);
    }

    document.getElementById('imageOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            closeOverlay();
        }
    });

    function closeOverlay() {
        const overlay = document.getElementById('imageOverlay');
        if (overlay) overlay.classList.remove('active');
        document.getElementById('overlayDescription').textContent = 'Loading...';
        document.getElementById('overlayCommentsList').innerHTML = '';
        document.removeEventListener('keydown', handleEscKey);
        currentOverlayFilename = '';
    }

    function handleEscKey(e) {
        if (e.key === 'Escape') {
            closeOverlay();
        }
    }

    function loadImageData(profileId, filename) {
        fetch(`../db/profile_social/getImageData.php?profileId=${profileId}&filename=${encodeURIComponent(filename)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('overlayDescription').textContent = data.description || 'No description.';
                    renderOverlayComments(data.comments);
                }
            })
            .catch(() => {});
    }

    function renderOverlayComments(comments) {
        const list = document.getElementById('overlayCommentsList');
        if (!comments || comments.length === 0) {
            list.innerHTML = '<div class="overlay-comments-empty">No comments yet.</div>';
            return;
        }
        list.innerHTML = comments.map(c => {
            const name = c.Profile_Name || c.username || 'Unknown';
            const canDelete = (currentUserId === -1 || currentUserId == c.User_Id);
            return `<div class="overlay-comment" data-id="${c.Comment_Id}">
                <div class="overlay-comment-header">
                    <span class="overlay-comment-author">${escapeHtml(name)} <small>${escapeHtml(c.Created_At)}</small></span>
                    ${canDelete ? `<button onclick="deleteImageComment(${c.Comment_Id})" class="overlay-comment-delete">&times;</button>` : ''}
                </div>
                <div class="overlay-comment-text">${escapeHtml(c.Comment_Text).replace(/\n/g, '<br>')}</div>
            </div>`;
        }).join('');
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function editDescription() {
        const currentDesc = document.getElementById('overlayDescription').textContent;
        document.getElementById('overlayDescriptionInput').value = currentDesc === 'No description.' ? '' : currentDesc;
        document.getElementById('overlayDescriptionEdit').style.display = 'flex';
        document.getElementById('overlayEditDescBtn').style.display = 'none';
        document.getElementById('overlayDescription').style.display = 'none';
    }

    function cancelEditDescription() {
        document.getElementById('overlayDescriptionEdit').style.display = 'none';
        document.getElementById('overlayEditDescBtn').style.display = 'inline-block';
        document.getElementById('overlayDescription').style.display = 'block';
    }

    function saveImageDescription() {
        const desc = document.getElementById('overlayDescriptionInput').value.trim();
        fetch('../db/profile_social/updateImageDescription.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profileId: profileId, filename: currentOverlayFilename, description: desc })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('overlayDescription').textContent = desc || 'No description.';
                cancelEditDescription();
            } else {
                alert(data.error || 'Error saving description');
            }
        })
        .catch(() => alert('Error saving description'));
    }

    function postImageComment() {
        const input = document.getElementById('overlayCommentInput');
        const text = input.value.trim();
        if (!text) { alert('Please enter a comment'); return; }

        fetch('../db/profile_social/addImageComment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profileId: profileId, filename: currentOverlayFilename, commentText: text })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                loadImageData(profileId, currentOverlayFilename);
            } else {
                alert(data.error || 'Error posting comment');
            }
        })
        .catch(() => alert('Error posting comment'));
    }

    function deleteImageComment(commentId) {
        if (!confirm('Delete this comment?')) return;
        fetch('../db/profile_social/deleteImageComment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ commentId: commentId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadImageData(profileId, currentOverlayFilename);
            } else {
                alert(data.error || 'Error deleting comment');
            }
        })
        .catch(() => alert('Error deleting comment'));
    }
    function toggleNotesComments(tab) {
        const notesTab = document.getElementById('tab-notes');
        const commentsTab = document.getElementById('tab-comments');
        const notesContent = document.getElementById('content-notes');
        const commentsContent = document.getElementById('content-comments');
        const showNotesBtn = document.getElementById('btn-show-notes');

        if (tab === 'notes') {
            notesTab.style.opacity = '1';
            commentsTab.style.opacity = '0.5';
            notesContent.style.display = 'flex';
            commentsContent.style.display = 'none';
            if (showNotesBtn) showNotesBtn.style.display = 'inline-flex';
        } else {
            notesTab.style.opacity = '0.5';
            commentsTab.style.opacity = '1';
            notesContent.style.display = 'none';
            commentsContent.style.display = 'block';
            if (showNotesBtn) showNotesBtn.style.display = 'none';
        }
    }
</script>

<?php include '../includes/footer.php'; ?>


