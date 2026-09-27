<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Only logged in users
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Get user profile info
$userQuery = "SELECT u.username, u.inventory_id, pp.Profile_Name 
              FROM users u 
              LEFT JOIN player_profiles pp ON pp.User_Id = u.id 
              WHERE u.id = ?";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute([$userId]);
$userData = $userStmt->fetch();

$balance = 0;
if ($userData && $userData['inventory_id']) {
    $moneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $moneyStmt = $pdo->prepare($moneyQuery);
    $moneyStmt->execute([$userData['inventory_id']]);
    $balance = $moneyStmt->fetchColumn() ?: 0;
}

// Check/Create home config
$configQuery = "SELECT * FROM user_home_config WHERE user_id = ?";
$configStmt = $pdo->prepare($configQuery);
$configStmt->execute([$userId]);
$config = $configStmt->fetch();

if (!$config) {
    try {
        $pdo->prepare("INSERT INTO user_home_config (user_id, background_image, notify_images, notify_docs, notify_news, notify_notes, notify_forum, notify_messages) VALUES (?, '../images/homeBackground/1.png', 1, 1, 1, 1, 1, 1)")->execute([$userId]);
        $configStmt->execute([$userId]);
        $config = $configStmt->fetch();
    } catch(Exception $e) {}
}

// Get Desktop Shortcuts
$shortcuts = [];
try {
    $shortcutsQuery = "
        SELECT ds.id, ds.grid_row, ds.grid_col, dp.name, dp.url, dp.icon_path,
               ds.custom_name, ds.custom_url, ds.custom_icon_path, ds.is_settings_btn
        FROM user_desktop_shortcuts ds
        LEFT JOIN desktop_pages dp ON ds.page_id = dp.id
        WHERE ds.user_id = ?
    ";
    $shortcutsStmt = $pdo->prepare($shortcutsQuery);
    $shortcutsStmt->execute([$userId]);
    $shortcuts = $shortcutsStmt->fetchAll();

    if (empty($shortcuts)) {
        $defaults = [
            [1, 1, 'Market', 'shop.php', '../images/icons/header/markets.svg', 0],
            [1, 2, 'Profile', 'profile.php', '../images/icons/desktop/profile.svg', 0],
            [1, 3, 'Inventory', 'inventory.php', '../images/icons/desktop/inventory.svg', 0],
            [1, 4, 'Forum', 'forum.php', '../images/icons/header/socials.svg', 0],
            [1, 5, 'logs', 'logs.php', '../images/icons/header/intelligence.svg', 0],
            [1, -1, 'Settings', '', '../images/icons/settings.svg', 1]
        ];
        $insertStmt = $pdo->prepare("INSERT INTO user_desktop_shortcuts (user_id, page_id, grid_row, grid_col, custom_name, custom_url, custom_icon_path, is_settings_btn) VALUES (?, 0, ?, ?, ?, ?, ?, ?)");
        foreach ($defaults as $d) {
            $insertStmt->execute([$userId, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]]);
        }
        $shortcutsStmt->execute([$userId]);
        $shortcuts = $shortcutsStmt->fetchAll();
    }
} catch (Exception $e) {}

// Get Next Event
$nextEvent = null;
try {
    $eventQuery = "SELECT Title as title, Event_Date as event_date, Event_Time as event_time, Image_Url as image_url FROM calendar_events WHERE Event_Date > CURDATE() OR (Event_Date = CURDATE() AND (Event_Time >= CURTIME() OR Event_Time IS NULL)) ORDER BY Event_Date ASC, Event_Time ASC LIMIT 1";
    $eventStmt = $pdo->query($eventQuery);
    $nextEvent = $eventStmt->fetch();
    
    if ($nextEvent) {
        $dateStr = date('M j, Y', strtotime($nextEvent['event_date']));
        if (!empty($nextEvent['event_time'])) {
            $dateStr .= ' at ' . substr($nextEvent['event_time'], 0, 5);
        }
        $nextEvent['formatted_date'] = $dateStr;
    }
} catch (Exception $e) {}

// Get Inbox items
$inboxItems = [];

