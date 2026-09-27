<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is logged in as a standard user (not admin)
if (!isLoggedIn() || $_SESSION['user_id'] === -1) {
    // Admins should use admin/financialReports.php instead
    if ($_SESSION['user_id'] === -1) {
        header('Location: ../admin/financialReports.php');
        exit;
    }
    redirectToLogin();
}

// Fetch all reports chronologically
try {
    $reportsStmt = $pdo->query("SELECT * FROM financial_reports ORDER BY Report_Date ASC, Created_At ASC");
    $reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reports = [];
}

// Build summary table data with deltas
$summaryRows = [];
$previousBalance = null;
foreach ($reports as $rep) {
    $data = json_decode($rep['Data'], true);
    $currentBalance = $data['current_balance'] ?? 0;
    $delta = null;
    if ($previousBalance !== null) {
        $delta = $currentBalance - $previousBalance;
    }
    $summaryRows[] = [
        'report_id' => $rep['Report_Id'],
        'report_date' => $rep['Report_Date'],
        'current_balance' => $currentBalance,
        'delta' => $delta,
        'report' => $rep
    ];
    $previousBalance = $currentBalance;
}

// Reverse summaryRows for display (newest first), but keep original order for delta calculation
$summaryRowsDisplay = array_reverse($summaryRows);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="financials-preview-container preview-container">

<div class="financial-container financial-container-main">
    <!-- Sidebar / Navigation -->
    <div class="financial-sidebar">
        <h3>Financial Reports</h3>
        <div class="reports-list">
            <?php if (empty($reports)): ?>
                <div class="financial-no-reports">No reports available yet.</div>
            <?php else: ?>
                <?php foreach (array_reverse($reports) as $rep): ?>
                    <div class="report-item"
                         onclick="openReportOverlay(<?php echo htmlspecialchars(json_encode($rep)); ?>, this)">
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

        <!-- Summary Table View (Always visible) -->
        <div class="summary-table-container" id="summaryView">
            <h3>Financial Overview — All Reports</h3>
            <?php if (empty($summaryRowsDisplay)): ?>
                <div class="empty-state">No financial reports have been compiled yet.</div>
            <?php else: ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Report Date</th>
                            <th>Balance at End of Period</th>
                            <th>Delta (Change)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryRowsDisplay as $row): ?>
                            <tr onclick="openReportOverlayById(<?php echo $row['report_id']; ?>)" title="Click to view full report">
                                <td><?php echo date('d.m.Y', strtotime($row['report_date'])); ?></td>
                                <td class="balance-col"><?php echo number_format($row['current_balance'], 2, '.', "'"); ?> Cr</td>
                                <td>
                                    <?php if ($row['delta'] === null): ?>
                                        <span class="delta-na">— (first report)</span>
                                    <?php elseif ($row['delta'] > 0): ?>
                                        <span class="delta-positive">+<?php echo number_format($row['delta'], 2, '.', "'"); ?> Cr</span>
                                    <?php elseif ($row['delta'] < 0): ?>
                                        <span class="delta-negative"><?php echo number_format($row['delta'], 2, '.', "'"); ?> Cr</span>
                                    <?php else: ?>
                                        <span class="delta-zero">0.00 Cr</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ============ OVERLAY MODAL ============ -->
