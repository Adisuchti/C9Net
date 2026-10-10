<?php
if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'cinder9.com') !== false) {
        session_set_cookie_params(['domain' => '.cinder9.com']);
    }
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;
$isAdmin = ($userId === -1);
$isStandardUser = ($userId !== null && !$isAdmin);

// Touch session activity timestamp on each page load
if ($userId !== null) {
    try {
        $touchStmt = $pdo->prepare("UPDATE active_sessions SET last_activity = NOW() WHERE user_id = ? AND session_id = ?");
        $touchStmt->execute([$userId, session_id()]);
    } catch (Exception $e) {
        // Silently fail if table doesn't exist yet
    }
}

// Force refresh of session data on each page load
if ($isStandardUser) {
    // Refresh inventory data
    if (isset($_SESSION['inventory_id'])) {
        $refreshQuery = "SELECT Inventory_Name, Inventory_Money FROM inventories WHERE Inventory_Id = ?";
        $refreshStmt = $pdo->prepare($refreshQuery);
        $refreshStmt->execute([$_SESSION['inventory_id']]);
        $refreshData = $refreshStmt->fetch();
        
        if ($refreshData) {
            $_SESSION['inventory_name'] = $refreshData['Inventory_Name'];
            $_SESSION['inventory_money'] = $refreshData['Inventory_Money'];
        }
    }
}

// Get profile ID for logged in user (refresh on each load)
$headerProfileId = null;
if ($userId !== null) {
    $headerQuery = "SELECT Profile_Id FROM player_profiles WHERE User_Id = ?";
    $stmt = $pdo->prepare($headerQuery);
    $stmt->execute([$userId]);
    $headerProfile = $stmt->fetch();
    if ($headerProfile) {
        $headerProfileId = $headerProfile['Profile_Id'];
    }
}

// Get current page for active navigation highlighting
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_path = $_SERVER['REQUEST_URI'];