// Messages
if (!empty($config['notify_messages'])) {
    try {
        $msgQuery = "
            SELECT m.Message_Id as id, 
                   m.Message_Date as date, 
                   m.Message_Title as title, 
                   m.Message_Content as message
            FROM messages m
            JOIN player_profiles p ON m.Message_Receiver = p.Profile_Id
            WHERE p.User_Id = ?
            ORDER BY m.Message_Date DESC LIMIT 30
        ";
        $msgStmt = $pdo->prepare($msgQuery);
        $msgStmt->execute([$userId]);
        $fetchedMessages = $msgStmt->fetchAll();
        
        foreach ($fetchedMessages as $msg) {
            $inboxItems[] = [
                'timestamp' => strtotime($msg['date']),
                'date' => $msg['date'],
                'message' => 'Message: ' . $msg['title'],
                'link' => 'messages.php?id=' . $msg['id']
            ];
        }
    } catch (Exception $e) {}
}

// Activity Logs (Images, Docs, News, Notes)
try {
    $activityFilters = [];
    if (!empty($config['notify_images'])) $activityFilters[] = "Activity LIKE 'Image uploaded:%'";
    if (!empty($config['notify_notes'])) $activityFilters[] = "(Activity LIKE 'Note added:%' OR Activity LIKE 'Image comment added:%' OR Activity LIKE 'Profile comment added:%')";
    
    // Docs and News have same prefix but different links
    if (!empty($config['notify_docs']) && !empty($config['notify_news'])) {
        $activityFilters[] = "Activity LIKE 'Docs uploaded:%'";
    } else {
        if (!empty($config['notify_docs'])) $activityFilters[] = "(Activity LIKE 'Docs uploaded:%' AND Link LIKE '%mode=docs%')";
        if (!empty($config['notify_news'])) $activityFilters[] = "(Activity LIKE 'Docs uploaded:%' AND Link LIKE '%mode=news%')";
    }
    
    if (!empty($activityFilters)) {
        $activityQuery = "
            SELECT Activity as title, Link as link, Timestamp as date 
            FROM web_activity_log 
            WHERE (" . implode(' OR ', $activityFilters) . ")
            ORDER BY Timestamp DESC LIMIT 30
        ";
        $activityStmt = $pdo->query($activityQuery);
        $fetchedActivities = $activityStmt->fetchAll();
        foreach ($fetchedActivities as $act) {
            $inboxItems[] = [
                'timestamp' => strtotime($act['date']),
                'date' => $act['date'],
                'message' => $act['title'],
                'link' => $act['link']
            ];
        }
    }
} catch (Exception $e) {}

// Forum Posts (phpBB)
if (!empty($config['notify_forum'])) {
    try {
        $forumQuery = "
            SELECT fp.post_text as text, ft.topic_id as topic_id, fp.post_id as post_id, fp.post_time as timestamp 
            FROM phpbb_posts fp
            JOIN phpbb_topics ft ON fp.topic_id = ft.topic_id
            ORDER BY fp.post_time DESC LIMIT 30
        ";
        $forumStmt = $pdo->query($forumQuery);
        $fetchedForum = $forumStmt->fetchAll();
        foreach ($fetchedForum as $fp) {
            $date = date('Y-m-d H:i:s', $fp['timestamp']);
            
            // Clean up BBCode and HTML
            $cleanText = preg_replace('/\[.*?\]/', '', $fp['text']);
            $cleanText = strip_tags($cleanText);
            if (strlen($cleanText) > 50) {
                $cleanText = substr($cleanText, 0, 50) . '...';
            }
            
            $inboxItems[] = [
                'timestamp' => $fp['timestamp'],
                'date' => $date,
                'message' => 'Forum: ' . $cleanText,
                'link' => 'forum.php?t=' . $fp['topic_id'] . '#p' . $fp['post_id']
            ];
        }
    } catch (Exception $e) {}
}

// Sort combined items by timestamp descending
usort($inboxItems, function($a, $b) {
    return $b['timestamp'] <=> $a['timestamp'];
});