<div class="overlay-backdrop" id="reportOverlay">
    <div class="overlay-content">
        <div class="overlay-actions">
            <button class="action-btn export" onclick="exportToImage()">Export as PNG</button>
            <button class="overlay-close-btn" onclick="closeOverlay()">✖ Close</button>
        </div>

        <!-- Paper Fax Document -->
        <div class="fax-document" id="faxDoc">

            <!-- PMC Logo Image -->
            <?php
            $host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';
            ?>
            <img src="<?php echo $imageBaseUrl; ?>/icons/PMC_Logo.png" id="fax_pmc_logo" alt="PMC Logo" class="fax-pmc-logo">

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
                    <div class="fax-account-title">CINDER-9 ACCOUNT DETAILS</div>
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
                        <th class="fax-th-wide">EXPENSE CATEGORY</th>
                        <th class="fax-th-mid">COST (Cr.)</th>
                        <th>SUMMARY</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- Table 2: Equipment -->
            <div class="fax-section-title">/ EQUIPMENT PURCHASED</div>
            <table class="fax-table" id="fax_equipment_table">
                <thead>
                    <tr>
                        <th class="fax-th-wide">ASSET</th>
                        <th class="fax-th-mid">COST/PAYMENT (Cr.)</th>
                        <th>SUMMARY</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- Table 3: Contracts -->
            <div class="fax-section-title">/ CONTRACT(S) SUBMISSION FOR COMPLETION / FAILURE</div>
            <table class="fax-table" id="fax_contracts_table">
                <thead>
                    <tr>
                        <th class="fax-th-wide">CONTRACT</th>
                        <th class="fax-th-mid">PAYMENT (Cr.)</th>
                        <th>SUMMARY</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- Table 4: Acquisitions -->
            <div class="fax-section-title">/ OPERATIONAL ACQUISITIONS</div>
            <table class="fax-table" id="fax_acquisitions_table">
                <thead>
                    <tr>
                        <th class="fax-th-wide">ASSET</th>
                        <th class="fax-th-mid">COST/PAYMENT (Cr.)</th>
                        <th>SUMMARY</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- Accounts Summary section -->
            <div class="fax-section-title">/ FINANCIAL ACCOUNTS WEEK TO DATE</div>
            <div class="fax-accounts-week" id="fax_accounts_week_container"></div>

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

