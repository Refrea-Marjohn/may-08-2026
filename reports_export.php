<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$is_admin && !$is_accounting) {
    header("Location: borrower_dashboard.php");
    exit();
}

$report = $_GET['report'] ?? '';
$format = $_GET['format'] ?? 'csv';

$today = new DateTime('today');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$today->format('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$today->format('Y');
$month_start = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-01 00:00:00', $year, $month));
$month_end = (clone $month_start)->modify('last day of this month')->setTime(23, 59, 59);

$week_start = isset($_GET['week_start']) ? new DateTime($_GET['week_start']) : (clone $today)->modify('monday this week');
$week_start->setTime(0, 0, 0);
$week_end = isset($_GET['week_end']) && $_GET['week_end'] !== ''
    ? new DateTime($_GET['week_end'])
    : (clone $week_start)->modify('sunday this week');
$week_end->setTime(23, 59, 59);

function output_csv($filename, $headers, $rows) {
    $sanitize_cell = function ($value) {
        $str = (string) $value;
        if ($str !== '' && preg_match('/^[=\+\-@]/', $str)) {
            return "'" . $str;
        }
        return $str;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        $safe_row = array_map($sanitize_cell, $row);
        fputcsv($output, $safe_row);
    }
    fclose($output);
    exit();
}

function output_pdf_html($title, $headers, $rows, $subtitle = '', $back_query = []) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . preg_replace('/\s+/', '_', strtolower($title)) . '.pdf"');
    $back_href = 'admin_reports.php';
    if (!empty($back_query)) {
        $back_href .= '?' . http_build_query($back_query);
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 24px; color: #333; }
            .pdf-toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 12px;
                margin: -8px 0 20px 0;
                padding: 12px 14px;
                background: #f4f4f4;
                border: 1px solid #ddd;
                border-radius: 8px;
            }
            .pdf-toolbar a {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 14px;
                font-weight: 600;
                color: #8b0000;
                text-decoration: none;
            }
            .pdf-toolbar a:hover { text-decoration: underline; }
            .pdf-toolbar button {
                font-size: 13px;
                padding: 8px 14px;
                cursor: pointer;
                border: 1px solid #ccc;
                border-radius: 6px;
                background: #fff;
            }
            .pdf-toolbar button:hover { background: #fafafa; }
            @media print {
                .no-print { display: none !important; }
                body { margin: 16px; }
            }
            h1 { font-size: 18px; margin-bottom: 6px; }
            .subtitle { font-size: 12px; color: #666; margin-bottom: 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            th, td { border: 1px solid #ddd; padding: 8px; font-size: 12px; text-align: left; }
            th { background: #f4f4f4; }
            .note { margin-top: 12px; font-size: 11px; color: #555; }
        </style>
    </head>
    <body>
        <div class="pdf-toolbar no-print">
            <a href="<?php echo htmlspecialchars($back_href); ?>">← Back to Reports</a>
            <button type="button" onclick="window.print()">Print / Save as PDF</button>
        </div>
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <?php if ($subtitle): ?>
            <div class="subtitle"><?php echo htmlspecialchars($subtitle); ?></div>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?php echo htmlspecialchars($header); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr><td colspan="<?php echo count($headers); ?>">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?php echo htmlspecialchars((string)$cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="note no-print">Tip: Use Print / Save as PDF above, or your browser’s print dialog (Ctrl+P) and choose “Save as PDF.”</div>
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 300);
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}

function output_csv_preview_html($title, $headers, $rows, $subtitle, $back_query, $download_href) {
    header('Content-Type: text/html; charset=utf-8');
    $back_href = 'admin_reports.php';
    if (!empty($back_query)) {
        $back_href .= '?' . http_build_query($back_query);
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?> — Preview</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 24px; color: #333; }
            .pdf-toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 12px;
                margin: -8px 0 20px 0;
                padding: 12px 14px;
                background: #f4f4f4;
                border: 1px solid #ddd;
                border-radius: 8px;
            }
            .pdf-toolbar a {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 14px;
                font-weight: 600;
                color: #8b0000;
                text-decoration: none;
            }
            .pdf-toolbar a:hover { text-decoration: underline; }
            .pdf-toolbar a.btn-download {
                color: #fff;
                background: #166534;
                padding: 8px 14px;
                border-radius: 6px;
                text-decoration: none;
            }
            .pdf-toolbar a.btn-download:hover {
                background: #14532d;
                text-decoration: none;
            }
            h1 { font-size: 18px; margin-bottom: 6px; }
            .subtitle { font-size: 12px; color: #666; margin-bottom: 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            th, td { border: 1px solid #ddd; padding: 8px; font-size: 12px; text-align: left; }
            th { background: #f4f4f4; }
            .note { margin-top: 12px; font-size: 11px; color: #555; }
        </style>
    </head>
    <body>
        <div class="pdf-toolbar">
            <a href="<?php echo htmlspecialchars($back_href); ?>">← Back to Reports</a>
            <a class="btn-download" href="<?php echo htmlspecialchars($download_href); ?>">Download Excel (.csv)</a>
        </div>
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <?php if ($subtitle): ?>
            <div class="subtitle"><?php echo htmlspecialchars($subtitle); ?></div>
        <?php endif; ?>
        <p style="font-size:12px;color:#666;margin:0 0 8px 0;">Preview — same data as the file you will download.</p>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?php echo htmlspecialchars($header); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr><td colspan="<?php echo count($headers); ?>">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?php echo htmlspecialchars((string)$cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="note">After you confirm the data looks right, click <strong>Download Excel (.csv)</strong> above. Open the file in Microsoft Excel or Google Sheets.</div>
    </body>
    </html>
    <?php
    exit();
}

switch ($report) {
    case 'monthly_deductions':
        $sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.monthly_payment,
                    COALESCE(SUM(d.amount), 0) AS amount_deducted,
                    MAX(d.deduction_date) AS last_posted_date,
                    COALESCE(NULLIF(GROUP_CONCAT(DISTINCT pu.full_name ORDER BY pu.full_name SEPARATOR ', '), ''), 'System / Unknown') AS posted_by
                FROM loans l
                JOIN users u ON l.user_id = u.id
                LEFT JOIN deductions d
                    ON d.loan_id = l.id
                    AND d.deduction_date BETWEEN ? AND ?
                LEFT JOIN users pu ON pu.id = d.posted_by
                WHERE l.status = 'approved'
                  AND l.released_at IS NOT NULL
                GROUP BY l.id
                ORDER BY u.full_name ASC";
        $stmt = $conn->prepare($sql);
        $start_str = $month_start->format('Y-m-d');
        $end_str = $month_end->format('Y-m-d');
        $stmt->bind_param("ss", $start_str, $end_str);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                $row['borrower_id'],
                $row['full_name'],
                number_format($row['monthly_payment'] ?? 0, 2),
                number_format($row['amount_deducted'] ?? 0, 2),
                !empty($row['last_posted_date']) ? date('M d, Y', strtotime($row['last_posted_date'])) : '—',
                $row['posted_by'] ?? 'System / Unknown'
            ];
        }
        $stmt->close();
        $headers = ['Borrower ID', 'Borrower Name', 'Monthly Amortization', 'Amount Deducted', 'Last Posted Date', 'Posted By'];
        $title = 'Monthly Deduction / Collection Report';
        $subtitle = $month_start->format('F Y');
        break;

    case 'weekly_deductions':
        $sql = "SELECT
                    COUNT(*) AS total_deductions,
                    COUNT(DISTINCT borrower_id) AS borrowers_deducted,
                    COALESCE(SUM(amount), 0) AS total_amount
                FROM deductions
                WHERE deduction_date BETWEEN ? AND ?";
        $stmt = $conn->prepare($sql);
        $start_str = $week_start->format('Y-m-d');
        $end_str = $week_end->format('Y-m-d');
        $stmt->bind_param("ss", $start_str, $end_str);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $headers = ['Week Covered', 'Total Deductions Posted', 'Number of Borrowers Deducted', 'Total Deducted Amount'];
        $rows = [[
            $week_start->format('M d') . ' - ' . $week_end->format('M d, Y'),
            (int)($summary['total_deductions'] ?? 0),
            (int)($summary['borrowers_deducted'] ?? 0),
            number_format($summary['total_amount'] ?? 0, 2)
        ]];
        $title = 'Weekly Deduction Monitoring Report';
        $subtitle = $week_start->format('M d') . ' - ' . $week_end->format('M d, Y');
        break;

    case 'loan_releases':
        $sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.loan_amount,
                    l.loan_term,
                    l.released_at AS released_at
                FROM loans l
                JOIN users u ON l.user_id = u.id
                WHERE l.status = 'approved'
                  AND l.released_at IS NOT NULL
                  AND l.released_at BETWEEN ? AND ?
                ORDER BY released_at DESC";
        $stmt = $conn->prepare($sql);
        $start_str = $month_start->format('Y-m-d H:i:s');
        $end_str = $month_end->format('Y-m-d H:i:s');
        $stmt->bind_param("ss", $start_str, $end_str);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                date('M d, Y', strtotime($row['released_at'])),
                $row['borrower_id'],
                $row['full_name'],
                number_format($row['loan_amount'], 2),
                $row['loan_term']
            ];
        }
        $stmt->close();
        $headers = ['Date Released', 'Borrower ID', 'Borrower Name', 'Loan Amount', 'Loan Term (months)'];
        $title = 'Loan Releases Report';
        $subtitle = $month_start->format('F Y');
        break;

    case 'active_loans':
        $sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.total_amount,
                    l.monthly_payment,
                    l.loan_term,
                    COALESCE(SUM(d.amount), 0) AS total_paid,
                    (l.total_amount - COALESCE(SUM(d.amount), 0)) AS remaining_balance,
                    DATE_ADD(l.released_at, INTERVAL l.loan_term MONTH) AS expected_end_date
                FROM loans l
                JOIN users u ON l.user_id = u.id
                LEFT JOIN deductions d ON d.loan_id = l.id
                WHERE l.status = 'approved'
                  AND l.released_at IS NOT NULL
                GROUP BY l.id
                HAVING remaining_balance > 0
                ORDER BY expected_end_date ASC";
        try {
            $result = $conn->query($sql);
        } catch (mysqli_sql_exception $e) {
            $fallback_sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.loan_amount AS total_amount,
                    l.monthly_payment,
                    l.loan_term,
                    COALESCE(SUM(d.amount), 0) AS total_paid,
                    (l.loan_amount - COALESCE(SUM(d.amount), 0)) AS remaining_balance,
                    DATE_ADD(l.released_at, INTERVAL l.loan_term MONTH) AS expected_end_date
                FROM loans l
                JOIN users u ON l.user_id = u.id
                LEFT JOIN deductions d ON d.loan_id = l.id
                WHERE l.status = 'approved'
                  AND l.released_at IS NOT NULL
                GROUP BY l.id
                HAVING remaining_balance > 0
                ORDER BY expected_end_date ASC";
            $result = $conn->query($fallback_sql);
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                $row['borrower_id'],
                $row['full_name'],
                number_format($row['total_amount'] ?? 0, 2),
                number_format($row['remaining_balance'] ?? 0, 2),
                number_format($row['monthly_payment'] ?? 0, 2),
                date('M d, Y', strtotime($row['expected_end_date'])),
                'Active'
            ];
        }
        $headers = ['Borrower ID', 'Borrower Name', 'Original Loan Amount', 'Remaining Balance', 'Monthly Amortization', 'Expected End Date', 'Loan Status'];
        $title = 'Active Loans & Remaining Balance';
        $subtitle = 'As of ' . $today->format('M d, Y');
        break;

    case 'completed_loans':
        $sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.loan_amount,
                    MAX(d.deduction_date) AS fully_paid_date,
                    COALESCE(SUM(d.amount), 0) AS total_paid
                FROM loans l
                JOIN users u ON l.user_id = u.id
                LEFT JOIN deductions d ON d.loan_id = l.id
                WHERE l.status IN ('approved', 'completed')
                  AND l.released_at IS NOT NULL
                GROUP BY l.id
                HAVING total_paid >= l.total_amount
                ORDER BY fully_paid_date DESC";
        try {
            $result = $conn->query($sql);
        } catch (mysqli_sql_exception $e) {
            $fallback_sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.loan_amount,
                    MAX(d.deduction_date) AS fully_paid_date,
                    COALESCE(SUM(d.amount), 0) AS total_paid
                FROM loans l
                JOIN users u ON l.user_id = u.id
                LEFT JOIN deductions d ON d.loan_id = l.id
                WHERE l.status IN ('approved', 'completed')
                  AND l.released_at IS NOT NULL
                GROUP BY l.id
                HAVING total_paid >= l.loan_amount
                ORDER BY fully_paid_date DESC";
            $result = $conn->query($fallback_sql);
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                $row['borrower_id'],
                $row['full_name'],
                number_format($row['loan_amount'] ?? 0, 2),
                !empty($row['fully_paid_date']) ? date('M d, Y', strtotime($row['fully_paid_date'])) : '—'
            ];
        }
        $headers = ['Borrower ID', 'Borrower Name', 'Loan Amount', 'Date Fully Paid'];
        $title = 'Completed / Fully Paid Loans';
        break;

    case 'fund_summary':
        $year_start = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-01-01 00:00:00', $year));
        $year_end = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-12-31 23:59:59', $year));

        $begin_sql = "SELECT COALESCE(SUM(
                        CASE entry_type
                            WHEN 'collection' THEN amount
                            WHEN 'release' THEN -amount
                            ELSE amount
                        END
                    ), 0) AS balance
                    FROM fund_ledger
                    WHERE entry_date < ?";
        $stmt = $conn->prepare($begin_sql);
        $start_date = $month_start->format('Y-m-d');
        $stmt->bind_param("s", $start_date);
        $stmt->execute();
        $beginning_balance = $stmt->get_result()->fetch_assoc()['balance'] ?? 0;
        $stmt->close();

        $col_sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM deductions WHERE deduction_date BETWEEN ? AND ?";
        $stmt = $conn->prepare($col_sql);
        $start_str = $month_start->format('Y-m-d');
        $end_str = $month_end->format('Y-m-d');
        $stmt->bind_param("ss", $start_str, $end_str);
        $stmt->execute();
        $collections = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        $rel_sql = "SELECT COALESCE(SUM(loan_amount), 0) AS total FROM loans
                    WHERE status = 'approved'
                      AND released_at IS NOT NULL
                      AND released_at BETWEEN ? AND ?";
        $stmt = $conn->prepare($rel_sql);
        $start_dt = $month_start->format('Y-m-d H:i:s');
        $end_dt = $month_end->format('Y-m-d H:i:s');
        $stmt->bind_param("ss", $start_dt, $end_dt);
        $stmt->execute();
        $releases = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        $adj_sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM fund_ledger
                    WHERE entry_type = 'adjustment' AND entry_date BETWEEN ? AND ?";
        $stmt = $conn->prepare($adj_sql);
        $stmt->bind_param("ss", $start_str, $end_str);
        $stmt->execute();
        $adjustments = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        $ending_balance = $beginning_balance + $collections - $releases + $adjustments;

        $headers = ['Period', 'Beginning Balance', 'Total Collections', 'Total Loan Releases', 'Adjustments', 'Ending Balance'];
        $rows = [[
            $month_start->format('F Y'),
            number_format($beginning_balance, 2),
            number_format($collections, 2),
            number_format($releases, 2),
            number_format($adjustments, 2),
            number_format($ending_balance, 2)
        ]];
        $title = 'Provident Fund Summary Report';
        $subtitle = $month_start->format('F Y');
        break;

    case 'payroll_recon':
        $sql = "SELECT
                    u.id AS borrower_id,
                    u.full_name,
                    l.monthly_payment,
                    COALESCE(SUM(d.amount), 0) AS actual_deduction
                FROM loans l
                JOIN users u ON l.user_id = u.id
                LEFT JOIN deductions d
                    ON d.loan_id = l.id
                    AND d.deduction_date BETWEEN ? AND ?
                WHERE l.status = 'approved'
                  AND l.released_at IS NOT NULL
                GROUP BY l.id
                ORDER BY u.full_name ASC";
        $stmt = $conn->prepare($sql);
        $start_str = $month_start->format('Y-m-d');
        $end_str = $month_end->format('Y-m-d');
        $stmt->bind_param("ss", $start_str, $end_str);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $expected = (float)($row['monthly_payment'] ?? 0);
            $actual = (float)($row['actual_deduction'] ?? 0);
            $variance = $expected - $actual;
            $remarks = $variance == 0.0 ? 'Balanced' : ($variance > 0 ? 'Short - verify payroll posting' : 'Over-posted - verify');
            $rows[] = [
                $row['borrower_id'],
                $row['full_name'],
                number_format($expected, 2),
                number_format($actual, 2),
                number_format($variance, 2),
                $remarks
            ];
        }
        $stmt->close();
        $headers = ['Borrower ID', 'Borrower Name', 'Expected Deduction', 'Actual Deduction', 'Variance', 'Remarks'];
        $title = 'Payroll Deduction Reconciliation Report';
        $subtitle = $month_start->format('F Y');
        break;

    case 'co_maker':
        $sql = "SELECT
                    u.full_name AS borrower_name,
                    l.co_maker_full_name AS co_maker_name,
                    l.loan_amount,
                    l.status
                FROM loans l
                JOIN users u ON l.user_id = u.id
                WHERE l.status = 'approved'
                  AND l.released_at IS NOT NULL
                ORDER BY u.full_name ASC";
        $result = $conn->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                $row['borrower_name'],
                $row['co_maker_name'],
                number_format($row['loan_amount'], 2),
                ucfirst($row['status'])
            ];
        }
        $headers = ['Borrower Name', 'Co-Maker Name', 'Loan Amount', 'Loan Status'];
        $title = 'Co-Maker Reference Report';
        break;

    default:
        http_response_code(400);
        echo "Invalid report.";
        exit();
}

$conn->close();

$reports_back_query = [
    'month' => $month,
    'year' => $year,
    'week_start' => $week_start->format('Y-m-d'),
    'week_end' => $week_end->format('Y-m-d'),
];

if ($format === 'pdf') {
    output_pdf_html($title, $headers, $rows, $subtitle ?? '', $reports_back_query);
}

$csv_preview = isset($_GET['preview']) && $_GET['preview'] !== '' && $_GET['preview'] !== '0';
if ($format === 'csv' && $csv_preview) {
    $dl_params = [
        'report' => $report,
        'format' => 'csv',
        'month' => $month,
        'year' => $year,
        'week_start' => $week_start->format('Y-m-d'),
        'week_end' => $week_end->format('Y-m-d'),
    ];
    $download_href = 'reports_export.php?' . http_build_query($dl_params);
    output_csv_preview_html($title, $headers, $rows, $subtitle ?? '', $reports_back_query, $download_href);
}

output_csv(strtolower(str_replace(' ', '_', $title)) . '.csv', $headers, $rows);
