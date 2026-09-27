<?php
if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'cinder9.com') !== false) {
        session_set_cookie_params(['domain' => '.cinder9.com']);
    }
    session_start();
}

$redirectlink = isset($_GET['dir']) ? $_GET['dir'] : 'home.php';
// Prevent open redirect - only allow relative paths on this domain
if (preg_match('/^https?:\/\//i', $redirectlink) || str_starts_with($redirectlink, '//') || str_starts_with($redirectlink, '\\')) {
    $redirectlink = 'home.php';
}

// Check if user has a valid remember me token, but skip if they are manually logging in via POST
if (!isset($_SESSION['username']) && isset($_COOKIE['remember_token']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once '../db/connection.php';
    require_once '../includes/auth.php';
    
    $token = $_COOKIE['remember_token'];
    
    // Verify token in database (supports multiple devices)
    $stmt = $pdo->prepare("
        SELECT u.id, u.username FROM remember_tokens rt
        JOIN users u ON u.id = rt.user_id
        WHERE rt.token = ? AND rt.expires > NOW()
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Restore session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        
        // Get MarketEnabled setting
        $stmt = $pdo->prepare("SELECT Var_Value FROM condition_variables WHERE Var_Name = 'Market_Enabled';");
        $stmt->execute();
        $marketEnabled = $stmt->fetch();
        $_SESSION['MarketEnabled'] = $marketEnabled['Var_Value'] ?? 0;
        
        // Get inventory ID
        $invStmt = $pdo->prepare("SELECT inventory_id FROM users WHERE id = ? LIMIT 1");
        $invStmt->execute([$user['id']]);
        $invData = $invStmt->fetch(PDO::FETCH_ASSOC);
        if ($invData) {
            $_SESSION['inventory_id'] = $invData['inventory_id'];
        }
        
        // Get inventory info for session
        $invQuery = "SELECT Inventory_Name, Inventory_Money, Inventory_Market_Saturation FROM inventories WHERE Inventory_Id = ?";
        $invStmt = $pdo->prepare($invQuery);
        $invStmt->execute([$_SESSION['inventory_id']]);
        $inventoryInfo = $invStmt->fetch();
        
        if ($inventoryInfo) {
            $_SESSION['inventory_name'] = $inventoryInfo['Inventory_Name'];
            $_SESSION['inventory_money'] = $inventoryInfo['Inventory_Money'];
            $_SESSION['Inventory_market_saturation'] = $inventoryInfo['Inventory_Market_Saturation'];
        }
        
        header('Location: ' . $redirectlink);
        exit();
    } else {
        // Token expired or invalid, clear cookie
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }
}

// Clear any existing session data on fresh login page
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    session_unset();
}

// If user is already logged in, redirect to inventory
if (isset($_SESSION['username'])) {
    header('Location: ' . $redirectlink);
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once '../db/connection.php';
    require_once '../includes/auth.php';
    require_once '../includes/debug.php';

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']) ? true : false;

    try {
        if (authenticate($username, $password)) {
            // Get user ID for token storage
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $userStmt->execute([$username]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // Handle remember me
            if ($remember_me && $userData) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                // Store token in database (separate table allows multiple devices)
                $tokenStmt = $pdo->prepare("
                    INSERT INTO remember_tokens (user_id, token, expires)
                    VALUES (?, ?, ?)
                ");
                $tokenStmt->execute([$userData['id'], $token, $expires]);
                
                // Clean up expired tokens for this user
                $cleanStmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND expires <= NOW()");
                $cleanStmt->execute([$userData['id']]);
                
                // Set secure HTTP-only cookie
                setcookie(
                    'remember_token',
                    $token,
                    strtotime('+30 days'),
                    '/',
                    '',
                    false,  // Not HTTPS only for development (set to true in production)
                    true    // HTTP only (prevents JavaScript access)
                );
            }
            
            $logStmt = $pdo->prepare("
                INSERT INTO hiddenLogs (Comment) 
                VALUES (?)
            ");
            $logMessage = "Successful login attempt for username '". $username ."' from ". $_SERVER['REMOTE_ADDR'] ." at ". date('Y-m-d H:i:s');
            $logStmt->execute([$logMessage]);
            header('Location: ' . $redirectlink);
            exit();
        } else {
            $error = 'Invalid username or password.';
            $logStmt = $pdo->prepare("
                INSERT INTO hiddenLogs (Comment) 
                VALUES (?)
            ");
            $logMessage = "Failed login attempt for username '". $username ."' from ". $_SERVER['REMOTE_ADDR'] ." at ". date('Y-m-d H:i:s');
            $logStmt->execute([$logMessage]);
            debug_to_console(("Failed login attempt for username: " . $username));
        }
    } catch (Exception $e) {
        $error = 'Login error occurred. Please try again.' . $e->getMessage();
        debug_to_console(("Login error for username " . $username . ": " . $e->getMessage()));
    }
}
$host = $_SERVER['HTTP_HOST'] ?? '';
$cssBaseUrl = '../styles';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C9 - Authentication</title>
    <link rel="stylesheet" href="<?php echo $cssBaseUrl; ?>/preview_styles.css?t=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cinder9.com/favicon.ico" rel="icon" type="image/x-icon">
</head>
<body class="login-preview-body">
    <div class="login-preview-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h1>Cinder 9</h1>
                <p>Intranet Access</p>
            </div>
            
            <?php if ($error): ?>
                <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form action="login.php<?php echo isset($_GET['dir']) ? '?dir=' . urlencode($_GET['dir']) : ''; ?>" method="POST" autocomplete="on" class="login-form">
                <div class="login-input-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="username" class="login-input">
                </div>
                <div class="login-input-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" class="login-input">
                </div>
                <div class="login-remember">
                    <label class="custom-checkbox-container">
                        <input type="checkbox" id="remember_me" name="remember_me" value="1">
                        <span class="custom-checkmark"></span>
                        Remember me
                    </label>
                </div>
                <div class="login-actions">
                    <button type="submit" class="btn-industrial btn-login-submit">Authenticate</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