// Calculate unread count
$lastViewTime = !empty($config['last_inbox_view']) ? strtotime($config['last_inbox_view']) : 0;
$unreadCount = 0;
foreach ($inboxItems as $item) {
    if ($item['timestamp'] > $lastViewTime) {
        $unreadCount++;
    }
}
$displayBadge = $unreadCount > 0;
// We limit to 30, so if there are 30 unread we might have more.
$badgeText = $unreadCount >= 30 ? "30+" : $unreadCount;

// Limit to top 30
$inboxItems = array_slice($inboxItems, 0, 30);

include '../includes/header.php';
?>



<?php 
$isBgVideo = !empty($config['background_image']) && strtolower(pathinfo($config['background_image'], PATHINFO_EXTENSION)) === 'mp4';
$bgStyle = (!empty($config['background_image']) && !$isBgVideo) ? 'style="background-image: url('.htmlspecialchars($config['background_image']).')"' : '';
?>
<div class="home-desktop" <?php echo $bgStyle; ?>>
    <?php if ($isBgVideo): ?>
        <video autoplay loop muted playsinline style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; pointer-events: none;">
            <source src="<?php echo htmlspecialchars($config['background_image']); ?>" type="video/mp4">
        </video>
    <?php endif; ?>
    <div class="home-grid">
        <?php foreach ($shortcuts as $shortcut): 
            $name = !empty($shortcut['custom_name']) ? $shortcut['custom_name'] : $shortcut['name'];
            $url = !empty($shortcut['custom_url']) ? $shortcut['custom_url'] : $shortcut['url'];
            $icon = !empty($shortcut['custom_icon_path']) ? $shortcut['custom_icon_path'] : $shortcut['icon_path'];
            $isSettings = !empty($shortcut['is_settings_btn']);
            $clickAction = $isSettings ? 'onclick="openHomeSettings(); return false;"' : '';
            
            $gridColStr = $shortcut['grid_col'];
            if ($shortcut['grid_col'] < 0) {
                $startCol = $shortcut['grid_col'] - 1;
                $gridColStr = $startCol . ' / ' . $shortcut['grid_col'];
            }
        ?>
            <a href="<?php echo $isSettings ? '#' : htmlspecialchars($url); ?>" <?php echo $clickAction; ?> class="home-icon-container" style="grid-row: <?php echo $shortcut['grid_row']; ?>; grid-column: <?php echo $gridColStr; ?>;" data-is-settings="<?php echo $isSettings ? 1 : 0; ?>" data-shortcut-id="<?php echo $shortcut['id']; ?>">
                <div class="home-icon">
                    <?php
                    $fullPath = __DIR__ . '/' . $icon;
                    if (pathinfo($icon, PATHINFO_EXTENSION) === 'svg' && file_exists($fullPath)) {
                        echo @file_get_contents($fullPath);
                    } else {
                        echo '<img src="' . htmlspecialchars($icon) . '" alt="' . htmlspecialchars($name) . '">';
                    }
                    ?>
                </div>
                <div class="home-icon-label"><?php echo htmlspecialchars($name); ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    
    <div class="home-bottom-left">
        <div class="home-user-info">
            <div class="home-username"><?php echo htmlspecialchars($userData['username'] ?? 'User'); ?></div>
            <div class="home-balance"><?php echo number_format($balance, 2, ".", "'"); ?> Cr</div>
        </div>
        <button class="home-inbox-btn" onclick="toggleInbox()">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="white"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
            <?php if ($displayBadge): ?>
                <span id="inboxBadge" class="home-inbox-badge"><?php echo $badgeText; ?></span>
            <?php endif; ?>
        </button>
    </div>
    
    <div class="home-bottom-right">
        <?php if ($nextEvent): ?>
            <div class="home-next-event">
                <?php if (!empty($nextEvent['image_url'])): ?>
                    <div class="home-event-img-wrap">
                        <img src="<?php echo htmlspecialchars($nextEvent['image_url']); ?>" alt="<?php echo htmlspecialchars($nextEvent['title']); ?>" class="home-event-img">
                    </div>
                <?php endif; ?>
                <div class="home-event-details">
                    <div class="home-event-title"><?php echo htmlspecialchars($nextEvent['title'] ?? 'Next Event'); ?></div>
                    <div class="home-event-date"><?php echo htmlspecialchars($nextEvent['formatted_date'] ?? ''); ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="home-next-event">
                <div class="home-event-details">
                    <div class="home-event-title">No Upcoming Events</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Inbox Overlay -->
    <div id="homeInbox" class="home-inbox-overlay">
        <div class="home-inbox-header">
            <h3>Inbox</h3>
            <button onclick="document.getElementById('homeInbox').classList.remove('active')">&times;</button>
        </div>
        <div class="home-inbox-content">
            <details class="home-inbox-settings">
                <summary class="home-subscriptions-summary">
                    <h4 class="home-subscriptions-title">Subscriptions</h4>
                </summary>
                <label><input type="checkbox" onchange="updateHomeSetting('notify_images', this.checked)" <?php echo !empty($config['notify_images']) ? 'checked' : ''; ?>> Image Uploads</label>
                <label><input type="checkbox" onchange="updateHomeSetting('notify_docs', this.checked)" <?php echo !empty($config['notify_docs']) ? 'checked' : ''; ?>> New Docs</label>
                <label><input type="checkbox" onchange="updateHomeSetting('notify_news', this.checked)" <?php echo !empty($config['notify_news']) ? 'checked' : ''; ?>> New News</label>
                <label><input type="checkbox" onchange="updateHomeSetting('notify_notes', this.checked)" <?php echo !empty($config['notify_notes']) ? 'checked' : ''; ?>> New Notes</label>
                <label><input type="checkbox" onchange="updateHomeSetting('notify_forum', this.checked)" <?php echo !empty($config['notify_forum']) ? 'checked' : ''; ?>> Forum Posts</label>
                <label><input type="checkbox" onchange="updateHomeSetting('notify_messages', this.checked)" <?php echo !empty($config['notify_messages']) ? 'checked' : ''; ?>> Messages</label>
            </details>
            <div class="home-inbox-list">
                <h4>Recent Activity</h4>
                <?php if (empty($inboxItems)): ?>
                    <div class="home-inbox-empty">No new notifications.</div>
                <?php else: ?>
                    <?php foreach ($inboxItems as $item): ?>
                        <?php if (!empty($item['link'])): ?>
                            <a href="<?php echo htmlspecialchars($item['link']); ?>" class="home-inbox-item home-inbox-item-link">
                        <?php else: ?>
                            <div class="home-inbox-item">
                        <?php endif; ?>
                            <div class="home-inbox-item-date"><?php echo htmlspecialchars($item['date']); ?></div>
                            <div class="home-inbox-item-msg"><?php echo htmlspecialchars($item['message']); ?></div>
                        <?php if (!empty($item['link'])): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function updateHomeSetting(setting, value) {
    fetch('../db/misc/updateHomeSettings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            setting: setting,
            value: value ? 1 : 0
        })
    }).then(async response => {
        const json = await response.json();
        if (!json.success) {
            console.error("Failed to update setting:", json.error);
            // Optionally, reload the page to refresh the inbox items
        } else {
            // Optional: reload to apply settings immediately
            // location.reload();
        }
    }).catch(err => {
        console.error("Error updating setting:", err);
    });
}
</script><!-- Home Settings Overlay -->
<div id="homeSettingsOverlay" class="home-settings-overlay">
    <div class="home-settings-header">
        <h3>Home Settings</h3>
        <button class="close-btn" onclick="document.getElementById('homeSettingsOverlay').classList.remove('active')">&times;</button>
    </div>
    <div class="home-settings-tabs">
        <div class="home-settings-tab active" onclick="switchSettingsTab('shortcuts')">Shortcuts & Grid</div>
        <div class="home-settings-tab" onclick="switchSettingsTab('background')">Background</div>
        <div class="home-settings-tab" onclick="switchSettingsTab('other')">Other</div>
    </div>
    <div class="home-settings-content">
        
        <!-- Shortcuts Pane -->
        <div id="pane-shortcuts" class="home-settings-pane active">
            <p class="home-settings-desc">Configure your desktop icons. Use negative columns (e.g. -2) to align icons to the right side of the screen.</p>
            <div id="shortcutsEditorList"></div>
            <button class="add-shortcut-btn" onclick="addShortcutRow()">+ Add Shortcut</button>
        </div>

        <!-- Background Pane -->
        <div id="pane-background" class="home-settings-pane">
            <div id="backgroundSelector" class="bg-selector-grid">
                <!-- Loaded dynamically -->
            </div>
        </div>

        <!-- Other Pane -->
        <div id="pane-other" class="home-settings-pane">
            <div class="home-settings-medication-container">
                <label title="removes the anime women in your head" class="home-settings-medication-label">
                    <input type="checkbox" onchange="updateHomeSetting('medication', this.checked)" <?php echo !empty($config['medication']) ? 'checked' : ''; ?>> 
                    <span>Medication <small class="home-settings-medication-small">(removes the anime women in your head)</small></span>
                </label>
            </div>
        </div>

    </div>
    <div class="home-settings-footer">
        <button class="btn-industrial success" onclick="saveDesktopSettings()">Save Changes</button>
    </div>
