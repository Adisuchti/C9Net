<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Fetch previous reports
try {
    $reportsStmt = $pdo->query("SELECT * FROM financial_reports ORDER BY Created_At DESC");
    $reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reports = [];
}

// Fetch default previous balance from the latest report, otherwise use 223323
$defaultPrevBalance = 223323;
if (!empty($reports)) {
    $latestReport = $reports[0];
    $latestData = json_decode($latestReport['Data'], true);
    if (isset($latestData['current_balance'])) {
        $defaultPrevBalance = $latestData['current_balance'];
    }
}

// Pre-calculate roleplay date (current date + 50 years)
$roleplayDate = date('Y-m-d', strtotime('+50 years'));

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>C9 - Financial Reports Admin</title>
    <link rel="stylesheet" href="../styles/styles.css?t=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@200..800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link href="../favicon.ico" rel="icon" type="image/x-icon">
    
</head>
<body>

<div class="financial-container">
    <!-- Sidebar / Navigation -->
    <div class="financial-sidebar">
        <button class="create-btn" onclick="showEditor()">+ Create Financial Report</button>
        
        <h3>Past Reports</h3>
        <div class="reports-list">
            <?php if (empty($reports)): ?>
                <div class="preview-financialReports-1">No reports generated yet.</div>
            <?php else: ?>
                <?php foreach ($reports as $index => $rep): ?>
                    <div class="report-item <?php echo $index === 0 ? 'active' : ''; ?>" 
                         onclick="loadReport(<?php echo htmlspecialchars(json_encode($rep)); ?>, this)">
                        <span class="date">Report: <?php echo date('d.m.Y', strtotime($rep['Report_Date'])); ?></span>
                        <span class="meta">Compiled by: <?php echo htmlspecialchars($rep['Compiled_By']); ?></span>
                        <span class="meta">Date: <?php echo date('d.m.Y H:i', strtotime($rep['Created_At'])); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Content Workspace -->
    <div class="financial-main">
        
        <!-- Editor View (Hidden by default, shown when clicking Create) -->
        <div class="report-editor preview-financialReports-2" id="editorView">
            <h3 id="editorTitle" class="preview-financialReports-3">New Financial Report</h3>
            
            <div id="cascadeWarningBanner" class="preview-financialReports-4">
                <span class="preview-financialReports-5">⚠ï¸</span>
                <div>
                    <strong>Balance Cascade Warning:</strong> You are editing an existing report. Saving this will automatically cascade and recalculate the previous and current balances of all chronologically subsequent reports in the system.
                </div>
            </div>
            
            <div class="editor-section">
                <h4>1. General Configuration</h4>
                <div class="form-row-grid">
                    <div class="form-group">
                        <label for="edit_report_date">Report Date (Roleplay Date):</label>
                        <input type="date" id="edit_report_date" value="<?php echo $roleplayDate; ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit_account_code">Account Code:</label>
                        <input type="text" id="edit_account_code" value="C13370-C-T">
                    </div>
                    <div class="form-group">
                        <label for="edit_account_holder">Account Holder:</label>
                        <input type="text" id="edit_account_holder" value="M. ROOK">
                    </div>
                </div>
                <div class="form-row-grid">
                    <div class="form-group">
                        <label for="edit_contractors">Licensed Contractors:</label>
                        <input type="text" id="edit_contractors" value="11 OPERATIVES, 1 GENERATION 2 TACTICAL DOLLS">
                    </div>
                    <div class="form-group">
                        <label for="edit_assistant_name">Contracting Assistant:</label>
                        <input type="text" id="edit_assistant_name" value="Kirsten Handley">
                    </div>
                    <div class="form-group">
                        <label for="edit_compiled_by">Compiled By:</label>
                        <input type="text" id="edit_compiled_by" value="1293A">
                    </div>
                </div>
                <div class="form-row-grid">
                    <div class="form-group">
                        <label for="edit_previous_balance">Previous Account Balance (Cr.):</label>
                        <input type="number" id="edit_previous_balance" value="<?php echo $defaultPrevBalance; ?>" onchange="calculateTotals()">
                    </div>
                </div>
            </div>

            <!-- Table 1: Base Costs -->
            <div class="editor-section">
                <h4>2. Base Cost Overview (Expenses)</h4>
                <div class="dynamic-table-container">
                    <table class="dynamic-table" id="expensesTable">
                        <thead>
                            <tr>
                                <th>Expense Category</th>
                                <th>Cost (Cr.) [negative number]</th>
                                <th>Summary</th>
                                <th class="preview-financialReports-6"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" value="Deployment Costs" required></td>
                                <td><input type="number" value="-4210" required onchange="calculateTotals()"></td>
                                <td><input type="text" value="Associated costs with deployment of contractors and dolls"></td>
                                <td><button class="remove-row-btn" onclick="removeRow(this)">X</button></td>
                            </tr>
                            <tr>
                                <td><input type="text" value="Wages" required></td>
                                <td><input type="number" value="-8250" required onchange="calculateTotals()"></td>
                                <td><input type="text" value="Deployment payments"></td>
                                <td><button class="remove-row-btn" onclick="removeRow(this)">X</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button class="add-row-btn" onclick="addRow('expensesTable')">+ Add Expense Row</button>
                </div>
            </div>

            <!-- Table 2: Equipment -->
            <div class="editor-section">
                <h4>3. Equipment Purchased</h4>
                <div class="dynamic-table-container">
                    <table class="dynamic-table" id="equipmentTable">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Cost/Payment (Cr.) [negative number]</th>
                                <th>Summary</th>
                                <th class="preview-financialReports-7"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" value="Vehicle Refill (Ammo)" required></td>
                                <td><input type="number" value="-1788" required onchange="calculateTotals()"></td>
                                <td><input type="text" value=""></td>
                                <td><button class="remove-row-btn" onclick="removeRow(this)">X</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button class="add-row-btn" onclick="addRow('equipmentTable')">+ Add Equipment Row</button>
                </div>
            </div>

            <!-- Table 3: Contracts -->
            <div class="editor-section">
                <h4>4. Contracts Submission</h4>
                <div class="dynamic-table-container">
                    <table class="dynamic-table" id="contractsTable">
                        <thead>
                            <tr>
                                <th>Contract</th>
                                <th>Payment (Cr.) [positive number]</th>
                                <th>Summary</th>
                                <th class="preview-financialReports-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" value="URNC-451-CHK" required></td>
                                <td><input type="number" value="8816" required onchange="calculateTotals()"></td>
                                <td><input type="text" value="Visits to various points occupied by the NSU"></td>
                                <td><button class="remove-row-btn" onclick="removeRow(this)">X</button></td>
                            </tr>
                            <tr>
                                <td><input type="text" value="IDAP-887-DEP" required></td>
                                <td><input type="number" value="5307" required onchange="calculateTotals()"></td>
                                <td><input type="text" value="Delivery and sourcing of supplies for IDAP"></td>
                                <td><button class="remove-row-btn" onclick="removeRow(this)">X</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button class="add-row-btn" onclick="addRow('contractsTable')">+ Add Contract Row</button>
                </div>
            </div>

            <!-- Table 4: Operational Acquisitions -->
            <div class="editor-section">
                <h4>5. Operational Acquisitions</h4>
                <div class="dynamic-table-container">
                    <table class="dynamic-table" id="acquisitionsTable">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Cost/Payment (Cr.)</th>
                                <th>Summary</th>
                                <th class="preview-financialReports-9"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" value="Salvage (Inc. vehicles)" required></td>
                                <td><input type="number" value="0" required onchange="calculateTotals()"></td>
                                <td><input type="text" value="Salvage inclusive of weapons, attachments and vehicles recovered."></td>
                                <td><button class="remove-row-btn" onclick="removeRow(this)">X</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button class="add-row-btn" onclick="addRow('acquisitionsTable')">+ Add Acquisition Row</button>
                </div>
            </div>

            <!-- Section 6: Calculations / Review -->
            <div class="editor-section">
                <h4>6. Financial Accounts Summary (Auto-calculated)</h4>
                <div class="form-row-grid">
                    <div class="form-group">
                        <label>Total Earnings (Cr.):</label>
                        <input type="text" id="review_total_earnings" readonly class="preview-financialReports-10">
                    </div>
                    <div class="form-group">
                        <label>Total Costs (Cr.):</label>
                        <input type="text" id="review_total_costs" readonly class="preview-financialReports-11">
                    </div>
                    <div class="form-group">
                        <label>Current Account Balance (Cr.):</label>
                        <input type="text" id="review_current_balance" readonly class="preview-financialReports-12">
                    </div>
                </div>
            </div>

            <div class="submit-bar">
                <button class="create-btn" onclick="saveReport()">Save and Compile Report</button>
                <button class="action-btn delete" onclick="showPreview(null)">Cancel</button>
            </div>
        </div>

        <!-- Preview View (Rendered document) -->
        <div class="report-preview-container" id="previewView">
            <div class="report-preview-actions">
                <button class="action-btn export preview-financialReports-13" id="editReportBtn" onclick="editCurrentReport()">Edit Report</button>
                <button class="action-btn export" onclick="exportToImage()">Export as PNG</button>
                <button class="action-btn delete" id="deleteReportBtn" onclick="deleteReport()">Delete Report</button>
            </div>

            <!-- Paper Fax Document Rendered View -->
            <div class="fax-document" id="faxDoc">
                
                <!-- PMC Logo Image -->
                <img src="../images/icons/PMC_Logo.png" id="fax_pmc_logo" alt="PMC Logo" class="preview-financialReports-14">

                <div class="fax-header">
                    <h2>OFFICIAL : Financial Report</h2>
                </div>

                <div class="fax-meta-top">
                    <div>From: B.R.I.E.F</div>
                    <div id="fax_top_date">10.05.2076</div>
                </div>

                <div class="fax-greeting">
                    <h3>Commander Rook,</h3>
                    <p>
                        We have received and processed the operational report from <span id="fax_compiler_code_top">1293A</span> for the tasks carried out on the <span id="fax_body_date">10.05.2076</span>. All information appears to be verified and your accounts have been updated in the B.R.I.E.F systems.
                    </p>
                </div>

                <div class="fax-sign">
                    B.R.I.E.F Contracting Assistant<br>
                    <strong id="fax_assistant_name">Kirsten Handley</strong>
                </div>

                <hr class="fax-divider">

                <div class="fax-account-details">
                    <div class="fax-account-details-text">
                        <div class="preview-financialReports-15">CINDER-9 ACCOUNT DETAILS</div>
                        <div>// ACCOUNT CODE : <span id="fax_account_code">C13370-C-T</span></div>
                        <div>// ACCOUNT HOLDER : <span id="fax_account_holder">M. ROOK</span></div>
                        <div>// REPORT DATE : <span id="fax_report_date">10.05.2076</span></div>
                        <div>// LICENCED CONTRACTORS : <span id="fax_contractors">11 OPERATIVES, 1 GENERATION 2 TACTICAL DOLLS</span></div>
                    </div>
                </div>

                <hr class="fax-divider">

                <!-- Table 1: Base Costs -->
                <div class="fax-section-title">/ FINANCIAL SUBSCRIPTION BASE COST OVERVIEW</div>
                <table class="fax-table" id="fax_expenses_table">
                    <thead>
                        <tr>
                            <th class="preview-financialReports-16">EXPENSE CATEGORY</th>
                            <th class="preview-financialReports-17">COST (Cr.)</th>
                            <th>SUMMARY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamic rows -->
                    </tbody>
                </table>

                <!-- Table 2: Equipment -->
                <div class="fax-section-title">/ EQUIPMENT PURCHASED</div>
                <table class="fax-table" id="fax_equipment_table">
                    <thead>
                        <tr>
                            <th class="preview-financialReports-18">ASSET</th>
                            <th class="preview-financialReports-19">COST/PAYMENT (Cr.)</th>
                            <th>SUMMARY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamic rows -->
                    </tbody>
                </table>

                <!-- Table 3: Contracts -->
                <div class="fax-section-title">/ CONTRACT(S) SUBMISSION FOR COMPLETION / FAILURE</div>
                <table class="fax-table" id="fax_contracts_table">
                    <thead>
                        <tr>
                            <th class="preview-financialReports-20">CONTRACT</th>
                            <th class="preview-financialReports-21">PAYMENT (Cr.)</th>
                            <th>SUMMARY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamic rows -->
                    </tbody>
                </table>

                <!-- Table 4: Acquisitions -->
                <div class="fax-section-title">/ OPERATIONAL ACQUISITIONS</div>
                <table class="fax-table" id="fax_acquisitions_table">
                    <thead>
                        <tr>
                            <th class="preview-financialReports-22">ASSET</th>
                            <th class="preview-financialReports-23">COST/PAYMENT (Cr.)</th>
                            <th>SUMMARY</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamic rows -->
                    </tbody>
                </table>

                <!-- Accounts Summary section -->
                <div class="fax-section-title">/ FINANCIAL ACCOUNTS WEEK TO DATE</div>
                <div class="fax-accounts-week" id="fax_accounts_week_container">
                    <!-- Dynamic Summary Rows -->
                </div>

                <div class="fax-accounts-week">
                    <div class="fax-accounts-row">
                        <div>Previous Account Balance</div>
                        <div class="cr-val earning" id="fax_previous_balance">Cr 223,323</div>
                    </div>
                    <div class="fax-accounts-row">
                        <div>Total Earnings</div>
                        <div class="cr-val earning" id="fax_total_earnings">Cr 14,123</div>
                    </div>
                    <div class="fax-accounts-row">
                        <div>Total Costs</div>
                        <div class="cr-val cost" id="fax_total_costs">Cr 14,248</div>
                    </div>
                    <div class="fax-accounts-row total-line">
                        <div>Current Account Balance</div>
                        <div class="cr-val earning" id="fax_current_balance">Cr 223,198</div>
                    </div>
                </div>

                <div class="fax-footer">
                    Report Compiled By : <span id="fax_compiled_by">1293A</span>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    let activeReportId = null;
    let activeReportData = null;

    // Convert standard SQL YYYY-MM-DD date to retro fax format DD.MM.YYYY
    function formatDateToDisplay(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            return `${parts[2]}.${parts[1]}.${parts[0]}`;
        }
        return dateStr;
    }

    // Helper functions for dynamic editor tables
    function addRow(tableId, values = ['', '', '']) {
        const table = document.getElementById(tableId).getElementsByTagName('tbody')[0];
        const row = table.insertRow();
        
        let cell1 = row.insertCell(0);
        let cell2 = row.insertCell(1);
        let cell3 = row.insertCell(2);
        let cell4 = row.insertCell(3);

        const isContractTable = (tableId === 'contractsTable');
        const defaultType = isContractTable ? 'number' : 'number';
        
        cell1.innerHTML = `<input type="text" value="${values[0]}" required>`;
        cell2.innerHTML = `<input type="${defaultType}" value="${values[1]}" required onchange="calculateTotals()">`;
        cell3.innerHTML = `<input type="text" value="${values[2]}">`;
        cell4.innerHTML = `<button class="remove-row-btn" onclick="removeRow(this)">X</button>`;
        
        calculateTotals();
    }

    function removeRow(button) {
        const row = button.parentNode.parentNode;
        row.parentNode.removeChild(row);
        calculateTotals();
    }

    // Dynamic math totals calculation
    function calculateTotals() {
        const prevBal = parseFloat(document.getElementById('edit_previous_balance').value) || 0;
        
        let totalEarnings = 0;
        let totalCosts = 0;

        // Sum up expenses (expenses are negative)
        const expenses = getTableData('expensesTable');
        expenses.forEach(r => {
            if (r.cost < 0) totalCosts += Math.abs(r.cost);
            else totalEarnings += r.cost;
        });

        // Sum up equipment purchases (purchases are negative)
        const equipment = getTableData('equipmentTable');
        equipment.forEach(r => {
            if (r.cost < 0) totalCosts += Math.abs(r.cost);
            else totalEarnings += r.cost;
        });

        // Sum up contracts (payments are positive)
        const contracts = getTableData('contractsTable');
        contracts.forEach(r => {
            if (r.cost > 0) totalEarnings += r.cost;
            else totalCosts += Math.abs(r.cost);
        });

        // Sum up operational acquisitions
        const acquisitions = getTableData('acquisitionsTable');
        acquisitions.forEach(r => {
            if (r.cost > 0) totalEarnings += r.cost;
            else totalCosts += Math.abs(r.cost);
        });

        const currentBal = prevBal + totalEarnings - totalCosts;

        document.getElementById('review_total_earnings').value = totalEarnings.toLocaleString(undefined, {minimumFractionDigits: 0}) + ' Cr.';
        document.getElementById('review_total_costs').value = totalCosts.toLocaleString(undefined, {minimumFractionDigits: 0}) + ' Cr.';
        document.getElementById('review_current_balance').value = currentBal.toLocaleString(undefined, {minimumFractionDigits: 0}) + ' Cr.';
    }

    function getTableData(tableId) {
        const rows = document.getElementById(tableId).getElementsByTagName('tbody')[0].rows;
        const data = [];
        for (let i = 0; i < rows.length; i++) {
            const inputs = rows[i].getElementsByTagName('input');
            if (inputs.length >= 3) {
                data.push({
                    category: inputs[0].value,
                    cost: parseFloat(inputs[1].value) || 0,
                    summary: inputs[2].value
                });
            }
        }
        return data;
    }

    // Helper to reset dynamic editor tables to default rows
    function resetDynamicTablesToDefaults() {
        // Clear all
        document.getElementById('expensesTable').getElementsByTagName('tbody')[0].innerHTML = '';
        document.getElementById('equipmentTable').getElementsByTagName('tbody')[0].innerHTML = '';
        document.getElementById('contractsTable').getElementsByTagName('tbody')[0].innerHTML = '';
        document.getElementById('acquisitionsTable').getElementsByTagName('tbody')[0].innerHTML = '';
        
        // Add defaults
        addRow('expensesTable', ['Deployment Costs', '-4210', 'Associated costs with deployment of contractors and dolls']);
        addRow('expensesTable', ['Wages', '-8250', 'Deployment payments']);
        
        addRow('equipmentTable', ['Vehicle Refill (Ammo)', '-1788', '']);
        
        addRow('contractsTable', ['URNC-451-CHK', '8816', 'Visits to various points occupied by the NSU']);
        addRow('contractsTable', ['IDAP-887-DEP', '5307', 'Delivery and sourcing of supplies for IDAP']);
        
        addRow('acquisitionsTable', ['Salvage (Inc. vehicles)', '0', 'Salvage inclusive of weapons, attachments and vehicles recovered.']);
    }

    // Toggle views
    function showEditor() {
        activeReportId = null;
        document.getElementById('editorTitle').textContent = 'New Financial Report';
        document.getElementById('cascadeWarningBanner').style.display = 'none';
        
        // Reset defaults
        document.getElementById('edit_report_date').value = "<?php echo $roleplayDate; ?>";
        document.getElementById('edit_account_code').value = "C13370-C-T";
        document.getElementById('edit_account_holder').value = "M. ROOK";
        document.getElementById('edit_contractors').value = "11 OPERATIVES, 1 GENERATION 2 TACTICAL DOLLS";
        document.getElementById('edit_assistant_name').value = "Kirsten Handley";
        document.getElementById('edit_compiled_by').value = "1293A";
        document.getElementById('edit_previous_balance').value = "<?php echo $defaultPrevBalance; ?>";
        document.getElementById('edit_previous_balance').disabled = false;
        
        resetDynamicTablesToDefaults();
        
        document.getElementById('previewView').style.display = 'none';
        document.getElementById('editorView').style.display = 'flex';
        calculateTotals();
    }

    function editCurrentReport() {
        if (!activeReportData) return;
        
        activeReportId = activeReportData.Report_Id;
        document.getElementById('editorTitle').textContent = `Edit Financial Report (ID: ${activeReportId})`;
        document.getElementById('cascadeWarningBanner').style.display = 'flex';
        
        const data = JSON.parse(activeReportData.Data);
        
        // Fill editor fields
        document.getElementById('edit_report_date').value = activeReportData.Report_Date;
        document.getElementById('edit_account_code').value = data.account_code || 'C13370-C-T';
        document.getElementById('edit_account_holder').value = data.account_holder || 'M. ROOK';
        document.getElementById('edit_contractors').value = activeReportData.Contractors || '';
        document.getElementById('edit_assistant_name').value = data.assistant_name || 'Kirsten Handley';
        document.getElementById('edit_compiled_by').value = activeReportData.Compiled_By || '1293A';
        document.getElementById('edit_previous_balance').value = data.previous_balance || 0;
        
        // Clear and fill tables
        const clearAndFillTable = (tableId, rows) => {
            const tbody = document.getElementById(tableId).getElementsByTagName('tbody')[0];
            tbody.innerHTML = '';
            if (rows) {
                rows.forEach(r => {
                    addRow(tableId, [r.category, r.cost, r.summary]);
                });
            }
        };
        
        clearAndFillTable('expensesTable', data.expenses);
        clearAndFillTable('equipmentTable', data.equipment);
        clearAndFillTable('contractsTable', data.contracts);
        clearAndFillTable('acquisitionsTable', data.acquisitions);
        
        document.getElementById('previewView').style.display = 'none';
        document.getElementById('editorView').style.display = 'flex';
        calculateTotals();
    }

    function showPreview(reportId) {
        document.getElementById('editorView').style.display = 'none';
        document.getElementById('previewView').style.display = 'flex';
        
        const deleteBtn = document.getElementById('deleteReportBtn');
        const editBtn = document.getElementById('editReportBtn');
        if (reportId) {
            deleteBtn.style.display = 'block';
            editBtn.style.display = 'block';
        } else {
            deleteBtn.style.display = 'none';
            editBtn.style.display = 'none';
        }
    }

    // Helper to format currency values exactly as in the mock image
    function formatCr(value, forceSymbol = true) {
        const cleanVal = Math.abs(value).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        return (forceSymbol ? 'Cr ' : '') + cleanVal;
    }

    // Load a report structurally into the premium view
    function loadReport(report, element = null) {
        activeReportData = report;
        if (element) {
            document.querySelectorAll('.report-item').forEach(i => i.classList.remove('active'));
            element.classList.add('active');
        }

        activeReportId = report.Report_Id;
        showPreview(activeReportId);

        const data = JSON.parse(report.Data);

        // Fill text fields
        document.getElementById('fax_top_date').textContent = formatDateToDisplay(report.Report_Date);
        document.getElementById('fax_body_date').textContent = formatDateToDisplay(report.Report_Date);
        document.getElementById('fax_compiler_code_top').textContent = report.Compiled_By;
        document.getElementById('fax_assistant_name').textContent = data.assistant_name || 'Kirsten Handley';
        
        document.getElementById('fax_account_code').textContent = data.account_code || 'C13370-C-T';
        document.getElementById('fax_account_holder').textContent = data.account_holder || 'M. ROOK';
        document.getElementById('fax_report_date').textContent = formatDateToDisplay(report.Report_Date);
        document.getElementById('fax_contractors').textContent = report.Contractors;

        // Render Base Costs
        const expBody = document.getElementById('fax_expenses_table').getElementsByTagName('tbody')[0];
        expBody.innerHTML = '';
        let totalExpenses = 0;
        if (data.expenses) {
            data.expenses.forEach(e => {
                totalExpenses += Math.abs(e.cost);
                expBody.innerHTML += `
                    <tr>
                        <td>${escapeHtml(e.category)}</td>
                        <td class="cr-val cost">${formatCr(e.cost)}</td>
                        <td>${escapeHtml(e.summary)}</td>
                    </tr>
                `;
            });
        }

        // Render Equipment Purchases
        const eqBody = document.getElementById('fax_equipment_table').getElementsByTagName('tbody')[0];
        eqBody.innerHTML = '';
        let totalEquipment = 0;
        if (data.equipment) {
            data.equipment.forEach(e => {
                totalEquipment += Math.abs(e.cost);
                eqBody.innerHTML += `
                    <tr>
                        <td>${escapeHtml(e.category)}</td>
                        <td class="cr-val cost">${formatCr(e.cost)}</td>
                        <td>${escapeHtml(e.summary)}</td>
                    </tr>
                `;
            });
        }

        // Render Contracts
        const conBody = document.getElementById('fax_contracts_table').getElementsByTagName('tbody')[0];
        conBody.innerHTML = '';
        let totalContracts = 0;
        if (data.contracts) {
            data.contracts.forEach(e => {
                totalContracts += Math.abs(e.cost);
                conBody.innerHTML += `
                    <tr>
                        <td>${escapeHtml(e.category)}</td>
                        <td class="cr-val earning">${formatCr(e.cost)}</td>
                        <td>${escapeHtml(e.summary)}</td>
                    </tr>
                `;
            });
        }

        // Render Acquisitions
        const acqBody = document.getElementById('fax_acquisitions_table').getElementsByTagName('tbody')[0];
        acqBody.innerHTML = '';
        let totalAcquisitions = 0;
        if (data.acquisitions) {
            data.acquisitions.forEach(e => {
                totalAcquisitions += Math.abs(e.cost);
                acqBody.innerHTML += `
                    <tr>
                        <td>${escapeHtml(e.category)}</td>
                        <td class="cr-val ${e.cost >= 0 ? 'earning' : 'cost'}">${formatCr(e.cost)}</td>
                        <td>${escapeHtml(e.summary)}</td>
                    </tr>
                `;
            });
        }

        // Render Weekly Accounts Breakdown
        const weekContainer = document.getElementById('fax_accounts_week_container');
        weekContainer.innerHTML = `
            <div class="fax-accounts-row">
                <div>Deployment Costs</div>
                <div class="cr-val cost">${formatCr(totalExpenses)}</div>
            </div>
            <div class="fax-accounts-row">
                <div>Equipment Purchased</div>
                <div class="cr-val cost">${formatCr(totalEquipment)}</div>
            </div>
            <div class="fax-accounts-row">
                <div>Contractual Pay</div>
                <div class="cr-val earning">${formatCr(totalContracts)}</div>
            </div>
            <div class="fax-accounts-row">
                <div>Operational Acquisitions</div>
                <div class="cr-val ${totalAcquisitions >= 0 ? 'earning' : 'cost'}">${formatCr(totalAcquisitions)}</div>
            </div>
        `;

        // Render final balances
        const prevBal = data.previous_balance || 0;
        const totalEarn = totalContracts + (totalAcquisitions > 0 ? totalAcquisitions : 0);
        const totalCost = totalExpenses + totalEquipment + (totalAcquisitions < 0 ? Math.abs(totalAcquisitions) : 0);
        const finalBal = prevBal + totalEarn - totalCost;

        document.getElementById('fax_previous_balance').textContent = formatCr(prevBal);
        document.getElementById('fax_total_earnings').textContent = formatCr(totalEarn);
        document.getElementById('fax_total_costs').textContent = formatCr(totalCost);
        document.getElementById('fax_current_balance').textContent = formatCr(finalBal);
        
        document.getElementById('fax_compiled_by').textContent = report.Compiled_By;
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Save report database query handler
    function saveReport() {
        const reportDate = document.getElementById('edit_report_date').value;
        const contractors = document.getElementById('edit_contractors').value;
        const compiledBy = document.getElementById('edit_compiled_by').value;
        
        const previousBalance = parseFloat(document.getElementById('edit_previous_balance').value) || 0;

        const expenses = getTableData('expensesTable');
        const equipment = getTableData('equipmentTable');
        const contracts = getTableData('contractsTable');
        const acquisitions = getTableData('acquisitionsTable');

        // Sum up total earnings & costs for quick json reference
        let totalEarnings = 0;
        let totalCosts = 0;

        expenses.forEach(r => {
            if (r.cost < 0) totalCosts += Math.abs(r.cost);
            else totalEarnings += r.cost;
        });
        equipment.forEach(r => {
            if (r.cost < 0) totalCosts += Math.abs(r.cost);
            else totalEarnings += r.cost;
        });
        contracts.forEach(r => {
            if (r.cost > 0) totalEarnings += r.cost;
            else totalCosts += Math.abs(r.cost);
        });
        acquisitions.forEach(r => {
            if (r.cost > 0) totalEarnings += r.cost;
            else totalCosts += Math.abs(r.cost);
        });

        const currentBalance = previousBalance + totalEarnings - totalCosts;

        const reportData = {
            account_code: document.getElementById('edit_account_code').value,
            account_holder: document.getElementById('edit_account_holder').value,
            assistant_name: document.getElementById('edit_assistant_name').value,
            previous_balance: previousBalance,
            total_earnings: totalEarnings,
            total_costs: totalCosts,
            current_balance: currentBalance,
            expenses: expenses,
            equipment: equipment,
            contracts: contracts,
            acquisitions: acquisitions
        };

        const postData = {
            report_id: activeReportId,
            report_date: reportDate,
            contractors: contractors,
            compiled_by: compiledBy,
            report_data: reportData
        };

        fetch('../db/market_assets/saveFinancialReport.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(postData)
        })
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                showToast('Financial report compiled successfully!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showError(res.error);
            }
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    // Delete report database handler
    function deleteReport() {
        if (!activeReportId) return;
        if (!confirm('Are you sure you want to delete this financial report?')) return;

        fetch('../db/market_assets/deleteFinancialReport.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ report_id: activeReportId })
        })
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                showToast('Financial report deleted successfully!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showError(res.error);
            }
        })
        .catch(err => showError('Fetch error: ' + err.message));
    }

    // High fidelity export to image using HTML Canvas API
    function exportToImage() {
        showToast('Compiling image, please wait...', 'info');

        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = 1131; // Strict Single A4 page portrait format (800 x 1131 px, 1:1.414 ratio)
        const ctx = canvas.getContext('2d');

        // 1. Draw physical paper background tone
        ctx.fillStyle = '#f4f6f7';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // 2. Draw vertical striped technical bar on the left edge (matching HTML's .fax-document::before)
        ctx.save();
        ctx.fillStyle = '#1a252f';
        ctx.globalAlpha = 0.15;
        ctx.beginPath();
        ctx.rect(0, 0, 8, canvas.height);
        ctx.clip();
        
        ctx.strokeStyle = '#1a252f';
        ctx.lineWidth = 4;
        for (let y = -20; y < canvas.height + 20; y += 8) {
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(8, y + 8);
            ctx.stroke();
        }
        ctx.restore();

        // 3. Draw PMC Logo Image top-right
        const logoImg = document.getElementById('fax_pmc_logo');
        if (logoImg && logoImg.complete && logoImg.naturalWidth !== 0) {
            ctx.drawImage(logoImg, 658, 68, 85, 85);
        } else {
            // Robust fallback if image has not fully loaded yet in browser DOM
            ctx.strokeStyle = '#1a252f';
            ctx.lineWidth = 2;
            ctx.strokeRect(658, 68, 85, 85);
            ctx.font = 'bold 12px "Share Tech Mono", monospace';
            ctx.fillStyle = '#1a252f';
            ctx.textAlign = 'center';
            ctx.fillText('PMC Logo', 700, 115);
        }

        // 4. Set text alignment and font styles
        ctx.fillStyle = '#1a252f';
        ctx.font = 'bold 22px "Share Tech Mono", monospace';
        ctx.textAlign = 'center';
        
        // Centered Header
        ctx.fillText('OFFICIAL : Financial Report', 400, 70);
        ctx.lineWidth = 2;
        ctx.strokeStyle = '#1a252f';
        ctx.beginPath();
        ctx.moveTo(220, 80);
        ctx.lineTo(580, 80);
        ctx.stroke();

        ctx.textAlign = 'left';
        ctx.font = 'bold 14px "Share Tech Mono", monospace';
        ctx.fillText('From: B.R.I.E.F', 50, 130);
        ctx.textAlign = 'right';
        ctx.fillText(document.getElementById('fax_top_date').textContent, 650, 130);
        ctx.textAlign = 'left';

        // GREETING
        ctx.font = 'bold 15px "Share Tech Mono", monospace';
        ctx.fillText('Commander Rook,', 50, 190);
        
        ctx.font = '13px "Share Tech Mono", monospace';
        const greetingText = `We have received and processed the operational report from ${document.getElementById('fax_compiled_by').textContent} for the tasks carried out on the ${document.getElementById('fax_body_date').textContent}. All information appears to be verified and your accounts have been updated in the B.R.I.E.F systems.`;
        wrapText(ctx, greetingText, 50, 220, 700, 20);

        ctx.font = '13px "Share Tech Mono", monospace';
        ctx.fillText('B.R.I.E.F Contracting Assistant', 50, 280);
        ctx.font = 'bold 13px "Share Tech Mono", monospace';
        ctx.fillText(document.getElementById('fax_assistant_name').textContent, 50, 298);

        // DIVIDER
        ctx.strokeStyle = '#1a252f';
        ctx.lineWidth = 1;
        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(50, 325);
        ctx.lineTo(750, 325);
        ctx.stroke();
        ctx.setLineDash([]);

        // ACCOUNT DETAILS SECTION
        ctx.font = 'bold 14px "Share Tech Mono", monospace';
        ctx.fillText('CINDER-9 ACCOUNT DETAILS', 50, 355);
        ctx.font = '13px "Share Tech Mono", monospace';
        ctx.fillText('// ACCOUNT CODE : ' + document.getElementById('fax_account_code').textContent, 50, 378);
        ctx.fillText('// ACCOUNT HOLDER : ' + document.getElementById('fax_account_holder').textContent, 50, 396);
        ctx.fillText('// REPORT DATE : ' + document.getElementById('fax_report_date').textContent, 50, 414);
        ctx.fillText('// LICENCED CONTRACTORS : ' + document.getElementById('fax_contractors').textContent, 50, 432);

        // DIVIDER
        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(50, 455);
        ctx.lineTo(750, 455);
        ctx.stroke();
        ctx.setLineDash([]);

        let currentY = 485;

        // Function to draw low-tech technical table onto canvas
        const drawFaxTable = (title, headers, rows, isEarningTable = false) => {
            ctx.fillStyle = '#1a252f';
            ctx.font = 'bold 13px "Share Tech Mono", monospace';
            ctx.fillText(title, 50, currentY);
            currentY += 18;

            ctx.beginPath();
            ctx.moveTo(50, currentY);
            ctx.lineTo(750, currentY);
            ctx.stroke();
            currentY += 15;

            // Draw Headers
            ctx.font = 'bold 12px "Share Tech Mono", monospace';
            ctx.fillText(headers[0], 50, currentY);
            ctx.fillText(headers[1], 300, currentY);
            ctx.fillText(headers[2], 420, currentY);
            currentY += 10;

            ctx.beginPath();
            ctx.moveTo(50, currentY);
            ctx.lineTo(750, currentY);
            ctx.stroke();
            currentY += 18;

            // Draw rows
            ctx.font = '12px "Share Tech Mono", monospace';
            rows.forEach(r => {
                ctx.fillStyle = '#1a252f';
                ctx.fillText(r.col1, 50, currentY);
                
                // Color formatting for currency values
                if (r.costVal < 0) ctx.fillStyle = '#b03a2e';
                else if (r.costVal > 0) ctx.fillStyle = '#196f3d';
                
                ctx.fillText(r.col2, 300, currentY);
                
                // Dynamic wrapping for the Summary/Comment text to fit within column bounds (max 330px)
                ctx.fillStyle = '#1a252f';
                const words = (r.col3 || '').split(' ');
                const lines = [];
                let currentLine = '';
                const maxCol3Width = 330; // X = 420 to X = 750
                
                for (let n = 0; n < words.length; n++) {
                    const testLine = currentLine + words[n] + ' ';
                    const metrics = ctx.measureText(testLine);
                    if (metrics.width > maxCol3Width && n > 0) {
                        lines.push(currentLine.trim());
                        currentLine = words[n] + ' ';
                    } else {
                        currentLine = testLine;
                    }
                }
                lines.push(currentLine.trim());
                
                // Render each wrapped line of column 3
                lines.forEach((line, idx) => {
                    ctx.fillText(line, 420, currentY + idx * 14);
                });
                
                // Shift down dynamically based on number of wrapped lines to keep rows perfectly spaced
                const additionalLinesCount = Math.max(0, lines.length - 1);
                currentY += 16 + additionalLinesCount * 14;
            });

            currentY += 10;
        };

        // Table 1: Expenses
        const expRows = [];
        const expTable = document.getElementById('fax_expenses_table').getElementsByTagName('tbody')[0].rows;
        let totalExpenses = 0;
        for (let i = 0; i < expTable.length; i++) {
            const costText = expTable[i].cells[1].textContent;
            const costVal = parseFloat(costText.replace(/[^0-9.-]+/g,"")) || 0;
            totalExpenses += Math.abs(costVal);
            expRows.push({ col1: expTable[i].cells[0].textContent, col2: costText, costVal: -Math.abs(costVal), col3: expTable[i].cells[2].textContent });
        }
        drawFaxTable('/ FINANCIAL SUBSCRIPTION BASE COST OVERVIEW', ['EXPENSE CATEGORY', 'COST (Cr.)', 'SUMMARY'], expRows);

        // Table 2: Equipment
        const eqRows = [];
        const eqTable = document.getElementById('fax_equipment_table').getElementsByTagName('tbody')[0].rows;
        let totalEquipment = 0;
        for (let i = 0; i < eqTable.length; i++) {
            const costText = eqTable[i].cells[1].textContent;
            const costVal = parseFloat(costText.replace(/[^0-9.-]+/g,"")) || 0;
            totalEquipment += Math.abs(costVal);
            eqRows.push({ col1: eqTable[i].cells[0].textContent, col2: costText, costVal: -Math.abs(costVal), col3: eqTable[i].cells[2].textContent });
        }
        drawFaxTable('/ EQUIPMENT PURCHASED', ['ASSET', 'COST/PAYMENT (Cr.)', 'SUMMARY'], eqRows);

        // Table 3: Contracts
        const conRows = [];
        const conTable = document.getElementById('fax_contracts_table').getElementsByTagName('tbody')[0].rows;
        let totalContracts = 0;
        for (let i = 0; i < conTable.length; i++) {
            const costText = conTable[i].cells[1].textContent;
            const costVal = parseFloat(costText.replace(/[^0-9.-]+/g,"")) || 0;
            totalContracts += Math.abs(costVal);
            conRows.push({ col1: conTable[i].cells[0].textContent, col2: costText, costVal: Math.abs(costVal), col3: conTable[i].cells[2].textContent });
        }
        drawFaxTable('/ CONTRACT(S) SUBMISSION FOR COMPLETION / FAILURE', ['CONTRACT', 'PAYMENT (Cr.)', 'SUMMARY'], conRows);

        // Table 4: Acquisitions
        const acqRows = [];
        const acqTable = document.getElementById('fax_acquisitions_table').getElementsByTagName('tbody')[0].rows;
        let totalAcquisitions = 0;
        let acqDirection = 0;
        for (let i = 0; i < acqTable.length; i++) {
            const costText = acqTable[i].cells[1].textContent;
            const costVal = parseFloat(costText.replace(/[^0-9.-]+/g,"")) || 0;
            totalAcquisitions += Math.abs(costVal);
            acqDirection = costVal;
            acqRows.push({ col1: acqTable[i].cells[0].textContent, col2: costText, costVal: costVal, col3: acqTable[i].cells[2].textContent });
        }
        drawFaxTable('/ OPERATIONAL ACQUISITIONS', ['ASSET', 'COST/PAYMENT (Cr.)', 'SUMMARY'], acqRows);

        // Accounts Summary section
        ctx.fillStyle = '#1a252f';
        ctx.font = 'bold 13px "Share Tech Mono", monospace';
        ctx.fillText('/ FINANCIAL ACCOUNTS WEEK TO DATE', 50, currentY);
        currentY += 18;

        const drawAccountsRow = (name, costText, isCost, isTotal = false) => {
            if (isTotal) {
                ctx.setLineDash([3, 3]);
                ctx.strokeStyle = '#1a252f';
                ctx.beginPath();
                ctx.moveTo(50, currentY);
                ctx.lineTo(750, currentY);
                ctx.stroke();
                ctx.setLineDash([]);
                currentY += 14;
                ctx.font = 'bold 12px "Share Tech Mono", monospace';
            } else {
                ctx.font = '12px "Share Tech Mono", monospace';
            }

            ctx.fillStyle = '#1a252f';
            ctx.fillText(name, 50, currentY);
            
            // Align cost to right
            ctx.textAlign = 'right';
            if (isCost) ctx.fillStyle = '#b03a2e';
            else ctx.fillStyle = '#196f3d';
            ctx.fillText(costText, 750, currentY);
            ctx.textAlign = 'left';
            
            currentY += 16;
        };

        drawAccountsRow('Deployment Costs', formatCr(totalExpenses), true);
        drawAccountsRow('Equipment Purchased', formatCr(totalEquipment), true);
        drawAccountsRow('Contractual Pay', formatCr(totalContracts), false);
        drawAccountsRow('Operational Acquisitions', formatCr(totalAcquisitions), acqDirection < 0);
        currentY += 5;

        const prevBal = parseFloat(document.getElementById('fax_previous_balance').textContent.replace(/[^0-9.-]+/g,"")) || 0;
        const totalEarn = parseFloat(document.getElementById('fax_total_earnings').textContent.replace(/[^0-9.-]+/g,"")) || 0;
        const totalCost = parseFloat(document.getElementById('fax_total_costs').textContent.replace(/[^0-9.-]+/g,"")) || 0;
        const finalBal = parseFloat(document.getElementById('fax_current_balance').textContent.replace(/[^0-9.-]+/g,"")) || 0;

        drawAccountsRow('Previous Account Balance', formatCr(prevBal), false);
        drawAccountsRow('Total Earnings', formatCr(totalEarn), false);
        drawAccountsRow('Total Costs', formatCr(totalCost), true);
        drawAccountsRow('Current Account Balance', formatCr(finalBal), finalBal < 0, true);

        currentY += 15;
        ctx.fillStyle = '#1a252f';
        ctx.font = 'bold 13px "Share Tech Mono", monospace';
        ctx.fillText('Report Compiled By : ' + document.getElementById('fax_compiled_by').textContent, 50, currentY);

        // Download PNG Image directly from the strict A4 canvas
        const reportDate = document.getElementById('fax_report_date').textContent;
        const link = document.createElement('a');
        link.download = `Financial_Report_${reportDate.replace(/\./g, '_')}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    }

    // Wrap long lines for technical text fax formatting
    function wrapText(context, text, x, y, maxWidth, lineHeight) {
        const words = text.split(' ');
        let line = '';
        for(let n = 0; n < words.length; n++) {
            const testLine = line + words[n] + ' ';
            const metrics = context.measureText(testLine);
            const testWidth = metrics.width;
            if (testWidth > maxWidth && n > 0) {
                context.fillText(line, x, y);
                line = words[n] + ' ';
                y += lineHeight;
            } else {
                line = testLine;
            }
        }
        context.fillText(line, x, y);
    }

    // Initialize list with first report
    window.addEventListener('DOMContentLoaded', () => {
        const firstReport = <?php echo !empty($reports) ? json_encode($reports[0]) : 'null'; ?>;
        if (firstReport) {
            loadReport(firstReport);
        } else {
            showEditor();
        }
    });
</script>

</body>
</html>

<?php include '../includes/footer.php'; ?>