function isActivePage($page) {
    global $current_page, $current_path;
    
    // Handle specific page matches
    switch($page) {
        case 'home':
            return $current_page === 'home';
        case 'dashboard':
            return $current_page === 'dashboard';
        case 'forum':
            return $current_page === 'forum';
        case 'inventory':
            return $current_page === 'inventory';
        case 'shop':
            return $current_page === 'shop' || $current_page === 'detail' || $current_page === 'detailPage';
        case 'playerMarket':
            return $current_page === 'playerMarket';
        case 'roster':
            return $current_page === 'roster' || strpos($current_path, '/roster') !== false;
        case 'wiki':
            return $current_page === 'wiki';
        case 'docs':
            return $current_page === 'docs' || $current_page === 'news';
        case 'logs':
            return $current_page === 'logs';
        case 'oldRoster':
            return $current_page === 'oldRoster';
        case 'messages':
            return $current_page === 'messages';
        case 'calendar':
            return $current_page === 'calendar';
        case 'admin-inventories':
            return strpos($current_path, '/admin/inventories') !== false;
        case 'admin-users':
            return strpos($current_path, '/admin/users') !== false;
        case 'admin-parameters':
            return strpos($current_path, '/admin/parameters') !== false;
        case 'admin-markets':
            return strpos($current_path, '/admin/markets') !== false;
        case 'admin-interchangeable':
            return strpos($current_path, '/admin/interchangeableItems') !== false;
        case 'admin-shopList':
            return strpos($current_path, '/admin/shopList') !== false;
        case 'admin-maps':
            return strpos($current_path, '/admin/maps') !== false;
        case 'admin-renderSvgTiles':
            return strpos($current_path, '/admin/renderSvgTiles') !== false;
        case 'admin-financial-reports':
            return strpos($current_path, '/admin/financialReports') !== false;
        case 'admin-manageProfiles':
            return strpos($current_path, '/admin/manageProfiles') !== false;
        case 'admin-editHierarchy':
            return strpos($current_path, '/admin/editHierarchy') !== false;
        case 'admin-addNewUser':
            return strpos($current_path, '/admin/addNewUser') !== false;
        case 'financials':
            return $current_page === 'financials';
        default:
            return false;
    }
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$cssBaseUrl = '../styles';
$mainAppUrl = '..';
$previewAppUrl = '..';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <link rel="stylesheet" href="<?php echo $cssBaseUrl; ?>/preview_styles.css?v=<?php echo time(); ?>">
</head>
<body>
    <header data-last-update="<?php echo time(); ?>" class="header-preview <?php echo $isAdmin ? 'header-admin' : 'header-user'; ?>">
        <div class="header-content">
            <h1>C9 IntraNet <?php if ($isAdmin) echo "Admin"; ?></h1>
            <nav>
                <ul class="header-nav-links">
                        <li><a href="<?php echo $previewAppUrl; ?>/views/home.php" class="<?php echo isActivePage('home') ? 'active' : ''; ?>">
                            <?php echo @file_get_contents(__DIR__ . '/../images/icons/header/home.svg'); ?> Home
                        </a></li>
                        <?php if($isStandardUser): ?>
                            <li><a href="<?php echo $previewAppUrl; ?>/views/inventory.php" class="<?php echo isActivePage('inventory') ? 'active' : ''; ?>">
                                <?php echo @file_get_contents(__DIR__ . '/../images/icons/header/inventory.svg'); ?> Inventory
                            </a></li>
                        <?php endif; ?>
                        
                        <li class="has-dropdown">
                            <a href="#" class="<?php echo isActivePage('shop') || isActivePage('playerMarket') ? 'active' : ''; ?>">
                                <?php echo @file_get_contents(__DIR__ . '/../images/icons/header/markets.svg'); ?> Markets <span class="caret">▼</span>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a href="<?php echo $previewAppUrl; ?>/views/shop.php" class="<?php echo isActivePage('shop') ? 'active' : ''; ?>">Vendor Market</a></li>
                                <?php if($isStandardUser): ?>
                                    <li><a href="<?php echo $previewAppUrl; ?>/views/playerMarket.php" class="<?php echo isActivePage('playerMarket') ? 'active' : ''; ?>">Internal Market</a></li>
                                <?php endif; ?>

                            </ul>
                        </li>

                        <?php
                        $newPostsCount = 0;
                        if ($userId !== null) {
                            $lastOnlineQuery = "SELECT last_online FROM custom_phpbb_lastonline WHERE user_id = ?";
                            $lastOnlineStmt = $pdo->prepare($lastOnlineQuery);
                            $lastOnlineStmt->execute([$userId]);
                            $lastOnlineRow = $lastOnlineStmt->fetch();
                            $lastOnlineOrNow = !$lastOnlineRow ? 0 : strtotime($lastOnlineRow['last_online']);
                            
                            $newPostsQuery = "SELECT COUNT(*) as count FROM phpbb_posts WHERE post_time > ?";
                            $newPostsStmt = $pdo->prepare($newPostsQuery);
                            $newPostsStmt->execute([$lastOnlineOrNow]);
                            $newPostsCount = $newPostsStmt->fetch()['count'];
                        }

                        $unreadCount = 0;
                        if ($isStandardUser) {
                            $unreadQuery = "SELECT COUNT(*) as count FROM messages m JOIN player_profiles p ON m.Message_Receiver = p.Profile_Id WHERE p.User_Id = ? AND m.Message_Read = 0";
                            $unreadStmt = $pdo->prepare($unreadQuery);
                            $unreadStmt->execute([$userId]);
                            $unreadCount = $unreadStmt->fetch()['count'];
                        }
                        
                        $socialHasNotif = ($newPostsCount > 0 || $unreadCount > 0);
                        ?>
                        <li class="has-dropdown" id="nav-social-dropdown">
                            <a href="#" class="<?php echo isActivePage('roster') || isActivePage('forum') || isActivePage('messages') || isActivePage('calendar') ? 'active' : ''; ?>">
                                <?php echo @file_get_contents(__DIR__ . '/../images/icons/header/socials.svg'); ?> Social 
                                <?php if($socialHasNotif): ?><span class="social-badge"></span><?php endif; ?>
                                <span class="caret">▼</span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?php echo $previewAppUrl; ?>/views/forum.php" class="<?php echo isActivePage('forum') ? 'active' : ''; ?>">
                                        Forum
                                        <?php if ($newPostsCount > 0): ?>
                                            <span class="messages-notification"><?php echo $newPostsCount; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                                <?php if($isStandardUser): ?>
                                <li>
                                    <a href="<?php echo $previewAppUrl; ?>/views/messages.php" class="<?php echo isActivePage('messages') ? 'active' : ''; ?>">
                                        Messages
                                        <?php if ($unreadCount > 0): ?>
                                            <span class="messages-notification"><?php echo $unreadCount; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <li><a href="<?php echo $previewAppUrl; ?>/views/roster.php" class="<?php echo isActivePage('roster') ? 'active' : ''; ?>">Roster</a></li>
                            </ul>
                        </li>
                        
                        <li class="has-dropdown">
                            <a href="#" class="<?php echo isActivePage('dashboard') || isActivePage('docs') || isActivePage('logs') || isActivePage('financials') ? 'active' : ''; ?>">
                                <?php echo @file_get_contents(__DIR__ . '/../images/icons/header/intelligence.svg'); ?> Intelligence <span class="caret">▼</span>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a href="<?php echo $previewAppUrl; ?>/views/dashboard.php" class="<?php echo isActivePage('dashboard') ? 'active' : ''; ?>">Dashboard</a></li>
                                <li><a href="<?php echo $previewAppUrl; ?>/views/docs.php" class="<?php echo isActivePage('docs') ? 'active' : ''; ?>">News</a></li>
                                <li><a href="<?php echo $previewAppUrl; ?>/views/logs.php" class="<?php echo isActivePage('logs') ? 'active' : ''; ?>">Logs</a></li>
                                <?php if($isStandardUser): ?>
                                    <li><a href="<?php echo $previewAppUrl; ?>/views/financials.php" class="<?php echo isActivePage('financials') ? 'active' : ''; ?>">Financials</a></li>
                                <?php endif; ?>
                                <li><a href="<?php echo $previewAppUrl; ?>/views/calendar.php" class="<?php echo isActivePage('calendar') ? 'active' : ''; ?>">Calendar</a></li>
                            </ul>
                        </li>

                        <?php if ($isAdmin): ?>
                            <li class="has-dropdown">
                                <a href="#" class="<?php echo isActivePage('admin-inventories') || isActivePage('admin-users') || isActivePage('admin-addNewUser') || isActivePage('admin-parameters') || isActivePage('admin-markets') || isActivePage('admin-shopList') || isActivePage('admin-interchangeable') || isActivePage('admin-maps') || isActivePage('admin-renderSvgTiles') || isActivePage('admin-financial-reports') ? 'active' : ''; ?>">Admin <span class="caret">▼</span></a>
                                <ul class="dropdown-menu">
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/inventories.php" class="<?php echo isActivePage('admin-inventories') ? 'active' : ''; ?>">Inventories</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/users.php" class="<?php echo isActivePage('admin-users') ? 'active' : ''; ?>">Users</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/addNewUser.php" class="<?php echo isActivePage('admin-addNewUser') ? 'active' : ''; ?>">Add New User</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/parameters.php" class="<?php echo isActivePage('admin-parameters') ? 'active' : ''; ?>">Parameters</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/markets.php" class="<?php echo isActivePage('admin-markets') ? 'active' : ''; ?>">Markets</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/shopList.php" class="<?php echo isActivePage('admin-shopList') ? 'active' : ''; ?>">Shop List</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/interchangeableItems.php" class="<?php echo isActivePage('admin-interchangeable') ? 'active' : ''; ?>">Item Exchange</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/maps.php" class="<?php echo isActivePage('admin-maps') ? 'active' : ''; ?>">Maps</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/renderSvgTiles.php" class="<?php echo isActivePage('admin-renderSvgTiles') ? 'active' : ''; ?>">Render SVG Tiles</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/financialReports.php" class="<?php echo isActivePage('admin-financial-reports') ? 'active' : ''; ?>">Financial Reports</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/manageProfiles.php" class="<?php echo isActivePage('admin-manageProfiles') ? 'active' : ''; ?>">Manage Profiles</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/editHierarchy.php" class="<?php echo isActivePage('admin-editHierarchy') ? 'active' : ''; ?>">Edit Hierarchy</a></li>
                                    <li><a href="<?php echo $previewAppUrl; ?>/admin/activeSessions.php">Active Sessions</a></li>
                                </ul>
                            </li>
                        <?php endif; ?>
                </ul>
            </nav>
            <div class="header-right-actions">
                <?php if($isStandardUser): ?>
                    <?php if (isset($_SESSION['inventory_name'], $_SESSION['inventory_money'])): ?>
                    <div class="header-inventory-status">
                        <div class="header-status-line">
                            <span class="header-inv-money"><?php echo number_format($_SESSION['inventory_money'], 2, ".", "'"); ?> Cr</span>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="#" onclick="showProfileOverlay()" class="btn-industrial">Change Password</a>
                <?php if($isStandardUser): ?>
                    <a href="<?php echo $mainAppUrl; ?>/views/profile.php?id=<?php echo $headerProfileId ?>" class="btn-industrial primary">Profile</a>
                <?php endif; ?>
                <?php $previewLogoutUrl = '../views/logout.php'; ?>
                <a href="<?php echo $previewLogoutUrl; ?>" class="btn-industrial danger">Logout</a>
            </div>
        </div>
    </header>

    <div id="profile-overlay" class="preview-header-1">
        <div id="profile-overlay-container">
            <h3>Change Password</h3>
            <div id="profile-content">
                <form id="changePasswordForm" class="profile-form">
                    <div class="profile-form-row">
                        <label for="currentPassword">Current Password:</label>
                        <input type="password" id="currentPassword" required autocomplete="current-password">
                    </div>
                    <div class="profile-form-row">
                        <label for="newPassword">New Password:</label>
                        <input type="password" id="newPassword" required autocomplete="new-password">
                    </div>
                    <div class="profile-form-row">
                        <label for="confirmPassword">Confirm New Password:</label>
                        <input type="password" id="confirmPassword" required autocomplete="new-password">
                    </div>
                    <div class="profile-actions">
                        <button type="button" onclick="changePassword()" class="btn-industrial success">Save</button>
                        <button type="button" onclick="hideProfileOverlay()" class="btn-industrial">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // CSRF token - automatically added to all POST fetch requests
        (function() {
            const originalFetch = window.fetch;
            window.fetch = function(url, options = {}) {
                if (options.method && options.method.toUpperCase() === 'POST') {
                    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    if (csrfMeta) {
                        if (!options.headers) {
                            options.headers = {};
                        }
                        if (options.headers instanceof Headers) {
                            options.headers.set('X-CSRF-Token', csrfMeta.content);
                        } else {
                            options.headers['X-CSRF-Token'] = csrfMeta.content;
                        }
                    }
                }
                return originalFetch.call(this, url, options);
            };
        })();

        // Auto-refresh header data periodically
        function refreshHeaderData() {
            fetch('<?php echo $mainAppUrl; ?>/db/misc/getHeaderData.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const invMoney = document.querySelector('.header-inv-money');
                        const messagesLink = document.querySelector('a[href="<?php echo $mainAppUrl; ?>/views/messages.php"]');
                        const notification = messagesLink ? messagesLink.querySelector('.messages-notification') : null;
                        
                        if (invMoney && data.inventory_money !== undefined) {
                            let balance = Number(data.inventory_money);
                            let formatted = balance.toFixed(2).split('.');
                            formatted[0] = formatted[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
                            invMoney.textContent = formatted.join('.') + ' Cr';
                        }
                        if (data.unread_messages > 0) {
                            if (notification) {
                                notification.textContent = data.unread_messages;
                            } else if (messagesLink) {
                                const span = document.createElement('span');
                                span.className = 'messages-notification';
                                span.textContent = data.unread_messages;
                                messagesLink.appendChild(span);
                            }
                            
                            const socialDropdown = document.getElementById('nav-social-dropdown');
                            if (socialDropdown) {
                                const socialLink = socialDropdown.querySelector('a');
                                if (socialLink && !socialLink.querySelector('.social-badge')) {
                                    const badge = document.createElement('span');
                                    badge.className = 'social-badge';
                                    const caret = socialLink.querySelector('.caret');
                                    if (caret) {
                                        socialLink.insertBefore(badge, caret);
                                    }
                                }
                            }
                        } else if (notification) {
                            notification.remove();
                        }
                    }
                })
                .catch(err => console.log('Header refresh error:', err));
        }

        window.addEventListener('focus', refreshHeaderData);
        setInterval(refreshHeaderData, 30000);

        function showProfileOverlay() {
            document.getElementById('profile-overlay').style.display = 'block';
        }

        function hideProfileOverlay() {
            document.getElementById('profile-overlay').style.display = 'none';
        }

        function changePassword() {
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword !== confirmPassword) {
                if (typeof showError === "function") showError('New passwords do not match');
                else alert('New passwords do not match');
                return;
            }

            const data = {
                currentPassword: currentPassword,
                newPassword: newPassword
            };

            fetch('<?php echo $mainAppUrl; ?>/db/changePassword.php', {
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
                        hideProfileOverlay();
                        if (typeof showToast === "function") showToast('Password changed successfully!', 'success');
                        else alert('Password changed successfully!');
                        document.getElementById('changePasswordForm').reset();
                    } else {
                        if (typeof showError === "function") showError(json.error);
                        else alert(json.error);
                    }
                } catch (e) {
                    console.error("JSON parse error: ", e);
                }
            })
            .catch(err => console.error("Fetch error: " + err.message));
        }
    </script>