</div>

<!-- Icon Selector Modal -->
<div id="iconSelectorModal" class="icon-modal">
    <div class="icon-modal-header">
        <h4 class="home-icon-modal-title">Select Icon</h4>
        <button class="home-icon-modal-close" onclick="document.getElementById('iconSelectorModal').classList.remove('active')">&times;</button>
    </div>
    <div id="iconSelectorContent" class="icon-modal-content">
        <!-- Loaded dynamically -->
    </div>
</div>

<script>
let editingShortcutIndex = -1;
let availableIcons = [];
let availableBackgrounds = [];
let currentBg = <?php echo json_encode($config['background_image'] ?? ''); ?>;

// Normalize the initial shortcuts
let currentShortcuts = <?php echo json_encode(array_map(function($s) {
    return [
        'id' => $s['id'] ?? null,
        'grid_row' => $s['grid_row'],
        'grid_col' => $s['grid_col'],
        'custom_name' => $s['custom_name'] ?: $s['name'],
        'custom_url' => $s['custom_url'] ?: $s['url'],
        'custom_icon_path' => $s['custom_icon_path'] ?: $s['icon_path'],
        'is_settings_btn' => $s['is_settings_btn']
    ];
}, $shortcuts)); ?>;

function switchSettingsTab(tab) {
    document.querySelectorAll('.home-settings-tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.home-settings-pane').forEach(el => el.classList.remove('active'));
    
    event.currentTarget.classList.add('active');
    document.getElementById('pane-' + tab).classList.add('active');
}

