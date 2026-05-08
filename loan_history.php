<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$user_sql = "SELECT username, email, full_name, created_at, profile_photo, deped_id FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$stmt->close();
$profile_photo = $user_data['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

// Format registration date
$account_age = date_diff(date_create($user_data['created_at']), date_create())->format('%y years, %m months');

// Fetch loan history with payments summary
$loans = [];
$loan_stmt = $conn->prepare(
    "SELECT 
        l.id,
        l.loan_amount,
        l.loan_term,
        l.status,
        l.loan_purpose,
        l.application_date,
        l.reviewed_at,
        l.released_at,
        l.monthly_payment,
        l.total_amount,
        l.total_interest,
        l.net_pay,
        l.school_assignment,
        l.position,
        l.employment_status,
        l.co_maker_full_name,
        l.co_maker_position,
        l.co_maker_school_assignment,
        l.payslip_filename,
        l.co_maker_payslip_filename,
        l.borrower_id_front_filename,
        l.borrower_id_back_filename,
        l.co_maker_id_front_filename,
        l.co_maker_id_back_filename,
        l.admin_comment,
        l.reviewed_by_name,
        l.reviewed_by_role,
        COALESCE(SUM(d.amount), 0) AS total_paid
     FROM loans l
     LEFT JOIN deductions d ON d.loan_id = l.id
     WHERE l.user_id = ?
     GROUP BY l.id
     ORDER BY l.application_date DESC, l.id DESC"
);
$loan_stmt->bind_param("i", $user_id);
$loan_stmt->execute();
$loan_result = $loan_stmt->get_result();
while ($row = $loan_result->fetch_assoc()) {
    $loans[] = $row;
}
$loan_stmt->close();

$total_loans = 0;
$total_borrowed = 0.0;
$total_paid = 0.0;
$total_outstanding = 0.0;
$available_years = [];

foreach ($loans as $loan) {
    $loan_amount = (float) ($loan['loan_amount'] ?? 0);
    $paid = (float) ($loan['total_paid'] ?? 0);
    $status = strtolower((string) ($loan['status'] ?? ''));

    if (in_array($status, ['approved', 'completed', 'active'], true)) {
        $total_loans++;
    }

    if (!in_array($status, ['rejected', 'cancelled'], true)) {
        $total_borrowed += $loan_amount;
    }

    $total_paid += $paid;

    if (in_array($status, ['approved', 'completed', 'active'], true)) {
        $total_amount = (float) ($loan['total_amount'] ?? 0);
        if ($total_amount <= 0) {
            $total_amount = $loan_amount;
        }
        $total_outstanding += max(0, $total_amount - $paid);
    }

    if (!empty($loan['application_date'])) {
        $year = date('Y', strtotime($loan['application_date']));
        $available_years[$year] = true;
    }
}

if (!empty($available_years)) {
    krsort($available_years);
}

if (!function_exists('format_currency')) {
    function format_currency($amount) {
        return '₱' . number_format((float) $amount, 2);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan History - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            min-height: 100vh;
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
        
        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 192px; /* 80% of 250px */
        }
        
        .content-section {
            background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%);
            padding: 2.5rem;
            border-radius: 18px;
            box-shadow: 0 18px 35px rgba(17, 24, 39, 0.08);
            margin-bottom: 2rem;
            border: 1px solid rgba(139, 0, 0, 0.08);
        }
        
        .section-title {
            font-size: 1.75rem;
            color: #1f2933;
            margin-bottom: 0.35rem;
            letter-spacing: 0.02em;
        }

        .section-subtitle {
            color: #6b7280;
            margin-bottom: 1.75rem;
            font-size: 0.95rem;
        }
        
        .history-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: #ffffff;
            padding: 1.5rem;
            border-radius: 14px;
            text-align: left;
            border: 1px solid rgba(139, 0, 0, 0.12);
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.08);
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .summary-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.15), rgba(220, 20, 60, 0.2));
            color: #8b0000;
            font-size: 1.1rem;
        }

        .summary-details {
            display: flex;
            flex-direction: column;
        }
        
        .summary-number {
            font-size: 1.6rem;
            font-weight: 700;
            color: #111827;
        }
        
        .summary-label {
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        .loan-history-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.9rem;
        }

        .loan-history-card {
            border: 1.5px solid #7f1d1d;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            padding: 0.8rem 0.85rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.55rem;
            min-width: 0;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .loan-history-card:hover {
            transform: translateY(-2px);
            border-color: #5b0f0f;
            box-shadow: 0 15px 28px rgba(15, 23, 42, 0.12);
        }

        .loan-field {
            border: 1px solid #dce6f2;
            border-radius: 10px;
            background: #f9fbff;
            padding: 0.42rem 0.55rem;
            min-height: 0;
        }

        .loan-field-label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #475569;
            font-weight: 800;
            margin-bottom: 0.14rem;
        }
        
        .loan-id {
            font-weight: 700;
            color: #111827;
        }
        
        .loan-amount {
            font-weight: 700;
            color: #8b0000;
            font-size: 1rem;
        }

        .date-main {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.93rem;
        }

        .loan-meta {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #64748b;
            font-size: 0.78rem;
            margin-top: 0.18rem;
        }

        .term-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.34rem;
            padding: 0.24rem 0.54rem;
            border-radius: 999px;
            border: 1px solid #d6e3f3;
            background: #fff;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 700;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.65rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            border: 1px solid transparent;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
            border-color: #bde5c6;
        }
        
        .status-active {
            background: #cce5ff;
            color: #004085;
            border-color: #b7d7ff;
        }

        .status-approved {
            background: #ede9fe;
            color: #4c1d95;
            border-color: #ddd3fd;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border-color: #f3bcc2;
        }
        
        .status-cancelled {
            background: #e2e3e5;
            color: #383d41;
            border-color: #d5d8de;
        }
        
        .action-cell {
            margin-top: 0.1rem;
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            background: transparent;
            border: 0;
            padding: 0.05rem 0;
        }

        .action-cell .loan-field-label {
            display: none;
        }

        .view-details-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid #8b0000;
            color: #fff;
            background: #8b0000;
            border-radius: 8px;
            padding: 0.45rem 0.76rem;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }

        .view-details-btn:hover {
            background: #6d0000;
            color: #fff;
            border-color: #6d0000;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1200;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            box-sizing: border-box;
        }

        #loanModal .modal-content {
            background-color: white;
            margin: auto;
            padding: 0;
            border-radius: 10px;
            width: min(1280px, 96vw);
            max-width: 1280px;
            height: min(90vh, 900px);
            max-height: 90vh;
            min-height: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.18);
            overflow: hidden;
            box-sizing: border-box;
        }

        #loanModal .modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            padding: 0.82rem 1.2rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        #loanModal .modal-header h3 {
            margin: 0;
            font-size: 1.22rem;
            font-weight: 600;
        }

        #loanModal .modal-header .close {
            color: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            border: 0;
            background: transparent;
        }

        #loanModal .modal-body {
            padding: 1rem 1.2rem 1.1rem;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1 1 auto;
            min-height: 0;
            -webkit-overflow-scrolling: touch;
            box-sizing: border-box;
            --loan-detail-label-w: min(13.5rem, 40%);
        }

        #loanModal .loan-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem;
            max-width: 100%;
            box-sizing: border-box;
        }

        #loanModal .detail-section {
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            padding: 0.82rem 0.95rem;
            min-width: 0;
            overflow: hidden;
            box-sizing: border-box;
        }

        #loanModal .detail-section h4 {
            margin: 0 0 0.45rem;
            font-size: 0.9rem;
            font-weight: 700;
            color: #8b0000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        #loanModal .detail-list {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        #loanModal .detail-row {
            display: grid;
            grid-template-columns: var(--loan-detail-label-w) minmax(0, 1fr);
            column-gap: 0.45rem;
            align-items: start;
            font-size: 0.93rem;
            line-height: 1.45;
            min-width: 0;
        }

        #loanModal .detail-label {
            color: #4b5563;
            font-weight: 600;
            font-size: 0.88rem;
            white-space: normal;
            min-width: 0;
        }

        #loanModal .detail-label::after {
            content: ':';
        }

        #loanModal .detail-value {
            color: #111827;
            font-weight: 500;
            font-size: 0.94rem;
            min-width: 0;
            max-width: 100%;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        #loanModal .detail-value strong {
            color: #8b0000;
            font-size: 0.97rem;
        }

        #loanModal .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.32rem 0.72rem;
            border-radius: 999px;
            border: 1px solid #8b0000;
            background: #8b0000;
            color: #ffffff;
            font-size: 0.84rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        #loanModal .view-btn:hover {
            background: #6d0000;
            border-color: #6d0000;
            color: #ffffff;
        }

        #loanModal .detail-muted {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.82rem;
        }

        #loanModal .detail-section-uploads .detail-row {
            align-items: center;
        }

        #loanModal .detail-section-uploads .detail-label {
            white-space: nowrap;
            font-weight: 600;
        }

        .doc-preview-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 4000;
        }

        .doc-preview-overlay.active { display: flex; }

        .doc-preview-modal {
            width: min(980px, 96vw);
            height: min(86vh, 760px);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .doc-preview-header {
            padding: 0.78rem 0.95rem;
            background: linear-gradient(135deg, #7f1d1d 0%, #8b0000 50%, #b91c1c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .doc-preview-title {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .doc-preview-close {
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 10px;
            color: #fff;
            background: rgba(255, 255, 255, 0.16);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .doc-preview-close:hover { background: rgba(255,255,255,0.26); }

        .doc-preview-body {
            flex: 1;
            background: #e2e8f0;
            position: relative;
        }

        .doc-preview-frame, .doc-preview-image {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
            object-fit: contain;
        }

        .doc-preview-loading, .doc-preview-error {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: #475569;
            text-align: center;
            padding: 1rem;
            background: #f8fafc;
        }

        .doc-preview-loading i {
            font-size: 1.2rem;
            color: #8b0000;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
            background: #fff;
            border-radius: 14px;
            padding: 0.85rem 1rem;
            border: 1px solid #e5e7eb;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-group label {
            color: #374151;
            font-weight: 600;
            font-size: 0.86rem;
        }
        
        .filter-group select {
            padding: 0.45rem 0.65rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            min-width: 140px;
            color: #111827;
            font-size: 0.84rem;
        }
        
        .filter-group select:focus {
            outline: none;
            border-color: #8b0000;
        }
        
        .export-btn {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 0.6rem 1.1rem;
            border-radius: 999px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.3s, box-shadow 0.3s;
            margin-left: auto;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(139, 0, 0, 0.2);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
            background: #ffffff;
            border-radius: 16px;
            border: 1px dashed rgba(148, 163, 184, 0.5);
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: #f3c1c1;
            margin-bottom: 1rem;
        }

        .loan-meta {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.85rem;
            color: #6b7280;
        }
        
        @media (max-width: 768px) {
            .navbar {
                left: 0;
                padding: 1rem;
            }
            
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
                order: 2;
            }
            
            .main-content {
                margin-left: 0;
                order: 1;
            }
            
            .container {
                flex-direction: column;
            }
            
            .history-summary {
                grid-template-columns: 1fr;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .export-btn {
                margin-left: 0;
                width: 100%;
            }
            
            .loan-history-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.75rem;
            }

            .loan-history-card {
                grid-template-columns: 1fr;
            }
            
            .welcome-message {
                font-size: 1rem;
            }
        }

        /* ===== App shell (match admin_dashboard/reports) ===== */
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

        .sidebar-backdrop { display: none; }
        .sidebar-close { display: none; }

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

            .sidebar-toggle { display: inline-flex; flex-shrink: 0; }

            .welcome-message { font-size: 1rem; min-width: 0; flex: 1 1 auto !important; width: auto !important; max-width: none !important; }
            .welcome-title { font-size: 0.94rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .welcome-meta { font-size: 0.74rem; gap: 0.35rem 0.55rem; flex-wrap: nowrap; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .welcome-meta span:last-child { display: none; }

            .nav-icons { gap: 0.55rem; margin-left: auto; flex: 0 0 auto !important; width: auto !important; justify-content: flex-end !important; }
            .profile-chevron { display: none; }

            .sidebar {
                --mobile-sidebar-width: clamp(200px, 62vw, 240px);
                position: fixed !important;
                top: 0 !important;
                left: calc(-1 * var(--mobile-sidebar-width) - 12px) !important;
                width: var(--mobile-sidebar-width) !important;
                max-width: var(--mobile-sidebar-width) !important;
                min-width: var(--mobile-sidebar-width) !important;
                height: 100vh !important;
                transition: left 0.24s ease !important;
                z-index: 1003 !important;
                overflow-y: auto !important;
                border-radius: 0 16px 16px 0;
                box-shadow: 0 20px 42px rgba(15, 23, 42, 0.24);
            }

            body.sidebar-open .sidebar { left: 0 !important; }

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

            .container { margin-top: 78px !important; min-height: 100vh; display: block !important; }
            .main-content { margin-left: 0 !important; padding: 1rem; margin-top: 0; }

            .content-section {
                padding: 0.95rem;
                overflow: hidden;
            }

            .loan-history-grid {
                grid-template-columns: 1fr;
            }

            #loanModal .loan-detail-grid {
                grid-template-columns: 1fr;
            }

            #loanModal .detail-row {
                grid-template-columns: 1fr;
            }

            /* Disable collapsed sidebar visuals from shared.css on mobile widths */
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

        @media (min-width: 701px) and (max-width: 900px) {
            .navbar { left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)) !important; }
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
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($user_data['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> Borrower</span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-history"></i> Loan History</span>
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
                    <span class="profile-initial"><?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?></span>
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
                                    <?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($user_data['full_name']); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($user_data['email']); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($user_data['deped_id'] ?? 'Not set'); ?></div>
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
                    <a href="borrower_dashboard.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-home"></i></span>
                        Borrower Dashboard
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="my_loans.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-credit-card"></i></span>
                        My Loans
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="apply_loan.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-plus-circle"></i></span>
                        Apply for Loan
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="loan_history.php" class="sidebar-link active">
                        <span class="sidebar-icon"><i class="fas fa-history"></i></span>
                        Loan History
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="support.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-headset"></i></span>
                        Support
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
                    <div class="sidebar-user-role">Borrower</div>
                </div>
            </div>
            
        </aside>
        
        <main class="main-content">
            <div class="content-section">
                <h2 class="section-title">Loan History</h2>
                <p class="section-subtitle">Track your applications, approvals, and repayments in one place.</p>
                
                <div class="history-summary">
                    <div class="summary-card">
                        <div class="summary-icon"><i class="fas fa-layer-group"></i></div>
                        <div class="summary-details">
                            <div class="summary-number"><?php echo (int) $total_loans; ?></div>
                            <div class="summary-label">Approved Loans</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="fas fa-hand-holding-dollar"></i></div>
                        <div class="summary-details">
                            <div class="summary-number"><?php echo format_currency($total_borrowed); ?></div>
                            <div class="summary-label">Total Borrowed</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="fas fa-sack-dollar"></i></div>
                        <div class="summary-details">
                            <div class="summary-number"><?php echo format_currency($total_paid); ?></div>
                            <div class="summary-label">Total Paid</div>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="summary-details">
                            <div class="summary-number"><?php echo format_currency($total_outstanding); ?></div>
                            <div class="summary-label">Outstanding</div>
                        </div>
                    </div>
                </div>
                
                <div class="filter-section">
                    <div class="filter-group">
                        <label for="statusFilter">Status:</label>
                        <select id="statusFilter">
                            <option value="">All Status</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="yearFilter">Year:</label>
                        <select id="yearFilter">
                            <option value="">All Years</option>
                            <?php foreach (array_keys($available_years) as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button class="export-btn">
                        <i class="fas fa-download"></i> Export History
                    </button>
                </div>
                
                <?php if (empty($loans)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <div>No loan history found.</div>
                        <div style="margin-top: 0.5rem;">
                            <a href="apply_loan.php" style="color: #8b0000; text-decoration: none; font-weight: 600;">Apply for your first loan</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="loan-history-grid">
                        <?php foreach ($loans as $loan): ?>
                            <?php
                                $status = strtolower((string) ($loan['status'] ?? 'pending'));
                                $status_class = 'status-' . $status;
                                $status_label = ucfirst($status);
                                $completion_raw = $loan['reviewed_at'] ?? $loan['released_at'] ?? '';
                                $completion_date = $completion_raw ? date('M d, Y', strtotime($completion_raw)) : '—';
                                $applied_year = !empty($loan['application_date']) ? date('Y', strtotime($loan['application_date'])) : '';
                            ?>
                            <article class="loan-history-card" data-status="<?php echo htmlspecialchars($status); ?>" data-year="<?php echo htmlspecialchars($applied_year); ?>">
                                <div class="loan-field">
                                    <span class="loan-field-label">Date Applied</span>
                                    <div class="date-main"><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></div>
                                    <div class="loan-meta"><i class="fas fa-calendar"></i> <?php echo date('h:i A', strtotime($loan['application_date'])); ?></div>
                                </div>
                                <div class="loan-field">
                                    <span class="loan-field-label">Amount</span>
                                    <div class="loan-amount"><?php echo format_currency($loan['loan_amount']); ?></div>
                                </div>
                                <div class="loan-field">
                                    <span class="loan-field-label">Status</span>
                                    <span class="status-badge <?php echo htmlspecialchars($status_class); ?>">
                                        <i class="fas fa-circle"></i> <?php echo htmlspecialchars($status_label); ?>
                                    </span>
                                </div>
                                <div class="loan-field action-cell">
                                    <span class="loan-field-label">Action</span>
                                    <button
                                        type="button"
                                        class="view-details-btn js-view-loan-details"
                                        data-loan-id="<?php echo (int) ($loan['id'] ?? 0); ?>"
                                        data-application-date="<?php echo htmlspecialchars((string) ($loan['application_date'] ?? '')); ?>"
                                        data-reviewed-at="<?php echo htmlspecialchars((string) ($loan['reviewed_at'] ?? '')); ?>"
                                        data-released-at="<?php echo htmlspecialchars((string) ($loan['released_at'] ?? '')); ?>"
                                        data-status="<?php echo htmlspecialchars($status_label); ?>"
                                        data-status-raw="<?php echo htmlspecialchars($status); ?>"
                                        data-amount="<?php echo htmlspecialchars(format_currency($loan['loan_amount'] ?? 0)); ?>"
                                        data-loan-purpose="<?php echo htmlspecialchars((string) ($loan['loan_purpose'] ?? '')); ?>"
                                        data-term="<?php echo htmlspecialchars((string) ($loan['loan_term'] ?? 'N/A')); ?>"
                                        data-monthly-payment="<?php echo htmlspecialchars(isset($loan['monthly_payment']) && $loan['monthly_payment'] !== null ? format_currency($loan['monthly_payment']) : 'N/A'); ?>"
                                        data-total-amount="<?php echo htmlspecialchars(isset($loan['total_amount']) && $loan['total_amount'] !== null ? format_currency($loan['total_amount']) : 'N/A'); ?>"
                                        data-total-interest="<?php echo htmlspecialchars(isset($loan['total_interest']) && $loan['total_interest'] !== null ? format_currency($loan['total_interest']) : 'N/A'); ?>"
                                        data-net-pay="<?php echo htmlspecialchars(isset($loan['net_pay']) && $loan['net_pay'] !== null ? format_currency($loan['net_pay']) : 'N/A'); ?>"
                                        data-office="<?php echo htmlspecialchars((string) ($loan['school_assignment'] ?? 'N/A')); ?>"
                                        data-position="<?php echo htmlspecialchars((string) ($loan['position'] ?? 'N/A')); ?>"
                                        data-employment-status="<?php echo htmlspecialchars((string) ($loan['employment_status'] ?? 'N/A')); ?>"
                                        data-co-maker-name="<?php echo htmlspecialchars((string) ($loan['co_maker_full_name'] ?? 'N/A')); ?>"
                                        data-co-maker-position="<?php echo htmlspecialchars((string) ($loan['co_maker_position'] ?? 'N/A')); ?>"
                                        data-co-maker-office="<?php echo htmlspecialchars((string) ($loan['co_maker_school_assignment'] ?? 'N/A')); ?>"
                                        data-payslip-file="<?php echo htmlspecialchars((string) ($loan['payslip_filename'] ?? '')); ?>"
                                        data-co-maker-payslip-file="<?php echo htmlspecialchars((string) ($loan['co_maker_payslip_filename'] ?? '')); ?>"
                                        data-borrower-id-front-file="<?php echo htmlspecialchars((string) ($loan['borrower_id_front_filename'] ?? '')); ?>"
                                        data-borrower-id-back-file="<?php echo htmlspecialchars((string) ($loan['borrower_id_back_filename'] ?? '')); ?>"
                                        data-co-maker-id-front-file="<?php echo htmlspecialchars((string) ($loan['co_maker_id_front_filename'] ?? '')); ?>"
                                        data-co-maker-id-back-file="<?php echo htmlspecialchars((string) ($loan['co_maker_id_back_filename'] ?? '')); ?>"
                                        data-admin-comment="<?php echo htmlspecialchars((string) ($loan['admin_comment'] ?? 'No comment')); ?>"
                                        data-reviewed-by="<?php echo htmlspecialchars((string) ($loan['reviewed_by_name'] ?? 'Not reviewed yet')); ?>"
                                        data-review-role="<?php echo htmlspecialchars((string) ($loan['reviewed_by_role'] ?? '')); ?>">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div id="loanHistoryEmpty" class="empty-state" style="display:none; margin-top: 0.8rem;">
                        <i class="fas fa-filter"></i>
                        <div>No records match your selected filters.</div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div id="loanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Loan Details</h3>
                <button type="button" class="close" id="loanModalClose" aria-label="Close details">&times;</button>
            </div>
            <div class="modal-body" id="loanDetails"></div>
        </div>
    </div>

    <div id="docPreviewOverlay" class="doc-preview-overlay" aria-hidden="true">
        <div class="doc-preview-modal" role="dialog" aria-modal="true" aria-labelledby="docPreviewTitle">
            <div class="doc-preview-header">
                <div id="docPreviewTitle" class="doc-preview-title">
                    <i class="fas fa-file-alt"></i>
                    <span id="docPreviewTitleText">Document Preview</span>
                </div>
                <button type="button" class="doc-preview-close" id="docPreviewClose" aria-label="Close document preview">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="doc-preview-body" id="docPreviewBody"></div>
        </div>
    </div>
    
    <script>
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileIcon = document.querySelector('.profile-trigger');
            const dropdown = document.getElementById('profileDropdown');
            
            if (!profileIcon.contains(event.target)) {
                dropdown.classList.remove('active');
            }
        });
        
        // Filter functionality
        document.getElementById('statusFilter').addEventListener('change', filterTable);
        document.getElementById('yearFilter').addEventListener('change', filterTable);
        
        function filterTable() {
            const statusFilter = document.getElementById('statusFilter').value;
            const yearFilter = document.getElementById('yearFilter').value;
            const rows = document.querySelectorAll('.loan-history-card');
            const emptyEl = document.getElementById('loanHistoryEmpty');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const status = row.dataset.status || '';
                const year = row.dataset.year || '';

                const statusMatch = !statusFilter || status === statusFilter.toLowerCase();
                const yearMatch = !yearFilter || year === yearFilter;

                const isVisible = statusMatch && yearMatch;
                row.style.display = isVisible ? '' : 'none';
                if (isVisible) visibleCount++;
            });

            if (emptyEl) {
                emptyEl.style.display = visibleCount === 0 ? '' : 'none';
            }
        }
        
        // Export functionality
        document.querySelector('.export-btn').addEventListener('click', function() {
            alert('Export functionality would download your loan history as a PDF or Excel file.');
        });

        const loanModal = document.getElementById('loanModal');
        const loanModalClose = document.getElementById('loanModalClose');
        const loanDetails = document.getElementById('loanDetails');
        const currentBorrowerName = <?php echo json_encode((string) ($user_data['full_name'] ?? 'Borrower')); ?>;
        const currentBorrowerEmail = <?php echo json_encode((string) ($user_data['email'] ?? 'N/A')); ?>;

        const formatDateTime = (rawValue) => {
            if (!rawValue) return 'N/A';
            const d = new Date(rawValue);
            if (Number.isNaN(d.getTime())) return 'N/A';
            return d.toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        };
        const formatDateOnly = (rawValue) => {
            if (!rawValue) return 'N/A';
            const d = new Date(rawValue);
            if (Number.isNaN(d.getTime())) return 'N/A';
            return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
        };

        const safeValue = (value, fallback = 'N/A') => {
            const clean = (value || '').trim();
            return clean !== '' ? clean : fallback;
        };

        const hasValue = (value) => String(value || '').trim() !== '';
        const loanDocTitles = {
            borrower_payslip: 'Borrower Payslip',
            co_maker_payslip: 'Co-maker Payslip',
            borrower_id_front: 'Borrower ID (Front)',
            borrower_id_back: 'Borrower ID (Back)',
            co_maker_id_front: 'Co-maker ID (Front)',
            co_maker_id_back: 'Co-maker ID (Back)'
        };
        const getLoanDocUrl = (loanId, doc) => {
            if (!loanId || !doc) return '#';
            return `download_loan_document.php?id=${encodeURIComponent(loanId)}&doc=${encodeURIComponent(doc)}`;
        };
        const renderDocLink = (loanId, docKey, label, iconClass, fileValue) => {
            if (!hasValue(fileValue)) {
                return `<span class="detail-muted">No file uploaded</span>`;
            }
            return `<button type="button" class="view-btn js-open-loan-doc" data-loan-id="${loanId}" data-doc="${docKey}" data-title="${loanDocTitles[docKey] || 'Document Preview'}"><i class="fas ${iconClass}"></i> ${label}</button>`;
        };

        let loanDocPreviewObjectUrl = null;
        const docPreviewOverlay = document.getElementById('docPreviewOverlay');
        const docPreviewBody = document.getElementById('docPreviewBody');
        const docPreviewTitleText = document.getElementById('docPreviewTitleText');

        function revokeDocPreviewUrl() {
            if (loanDocPreviewObjectUrl) {
                URL.revokeObjectURL(loanDocPreviewObjectUrl);
                loanDocPreviewObjectUrl = null;
            }
        }

        function closeDocPreview() {
            revokeDocPreviewUrl();
            if (!docPreviewOverlay || !docPreviewBody) return;
            docPreviewOverlay.classList.remove('active');
            docPreviewOverlay.setAttribute('aria-hidden', 'true');
            docPreviewBody.innerHTML = '';
            if (!loanModal || loanModal.style.display !== 'flex') {
                document.body.style.overflow = '';
            }
        }

        function openDocPreview(loanId, doc) {
            if (!docPreviewOverlay || !docPreviewBody || !loanId || !doc) return;
            const url = getLoanDocUrl(loanId, doc);
            const title = loanDocTitles[doc] || 'Document Preview';
            const titleEsc = title.replace(/"/g, '&quot;');

            revokeDocPreviewUrl();
            docPreviewTitleText.textContent = title;
            docPreviewBody.innerHTML = `
                <div class="doc-preview-loading">
                    <i class="fas fa-spinner"></i>
                    <span>Loading document...</span>
                </div>
            `;
            docPreviewOverlay.classList.add('active');
            docPreviewOverlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            fetch(url, { credentials: 'same-origin' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Document not available');
                    }
                    const rawCt = response.headers.get('Content-Type') || '';
                    const contentType = rawCt.split(';')[0].trim().toLowerCase();
                    if (contentType.includes('text/html') || contentType.includes('text/plain')) {
                        throw new Error('Unexpected response');
                    }
                    return response.blob().then((blob) => ({ blob, contentType }));
                })
                .then(({ blob, contentType }) => {
                    const ct = contentType || blob.type || '';
                    const isImage = ct.indexOf('image/') === 0;
                    loanDocPreviewObjectUrl = URL.createObjectURL(blob);
                    const objectUrl = loanDocPreviewObjectUrl;
                    if (isImage) {
                        docPreviewBody.innerHTML = `<img src="${objectUrl}" alt="${titleEsc}" class="doc-preview-image">`;
                    } else {
                        docPreviewBody.innerHTML = `<iframe src="${objectUrl}" class="doc-preview-frame" title="${titleEsc}"></iframe>`;
                    }
                })
                .catch(() => {
                    revokeDocPreviewUrl();
                    docPreviewBody.innerHTML = `
                        <div class="doc-preview-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>Unable to load this document.</div>
                        </div>
                    `;
                });
        }

        function openLoanDetails(button) {
            if (!button || !loanModal || !loanDetails) return;
            const loanId = safeValue(button.dataset.loanId, '');
            const reviewedBy = safeValue(button.dataset.reviewedBy);
            const reviewRole = safeValue(button.dataset.reviewRole, '');

            const statusRaw = safeValue(button.dataset.statusRaw, 'pending').toLowerCase();
            const statusLabel = safeValue(button.dataset.status, 'Pending');
            const statusIcon = statusRaw === 'rejected' ? 'times-circle'
                : statusRaw === 'completed' ? 'check-circle'
                : statusRaw === 'approved' ? 'clipboard-check'
                : 'clock';
            const reviewedByDisplay = reviewRole ? (reviewedBy + ' (' + reviewRole + ')') : reviewedBy;
            const applicantDob = 'N/A';
            const coMakerDob = 'N/A';
            const yearsOfService = 'N/A';
            const coMakerYearsOfService = 'N/A';

            loanDetails.innerHTML = `
                <div class="loan-detail-grid">
                    <div class="detail-section">
                        <h4><i class="fas fa-user"></i> Applicant Info</h4>
                        <div class="detail-list">
                            <div class="detail-row"><div class="detail-label">Name</div><div class="detail-value"><strong>${safeValue(currentBorrowerName)}</strong></div></div>
                            <div class="detail-row"><div class="detail-label">Email</div><div class="detail-value">${safeValue(currentBorrowerEmail)}</div></div>
                            <div class="detail-row"><div class="detail-label">Application Date</div><div class="detail-value">${formatDateTime(button.dataset.applicationDate)}</div></div>
                            <div class="detail-row"><div class="detail-label">Date of Birth</div><div class="detail-value">${applicantDob}</div></div>
                            <div class="detail-row"><div class="detail-label">Years of Service</div><div class="detail-value">${yearsOfService}</div></div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-file-contract"></i> Loan Details</h4>
                        <div class="detail-list">
                            <div class="detail-row"><div class="detail-label">Amount</div><div class="detail-value"><strong>${safeValue(button.dataset.amount)}</strong></div></div>
                            <div class="detail-row"><div class="detail-label">Loan Purpose</div><div class="detail-value">${safeValue(button.dataset.loanPurpose, 'No purpose provided')}</div></div>
                            <div class="detail-row"><div class="detail-label">Term</div><div class="detail-value">${safeValue(button.dataset.term)} months</div></div>
                            <div class="detail-row"><div class="detail-label">Net Pay</div><div class="detail-value">${safeValue(button.dataset.netPay)}</div></div>
                            <div class="detail-row"><div class="detail-label">Monthly Payment</div><div class="detail-value"><strong>${safeValue(button.dataset.monthlyPayment)}</strong></div></div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-user-tie"></i> Co-Maker Info</h4>
                        <div class="detail-list">
                            <div class="detail-row"><div class="detail-label">Name</div><div class="detail-value"><strong>${safeValue(button.dataset.coMakerName)}</strong></div></div>
                            <div class="detail-row"><div class="detail-label">Position</div><div class="detail-value">${safeValue(button.dataset.coMakerPosition)}</div></div>
                            <div class="detail-row"><div class="detail-label">Assignment</div><div class="detail-value">${safeValue(button.dataset.coMakerOffice)}</div></div>
                            <div class="detail-row"><div class="detail-label">Employment Status</div><div class="detail-value">${safeValue(button.dataset.employmentStatus)}</div></div>
                            <div class="detail-row"><div class="detail-label">Date of Birth</div><div class="detail-value">${coMakerDob}</div></div>
                            <div class="detail-row"><div class="detail-label">Years of Service</div><div class="detail-value">${coMakerYearsOfService}</div></div>
                            <div class="detail-row"><div class="detail-label">Net Pay</div><div class="detail-value">${safeValue(button.dataset.netPay)}</div></div>
                        </div>
                    </div>

                    <div class="detail-section detail-section-uploads">
                        <h4><i class="fas fa-file-upload"></i> Submitted Files</h4>
                        <div class="detail-list">
                            <div class="detail-row"><div class="detail-label">Borrower ID (Front)</div><div class="detail-value">${renderDocLink(loanId, 'borrower_id_front', 'View ID Front', 'fa-id-card', button.dataset.borrowerIdFrontFile)}</div></div>
                            <div class="detail-row"><div class="detail-label">Borrower ID (Back)</div><div class="detail-value">${renderDocLink(loanId, 'borrower_id_back', 'View ID Back', 'fa-id-card', button.dataset.borrowerIdBackFile)}</div></div>
                            <div class="detail-row"><div class="detail-label">Borrower Payslip</div><div class="detail-value">${renderDocLink(loanId, 'borrower_payslip', 'View Payslip', 'fa-file-download', button.dataset.payslipFile)}</div></div>
                            <div class="detail-row"><div class="detail-label">Co-maker ID (Front)</div><div class="detail-value">${renderDocLink(loanId, 'co_maker_id_front', 'View ID Front', 'fa-id-card', button.dataset.coMakerIdFrontFile)}</div></div>
                            <div class="detail-row"><div class="detail-label">Co-maker ID (Back)</div><div class="detail-value">${renderDocLink(loanId, 'co_maker_id_back', 'View ID Back', 'fa-id-card', button.dataset.coMakerIdBackFile)}</div></div>
                            <div class="detail-row"><div class="detail-label">Co-maker Payslip</div><div class="detail-value">${renderDocLink(loanId, 'co_maker_payslip', 'View Payslip', 'fa-file-download', button.dataset.coMakerPayslipFile)}</div></div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-calculator"></i> Totals</h4>
                        <div class="detail-list">
                            <div class="detail-row"><div class="detail-label">Total Amount</div><div class="detail-value"><strong>${safeValue(button.dataset.totalAmount)}</strong></div></div>
                            <div class="detail-row"><div class="detail-label">Total Interest</div><div class="detail-value">${safeValue(button.dataset.totalInterest)}</div></div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-check-circle"></i> Review Status</h4>
                        <div class="detail-list">
                            <div class="detail-row"><div class="detail-label">Status</div><div class="detail-value"><span class="status-badge status-${statusRaw}"><i class="fas fa-${statusIcon}"></i> ${statusLabel}</span></div></div>
                            <div class="detail-row"><div class="detail-label">Reviewed By</div><div class="detail-value">${reviewedByDisplay}</div></div>
                            <div class="detail-row"><div class="detail-label">Reviewed At</div><div class="detail-value">${formatDateTime(button.dataset.reviewedAt)}</div></div>
                            <div class="detail-row"><div class="detail-label">Admin Comment</div><div class="detail-value">${safeValue(button.dataset.adminComment, 'No comment')}</div></div>
                        </div>
                    </div>
                </div>
            `;

            loanModal.style.display = 'flex';
        }

        function closeLoanDetails() {
            if (!loanModal) return;
            if (docPreviewOverlay && docPreviewOverlay.classList.contains('active')) {
                closeDocPreview();
            }
            loanModal.style.display = 'none';
            if (!docPreviewOverlay || !docPreviewOverlay.classList.contains('active')) {
                document.body.style.overflow = '';
            }
        }

        document.querySelectorAll('.js-view-loan-details').forEach((button) => {
            button.addEventListener('click', () => openLoanDetails(button));
        });

        if (loanModalClose) {
            loanModalClose.addEventListener('click', closeLoanDetails);
        }

        if (loanModal) {
            loanModal.addEventListener('click', (event) => {
                if (event.target === loanModal) {
                    closeLoanDetails();
                }
            });
        }

        document.addEventListener('click', (event) => {
            const btn = event.target.closest('.js-open-loan-doc');
            if (!btn) return;
            openDocPreview(btn.getAttribute('data-loan-id'), btn.getAttribute('data-doc'));
        });

        const docPreviewCloseBtn = document.getElementById('docPreviewClose');
        if (docPreviewCloseBtn) {
            docPreviewCloseBtn.addEventListener('click', closeDocPreview);
        }
        if (docPreviewOverlay) {
            docPreviewOverlay.addEventListener('click', (event) => {
                if (event.target === docPreviewOverlay) {
                    closeDocPreview();
                }
            });
        }
    </script>

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

        document.addEventListener('click', function(event) {
            if (event.target && event.target.id === 'profileModalOverlay') {
                closeProfileModal();
            }
        });
    </script>

    <script>
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

            backdrop.addEventListener('click', function () { setSidebarOpen(false); });
            if (closeBtn) closeBtn.addEventListener('click', function () { setSidebarOpen(false); });

            window.addEventListener('resize', function () {
                if (window.innerWidth > 700) setSidebarOpen(false);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') setSidebarOpen(false);
            });
        })();
    </script>
</body>
</html>
