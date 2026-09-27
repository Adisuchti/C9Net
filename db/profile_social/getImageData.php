<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

$profileId = isset($_GET['profileId']) ? (int)$_GET['profileId'] : 0;
$filename = isset($_GET['filename']) ? $_GET['filename'] : '';

if (!$profileId || !$filename) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

// Get description
$descStmt = $pdo->prepare("SELECT Description FROM image_descriptions WHERE Profile_Id = ? AND Filename = ?");
$descStmt->execute([$profileId, $filename]);
$descRow = $descStmt->fetch();
$description = $descRow ? $descRow['Description'] : '';

// Get comments with usernames
$commentsStmt = $pdo->prepare("
    SELECT ic.Comment_Id, ic.Comment_Text, ic.Created_At, ic.User_Id,
           u.username, pp.Profile_Name
    FROM image_comments ic
    LEFT JOIN users u ON ic.User_Id = u.id
    LEFT JOIN player_profiles pp ON u.id = pp.User_Id
    WHERE ic.Profile_Id = ? AND ic.Filename = ?
    ORDER BY ic.Created_At ASC
");
$commentsStmt->execute([$profileId, $filename]);
$comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'description' => $description,
    'comments' => $comments
]);