function openHomeSettings() {
    document.getElementById('homeSettingsOverlay').classList.add('active');
    loadBackgrounds();
    loadIcons();
    renderShortcutEditor();
}

function toggleInbox() {
    const inbox = document.getElementById('homeInbox');
    inbox.classList.toggle('active');
    
    if (inbox.classList.contains('active')) {
        let badge = document.getElementById('inboxBadge');
        if (badge) {
            badge.style.display = 'none';
            fetch('../db/misc/updateInboxViewTime.php', { method: 'POST' });
        }
    }
}

function loadBackgrounds() {
    fetch('../db/misc/getBackgrounds.php')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                availableBackgrounds = data.backgrounds;
                renderBackgrounds();
            }
        });
}

function loadIcons() {
    fetch('../db/misc/getAvailableIcons.php')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                availableIcons = data.icons;
                renderIcons();
            }
        });
}

function renderBackgrounds() {
    const container = document.getElementById('backgroundSelector');
    container.innerHTML = '';
    
    // Add a "None" option
    const noneDiv = document.createElement('div');
    noneDiv.className = 'bg-selector-item' + (!currentBg ? ' selected' : '');
    noneDiv.style.background = '#111';
    noneDiv.style.display = 'flex';
    noneDiv.style.alignItems = 'center';
    noneDiv.style.justifyContent = 'center';
    noneDiv.style.color = '#aaa';
    noneDiv.textContent = 'None';
    noneDiv.onclick = () => selectBg('');
    container.appendChild(noneDiv);

    availableBackgrounds.forEach(bg => {
        const div = document.createElement('div');
        div.className = 'bg-selector-item' + (currentBg === bg ? ' selected' : '');
        if (bg.toLowerCase().endsWith('.mp4')) {
            const img = document.createElement('img');
            img.src = bg + '.thumb.jpg';
            // Fallback just in case the thumbnail doesn't exist yet
            img.onerror = function() {
                this.onerror = null;
                const vid = document.createElement('video');
                vid.src = bg + '#t=0.1';
                vid.preload = 'metadata';
                vid.muted = true;
                vid.playsInline = true;
                this.parentNode.replaceChild(vid, this);
            };
            div.appendChild(img);
        } else {
            const img = document.createElement('img');
            img.src = bg;
            div.appendChild(img);
        }
        div.onclick = () => selectBg(bg);
        container.appendChild(div);
    });
}

