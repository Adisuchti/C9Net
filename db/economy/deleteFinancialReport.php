<?php
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/financialReportCascade.php';

header('Content-Type: application/json');

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// Validate CSRF token
validateCsrfToken();

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$reportId = $data['report_id'] ?? null;

if (!$reportId) {
    echo json_encode(['success' => false, 'error' => 'Missing report ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM financial_reports WHERE Report_Id = ?");
    $stmt->execute([$reportId]);
    
    // Trigger balance cascade to adjust all remaining reports chronologically
    cascadeFinancialBalances($pdo);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
