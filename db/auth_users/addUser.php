<?php
require_once '../db/connection.php';
require_once '../../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Only admin can add users
if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - admin only']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$username = $input['username'];
$password = password_hash($input['password'], PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $stmt->execute([$username, $password]);

    echo json_encode(['success' => true, 'error' => 'null']);
} catch (PDOException $e) {
    // Check if it's a duplicate entry error
    if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
        $error = "Username already taken. Please choose a different one.";
        echo json_encode(['success' => false, 'error' => $error]);
    } else {
        $error = "An error occurred during registration.";
        echo json_encode(['success' => false, 'error' => "unknown error"]);
    }
}
