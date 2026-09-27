<?php
require_once '../db/connection.php';  // Move connection first
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

$lastOnlineQuery  = "SELECT user_id FROM custom_phpbb_lastonline WHERE user_id = :user_id";
$stmt = $pdo->prepare($lastOnlineQuery);
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$lastOnlineRow = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$lastOnlineRow) {
    $insertQuery = "INSERT INTO custom_phpbb_lastonline (user_id, last_online) VALUES (:user_id, NOW())";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute(['user_id' => $_SESSION['user_id']]);
} else {
    $updateQuery = "UPDATE custom_phpbb_lastonline SET last_online = NOW() WHERE user_id = :user_id";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute(['user_id' => $_SESSION['user_id']]);
}

// Handle phpBB auto-login
$my_username = $_SESSION['username'];

$orig_session = $_SESSION;
$orig_session_id = session_id();
$orig_get = $_GET;
$orig_post = $_POST;
$orig_cookie = $_COOKIE;
$orig_server = $_SERVER;
$orig_env = $_ENV ?? [];

// Close PHP's native session before phpBB initialization to prevent corruption
session_write_close();

// Buffer any stray output phpBB might produce during initialization
ob_start();

// Strip stale phpBB cookies before initialization to force fresh auto-login.
// Leftover cookies can cause session_begin() to load a seemingly valid but
// actually expired/broken session, preventing the auto-login from triggering.
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'phpbb3_') === 0) {
        unset($_COOKIE[$name]);
        setcookie($name, '', time() - 3600, '/');
    }
}

define('IN_PHPBB', true);
$phpbb_root_path = __DIR__ . '/forum/';
$phpEx = 'php';

// Include phpBB
require_once($phpbb_root_path . 'common.' . $phpEx);

// Also expire phpBB cookies at phpBB's configured cookie path (may differ from '/')
if (isset($config['cookie_path']) && $config['cookie_path'] !== '/') {
    foreach ($orig_cookie as $name => $value) {
        if (strpos($name, 'phpbb3_') === 0) {
            setcookie($name, '', time() - 3600, $config['cookie_path']);
        }
    }
}

// Restore superglobals unset by phpBB
$_GET = $orig_get;
$_POST = $orig_post;
$_COOKIE = $orig_cookie;
$_SERVER = $orig_server;
$_ENV = $orig_env;

// Start phpBB session (will see ANONYMOUS since we stripped cookies)
$user->session_begin();
$auth->acl($user->data);

// Debug: log phpBB session state (remove once auto-login is confirmed working)
error_log("[forum.php] phpBB auto-login: phpbb_uid=" . $user->data['user_id']
    . " phpbb_user=" . ($user->data['username'] ?? 'N/A')
    . " main_user=" . $my_username
    . " is_anon=" . ($user->data['user_id'] == ANONYMOUS ? 'Y' : 'N'));

// Check if user is logged in to your system but not phpBB
if ($user->data['user_id'] == ANONYMOUS) {
    // Check if user exists in phpBB
    $sql = 'SELECT user_id, username, user_password
        FROM ' . USERS_TABLE . "
        WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($my_username)) . "'";
    $result = $db->sql_query($sql);
    $row = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);
    
    if ($row) {
        // User exists, log them in
        $user->session_create($row['user_id'], false, true, true);
    } else {
        // Create new phpBB user
        if (!function_exists('user_add')) {
            require_once($phpbb_root_path . 'includes/functions_user.' . $phpEx);
        }
        
        $user_row = array(
            'username'          => $my_username,
            'user_password'     => phpbb_hash(bin2hex(random_bytes(16))), // Random password
            'user_email'        => strtolower($my_username) . '@forum.local',
            'group_id'          => 2, // REGISTERED
            'user_type'         => USER_NORMAL,
            'user_timezone'     => 'UTC',
            'user_lang'         => 'en',
            'user_dateformat'   => 'd M Y H:i',
        );
        
        $user_id = user_add($user_row);
        
        if ($user_id) {
            $user->session_create($user_id, false, true, true);
        }
    }
}
// Check if phpBB user doesn't match main site user
else if ($user->data['user_id'] != ANONYMOUS) {
    // Get phpBB username
    $phpbb_username = $user->data['username'];
    
    // If they don't match, kill phpBB session and re-login
    if (strtolower($my_username) !== strtolower($phpbb_username)) {
        $user->session_kill();
        $user->session_begin();
        
        // Re-authenticate
        $sql = 'SELECT user_id, username, user_password
            FROM ' . USERS_TABLE . "
            WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($my_username)) . "'";
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);
        
        if ($row) {
            $user->session_create($row['user_id'], false, true, true);
        }
    }
}

// Discard any stray phpBB output from initialization
ob_end_clean();

// Restore PHP's native session that phpBB may have disrupted
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_id($orig_session_id);
if (isset($orig_server['HTTP_HOST']) && strpos($orig_server['HTTP_HOST'], 'cinder9.com') !== false) {
    session_set_cookie_params(['domain' => '.cinder9.com']);
}
session_start();
$_SESSION = $orig_session;

// Define forum URL
$forum_url = 'forum/index.php';
if (isset($_GET['t'])) {
    $forum_url = 'forum/viewtopic.php?t=' . intval($_GET['t']);
}

// Include header
include '../includes/header.php';
?>

<style>
    body { overflow: hidden; }
</style>
<div class="forum-container" style="width: 100%; height: calc(100vh - 62px); overflow: hidden; display: flex; flex-direction: column;">
    <iframe src="<?php echo htmlspecialchars($forum_url); ?>" style="flex: 1; width: 100%; border: none; display: block;"></iframe>
</div>

<?php 
include '../includes/footer.php'; 
?>