<script>
    // Convert standard SQL YYYY-MM-DD date to retro fax format DD.MM.YYYY
    function formatDateToDisplay(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            return `${parts[2]}.${parts[1]}.${parts[0]}`;
        }
        return dateStr;
    }

    function formatCr(value, forceSymbol = true) {
        const absVal = Math.abs(value);
        let formattedVal = absVal.toFixed(2).split('.');
        formattedVal[0] = formattedVal[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
        const cleanVal = formattedVal.join('.');
        return cleanVal + (forceSymbol ? ' Cr' : '');
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&')
            .replace(/</g, '<')
            .replace(/>/g, '>')
            .replace(/"/g, '"')
            .replace(/'/g, '&#039;');
    }

    // Overlay controls
    function openReportOverlay(report, element) {
        if (element) {
            document.querySelectorAll('.report-item').forEach(i => i.classList.remove('active'));
            element.classList.add('active');
        }
        populateFaxDoc(report);
        document.getElementById('reportOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function openReportOverlayById(reportId) {
        const reports = <?php echo json_encode($reports); ?>;
        const report = reports.find(r => r.Report_Id == reportId);
        if (report) {
            document.querySelectorAll('.report-item').forEach(i => i.classList.remove('active'));
            const items = document.querySelectorAll('.report-item');
            items.forEach(item => {
                const onclick = item.getAttribute('onclick');
                if (onclick && onclick.includes('"Report_Id":' + reportId)) {
                    item.classList.add('active');
                }
            });
            openReportOverlay(report, null);
        }
    }

    function closeOverlay() {
        document.getElementById('reportOverlay').classList.remove('active');
        document.body.style.overflow = '';
        document.querySelectorAll('.report-item').forEach(i => i.classList.remove('active'));
    }

    // Click backdrop (outside content) to close
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('reportOverlay').addEventListener('click', function(e) {
            if (e.target === this) {
                closeOverlay();
            }
        });
    });

    // Populate the fax document with report data
    function populateFaxDoc(report) {
        const data = JSON.parse(report.Data);

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

        // Render Equipment
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

        // Weekly Accounts Breakdown
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

        // Final balances
        const prevBal = data.previous_balance || 0;
        const totalEarn = data.total_earnings || (totalContracts + (totalAcquisitions > 0 ? totalAcquisitions : 0));
        const totalCost = data.total_costs || (totalExpenses + totalEquipment + (totalAcquisitions < 0 ? Math.abs(totalAcquisitions) : 0));
        const finalBal = data.current_balance || (prevBal + totalEarn - totalCost);

        document.getElementById('fax_previous_balance').textContent = formatCr(prevBal);
        document.getElementById('fax_total_earnings').textContent = formatCr(totalEarn);
        document.getElementById('fax_total_costs').textContent = formatCr(totalCost);
        document.getElementById('fax_current_balance').textContent = formatCr(finalBal);

        document.getElementById('fax_compiled_by').textContent = report.Compiled_By;
    }

    // Export to PNG
    function exportToImage() {
        showToast('Compiling image, please wait...', 'info');

        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = 1131;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#f4f6f7';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

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

        const logoImg = document.getElementById('fax_pmc_logo');
        if (logoImg && logoImg.complete && logoImg.naturalWidth !== 0) {
            ctx.drawImage(logoImg, 658, 68, 85, 85);
        } else {
            ctx.strokeStyle = '#1a252f';
            ctx.lineWidth = 2;
            ctx.strokeRect(658, 68, 85, 85);
            ctx.font = 'bold 12px "Share Tech Mono", monospace';
            ctx.fillStyle = '#1a252f';
            ctx.textAlign = 'center';
            ctx.fillText('PMC Logo', 700, 115);
        }

        ctx.fillStyle = '#1a252f';
        ctx.font = 'bold 22px "Share Tech Mono", monospace';
        ctx.textAlign = 'center';
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

        ctx.font = 'bold 15px "Share Tech Mono", monospace';
        ctx.fillText('Commander Rook,', 50, 190);
        ctx.font = '13px "Share Tech Mono", monospace';
        const greetingText = `We have received and processed the operational report from ${document.getElementById('fax_compiled_by').textContent} for the tasks carried out on the ${document.getElementById('fax_body_date').textContent}. All information appears to be verified and your accounts have been updated in the B.R.I.E.F systems.`;
        wrapText(ctx, greetingText, 50, 220, 700, 20);

        ctx.font = '13px "Share Tech Mono", monospace';
        ctx.fillText('B.R.I.E.F Contracting Assistant', 50, 280);
        ctx.font = 'bold 13px "Share Tech Mono", monospace';
        ctx.fillText(document.getElementById('fax_assistant_name').textContent, 50, 298);

        ctx.strokeStyle = '#1a252f';
        ctx.lineWidth = 1;
        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(50, 325);
        ctx.lineTo(750, 325);
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.font = 'bold 14px "Share Tech Mono", monospace';
        ctx.fillText('CINDER-9 ACCOUNT DETAILS', 50, 355);
        ctx.font = '13px "Share Tech Mono", monospace';
        ctx.fillText('// ACCOUNT CODE : ' + document.getElementById('fax_account_code').textContent, 50, 378);
        ctx.fillText('// ACCOUNT HOLDER : ' + document.getElementById('fax_account_holder').textContent, 50, 396);
        ctx.fillText('// REPORT DATE : ' + document.getElementById('fax_report_date').textContent, 50, 414);
        ctx.fillText('// LICENCED CONTRACTORS : ' + document.getElementById('fax_contractors').textContent, 50, 432);

        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(50, 455);
        ctx.lineTo(750, 455);
        ctx.stroke();
        ctx.setLineDash([]);

        let currentY = 485;

        const drawFaxTable = (title, headers, rows) => {
            ctx.fillStyle = '#1a252f';
            ctx.font = 'bold 13px "Share Tech Mono", monospace';
            ctx.fillText(title, 50, currentY);
            currentY += 18;
            ctx.beginPath();
            ctx.moveTo(50, currentY);
            ctx.lineTo(750, currentY);
            ctx.stroke();
            currentY += 15;
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
            ctx.font = '12px "Share Tech Mono", monospace';
            rows.forEach(r => {
                ctx.fillStyle = '#1a252f';
                ctx.fillText(r.col1, 50, currentY);
                if (r.costVal < 0) ctx.fillStyle = '#b03a2e';
                else if (r.costVal > 0) ctx.fillStyle = '#196f3d';
                ctx.fillText(r.col2, 300, currentY);
                ctx.fillStyle = '#1a252f';
                const words = (r.col3 || '').split(' ');
                const lines = [];
                let currentLine = '';
                const maxCol3Width = 330;
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
                lines.forEach((line, idx) => {
                    ctx.fillText(line, 420, currentY + idx * 14);
                });
                const additionalLinesCount = Math.max(0, lines.length - 1);
                currentY += 16 + additionalLinesCount * 14;
            });
            currentY += 10;
        };

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

        const reportDate = document.getElementById('fax_report_date').textContent;
        const link = document.createElement('a');
        link.download = `Financial_Report_${reportDate.replace(/\./g, '_')}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    }

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
</script>

</body>
</html>
</div>
<?php include '../includes/footer.php'; ?>