function selectBg(bgPath) {
    currentBg = bgPath;
    renderBackgrounds();
}

function renderShortcutEditor() {
    const container = document.getElementById('shortcutsEditorList');
    container.innerHTML = `
        <div class="home-shortcut-editor-header">
            <div>Icon</div>
            <div>Name</div>
            <div>URL</div>
            <div>Row</div>
            <div>Col</div>
            <div></div>
        </div>
    `;

    currentShortcuts.forEach((s, index) => {
        const row = document.createElement('div');
        row.className = 'shortcut-editor-row';
        
        const isSettings = parseInt(s.is_settings_btn) === 1;

        row.innerHTML = `
            <div class="icon-preview" onclick="openIconSelector(${index})">
                <img src="${s.custom_icon_path}" alt="icon">
            </div>
            <input type="text" value="${s.custom_name || ''}" onchange="updateShortcut(${index}, 'custom_name', this.value)" ${isSettings ? 'readonly' : ''} placeholder="Name">
            <input type="text" value="${isSettings ? '#' : (s.custom_url || '')}" onchange="updateShortcut(${index}, 'custom_url', this.value)" ${isSettings ? 'readonly' : ''} placeholder="URL">
            <input type="number" value="${s.grid_row}" onchange="updateShortcut(${index}, 'grid_row', this.value)" placeholder="Row">
            <input type="number" value="${s.grid_col}" onchange="updateShortcut(${index}, 'grid_col', this.value)" placeholder="Col">
            <button class="delete-btn" onclick="deleteShortcut(${index})" ${isSettings ? 'disabled title="Cannot delete settings button"' : ''}>&times;</button>
        `;
        container.appendChild(row);
    });
}

function updateShortcut(index, field, value) {
    currentShortcuts[index][field] = value;
}

function addShortcutRow() {
    currentShortcuts.push({
        grid_row: 1,
        grid_col: 1,
        custom_name: 'New Shortcut',
        custom_url: '#',
        custom_icon_path: '../images/icons/desktop/market.svg',
        is_settings_btn: 0
    });
    renderShortcutEditor();
}

function deleteShortcut(index) {
    if(parseInt(currentShortcuts[index].is_settings_btn) === 1) return;
    currentShortcuts.splice(index, 1);
    renderShortcutEditor();
}

function openIconSelector(index) {
    editingShortcutIndex = index;
    document.getElementById('iconSelectorModal').classList.add('active');
}

function renderIcons() {
    const container = document.getElementById('iconSelectorContent');
    container.innerHTML = '';
    availableIcons.forEach(icon => {
        const div = document.createElement('div');
        div.className = 'icon-modal-item';
        const img = document.createElement('img');
        img.src = icon;
        div.appendChild(img);
        div.onclick = () => {
            if (editingShortcutIndex > -1) {
                currentShortcuts[editingShortcutIndex].custom_icon_path = icon;
                renderShortcutEditor();
            }
            document.getElementById('iconSelectorModal').classList.remove('active');
        };
        container.appendChild(div);
    });
}

function saveDesktopSettings() {
    fetch('../db/misc/saveDesktopConfig.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            background_image: currentBg,
            shortcuts: currentShortcuts
        })
    }).then(async res => {
        const json = await res.json();
        if (json.success) {
            location.reload();
        } else {
            alert('Failed to save settings: ' + json.error);
        }
    });
}
</script>

