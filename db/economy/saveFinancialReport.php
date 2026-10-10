<?php
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../../includes/auth.php';
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

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input.']);
    exit;
}

$reportDate = $data['report_date'] ?? '';
$contractors = $data['contractors'] ?? '';
$compiledBy = $data['compiled_by'] ?? '1293A';
$reportData = $data['report_data'] ?? null;

if (empty($reportDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) || !$reportData) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing report date (must be YYYY-MM-DD).']);
    exit;
}

$reportId = $data['report_id'] ?? null;

try {
    if ($reportId) {
        $stmt = $pdo->prepare("UPDATE financial_reports SET Report_Date = ?, Contractors = ?, Data = ?, Compiled_By = ? WHERE Report_Id = ?");
        $stmt->execute([
            $reportDate,
            $contractors,
            json_encode($reportData),
            $compiledBy,
            $reportId
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO financial_reports (Report_Date, Contractors, Data, Compiled_By) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $reportDate,
            $contractors,
            json_encode($reportData),
            $compiledBy
        ]);
        $reportId = $pdo->lastInsertId();
    }
    
    // Trigger balance cascade to chronologically subsequent reports
    cascadeFinancialBalances($pdo);
    
    echo json_encode(['success' => true, 'report_id' => $reportId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
