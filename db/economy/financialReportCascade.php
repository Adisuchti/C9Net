<?php
function cascadeFinancialBalances($pdo) {
    $stmt = $pdo->query("SELECT * FROM financial_reports ORDER BY Report_Date ASC, Created_At ASC");
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $currentRunningBalance = null;

    foreach ($reports as $report) {
        $data = json_decode($report['Data'], true);
        if (!is_array($data)) continue;

        if ($currentRunningBalance === null) {
            // First report in chronological order: trust its previous_balance as the absolute starting point
            $prevBal = isset($data['previous_balance']) ? (float)$data['previous_balance'] : 223323;
            $currentRunningBalance = $prevBal;
        }

        // Update this report's previous balance to the running balance
        $data['previous_balance'] = $currentRunningBalance;
        
        $earnings = isset($data['total_earnings']) ? (float)$data['total_earnings'] : 0;
        $costs = isset($data['total_costs']) ? (float)$data['total_costs'] : 0;
        
        // Calculate new current balance
        $newCurrentBal = $currentRunningBalance + $earnings - $costs;
        $data['current_balance'] = $newCurrentBal;

        // Encode back
        $newData = json_encode($data);

        // Only update if changed
        if ($newData !== $report['Data']) {
            $update = $pdo->prepare("UPDATE financial_reports SET Data = ? WHERE Report_Id = ?");
            $update->execute([$newData, $report['Report_Id']]);
        }

        // Set running balance for the NEXT report in the loop
        $currentRunningBalance = $newCurrentBal;
    }
}
