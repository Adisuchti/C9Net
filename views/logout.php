<?php
if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'cinder9.com') !== false) {
        session_set_cookie_params(['domain' => '.cinder9.com']);
    }
    session_start();
}
require_once '../db/connection.php';

// Clear remember token for this device from database
if (isset($_COOKIE['remember_token'])) {
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
    $stmt->execute([$_COOKIE['remember_token']]);
}

// Clear session
session_unset();
session_destroy();

// Expire the session cookie if any
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear remember me cookie for preview domain
setcookie('remember_token', '', time() - 3600, '/', '', false, true);
if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'cinder9.com') !== false) {
    setcookie('remember_token', '', time() - 3600, '/', '.cinder9.com', false, true);
}

header('Location: login.php');
exit();
?>
