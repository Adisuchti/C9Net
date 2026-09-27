<?php
// Bridge between your auth system and phpBB
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';

// Start your session first
if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'cinder9.com') !== false) {
        session_set_cookie_params(['domain' => '.cinder9.com']);
    }
    session_start();
}

// SAVE the username before phpBB potentially overwrites or clears $_SESSION
$my_username = isset($_SESSION['username']) ? $_SESSION['username'] : null;

// Include phpBB
include($phpbb_root_path . 'common.' . $phpEx);

// Start phpBB session
$user->session_begin();
$auth->acl($user->data);

// If user is NOT logged in to your system, kill phpBB session
if (!$my_username) {
    if ($user->data['user_id'] != ANONYMOUS) {
        $user->session_kill();
        $user->session_begin();
    }
    // Redirect to login
    header('Location: ../login.php?dir=' . urlencode('forum/phpbb_bridge.php'));
    exit;
}

// Check if user is logged in to your system but not phpBB
if ($my_username && $user->data['user_id'] == ANONYMOUS) {
    require_once __DIR__ . '/../../db/connection.php';
    
    $username = $my_username;
    
    // Check if user exists in phpBB
    $sql = 'SELECT user_id, username, user_password
        FROM ' . USERS_TABLE . "
        WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($username)) . "'";
    $result = $db->sql_query($sql);
    $row = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);
    
    if ($row) {
        // User exists, log them in
        $user->session_create($row['user_id'], false, true, true);
    } else {
        // Create new phpBB user
        if (!function_exists('user_add')) {
            include($phpbb_root_path . 'includes/functions_user.' . $phpEx);
        }
        
        $user_row = array(
            'username'          => $username,
            'user_password'     => phpbb_hash(bin2hex(random_bytes(16))), // Random password
            'user_email'        => strtolower($username) . '@forum.local',
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
else if ($my_username && $user->data['user_id'] != ANONYMOUS) {
    // Get phpBB username
    $phpbb_username = $user->data['username'];
    
    // If they don't match, kill phpBB session and re-login
    if (strtolower($my_username) !== strtolower($phpbb_username)) {
        $user->session_kill();
        header('Location: phpbb_bridge.php');
        exit;
    }
}

// Redirect to forum
header('Location: index.php');
exit;
?>