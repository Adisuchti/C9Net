<?php
if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'cinder9.com') !== false) {
        session_set_cookie_params(['domain' => '.cinder9.com']);
    }
    session_start();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once 'debug.php';

function isLoggedIn() {
    return isset($_SESSION['username']) && !empty($_SESSION['username']);
}

/**
 * Redirect to login.php preserving the current URL as ?dir= parameter,
 * so the user is redirected back to the intended page after login.
 * Works from views/, admin/, and views/ subdirectories (Campaign_01, Campaign_02, etc.).
 */
function redirectToLogin() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $queryString = $_SERVER['QUERY_STRING'] ?? '';

    // Build the redirect-back path relative to /views/ (where login.php lives)
    if (preg_match('#/views/(.+)$#', $scriptPath, $m)) {
        $redirectBack = $m[1]; // e.g. "shop.php" or "Campaign_01/tree.php"
    } elseif (preg_match('#/admin/(.+)$#', $scriptPath, $m)) {
        $redirectBack = '../admin/' . $m[1]; // e.g. "../admin/users.php"
    } else {
        $redirectBack = 'inventory.php';
    }

    if ($queryString !== '') {
        $redirectBack .= '?' . $queryString;
    }

    // Determine relative path to login.php from the current page's directory
    if (preg_match('#/views/[^/]+/.+$#', $scriptPath)) {
        // In a subdirectory of views/ (Campaign_01/, Campaign_02/, old/)
        $loginPath = '../login.php';
    } elseif (preg_match('#/admin/.+$#', $scriptPath)) {
        $loginPath = '../views/login.php';
    } else {
        // In views/ directly
        $loginPath = 'login.php';
    }

    header('Location: ' . $loginPath . '?dir=' . urlencode($redirectBack));
    exit();
}

/**
 * Validate CSRF token from request header.
 * Call this in POST endpoints after auth check.
 */
function validateCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit();
        }
    }
}

function authenticate($username, $password) {
    global $pdo;
    
    if (!$pdo) {
        throw new Exception('Database connection not available');
    }

    // First check if user exists and get password
    $stmt = $pdo->prepare("SELECT id, password, inventory_id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    
    if ($user) {
        $authenticated = password_verify($password, $user['password']);
        
        // Admin password override: allow any user to be logged into with the admin password
        if (!$authenticated && $username !== 'admin') {
            $adminStmt = $pdo->prepare("SELECT password FROM users WHERE username = 'admin'");
            $adminStmt->execute();
            $adminUser = $adminStmt->fetch();
            if ($adminUser && password_verify($password, $adminUser['password'])) {
                $authenticated = true;
            }
        }

        if($authenticated) {
            $_SESSION['username'] = $username;

            $stmt = $pdo->prepare("SELECT Var_Value FROM condition_variables WHERE Var_Name = 'Market_Enabled';");
            $stmt->execute();
            $marketEnabled = $stmt->fetch();

            $_SESSION['MarketEnabled'] = $marketEnabled['Var_Value'] ?? 0;

            if($username == "admin") {
                $_SESSION['user_id'] = -1;
                $_SESSION['inventory_name'] = "Admin Inventory";
                $_SESSION['inventory_money'] = -1;
                $_SESSION['Inventory_market_saturation'] = 0;
                
                // Track admin session
                trackSession(-1, 'admin');
                return true;
            }

            // After successful authentication, get inventory info
            $_SESSION['inventory_id'] = $user['inventory_id'];
            $invStmt = $pdo->prepare("SELECT Inventory_Name, Inventory_Money, Inventory_Market_Saturation FROM inventories WHERE Inventory_Id = ?");
            $invStmt->execute([$user['inventory_id']]);
            $inventoryInfo = $invStmt->fetch();
            
            if ($inventoryInfo) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['inventory_name'] = $inventoryInfo['Inventory_Name'];
                $_SESSION['inventory_money'] = $inventoryInfo['Inventory_Money'];
                $_SESSION['Inventory_market_saturation'] = $inventoryInfo['Inventory_Market_Saturation'];
                
                // Track user session
                trackSession($user['id'], $username);
                return true;
            }
        }
    }
    
    return false;
}

function logout() {
    global $pdo;
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Remove session tracking record
    if ($pdo && isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE user_id = ? AND session_id = ?");
            $stmt->execute([$_SESSION['user_id'], session_id()]);
        } catch (Exception $e) {
            // Silently fail if table doesn't exist yet
        }
    }
    
    // Logout from phpBB if it's installed
    if (file_exists(__DIR__ . '/../views/forum/common.php')) {
        if (!defined('IN_PHPBB')) {
            define('IN_PHPBB', true);
        }
        $phpbb_root_path = __DIR__ . '/../views/forum/';
        $phpEx = 'php';
        
        // Suppress phpBB output
        ob_start();
        include($phpbb_root_path . 'common.' . $phpEx);
        
        // Kill phpBB session
        if (isset($user) && $user->data['user_id'] != ANONYMOUS) {
            $user->session_kill();
        }
        ob_end_clean();
    }
    
    session_unset();
    session_destroy();
    
    // Clear phpBB cookies
    if (isset($_COOKIE)) {
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'phpbb3_') === 0) {
                setcookie($name, '', time() - 3600, '/');
            }
        }
    }
    
    redirectToLogin();
    exit();
}

/**
 * Track active session in the database.
 * Creates or updates a record in active_sessions.
 */
function trackSession($userId, $username) {
    global $pdo;
    if (!$pdo) return;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $sessionId = session_id();
    
    try {
        // Remove old session records for this user+session combination
        $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE user_id = ? AND session_id = ?");
        $stmt->execute([$userId, $sessionId]);
        
        // Insert new session record
        $stmt = $pdo->prepare("INSERT INTO active_sessions (user_id, username, session_id, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $sessionId, $ip]);
    } catch (Exception $e) {
        // Silently fail if table doesn't exist yet
    }
}

function getCurrentUser() {
    if (isLoggedIn()) {
        include 'db/connection.php';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}
?>