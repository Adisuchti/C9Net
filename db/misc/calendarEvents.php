<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Fetch events for a given month ---
if ($method === 'GET') {
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate   = date('Y-m-t', strtotime($startDate));

    // Fetch a wider range to include calendar padding days
    $fetchStart = date('Y-m-d', strtotime($startDate . ' -10 days'));
    $fetchEnd   = date('Y-m-d', strtotime($endDate . ' +10 days'));

    try {
        $stmt = $pdo->prepare("
            SELECT ce.*, u.username AS creator_name
            FROM calendar_events ce
            LEFT JOIN users u ON ce.Created_By = u.id
            WHERE (ce.Event_Date BETWEEN :start AND :end)
               OR (ce.Event_End_Date IS NOT NULL AND ce.Event_Date <= :end2 AND ce.Event_End_Date >= :start2)
            ORDER BY ce.Event_Date, ce.Event_Time
        ");
        $stmt->execute([
            ':start'  => $fetchStart,
            ':end'    => $fetchEnd,
            ':start2' => $fetchStart,
            ':end2'   => $fetchEnd
        ]);
        $events = $stmt->fetchAll();

        // Fetch next overall upcoming event from today
        $today = date('Y-m-d');
        $now = date('H:i:s');
        $nextEventStmt = $pdo->prepare("
            SELECT ce.*, u.username AS creator_name
            FROM calendar_events ce
            LEFT JOIN users u ON ce.Created_By = u.id
            WHERE ce.Event_Date > :today OR (ce.Event_Date = :today2 AND (ce.Event_Time >= :now OR ce.Event_Time IS NULL))
            ORDER BY ce.Event_Date ASC, ce.Event_Time ASC
            LIMIT 1
        ");
        $nextEventStmt->execute([
            ':today' => $today,
            ':today2' => $today,
            ':now' => $now
        ]);
        $nextEvent = $nextEventStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'events' => $events, 'next_event' => $nextEvent]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// --- POST: Create / Update / Delete events ---
if ($method === 'POST') {
    // Determine if input is JSON or FormData
    $isFormData = !empty($_POST) || !empty($_FILES);
    $input = $isFormData ? $_POST : json_decode(file_get_contents("php://input"), true);
    
    // In some setups, CSRF token might be checked. If FormData is used, make sure it passes.
    if ($isFormData) {
        // Skip validateCsrfToken() or validate it via $_POST['csrf_token']
        // To be safe and compatible with the existing validateCsrfToken() which expects headers, 
        // we assume the header X-CSRF-TOKEN is still sent by JS fetch.
    }
    validateCsrfToken();

    $action = $input['action'] ?? '';

    // Check if user is allowed to edit calendar
    $userId = $_SESSION['user_id'];
    $isAdmin = ($userId === -1);

    if (!$isAdmin) {
        $checkStmt = $pdo->prepare("SELECT 1 FROM calendar_editors WHERE User_Id = ?");
        $checkStmt->execute([$userId]);
        if (!$checkStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You are not authorized to manage calendar events']);
            exit();
        }
    }
    
    // Handle image upload function
    $handleImageUpload = function() {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $uploadDir = __DIR__ . '/../../images/events/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($fileExt, $allowedExts)) {
            throw new Exception('Invalid file type for image. Allowed: jpg, jpeg, png, gif, webp');
        }
        
        $newFilename = uniqid('ev_') . '.' . $fileExt;
        $destPath = $uploadDir . $newFilename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
            return '../images/events/' . $newFilename; // Store relative path
        } else {
            throw new Exception('Failed to move uploaded file');
        }
    };

    try {
        switch ($action) {
            case 'create':
                if (empty($input['title']) || empty($input['event_date'])) {
                    throw new Exception('Title and date are required');
                }
                
                $imageUrl = $handleImageUpload();
                
                $stmt = $pdo->prepare("
                    INSERT INTO calendar_events (Title, Description, Event_Date, Event_Time, Event_End_Date, Event_End_Time, Color, Created_By, Image_Url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $input['title'],
                    $input['description'] ?? null,
                    $input['event_date'],
                    $input['event_time'] ?: null,
                    $input['event_end_date'] ?: null,
                    $input['event_end_time'] ?: null,
                    $input['color'] ?? '#c89b3c',
                    $userId,
                    $imageUrl
                ]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'event_id' => $newId]);
                break;

            case 'update':
                if (empty($input['event_id']) || empty($input['title']) || empty($input['event_date'])) {
                    throw new Exception('Event ID, title and date are required');
                }
                
                $imageUrl = $handleImageUpload();
                
                if ($imageUrl !== null) {
                    $stmt = $pdo->prepare("
                        UPDATE calendar_events
                        SET Title = ?, Description = ?, Event_Date = ?, Event_Time = ?,
                            Event_End_Date = ?, Event_End_Time = ?, Color = ?, Image_Url = ?
                        WHERE Event_Id = ?
                    ");
                    $stmt->execute([
                        $input['title'],
                        $input['description'] ?? null,
                        $input['event_date'],
                        $input['event_time'] ?: null,
                        $input['event_end_date'] ?: null,
                        $input['event_end_time'] ?: null,
                        $input['color'] ?? '#c89b3c',
                        $imageUrl,
                        $input['event_id']
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE calendar_events
                        SET Title = ?, Description = ?, Event_Date = ?, Event_Time = ?,
                            Event_End_Date = ?, Event_End_Time = ?, Color = ?
                        WHERE Event_Id = ?
                    ");
                    $stmt->execute([
                        $input['title'],
                        $input['description'] ?? null,
                        $input['event_date'],
                        $input['event_time'] ?: null,
                        $input['event_end_date'] ?: null,
                        $input['event_end_time'] ?: null,
                        $input['color'] ?? '#c89b3c',
                        $input['event_id']
                    ]);
                }
                echo json_encode(['success' => true]);
                break;

            case 'delete':
                if (empty($input['event_id'])) {
                    throw new Exception('Event ID is required');
                }
                $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE Event_Id = ?");
                $stmt->execute([$input['event_id']]);
                echo json_encode(['success' => true]);
                break;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
?>
