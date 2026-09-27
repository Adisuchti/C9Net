<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

$userId = $_SESSION['user_id'] ?? null;
$canEditDashboard = false;
$isAdmin = ($_SESSION['username'] === 'admin');

// Permissions logic
$allowedUserIds = [-1, 16, 25, 31, 34, 32, 39];
if (in_array($userId, $allowedUserIds)) {
    $canEditDashboard = true;
} else {
    $stmt = $pdo->prepare("SELECT Role FROM player_profiles WHERE User_Id = ?");
    $stmt->execute([$userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty(array_intersect($roles, ['Officer', 'squadleader']))) {
        $canEditDashboard = true;
    }
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C9 - Preview Dashboard</title>
    <link rel="stylesheet" href="../styles/preview_styles.css?t=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-grid preview-dashboard">
        <!-- TOP: Notebook Section (Multi-page) -->
        <div class="dashboard-panel dashboard-notebook-panel full-width">
            <div class="dashboard-panel-header notebook-panel-header">
                <div class="notebook-header-left">
                    <h2>Notebook</h2>
                    <div class="notebook-tabs-container">
                        <div id="notebookTabs" class="notebook-tabs">
                            <!-- Tabs loaded via JS -->
                        </div>
                        <?php if($canEditDashboard): ?>
                            <button class="dashboard-add-btn small-btn" onclick="addNotebookPage()" title="Add new page">+</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if($canEditDashboard): ?>
                    <div class="notebook-header-actions">
                        <button class="dashboard-icon-btn edit" id="notebookEditBtn" onclick="toggleNotebookEdit()" title="Edit page">&#9998;</button>
                        <button class="dashboard-icon-btn delete" id="notebookDeleteBtn" onclick="deleteCurrentNotebookPage()" title="Delete page">&#10005;</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="dashboard-notebook-body">
                <!-- View Mode -->
                <div id="notebookDisplay" class="dashboard-notebook-content"></div>
                <!-- Edit Mode -->
                <div id="notebookEditor" class="dashboard-notebook-editor preview-dashboard-1">
                    <div class="notebook-title-edit-row">
                        <label>Title:</label>
                        <input type="text" id="notebookTitleInput" class="dashboard-notebook-title-input" placeholder="Page Title">
                    </div>
                    <textarea id="notebookTextarea" class="dashboard-notebook-textarea" placeholder="Write notes here..."></textarea>
                    <div class="dashboard-notebook-actions">
                        <button class="dashboard-add-btn" onclick="saveNotebook()">Save</button>
                        <button class="dashboard-add-btn dashboard-cancel-btn" onclick="cancelNotebookEdit()">Cancel</button>
                    </div>
                </div>
            </div>
            <div id="notebookStatus" class="dashboard-notebook-status"></div>
        </div>

        <div class="dashboard-bottom-row">
            <!-- BOTTOM LEFT: Vehicles & Assets -->
            <div class="dashboard-panel dashboard-assets-panel">
                <div class="dashboard-panel-header">
                    <h2>Vehicles & Assets</h2>
                    <div class="dashboard-panel-header-actions">
                        <?php if($canEditDashboard): ?>
                            <button class="dashboard-add-btn" onclick="showAssetAdd()">+ Asset</button>
                            <button class="dashboard-add-btn" onclick="showAssetGroupAdd()">+ Group</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="dashboard-panel-body dashboard-assets-body" id="assetsContainer">
                    <div class="dashboard-scroll-wrap">
                        <div class="dashboard-assets-groups" id="assetGroups"></div>
                        <div class="dashboard-assets-unassigned" id="assetUnassigned">
                            <h3>Unassigned Assets</h3>
                            <div class="dashboard-assets-pool" id="assetPool"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BOTTOM RIGHT: ORBAT -->
            <div class="dashboard-panel dashboard-orbat-panel">
                <div class="dashboard-panel-header">
                    <h2>ORBAT - Order of Battle</h2>
                    <?php if($canEditDashboard): ?>
                        <button class="dashboard-add-btn" onclick="showOrbatTeamAdd()">+ Team</button>
                    <?php endif; ?>
                </div>
                <div class="dashboard-panel-body dashboard-orbat-body" id="orbatContainer">
                    <div class="dashboard-scroll-wrap">
                        <div class="dashboard-orbat-teams" id="orbatTeams"></div>
                        <div class="dashboard-orbat-unassigned" id="orbatUnassigned">
                            <h3>Unassigned Personnel</h3>
                            <div class="dashboard-orbat-pool" id="orbatPool"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ORBAT Team Add Modal -->
    <div id="dashboard-orbat-team-overlay" class="dashboard-overlay preview-dashboard-2">
        <div class="dashboard-modal">
            <h3>Create ORBAT Team</h3>
            <form id="orbatTeamForm" class="dashboard-modal-form">
                <div class="dashboard-modal-row">
                    <label for="orbatTeamName">Team Name:</label>
                    <input type="text" id="orbatTeamName" required onkeypress="if(event.key==='Enter'){event.preventDefault(); createOrbatTeam();}">
                </div>
                <div class="dashboard-modal-actions">
                    <button type="button" class="dashboard-modal-submit" onclick="createOrbatTeam()">Create</button>
                    <button type="button" class="dashboard-modal-cancel" onclick="hideOrbatTeamOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ORBAT Rename Team Modal -->
    <div id="dashboard-orbat-rename-overlay" class="dashboard-overlay preview-dashboard-3">
        <div class="dashboard-modal">
            <h3>Rename Team</h3>
            <form id="orbatRenameForm" class="dashboard-modal-form">
                <input type="hidden" id="orbatRenameId">
                <div class="dashboard-modal-row">
                    <label for="orbatRenameName">Team Name:</label>
                    <input type="text" id="orbatRenameName" required>
                </div>
                <div class="dashboard-modal-actions">
                    <button type="button" class="dashboard-modal-submit" onclick="saveOrbatTeamRename()">Save</button>
                    <button type="button" class="dashboard-modal-cancel" onclick="hideOrbatRenameOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Asset Add/Edit Modal -->
    <div id="dashboard-asset-overlay" class="dashboard-overlay preview-dashboard-asset" style="display: none;">
        <div class="dashboard-modal" style="max-width: 600px;">
            <h3 id="assetModalTitle">Add Asset</h3>
            <form id="assetForm" class="dashboard-modal-form">
                <input type="hidden" id="assetId">
                <div class="dashboard-modal-row">
                    <label for="assetName">Asset Name:</label>
                    <input type="text" id="assetName" required>
                </div>
                <div class="dashboard-modal-row">
                    <label for="assetClassName">Class Name:</label>
                    <input type="text" id="assetClassName" required>
                </div>
                <div class="dashboard-modal-row" style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label for="assetQuantity">Quantity:</label>
                        <input type="number" id="assetQuantity" required value="1" min="1">
                    </div>
                    <div style="flex:1;">
                        <label for="assetAmmo">Ammo (Optional):</label>
                        <input type="number" id="assetAmmo">
                    </div>
                </div>
                <div class="dashboard-modal-row" style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label for="assetHealth">Health (Optional):</label>
                        <input type="number" id="assetHealth" step="0.01">
                    </div>
                    <div style="flex:1;">
                        <label for="assetFuel">Fuel (Optional):</label>
                        <input type="number" id="assetFuel" step="0.01">
                    </div>
                </div>
                <hr style="margin: 15px 0; border: 1px solid rgba(255,255,255,0.1);">
                <div class="dashboard-modal-row">
                    <label>Upload Image (Optional):</label>
                    <input type="file" id="assetImageFile" accept="image/png,image/jpg,image/jpeg" onchange="previewVehicleImageUpload()">
                    <div id="vehicleImageUploadPreview" style="display:none; margin-top:10px;">
                        <img id="uploadVehiclePreviewImg" src="" alt="Upload Preview" style="max-width:100px; max-height:100px; display:block;">
                        <p id="uploadVehiclePreviewFilename" style="font-size: 0.8em; color: #aaa;"></p>
                    </div>
                </div>
                <div class="dashboard-modal-actions">
                    <button type="button" class="dashboard-modal-submit" onclick="saveAsset()">Save</button>
                    <button type="button" class="dashboard-modal-cancel" onclick="hideAssetOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Asset Group Add Modal -->
    <div id="dashboard-asset-group-overlay" class="dashboard-overlay preview-dashboard-4">
        <div class="dashboard-modal">
            <h3>Create Asset Group</h3>
            <form id="assetGroupForm" class="dashboard-modal-form">
                <div class="dashboard-modal-row">
                    <label for="assetGroupName">Group Name:</label>
                    <input type="text" id="assetGroupName" required>
                </div>
                <div class="dashboard-modal-actions">
                    <button type="button" class="dashboard-modal-submit" onclick="createAssetGroup()">Create</button>
                    <button type="button" class="dashboard-modal-cancel" onclick="hideAssetGroupOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Asset Group Rename Modal -->
    <div id="dashboard-asset-group-rename-overlay" class="dashboard-overlay preview-dashboard-5">
        <div class="dashboard-modal">
            <h3>Rename Asset Group</h3>
            <form id="assetGroupRenameForm" class="dashboard-modal-form">
                <input type="hidden" id="assetGroupRenameId">
                <div class="dashboard-modal-row">
                    <label for="assetGroupRenameName">Group Name:</label>
                    <input type="text" id="assetGroupRenameName" required>
                </div>
                <div class="dashboard-modal-actions">
                    <button type="button" class="dashboard-modal-submit" onclick="saveAssetGroupRename()">Save</button>
                    <button type="button" class="dashboard-modal-cancel" onclick="hideAssetGroupRenameOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const canEditDashboard = <?php echo $canEditDashboard ? 'true' : 'false'; ?>;
    
    // ============================================================
    // UTILS
    // ============================================================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function escapeJsString(text) {
        if (!text) return '';
        return text.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    // ============================================================
    // NOTEBOOK (MULTI-PAGE)
    // ============================================================
    let notebookPages = [];
    let currentPageId = null;

    function loadNotebookPages() {
        fetch('../db/notebook/getNotebookPages.php')
            .then(r => r.json())
            .then(json => {
                if (json.success) {
                    notebookPages = json.pages;
                    renderNotebookTabs();
                    if (notebookPages.length > 0) {
                        // Select first page by default if none selected, or keep selection
                        if (!currentPageId || !notebookPages.find(p => p.id === currentPageId)) {
                            selectNotebookPage(notebookPages[0].id);
                        } else {
                            selectNotebookPage(currentPageId);
                        }
                    } else {
                        renderNotebookContent(null);
                    }
                } else {
                    console.error('Notebook load error:', json.error);
                }
            })
            .catch(err => console.error('Notebook fetch error:', err));
    }

    function renderNotebookTabs() {
        const tabsContainer = document.getElementById('notebookTabs');
        if (!tabsContainer) return;
        
        tabsContainer.innerHTML = '';
        notebookPages.forEach(page => {
            const tab = document.createElement('div');
            tab.className = 'notebook-tab' + (page.id === currentPageId ? ' active' : '');
            tab.textContent = page.title || 'Untitled';
            tab.onclick = () => selectNotebookPage(page.id);
            tabsContainer.appendChild(tab);
        });
    }

    function selectNotebookPage(id) {
        currentPageId = id;
        renderNotebookTabs(); // update active class
        
        const page = notebookPages.find(p => p.id === id);
        if (page) {
            renderNotebookContent(page);
        }
        
        // Update delete button visibility (can't delete page id 1)
        const deleteBtn = document.getElementById('notebookDeleteBtn');
        if (deleteBtn) {
            deleteBtn.style.display = (id === 1) ? 'none' : 'inline-block';
        }
    }

    function renderNotebookContent(page) {
        const display = document.getElementById('notebookDisplay');
        const statusEl = document.getElementById('notebookStatus');
        
        // Ensure we exit edit mode when switching pages
        cancelNotebookEdit();

        if (!page) {
            display.innerHTML = '<p class="dashboard-notebook-empty">No pages available.</p>';
            statusEl.textContent = '';
            return;
        }

        if (!page.content || page.content.trim() === '') {
            display.innerHTML = '<p class="dashboard-notebook-empty">No notes yet.</p>';
        } else {
            display.innerHTML = '<div class="dashboard-notebook-text">' + escapeHtml(page.content).replace(/\n/g, '<br>') + '</div>';
        }

        if (page.updated_at) {
            const date = new Date(page.updated_at);
            statusEl.textContent = 'Last saved: ' + date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) + (page.updated_by ? ' by ' + page.updated_by : '');
        } else {
            statusEl.textContent = '';
        }
    }

    function toggleNotebookEdit() {
        const display = document.getElementById('notebookDisplay');
        const editor = document.getElementById('notebookEditor');
        const btn = document.getElementById('notebookEditBtn');
        if (!editor || !currentPageId) return;

        const page = notebookPages.find(p => p.id === currentPageId);
        if (!page) return;

        if (editor.style.display === 'none') {
            document.getElementById('notebookTitleInput').value = page.title;
            document.getElementById('notebookTextarea').value = page.content || '';
            display.style.display = 'none';
            editor.style.display = 'flex';
            btn.textContent = '✖ Cancel';
            document.getElementById('notebookTextarea').focus();
            
            // hide delete button while editing
            const deleteBtn = document.getElementById('notebookDeleteBtn');
            if (deleteBtn) deleteBtn.style.display = 'none';
        } else {
            cancelNotebookEdit();
        }
    }

    function cancelNotebookEdit() {
        const display = document.getElementById('notebookDisplay');
        const editor = document.getElementById('notebookEditor');
        const btn = document.getElementById('notebookEditBtn');
        if (!editor) return;

        display.style.display = 'block';
        editor.style.display = 'none';
        if (btn) btn.innerHTML = '&#9998;';
        
        // restore delete button visibility
        const deleteBtn = document.getElementById('notebookDeleteBtn');
        if (deleteBtn && currentPageId !== 1) deleteBtn.style.display = 'inline-block';
    }

    function saveNotebook() {
        if (!currentPageId) return;
        
        const content = document.getElementById('notebookTextarea').value;
        const title = document.getElementById('notebookTitleInput').value;
        
        fetch('../db/notebook/saveNotebookPage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentPageId, content, title })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                // Update local data
                const page = notebookPages.find(p => p.id === currentPageId);
                if (page) {
                    page.content = content;
                    page.title = title;
                    page.updated_at = json.updated_at;
                }
                renderNotebookTabs();
                renderNotebookContent(page);
                cancelNotebookEdit();
            } else {
                showError(json.error || 'Failed to save notebook');
            }
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }
    
    function addNotebookPage() {
        const title = prompt("Enter new page title:");
        if (!title) return;
        
        fetch('../db/notebook/addNotebookPage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                currentPageId = json.id; // automatically select the new page
                loadNotebookPages();
            } else {
                showError(json.error || 'Failed to create page');
            }
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }
    
    function deleteCurrentNotebookPage() {
        if (!currentPageId || currentPageId === 1) return;
        
        const page = notebookPages.find(p => p.id === currentPageId);
        if (!page) return;
        
        if (!confirm("Are you sure you want to delete the page '" + page.title + "'?")) return;
        
        fetch('../db/notebook/deleteNotebookPage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentPageId })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                currentPageId = 1; // Fallback to main page
                loadNotebookPages();
            } else {
                showError(json.error || 'Failed to delete page');
            }
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    loadNotebookPages();

    // ============================================================
    // ORBAT
    // ============================================================
    let orbatData = { teams: [], unassigned: [] };
    let draggedProfileId = null;
    
    // ============================================================
    // ASSETS
    // ============================================================
    let assetData = { assetGroups: [], unassignedAssets: [] };
    let draggedAssetId = null;

    function loadOrbatData() {
        fetch('../db/roster_orbat/getOrbatData.php')
            .then(r => r.json())
            .then(json => {
                if (json.success) {
                    orbatData = { teams: json.teams, unassigned: json.unassigned };
                    assetData = { assetGroups: json.assetGroups || [], unassignedAssets: json.unassignedAssets || [] };
                    renderOrbat();
                    renderAssets();
                } else {
                    console.error('ORBAT load error:', json.error);
                }
            })
            .catch(err => console.error('ORBAT fetch error:', err));
    }

    function renderOrbat() {
        const teamsContainer = document.getElementById('orbatTeams');
        const poolContainer = document.getElementById('orbatPool');

        if (!teamsContainer || !poolContainer) return;

        teamsContainer.innerHTML = '';
        orbatData.teams.forEach(team => {
            const teamEl = document.createElement('div');
            teamEl.className = 'dashboard-orbat-team';
            teamEl.dataset.teamId = team.Id;

            teamEl.innerHTML = `
                <div class="dashboard-orbat-team-header">
                    <h4>${escapeHtml(team.Team_Name)}</h4>
                    ${canEditDashboard ? `<div class="dashboard-orbat-team-actions">
                        <button class="dashboard-icon-btn edit" onclick="showOrbatTeamRename(${team.Id}, '${escapeHtml(escapeJsString(team.Team_Name))}')" title="Rename">&#9998;</button>
                        <button class="dashboard-icon-btn delete" onclick="deleteOrbatTeam(${team.Id}, '${escapeHtml(escapeJsString(team.Team_Name))}')" title="Delete">&#10005;</button>
                    </div>` : ''}
                </div>
                <div class="dashboard-orbat-team-members" data-team-id="${team.Id}">
                    ${team.members.length === 0 ? '<span class="dashboard-orbat-dropzone-hint">Drop personnel here</span>' : ''}
                    ${team.members.map(m => renderOrbatMember(m)).join('')}
                </div>
            `;

            const membersEl = teamEl.querySelector('.dashboard-orbat-team-members');
            if (canEditDashboard) {
                membersEl.addEventListener('dragover', e => { e.preventDefault(); membersEl.classList.add('drag-over'); });
                membersEl.addEventListener('dragleave', () => membersEl.classList.remove('drag-over'));
                membersEl.addEventListener('drop', e => {
                    e.preventDefault();
                    membersEl.classList.remove('drag-over');
                    const profileId = e.dataTransfer.getData('text/plain');
                    if (profileId) assignToTeam(parseInt(profileId), team.Id);
                });
            }

            teamsContainer.appendChild(teamEl);
        });

        poolContainer.innerHTML = '';
        if (orbatData.unassigned.length === 0) {
            poolContainer.innerHTML = '<span class="dashboard-orbat-empty">All personnel assigned</span>';
        } else {
            orbatData.unassigned.forEach(p => {
                const el = createPlayerDragEl(p);
                poolContainer.appendChild(el);
            });
        }

        if (canEditDashboard) {
            poolContainer.addEventListener('dragover', e => { e.preventDefault(); poolContainer.classList.add('drag-over'); });
            poolContainer.addEventListener('dragleave', () => poolContainer.classList.remove('drag-over'));
            poolContainer.addEventListener('drop', e => {
                e.preventDefault();
                poolContainer.classList.remove('drag-over');
                const profileId = e.dataTransfer.getData('text/plain');
                if (profileId) unassignFromTeam(parseInt(profileId));
            });
        }
    }

    function renderOrbatMember(member) {
        const thumbPath = '/images/profiles/thumbs/profile-' + String(member.Profile_Id).padStart(3, '0') + '.png';
        return `<div class="dashboard-orbat-member" draggable="${canEditDashboard}" data-profile-id="${member.Profile_Id}"
                    ondragstart="${canEditDashboard ? `onOrbatDragStart(event, ${member.Profile_Id})` : 'return false'}">
                    <img src="${thumbPath}" alt="" class="dashboard-orbat-avatar" 
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="dashboard-orbat-avatar-placeholder preview-dashboard-6">
                        ${escapeHtml(member.Profile_Name.substring(0, 2).toUpperCase())}
                    </div>
                    <div class="dashboard-orbat-member-info">
                        <span class="dashboard-orbat-member-name">${escapeHtml(member.Profile_Name)}</span>
                        <span class="dashboard-orbat-member-role">${escapeHtml(member.Roster_Role || '')}</span>
                    </div>
                </div>`;
    }

    function createPlayerDragEl(player) {
        const el = document.createElement('div');
        el.className = 'dashboard-orbat-member';
        el.draggable = canEditDashboard;
        el.dataset.profileId = player.Profile_Id;

        const thumbPath = '/images/profiles/thumbs/profile-' + String(player.Profile_Id).padStart(3, '0') + '.png';
        el.innerHTML = `
            <img src="${thumbPath}" alt="" class="dashboard-orbat-avatar" 
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="dashboard-orbat-avatar-placeholder preview-dashboard-7">
                ${escapeHtml(player.Profile_Name.substring(0, 2).toUpperCase())}
            </div>
            <div class="dashboard-orbat-member-info">
                <span class="dashboard-orbat-member-name">${escapeHtml(player.Profile_Name)}</span>
                <span class="dashboard-orbat-member-role">${escapeHtml(player.Role || '')}</span>
            </div>
        `;

        if (canEditDashboard) {
            el.addEventListener('dragstart', e => onOrbatDragStart(e, player.Profile_Id));
        }
        return el;
    }

    function onOrbatDragStart(e, profileId) {
        e.dataTransfer.setData('text/plain', profileId.toString());
        e.dataTransfer.effectAllowed = 'move';
        draggedProfileId = profileId;
    }

    function assignToTeam(profileId, teamId) {
        fetch('../db/roster_orbat/assignOrbat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profileId, teamId })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to assign');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function unassignFromTeam(profileId) {
        fetch('../db/roster_orbat/unassignOrbat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profileId })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to unassign');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function showOrbatTeamAdd() {
        document.getElementById('orbatTeamName').value = '';
        document.getElementById('dashboard-orbat-team-overlay').style.display = 'flex';
    }

    function hideOrbatTeamOverlay() {
        document.getElementById('dashboard-orbat-team-overlay').style.display = 'none';
    }

    function createOrbatTeam() {
        const name = document.getElementById('orbatTeamName').value;
        if (!name) { showError('Team name is required'); return; }

        fetch('../db/roster_orbat/addOrbatTeam.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                hideOrbatTeamOverlay();
                loadOrbatData();
            } else showError(json.error || 'Failed to create team');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function deleteOrbatTeam(id, name) {
        if (!confirm('Delete team "' + name + '"? Members will be unassigned.')) return;
        fetch('../db/roster_orbat/deleteOrbatTeam.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to delete team');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function showOrbatTeamRename(id, currentName) {
        document.getElementById('orbatRenameId').value = id;
        document.getElementById('orbatRenameName').value = currentName;
        document.getElementById('dashboard-orbat-rename-overlay').style.display = 'flex';
    }

    function hideOrbatRenameOverlay() {
        document.getElementById('dashboard-orbat-rename-overlay').style.display = 'none';
    }

    function saveOrbatTeamRename() {
        const id = parseInt(document.getElementById('orbatRenameId').value);
        const name = document.getElementById('orbatRenameName').value;
        if (!name) { showError('Team name is required'); return; }

        fetch('../db/roster_orbat/renameOrbatTeam.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, name })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                hideOrbatRenameOverlay();
                loadOrbatData();
            } else showError(json.error || 'Failed to rename team');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    // ============================================================
    // ASSETS RENDERING
    // ============================================================
    function renderAssets() {
        const assetGroupsContainer = document.getElementById('assetGroups');
        const assetPoolContainer = document.getElementById('assetPool');

        if (!assetGroupsContainer || !assetPoolContainer) return;

        assetGroupsContainer.innerHTML = '';
        assetData.assetGroups.forEach(group => {
            const groupEl = document.createElement('div');
            groupEl.className = 'dashboard-assets-group';
            groupEl.dataset.groupId = group.Id;

            groupEl.innerHTML = `
                <div class="dashboard-assets-group-header">
                    <h4>${escapeHtml(group.Group_Name)}</h4>
                    ${canEditDashboard ? `<div class="dashboard-assets-group-actions">
                        <button class="dashboard-icon-btn edit" onclick="showAssetGroupRename(${group.Id}, '${escapeHtml(escapeJsString(group.Group_Name))}')" title="Rename">&#9998;</button>
                        <button class="dashboard-icon-btn delete" onclick="deleteAssetGroup(${group.Id}, '${escapeHtml(escapeJsString(group.Group_Name))}')" title="Delete">&#10005;</button>
                    </div>` : ''}
                </div>
                <div class="dashboard-assets-group-items" data-group-id="${group.Id}">
                    ${group.assets.length === 0 ? '<span class="dashboard-assets-dropzone-hint">Drop assets here</span>' : ''}
                    ${group.assets.map(a => renderAssetTile(a)).join('')}
                </div>
            `;

            const assetsEl = groupEl.querySelector('.dashboard-assets-group-items');
            if (canEditDashboard) {
                assetsEl.addEventListener('dragover', e => { e.preventDefault(); assetsEl.classList.add('drag-over'); });
                assetsEl.addEventListener('dragleave', () => assetsEl.classList.remove('drag-over'));
                assetsEl.addEventListener('drop', e => {
                    e.preventDefault();
                    assetsEl.classList.remove('drag-over');
                    const assetId = e.dataTransfer.getData('text/plain');
                    if (assetId) assignAssetToGroup(parseInt(assetId), group.Id);
                });
            }

            assetGroupsContainer.appendChild(groupEl);
        });

        assetPoolContainer.innerHTML = '';
        if (assetData.unassignedAssets.length === 0) {
            assetPoolContainer.innerHTML = '<span class="dashboard-assets-empty">All assets assigned</span>';
        } else {
            assetData.unassignedAssets.forEach(a => {
                const el = createAssetTile(a);
                assetPoolContainer.appendChild(el);
            });
        }

        if (canEditDashboard) {
            assetPoolContainer.addEventListener('dragover', e => { e.preventDefault(); assetPoolContainer.classList.add('drag-over'); });
            assetPoolContainer.addEventListener('dragleave', () => assetPoolContainer.classList.remove('drag-over'));
            assetPoolContainer.addEventListener('drop', e => {
                e.preventDefault();
                assetPoolContainer.classList.remove('drag-over');
                const assetId = e.dataTransfer.getData('text/plain');
                if (assetId) unassignAssetFromGroup(parseInt(assetId));
            });
        }
    }

    function renderAssetTile(asset) {
        const imagePath = '../images/vehicles/' + asset.ClassName.toUpperCase() + '.PNG';
        const assetId = asset.Asset_Id || asset.Id;

        return `<div class="dashboard-tile dashboard-asset-tile" style="position: relative;" draggable="${canEditDashboard}" data-asset-id="${assetId}"
                    ondragstart="${canEditDashboard ? `onAssetDragStart(event, ${assetId})` : 'return false'}">
                    <div class="dashboard-tile-image">
                        ${canEditDashboard ? `<div class="dashboard-asset-actions" style="position:absolute; top:5px; right:5px; z-index:10;">
                            <button class="dashboard-icon-btn edit" onclick="showAssetEdit(${assetId})" title="Edit Asset">&#9998;</button>
                            <button class="dashboard-icon-btn delete" onclick="deleteAsset(${assetId}, '${escapeHtml(escapeJsString(asset.Name))}')" title="Delete Asset">&#10005;</button>
                        </div>` : ''}
                        <img src="${imagePath}" alt="${escapeHtml(asset.Name)}" loading="lazy"
                             onerror="this.src='../images/vehicles/VEHICLE.PNG';">
                    </div>
                    <div class="dashboard-tile-info">
                        <span class="dashboard-tile-name"><span class="dashboard-tile-qty">&times;${asset.Quantity}</span> ${escapeHtml(asset.Name)}</span>
                        <span class="dashboard-tile-sub">${escapeHtml(asset.ClassName)}</span>
                    </div>
                </div>`;
    }

    function createAssetTile(asset) {
        const el = document.createElement('div');
        el.className = 'dashboard-tile dashboard-asset-tile';
        el.style.position = 'relative';
        el.draggable = canEditDashboard;
        el.dataset.assetId = asset.Id;

        const imagePath = '../images/vehicles/' + asset.ClassName.toUpperCase() + '.PNG';
        const assetId = asset.Asset_Id || asset.Id;

        el.innerHTML = `
            <div class="dashboard-tile-image">
                ${canEditDashboard ? `<div class="dashboard-asset-actions" style="position:absolute; top:5px; right:5px; z-index:10;">
                    <button class="dashboard-icon-btn edit" onclick="showAssetEdit(${assetId})" title="Edit Asset">&#9998;</button>
                    <button class="dashboard-icon-btn delete" onclick="deleteAsset(${assetId}, '${escapeHtml(escapeJsString(asset.Name))}')" title="Delete Asset">&#10005;</button>
                </div>` : ''}
                <img src="${imagePath}" alt="${escapeHtml(asset.Name)}" loading="lazy"
                     onerror="this.src='../images/vehicles/VEHICLE.PNG';">
            </div>
            <div class="dashboard-tile-info">
                <span class="dashboard-tile-name"><span class="dashboard-tile-qty">&times;${asset.Quantity}</span> ${escapeHtml(asset.Name)}</span>
                <span class="dashboard-tile-sub">${escapeHtml(asset.ClassName)}</span>
            </div>
        `;

        if (canEditDashboard) {
            el.addEventListener('dragstart', e => onAssetDragStart(e, assetId));
        }
        return el;
    }

    function onAssetDragStart(e, assetId) {
        e.dataTransfer.setData('text/plain', assetId.toString());
        e.dataTransfer.effectAllowed = 'move';
        draggedAssetId = assetId;
    }

    function showAssetGroupAdd() {
        document.getElementById('assetGroupName').value = '';
        document.getElementById('dashboard-asset-group-overlay').style.display = 'flex';
    }

    function hideAssetGroupOverlay() {
        document.getElementById('dashboard-asset-group-overlay').style.display = 'none';
    }

    function createAssetGroup() {
        const name = document.getElementById('assetGroupName').value;
        if (!name) { showError('Group name is required'); return; }

        fetch('../db/inventory_assets/addAssetGroup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                hideAssetGroupOverlay();
                loadOrbatData();
            } else showError(json.error || 'Failed to create asset group');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function deleteAssetGroup(id, name) {
        if (!confirm('Delete asset group "' + name + '"? Assets will be unassigned.')) return;
        fetch('../db/inventory_assets/deleteAssetGroup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to delete asset group');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function showAssetGroupRename(id, currentName) {
        document.getElementById('assetGroupRenameId').value = id;
        document.getElementById('assetGroupRenameName').value = currentName;
        document.getElementById('dashboard-asset-group-rename-overlay').style.display = 'flex';
    }

    function hideAssetGroupRenameOverlay() {
        document.getElementById('dashboard-asset-group-rename-overlay').style.display = 'none';
    }

    function saveAssetGroupRename() {
        const id = parseInt(document.getElementById('assetGroupRenameId').value);
        const name = document.getElementById('assetGroupRenameName').value;
        if (!name) { showError('Group name is required'); return; }

        fetch('../db/inventory_assets/renameAssetGroup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, name })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                hideAssetGroupRenameOverlay();
                loadOrbatData();
            } else showError(json.error || 'Failed to rename asset group');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function assignAssetToGroup(assetId, groupId) {
        fetch('../db/inventory_assets/assignAssetToGroup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assetId, groupId })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to assign asset');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function unassignAssetFromGroup(assetId) {
        fetch('../db/inventory_assets/unassignAssetFromGroup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assetId })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to unassign asset');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function showAssetAdd() {
        document.getElementById('assetModalTitle').textContent = 'Add Asset';
        document.getElementById('assetId').value = '';
        document.getElementById('assetForm').reset();
        document.getElementById('vehicleImageUploadPreview').style.display = 'none';
        document.getElementById('dashboard-asset-overlay').style.display = 'flex';
    }

    function showAssetEdit(assetId) {
        let asset = assetData.unassignedAssets.find(a => (a.Asset_Id || a.Id) == assetId);
        if (!asset) {
            for (const group of assetData.assetGroups) {
                asset = group.assets.find(a => (a.Asset_Id || a.Id) == assetId);
                if (asset) break;
            }
        }
        if (!asset) {
            showError("Asset not found.");
            return;
        }

        document.getElementById('assetModalTitle').textContent = 'Edit Asset';
        document.getElementById('assetId').value = asset.Asset_Id || asset.Id;
        document.getElementById('assetName').value = asset.Name || '';
        document.getElementById('assetClassName').value = asset.ClassName || '';
        document.getElementById('assetQuantity').value = asset.Quantity || 1;
        document.getElementById('assetAmmo').value = asset.Ammo !== null ? asset.Ammo : '';
        document.getElementById('assetHealth').value = asset.Health !== null ? asset.Health : '';
        document.getElementById('assetFuel').value = asset.Fuel !== null ? asset.Fuel : '';
        document.getElementById('assetImageFile').value = '';
        document.getElementById('vehicleImageUploadPreview').style.display = 'none';
        document.getElementById('dashboard-asset-overlay').style.display = 'flex';
    }

    function hideAssetOverlay() {
        document.getElementById('dashboard-asset-overlay').style.display = 'none';
    }

    function previewVehicleImageUpload() {
        const fileInput = document.getElementById('assetImageFile');
        const preview = document.getElementById('vehicleImageUploadPreview');
        const previewImg = document.getElementById('uploadVehiclePreviewImg');
        const previewFilename = document.getElementById('uploadVehiclePreviewFilename');
        const classNameInput = document.getElementById('assetClassName');

        if (fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                previewImg.src = e.target.result;
                const finalName = (classNameInput.value || 'UNKNOWN').toUpperCase() + '.PNG';
                previewFilename.textContent = 'Will be saved as: ' + finalName;
                preview.style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            preview.style.display = 'none';
        }
    }

    // Update upload preview filename if item class changes
    const assetClassNameInput = document.getElementById('assetClassName');
    if (assetClassNameInput) {
        assetClassNameInput.addEventListener('input', function() {
            const previewFilename = document.getElementById('uploadVehiclePreviewFilename');
            const preview = document.getElementById('vehicleImageUploadPreview');
            if (preview.style.display !== 'none') {
                const finalName = (this.value || 'UNKNOWN').toUpperCase() + '.PNG';
                previewFilename.textContent = 'Will be saved as: ' + finalName;
            }
        });
    }

    async function uploadVehicleImage(force = false) {
        const fileInput = document.getElementById('assetImageFile');
        const classNameInput = document.getElementById('assetClassName');

        if (!fileInput.files || !fileInput.files[0]) {
            return true; // No image to upload, proceed
        }
        
        const filename = classNameInput.value;
        if (!filename) {
            showError('Class Name cannot be empty for image upload.');
            return false;
        }

        const formData = new FormData();
        formData.append('image', fileInput.files[0]);
        formData.append('filename', filename);
        formData.append('force', force ? '1' : '0');

        try {
            const response = await fetch('../db/inventory_assets/uploadVehicleImage.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                return true;
            } else if (result.fileExists) {
                if (confirm('An image with the name "' + result.existingFile + '" already exists. Do you want to replace it?')) {
                    return await uploadVehicleImage(true);
                }
                return false;
            } else {
                showError('Error uploading image: ' + result.error);
                return false;
            }
        } catch (error) {
            showError('Upload failed: ' + error.message);
            return false;
        }
    }

    async function saveAsset() {
        const id = document.getElementById('assetId').value;
        const name = document.getElementById('assetName').value;
        const className = document.getElementById('assetClassName').value;
        
        if (!name || !className) {
            showError('Name and Class Name are required');
            return;
        }

        // Wait for image upload if file is selected
        const uploadSuccess = await uploadVehicleImage();
        if (!uploadSuccess) return;

        const data = {
            name: name,
            className: className,
            quantity: document.getElementById('assetQuantity').value,
            ammo: document.getElementById('assetAmmo').value,
            health: document.getElementById('assetHealth').value,
            fuel: document.getElementById('assetFuel').value
        };

        let endpoint = '../db/inventory_assets/addAsset.php';
        if (id) {
            endpoint = '../db/inventory_assets/updateAsset.php';
            data.id = id;
        }

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                hideAssetOverlay();
                loadOrbatData(); // reload assets
            } else {
                showError(json.error || 'Failed to save asset');
            }
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    function deleteAsset(id, name) {
        if (!confirm('Delete asset "' + name + '"?')) return;
        fetch('../db/inventory_assets/deleteAsset.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) loadOrbatData();
            else showError(json.error || 'Failed to delete asset');
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    loadOrbatData();

    </script>
</body>
</html>
