<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Only admins can create/edit wiki pages
if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['title']) || trim($input['title']) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit();
}

$title = trim($input['title']);
$content = isset($input['content']) ? $input['content'] : '';
$parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? intval($input['parent_id']) : null;
$pageId = isset($input['page_id']) ? intval($input['page_id']) : null;
$slug = isset($input['slug']) && trim($input['slug']) !== '' ? trim($input['slug']) : null;
$latitude = (isset($input['latitude']) && $input['latitude'] !== '' && $input['latitude'] !== null) ? floatval($input['latitude']) : null;
$longitude = (isset($input['longitude']) && $input['longitude'] !== '' && $input['longitude'] !== null) ? floatval($input['longitude']) : null;

// Generate slug from title if not provided
if (!$slug) {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
}

try {
    if ($pageId) {
        // Update existing page
        // Check slug uniqueness (excluding current page)
        $checkStmt = $pdo->prepare("SELECT Page_Id FROM wiki_pages WHERE Slug = ? AND Page_Id != ?");
        $checkStmt->execute([$slug, $pageId]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'A page with this slug already exists']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE wiki_pages SET Title = ?, Slug = ?, Content = ?, Parent_Id = ?, Latitude = ?, Longitude = ?, Updated_By = ? WHERE Page_Id = ?");
        $stmt->execute([$title, $slug, $content, $parentId, $latitude, $longitude, $_SESSION['user_id'], $pageId]);

        // Log
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Wiki page updated: '$title' (ID: $pageId) by user '" . $_SESSION['username'] . "'"]); 

        echo json_encode(['success' => true, 'page_id' => $pageId, 'slug' => $slug]);
    } else {
        // Create new page
        // Check slug uniqueness
        $checkStmt = $pdo->prepare("SELECT Page_Id FROM wiki_pages WHERE Slug = ?");
        $checkStmt->execute([$slug]);
        if ($checkStmt->fetch()) {
            // Append a number to make it unique
            $baseSlug = $slug;
            $counter = 2;
            do {
                $slug = $baseSlug . '-' . $counter;
                $checkStmt->execute([$slug]);
                $counter++;
            } while ($checkStmt->fetch());
        }

        // Get next sort order for this parent
        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(Sort_Order), 0) + 1 as next_sort FROM wiki_pages WHERE Parent_Id <=> ?");
        $sortStmt->execute([$parentId]);
        $nextSort = $sortStmt->fetch()['next_sort'];

        $stmt = $pdo->prepare("INSERT INTO wiki_pages (Title, Slug, Content, Parent_Id, Latitude, Longitude, Sort_Order, Created_By) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $slug, $content, $parentId, $latitude, $longitude, $nextSort, $_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();

        // Log
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Wiki page created: '$title' (ID: $newId) by user '" . $_SESSION['username'] . "'"]); 

        echo json_encode(['success' => true, 'page_id' => $newId, 'slug' => $slug]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
