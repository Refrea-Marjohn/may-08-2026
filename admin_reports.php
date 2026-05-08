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
$profile_photo = $user['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$is_admin && !$is_accounting) {
    header("Location: borrower_dashboard.php");
    exit();
}

$role_label = $is_accounting ? 'Accountant' : 'Administrator';
$access_label = $is_accounting ? 'Accountant Access' : 'Admin Access';

$pending_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM loans WHERE status = 'pending'");
$pending_stmt->execute();
$pending_loans_count = (int) ($pending_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$pending_stmt->close();

// Ensure required loan columns exist for report queries
$required_loan_columns = [
    'monthly_payment' => "ALTER TABLE loans ADD COLUMN monthly_payment DECIMAL(10,2) NULL",
    'total_amount' => "ALTER TABLE loans ADD COLUMN total_amount DECIMAL(10,2) NULL",
    'total_interest' => "ALTER TABLE loans ADD COLUMN total_interest DECIMAL(10,2) NULL",
    'released_at' => "ALTER TABLE loans ADD COLUMN released_at TIMESTAMP NULL"
];
foreach ($required_loan_columns as $column => $alter_sql) {
    $check_sql = "SHOW COLUMNS FROM loans LIKE '$column'";
    $result = $conn->query($check_sql);
    if ($result && $result->num_rows == 0) {
        $conn->query($alter_sql);
    }
}

$has_total_amount = false;
$check_total_sql = "SHOW COLUMNS FROM loans LIKE 'total_amount'";
$check_total_result = $conn->query($check_total_sql);
if ($check_total_result && $check_total_result->num_rows > 0) {
    $has_total_amount = true;
}

$today = new DateTime('today');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$today->format('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$today->format('Y');

$month_start = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-01 00:00:00', $selected_year, $selected_month));
$month_end = (clone $month_start)->modify('last day of this month')->setTime(23, 59, 59);

$week_start = isset($_GET['week_start']) ? new DateTime($_GET['week_start']) : (clone $today)->modify('monday this week');
$week_start->setTime(0, 0, 0);
$week_end = isset($_GET['week_end']) && $_GET['week_end'] !== ''
    ? new DateTime($_GET['week_end'])
    : (clone $week_start)->modify('sunday this week');
$week_end->setTime(23, 59, 59);

function fetch_deductions_summary($conn, $start, $end) {
    $sql = "SELECT
                COUNT(*) AS total_deductions,
                COUNT(DISTINCT borrower_id) AS borrowers_deducted,
                COALESCE(SUM(amount), 0) AS total_amount
            FROM deductions
            WHERE deduction_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $start_str = $start->format('Y-m-d');
    $end_str = $end->format('Y-m-d');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: [];
}

function fetch_monthly_deductions($conn, $start, $end) {
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
    $start_str = $start->format('Y-m-d');
    $end_str = $end->format('Y-m-d');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function fetch_loan_releases($conn, $start, $end) {
    $sql = "SELECT
                l.id,
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
    $start_str = $start->format('Y-m-d H:i:s');
    $end_str = $end->format('Y-m-d H:i:s');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function fetch_active_loans($conn) {
    $sql = "SELECT
                l.id,
                u.id AS borrower_id,
                u.full_name,
                l.total_amount AS total_amount,
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
        return $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        $fallback_sql = "SELECT
                l.id,
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
        return $conn->query($fallback_sql);
    }
}

function fetch_completed_loans($conn) {
    $sql = "SELECT
                l.id,
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
        return $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        $fallback_sql = "SELECT
                l.id,
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
        return $conn->query($fallback_sql);
    }
}

function fetch_payroll_recon($conn, $start, $end) {
    $sql = "SELECT
                l.id AS loan_id,
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
    $start_str = $start->format('Y-m-d');
    $end_str = $end->format('Y-m-d');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function fetch_co_maker_report($conn) {
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
    return $conn->query($sql);
}

function fetch_fund_ledger_balance($conn, $before_date) {
    $sql = "SELECT COALESCE(SUM(
                CASE entry_type
                    WHEN 'collection' THEN amount
                    WHEN 'release' THEN -amount
                    ELSE amount
                END
            ), 0) AS balance
            FROM fund_ledger
            WHERE entry_date < ?";
    $stmt = $conn->prepare($sql);
    $date_str = $before_date->format('Y-m-d');
    $stmt->bind_param("s", $date_str);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc()['balance'] ?? 0;
    $stmt->close();
    return $balance;
}

function fetch_fund_adjustments($conn, $start, $end) {
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total_adjustments
            FROM fund_ledger
            WHERE entry_type = 'adjustment' AND entry_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $start_str = $start->format('Y-m-d');
    $end_str = $end->format('Y-m-d');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['total_adjustments'] ?? 0;
}

function fetch_collections_total($conn, $start, $end) {
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total_collections
            FROM deductions
            WHERE deduction_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $start_str = $start->format('Y-m-d');
    $end_str = $end->format('Y-m-d');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['total_collections'] ?? 0;
}

function fetch_releases_total($conn, $start, $end) {
    $sql = "SELECT COALESCE(SUM(loan_amount), 0) AS total_releases
            FROM loans
            WHERE status = 'approved'
              AND released_at IS NOT NULL
              AND released_at BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $start_str = $start->format('Y-m-d H:i:s');
    $end_str = $end->format('Y-m-d H:i:s');
    $stmt->bind_param("ss", $start_str, $end_str);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['total_releases'] ?? 0;
}

$weekly_deductions = fetch_deductions_summary($conn, $week_start, $week_end);
$monthly_deductions_summary = fetch_deductions_summary($conn, $month_start, $month_end);
$monthly_deductions = fetch_monthly_deductions($conn, $month_start, $month_end);
$loan_releases = fetch_loan_releases($conn, $month_start, $month_end);
$active_loans = fetch_active_loans($conn);
$completed_loans = fetch_completed_loans($conn);
$payroll_recon = fetch_payroll_recon($conn, $month_start, $month_end);
$co_maker_report = fetch_co_maker_report($conn);

$beginning_balance = fetch_fund_ledger_balance($conn, $month_start);
$total_collections = fetch_collections_total($conn, $month_start, $month_end);
$total_releases = fetch_releases_total($conn, $month_start, $month_end);
$total_adjustments = fetch_fund_adjustments($conn, $month_start, $month_end);
$ending_balance = $beginning_balance + $total_collections - $total_releases + $total_adjustments;

$year_start = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-01-01 00:00:00', $selected_year));
$year_end = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-12-31 23:59:59', $selected_year));
$year_beginning = fetch_fund_ledger_balance($conn, $year_start);
$year_collections = fetch_collections_total($conn, $year_start, $year_end);
$year_releases = fetch_releases_total($conn, $year_start, $year_end);
$year_adjustments = fetch_fund_adjustments($conn, $year_start, $year_end);
$year_ending = $year_beginning + $year_collections - $year_releases + $year_adjustments;

$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        .navbar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 192px; /* 80% of 250px */
            right: 0;
            z-index: 1000;
        }

        .welcome-message {
            font-size: 1.2rem;
            color: #333;
        }

        .welcome-message strong {
            color: #8b0000;
        }

        .nav-icons {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
        }

        .icon-button {
            position: relative;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: color 0.3s;
        }

        .icon-button:hover {
            color: #8b0000;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            display: flex;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
        }

        .sidebar {
            width: 240px;
            background: rgba(179, 0, 0, 0.9);
            backdrop-filter: blur(10px);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            padding-top: 0.25rem;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow: hidden;
            z-index: 999;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1.5rem 1.25rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .sidebar-logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .sidebar-title {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.85rem;
            letter-spacing: 0.02em;
        }

        .sidebar-menu {
            list-style: none;
            flex: 1;
            padding: 0.5rem 0.5rem 1rem;
            overflow: hidden;
        }

        .sidebar-item {
            margin-bottom: 0.1rem;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.65rem 1rem;
            margin: 0.2rem 0.5rem;
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 0;
            border-radius: 12px;
            gap: 0.85rem;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .sidebar-link:hover {
            background: rgba(255, 255, 255, 0.14);
            color: white;
        }

        .sidebar-link.active {
            background: rgba(255, 255, 255, 0.22);
            color: white;
            font-weight: 600;
        }

        .sidebar-icon {
            margin-right: 0;
            font-size: 1.1rem;
            width: 26px;
            text-align: center;
        }

        .sidebar-item.logout {
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1rem 0;
            text-align: center;
        }

        .sidebar-item.logout .sidebar-link {
            justify-content: center;
        }

        .sidebar-item.logout {
            margin-top: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 192px; /* 80% of 250px */
            padding: 2rem;
            margin-top: 20px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .section-header.section-separated {
            margin-top: 2.5rem;
            padding-top: 1.25rem;
            border-top: 2px solid rgba(139, 0, 0, 0.45);
        }

        .report-section {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e4e7ef;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.07);
            margin-bottom: 1.85rem;
            overflow: hidden;
        }

        .report-section .section-header {
            margin-bottom: 0;
            padding: 0.95rem 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        }

        .report-section .section-header.section-separated {
            margin-top: 0;
            padding-top: 0.95rem;
            border-top: none;
        }

        .report-section .section-title {
            color: #ffffff;
            font-size: 1.2rem;
        }

        .report-section .section-title i {
            color: rgba(255, 255, 255, 0.95);
        }

        .report-section .report-range {
            color: rgba(255, 255, 255, 0.88);
            font-weight: 600;
        }

        .report-section .report-actions {
            padding: 0.85rem 1.25rem;
            margin-bottom: 0;
            background: linear-gradient(180deg, #f4f5f8 0%, #fafbfc 100%);
            border-bottom: 1px solid #e8ebf2;
        }

        .report-section .summary-grid {
            padding: 1rem 1.25rem 0;
            margin-bottom: 1rem;
        }

        .report-section .report-table {
            margin: 0 1.25rem 1.25rem;
            width: calc(100% - 2.5rem);
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
        }

        .report-section--collections .section-header {
            background: linear-gradient(135deg, #6b0a0a 0%, #8b0000 45%, #c41e3a 100%);
        }

        .report-section--weekly .section-header {
            background: linear-gradient(135deg, #4a1d6b 0%, #6b21a8 55%, #9333ea 100%);
        }

        .report-section--releases .section-header {
            background: linear-gradient(135deg, #134e4a 0%, #0f766e 50%, #0d9488 100%);
        }

        .report-section--active .section-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #1d4ed8 55%, #3b82f6 100%);
        }

        .report-section--completed .section-header {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #15803d 100%);
        }

        .report-section--fund .section-header {
            background: linear-gradient(135deg, #713f12 0%, #b45309 50%, #d97706 100%);
        }

        .report-section--recon .section-header {
            background: linear-gradient(135deg, #3730a3 0%, #4338ca 50%, #6366f1 100%);
        }

        .report-section--comaker .section-header {
            background: linear-gradient(135deg, #831843 0%, #9f1239 50%, #be123c 100%);
        }

        .section-title {
            font-size: 1.35rem;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .report-range {
            font-size: 0.9rem;
            color: #666;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .summary-card {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #8b0000;
        }

        .summary-card .label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.4rem;
        }

        .summary-card .value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #8b0000;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 2.5rem;
        }

        .report-table th,
        .report-table td {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
        }

        .report-table th {
            background: #f8f9fa;
            color: #333;
        }

        .status-pill {
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .status-pill.pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-pill.approved {
            background: #d4edda;
            color: #155724;
        }

        .status-pill.rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .filters {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group label {
            display: block;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 0.35rem;
            font-weight: 600;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.55rem 0.7rem;
            border: 1px solid #d9dee5;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 0.9rem;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #333;
            border: 1px solid #e5e7eb;
        }

        .report-actions {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .report-pagination-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.85rem 1.25rem 1.15rem;
            margin: 0 1.25rem 1.25rem;
            width: calc(100% - 2.5rem);
            box-sizing: border-box;
            border-top: 1px solid #e8ebf2;
            background: #f8fafc;
            border-radius: 0 0 10px 10px;
        }

        .report-pagination-info {
            font-size: 0.85rem;
            color: #555;
        }

        .report-pagination-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
        }

        .report-pagination-controls .page-btn {
            min-width: 2.1rem;
            padding: 0.35rem 0.55rem;
            border: 1px solid #d9dee5;
            border-radius: 6px;
            background: #fff;
            font-size: 0.8rem;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
        }

        .report-pagination-controls .page-btn:hover:not(:disabled) {
            background: #f3f4f6;
            border-color: #c4cad4;
        }

        .report-pagination-controls .page-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .report-pagination-controls .page-btn.is-active {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            border-color: #8b0000;
            color: #fff;
        }

        .note {
            background: #fff3cd;
            color: #856404;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid #ffeeba;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        @media (max-width: 900px) {
            .navbar {
                left: 0;
                padding: 1rem;
            }

            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .container {
                flex-direction: column;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .report-section .section-header {
                padding: 0.85rem 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.35rem;
            }

            .report-section .report-actions,
            .report-section .summary-grid {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .report-section .report-table {
                width: calc(100% - 2rem);
                margin-left: 1rem;
                margin-right: 1rem;
            }

            .report-pagination-bar {
                margin-left: 1rem;
                margin-right: 1rem;
                width: calc(100% - 2rem);
            }
        }

        /* ===== Admin shell (match admin_dashboard) ===== */
        .sidebar-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #eadfe2;
            border-radius: 10px;
            background: #fff;
            color: #8b0000;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.08);
        }

        .sidebar-backdrop {
            display: none;
        }

        .sidebar-close {
            display: none;
        }

        /* Mobile */
        @media (max-width: 700px) {
            .navbar {
                left: 0 !important;
                width: 100% !important;
                padding: 0.75rem 0.85rem;
                gap: 0.55rem;
                display: flex;
                flex-wrap: nowrap !important;
                align-items: center !important;
            }

            .sidebar-toggle {
                display: inline-flex;
                flex-shrink: 0;
            }

            .welcome-message {
                font-size: 1rem;
                min-width: 0;
                flex: 1 1 auto !important;
                width: auto !important;
                max-width: none !important;
            }

            .welcome-title {
                font-size: 0.94rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .welcome-meta {
                font-size: 0.74rem;
                gap: 0.35rem 0.55rem;
                flex-wrap: nowrap;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .welcome-meta span:last-child {
                display: none;
            }

            .nav-icons {
                gap: 0.55rem;
                margin-left: auto;
                flex: 0 0 auto !important;
                width: auto !important;
                justify-content: flex-end !important;
            }

            .profile-chevron {
                display: none;
            }

            .sidebar {
                --mobile-sidebar-width: clamp(200px, 62vw, 240px);
                position: fixed !important;
                top: 0 !important;
                left: calc(-1 * var(--mobile-sidebar-width) - 12px) !important;
                width: var(--mobile-sidebar-width) !important;
                max-width: var(--mobile-sidebar-width) !important;
                min-width: var(--mobile-sidebar-width) !important;
                height: 100vh !important;
                transform: none !important;
                transition: left 0.24s ease !important;
                z-index: 1003 !important;
                overflow-y: auto !important;
                border-radius: 0 16px 16px 0;
                box-shadow: 0 20px 42px rgba(15, 23, 42, 0.24);
            }

            body.sidebar-open .sidebar {
                left: 0 !important;
            }

            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                border: 0;
                background: rgba(15, 23, 42, 0.45);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 0.2s ease, visibility 0.2s ease;
                z-index: 1002;
            }

            body.sidebar-open .sidebar-backdrop {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }

            .sidebar-close {
                display: inline-flex;
                position: absolute;
                top: 10px;
                right: 10px;
                width: 34px;
                height: 34px;
                border: 1px solid rgba(255, 255, 255, 0.25);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.12);
                color: #fff;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                z-index: 2;
            }

            /* Layout */
            .container {
                margin-top: 78px !important;
                min-height: 100vh;
                display: block !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 1rem;
                margin-top: 0;
            }

            /* Report content: keep one consistent, readable mobile layout */
            .report-section {
                overflow: hidden;
            }

            .report-section .report-table {
                display: table;
                width: calc(100% - 2rem);
                max-width: calc(100% - 2rem);
                margin-left: 1rem;
                margin-right: 1rem;
                table-layout: auto;
                border-radius: 10px;
            }

            .report-section .report-table th,
            .report-section .report-table td {
                white-space: normal;
                word-break: break-word;
                font-size: 0.84rem;
                padding: 0.7rem 0.75rem;
            }

            .report-pagination-bar {
                width: calc(100% - 2rem);
                margin-left: 1rem;
                margin-right: 1rem;
            }

            /* Disable collapsed sidebar visuals on mobile widths */
            .sidebar-title,
            .sidebar-user-meta,
            .sidebar-badge,
            .sidebar-link .sidebar-label {
                opacity: 1 !important;
                transform: none !important;
                width: auto !important;
                max-width: none !important;
                max-height: none !important;
                overflow: visible !important;
                pointer-events: auto !important;
            }
        }

        @media (max-width: 480px) {
            .report-section .report-table th,
            .report-section .report-table td {
                font-size: 0.8rem;
                padding: 0.62rem 0.6rem;
            }
        }

        /* Small tablet */
        @media (min-width: 701px) and (max-width: 900px) {
            .navbar {
                left: var(--sidebar-width) !important;
                width: calc(100% - var(--sidebar-width)) !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Toggle menu" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>
        <div class="welcome-message">
            <div class="welcome-block">
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> <?php echo $role_label; ?></span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-chart-bar"></i> Reports</span>
                </div>
            </div>
        </div>
        <div class="nav-icons">
            <?php include 'notifications.php'; ?>
            <div class="profile-trigger" title="Profile menu" onclick="toggleProfileDropdown()">
                <div class="profile-trigger-main">
                <div class="profile-icon">
                <?php if ($profile_photo_exists): ?>
                    <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                <?php else: ?>
                    <span class="profile-initial"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?></span>
                <?php endif; ?>
                <div class="status-indicator"></div>
                </div>
                <span class="profile-chevron" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-user-info">
                            <div class="dropdown-user-avatar">
                                <?php if ($profile_photo_exists): ?>
                                    <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($_SESSION['deped_id'] ?? 'Not set'); ?></div>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="dropdown-item" onclick="openProfileModal('profile'); return false;">
                        <i class="fas fa-user-edit"></i>
                        Update Profile
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <button id="sidebarBackdrop" class="sidebar-backdrop" type="button" aria-label="Close menu"></button>

    <div class="container">
        <aside class="sidebar">
            <button id="sidebarClose" class="sidebar-close" type="button" aria-label="Hide sidebar">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="SDO.jpg" alt="DepEd Loan System Logo">
                </div>
                <div class="sidebar-title">DepEd Loan System</div>
            </div>

            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="<?php echo $is_accounting ? 'accountant_dashboard.php' : 'admin_dashboard.php'; ?>" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <?php echo $is_accounting ? 'Accountant Dashboard' : 'Admin Dashboard'; ?>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="loan_applications.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-clipboard-list"></i></span>
                        Loan Applications
                        <?php if ($pending_loans_count > 0): ?>
                            <span class="sidebar-badge"><?php echo $pending_loans_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="all_loans.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                        All Loans
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="existing_loans.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-landmark"></i></span>
                        Existing Loans
                    </a>
                </li>
                <?php if ($is_admin): ?>
                    <li class="sidebar-item">
                        <a href="manage_users.php" class="sidebar-link">
                            <span class="sidebar-icon"><i class="fas fa-users"></i></span>
                            Manage Users
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="audit_trail.php" class="sidebar-link">
                            <span class="sidebar-icon"><i class="fas fa-user-shield"></i></span>
                            Audit Trail
                        </a>
                    </li>
                <?php elseif ($is_accounting): ?>
                    <li class="sidebar-item">
                        <a href="accountant_manage_users.php" class="sidebar-link">
                            <span class="sidebar-icon"><i class="fas fa-users"></i></span>
                            Manage Users
                        </a>
                    </li>
                <?php endif; ?>
                <li class="sidebar-item">
                    <a href="admin_reports.php" class="sidebar-link active">
                        <span class="sidebar-icon"><i class="fas fa-chart-bar"></i></span>
                        Reports
                    </a>
                </li>
            </ul>
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar">
                    <?php if ($profile_photo_exists): ?>
                        <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="User avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                    <span class="sidebar-user-status" aria-hidden="true"></span>
                </div>
                <div class="sidebar-user-meta">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                    <div class="sidebar-user-role"><?php echo $is_accounting ? 'Accountant' : 'Administrator'; ?></div>
                </div>
            </div>

        </aside>

        <main class="main-content">
            <form class="filters" method="GET" action="admin_reports.php">
                <div class="filter-group">
                    <label for="month">Month</label>
                    <select id="month" name="month">
                        <?php foreach ($month_names as $num => $label): ?>
                            <option value="<?php echo $num; ?>" <?php echo $num === $selected_month ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="year">Year</label>
                    <select id="year" name="year">
                        <?php for ($y = $selected_year - 2; $y <= $selected_year + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selected_year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="week_start">Week Start</label>
                    <input type="date" id="week_start" name="week_start" value="<?php echo $week_start->format('Y-m-d'); ?>">
                </div>
                <div class="filter-group">
                    <label for="week_end">Week End</label>
                    <input type="date" id="week_end" name="week_end" value="<?php echo $week_end->format('Y-m-d'); ?>">
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                    <a class="btn btn-secondary" href="admin_reports.php"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            </form>

            <div class="report-section report-section--collections">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-coins"></i> Monthly Deduction / Collection Report</div>
                <div class="report-range">
                    <?php echo $month_start->format('F Y'); ?>
                </div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=monthly_deductions&format=csv&preview=1&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=monthly_deductions&format=pdf&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Collections</div>
                    <div class="value">₱<?php echo number_format($monthly_deductions_summary['total_amount'] ?? 0, 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Borrowers Deducted</div>
                    <div class="value"><?php echo (int)($monthly_deductions_summary['borrowers_deducted'] ?? 0); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Deductions Posted</div>
                    <div class="value"><?php echo (int)($monthly_deductions_summary['total_deductions'] ?? 0); ?></div>
                </div>
            </div>
            <table class="report-table" data-paginate="1">
                <thead>
                    <tr>
                        <th>Borrower ID</th>
                        <th>Borrower Name</th>
                        <th>Monthly Amortization</th>
                        <th>Amount Deducted</th>
                        <th>Last Posted Date</th>
                        <th>Posted By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($monthly_deductions->num_rows > 0): ?>
                        <?php while ($row = $monthly_deductions->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int)$row['borrower_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td>₱<?php echo number_format($row['monthly_payment'] ?? 0, 2); ?></td>
                                <td>₱<?php echo number_format($row['amount_deducted'] ?? 0, 2); ?></td>
                                <td><?php echo !empty($row['last_posted_date']) ? date('M d, Y', strtotime($row['last_posted_date'])) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($row['posted_by'] ?? 'System / Unknown'); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #666;">No deductions found for this month.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <div class="report-section report-section--weekly">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-calendar-week"></i> Weekly Deduction Monitoring</div>
                <div class="report-range">
                    <?php echo $week_start->format('M d') . ' - ' . $week_end->format('M d, Y'); ?>
                </div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=weekly_deductions&format=csv&preview=1&week_start=<?php echo $week_start->format('Y-m-d'); ?>&week_end=<?php echo $week_end->format('Y-m-d'); ?>">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=weekly_deductions&format=pdf&week_start=<?php echo $week_start->format('Y-m-d'); ?>&week_end=<?php echo $week_end->format('Y-m-d'); ?>">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Deductions Posted</div>
                    <div class="value"><?php echo (int)($weekly_deductions['total_deductions'] ?? 0); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Borrowers Deducted</div>
                    <div class="value"><?php echo (int)($weekly_deductions['borrowers_deducted'] ?? 0); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Deducted Amount</div>
                    <div class="value">₱<?php echo number_format($weekly_deductions['total_amount'] ?? 0, 2); ?></div>
                </div>
            </div>
            </div>

            <div class="report-section report-section--releases">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-hand-holding-usd"></i> Loan Releases Report</div>
                <div class="report-range"><?php echo $month_start->format('F Y'); ?></div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=loan_releases&format=csv&preview=1&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=loan_releases&format=pdf&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <table class="report-table" data-paginate="1">
                <thead>
                    <tr>
                        <th>Date Released</th>
                        <th>Borrower ID</th>
                        <th>Borrower Name</th>
                        <th>Loan Amount</th>
                        <th>Loan Term (months)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($loan_releases->num_rows > 0): ?>
                        <?php while ($row = $loan_releases->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($row['released_at'])); ?></td>
                                <td><?php echo (int)$row['borrower_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td>₱<?php echo number_format($row['loan_amount'], 2); ?></td>
                                <td><?php echo (int)$row['loan_term']; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #666;">No released loans found for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <div class="report-section report-section--active">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-wallet"></i> Active Loans & Remaining Balance</div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=active_loans&format=csv&preview=1">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=active_loans&format=pdf">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <table class="report-table" data-paginate="1">
                <thead>
                    <tr>
                        <th>Borrower ID</th>
                        <th>Borrower Name</th>
                        <th>Original Loan Amount</th>
                        <th>Remaining Balance</th>
                        <th>Monthly Amortization</th>
                        <th>Expected End Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($active_loans && $active_loans->num_rows > 0): ?>
                        <?php while ($row = $active_loans->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int)$row['borrower_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td>₱<?php echo number_format($row['total_amount'] ?? 0, 2); ?></td>
                                <td>₱<?php echo number_format($row['remaining_balance'] ?? 0, 2); ?></td>
                                <td>₱<?php echo number_format($row['monthly_payment'] ?? 0, 2); ?></td>
                                <td><?php echo !empty($row['expected_end_date']) ? date('M d, Y', strtotime($row['expected_end_date'])) : '—'; ?></td>
                                <td><span class="status-pill approved">Active</span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #666;">No active loans found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <div class="report-section report-section--completed">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-check-circle"></i> Completed / Fully Paid Loans</div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=completed_loans&format=csv&preview=1">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=completed_loans&format=pdf">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <table class="report-table" data-paginate="1">
                <thead>
                    <tr>
                        <th>Borrower ID</th>
                        <th>Borrower Name</th>
                        <th>Loan Amount</th>
                        <th>Date Fully Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($completed_loans && $completed_loans->num_rows > 0): ?>
                        <?php while ($row = $completed_loans->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int)$row['borrower_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td>₱<?php echo number_format($row['loan_amount'] ?? 0, 2); ?></td>
                                <td><?php echo !empty($row['fully_paid_date']) ? date('M d, Y', strtotime($row['fully_paid_date'])) : '—'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #666;">No completed loans found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <div class="report-section report-section--fund">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-chart-line"></i> Provident Fund Summary</div>
                <div class="report-range"><?php echo $month_start->format('F Y'); ?> / <?php echo $selected_year; ?></div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=fund_summary&format=csv&preview=1&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=fund_summary&format=pdf&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Beginning Balance (Monthly)</div>
                    <div class="value">₱<?php echo number_format($beginning_balance, 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Collections (Monthly)</div>
                    <div class="value">₱<?php echo number_format($total_collections, 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Loan Releases (Monthly)</div>
                    <div class="value">₱<?php echo number_format($total_releases, 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Adjustments (Monthly)</div>
                    <div class="value">₱<?php echo number_format($total_adjustments, 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Ending Balance (Monthly)</div>
                    <div class="value">₱<?php echo number_format($ending_balance, 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Ending Balance (Yearly)</div>
                    <div class="value">₱<?php echo number_format($year_ending, 2); ?></div>
                </div>
            </div>
            </div>

            <div class="report-section report-section--recon">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-balance-scale"></i> Payroll Deduction Reconciliation</div>
                <div class="report-range"><?php echo $month_start->format('F Y'); ?></div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=payroll_recon&format=csv&preview=1&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=payroll_recon&format=pdf&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <table class="report-table" data-paginate="1">
                <thead>
                    <tr>
                        <th>Borrower ID</th>
                        <th>Borrower Name</th>
                        <th>Expected Deduction</th>
                        <th>Actual Deduction</th>
                        <th>Variance</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($payroll_recon->num_rows > 0): ?>
                        <?php while ($row = $payroll_recon->fetch_assoc()): ?>
                            <?php
                                $expected = (float)($row['monthly_payment'] ?? 0);
                                $actual = (float)($row['actual_deduction'] ?? 0);
                                $variance = $expected - $actual;
                                $remarks = $variance == 0.0 ? 'Balanced' : ($variance > 0 ? 'Short - verify payroll posting' : 'Over-posted - verify');
                            ?>
                            <tr>
                                <td><?php echo (int)$row['borrower_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td>₱<?php echo number_format($expected, 2); ?></td>
                                <td>₱<?php echo number_format($actual, 2); ?></td>
                                <td>₱<?php echo number_format($variance, 2); ?></td>
                                <td><?php echo $remarks; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #666;">No reconciliation data found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <div class="report-section report-section--comaker">
            <div class="section-header">
                <div class="section-title"><i class="fas fa-user-friends"></i> Co-Maker Reference Report</div>
            </div>
            <div class="report-actions">
                <a class="btn btn-secondary" href="reports_export.php?report=co_maker&format=csv&preview=1">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a class="btn btn-secondary" href="reports_export.php?report=co_maker&format=pdf">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <table class="report-table" data-paginate="1">
                <thead>
                    <tr>
                        <th>Borrower Name</th>
                        <th>Co-Maker Name</th>
                        <th>Loan Amount</th>
                        <th>Loan Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($co_maker_report && $co_maker_report->num_rows > 0): ?>
                        <?php while ($row = $co_maker_report->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['borrower_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['co_maker_name']); ?></td>
                                <td>₱<?php echo number_format($row['loan_amount'], 2); ?></td>
                                <td><?php echo ucfirst($row['status']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #666;">No co-maker records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </main>
    </div>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <script>
        function openProfileModal(tab) {
            const overlay = document.getElementById('profileModalOverlay');
            const frame = document.getElementById('profileModalFrame');
            const safeTab = tab === 'password' ? 'password' : 'profile';
            frame.src = 'profile_update.php?tab=' + safeTab + '&embed=1';
            if (tab === 'password') overlay.classList.add('change-password-modal');
            else overlay.classList.remove('change-password-modal');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProfileModal() {
            const overlay = document.getElementById('profileModalOverlay');
            const frame = document.getElementById('profileModalFrame');
            overlay.classList.remove('active', 'change-password-modal');
            document.body.style.overflow = 'auto';
            frame.src = '';
        }

        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }

        (function () {
            const toggleBtn = document.getElementById('sidebarToggle');
            const backdrop = document.getElementById('sidebarBackdrop');
            const closeBtn = document.getElementById('sidebarClose');
            if (!toggleBtn || !backdrop) return;

            function setSidebarOpen(open) {
                document.body.classList.toggle('sidebar-open', open);
                toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.body.style.overflow = open ? 'hidden' : '';
            }

            toggleBtn.addEventListener('click', function () {
                setSidebarOpen(!document.body.classList.contains('sidebar-open'));
            });

            backdrop.addEventListener('click', function () {
                setSidebarOpen(false);
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    setSidebarOpen(false);
                });
            }

            window.addEventListener('resize', function () {
                if (window.innerWidth > 700) {
                    setSidebarOpen(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') setSidebarOpen(false);
            });
        })();

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('profileDropdown');
            const profileIcon = document.querySelector('.profile-trigger');
            if (dropdown && profileIcon && !profileIcon.contains(event.target)) {
                dropdown.classList.remove('active');
            }
            if (event.target && event.target.id === 'profileModalOverlay') {
                closeProfileModal();
            }
        });

        function initAdminReportPagination() {
            var PAGE_SIZE = 10;
            document.querySelectorAll('table.report-table[data-paginate="1"]').forEach(function (table) {
                var tbody = table.querySelector('tbody');
                if (!tbody) return;
                var dataRows = Array.prototype.filter.call(tbody.querySelectorAll('tr'), function (tr) {
                    var td = tr.querySelector('td');
                    return td && !td.hasAttribute('colspan');
                });
                if (dataRows.length === 0) return;

                var bar = document.createElement('div');
                bar.className = 'report-pagination-bar';
                bar.setAttribute('role', 'navigation');
                bar.setAttribute('aria-label', 'Table pagination');
                table.parentNode.insertBefore(bar, table.nextSibling);

                var currentPage = 1;
                var totalPages = Math.max(1, Math.ceil(dataRows.length / PAGE_SIZE));

                function render() {
                    if (currentPage > totalPages) currentPage = totalPages;
                    if (currentPage < 1) currentPage = 1;
                    var start = (currentPage - 1) * PAGE_SIZE;
                    var end = start + PAGE_SIZE;
                    dataRows.forEach(function (tr, i) {
                        tr.style.display = (i >= start && i < end) ? '' : 'none';
                    });

                    bar.innerHTML = '';
                    var info = document.createElement('span');
                    info.className = 'report-pagination-info';
                    var from = start + 1;
                    var to = Math.min(end, dataRows.length);
                    info.textContent = 'Page ' + currentPage + ' of ' + totalPages + ' · Showing ' + from + '–' + to + ' of ' + dataRows.length + ' (10 per page)';

                    var controls = document.createElement('div');
                    controls.className = 'report-pagination-controls';

                    var prev = document.createElement('button');
                    prev.type = 'button';
                    prev.className = 'page-btn';
                    prev.textContent = 'Prev';
                    prev.disabled = currentPage <= 1;
                    prev.addEventListener('click', function () {
                        currentPage -= 1;
                        render();
                    });
                    controls.appendChild(prev);

                    var maxButtons = 12;
                    var startPage = 1;
                    var endPage = totalPages;
                    if (totalPages > maxButtons) {
                        var half = Math.floor(maxButtons / 2);
                        startPage = Math.max(1, currentPage - half);
                        endPage = Math.min(totalPages, startPage + maxButtons - 1);
                        if (endPage - startPage < maxButtons - 1) {
                            startPage = Math.max(1, endPage - maxButtons + 1);
                        }
                    }
                    for (var p = startPage; p <= endPage; p++) {
                        (function (pageNum) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'page-btn' + (pageNum === currentPage ? ' is-active' : '');
                            btn.textContent = String(pageNum);
                            btn.addEventListener('click', function () {
                                currentPage = pageNum;
                                render();
                            });
                            controls.appendChild(btn);
                        })(p);
                    }

                    var next = document.createElement('button');
                    next.type = 'button';
                    next.className = 'page-btn';
                    next.textContent = 'Next';
                    next.disabled = currentPage >= totalPages;
                    next.addEventListener('click', function () {
                        currentPage += 1;
                        render();
                    });
                    controls.appendChild(next);

                    bar.appendChild(info);
                    bar.appendChild(controls);
                }
                render();
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAdminReportPagination);
        } else {
            initAdminReportPagination();
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>
