<?php
require_once 'config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$profile_photo = $user['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

// Check if user is admin or accountant
$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$is_admin && !$is_accounting) {
    header("Location: borrower_dashboard.php");
    exit();
}

$dashboard_url = $is_accounting ? 'accountant_dashboard.php' : 'admin_dashboard.php';
$dashboard_label = $is_accounting ? 'Accountant Dashboard' : 'Admin Dashboard';
$role_label = $is_accounting ? 'Accountant' : 'Administrator';
$access_label = $is_accounting ? 'Accountant Access' : 'Admin Access';

$pending_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM loans WHERE status = 'pending'");
$pending_stmt->execute();
$pending_loans_count = (int) ($pending_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$pending_stmt->close();

// Fetch all loans
$all_loans = [];
$stmt = $conn->prepare(
    "SELECT l.*, u.full_name, u.email,
            COALESCE(SUM(d.amount), 0) AS total_deducted,
            MAX(d.deduction_date) AS last_deduction_date
     FROM loans l
     JOIN users u ON l.user_id = u.id
     LEFT JOIN deductions d ON d.loan_id = l.id
     WHERE l.status IN ('approved', 'completed')
     GROUP BY l.id
     ORDER BY l.application_date DESC"
);
$stmt->execute();
$result = $stmt->get_result();
$total_collected = 0;
$total_outstanding = 0;
while ($row = $result->fetch_assoc()) {
    $principal_total = $row['total_amount'] ?? $row['loan_amount'];
    $deducted = (float)($row['total_deducted'] ?? 0);
    $balance = max(0, (float)$principal_total - $deducted);
    $row['balance_remaining'] = $balance;
    $row['principal_total'] = (float)$principal_total;
    $row['payment_progress'] = $principal_total > 0 ? min(100, ($deducted / $principal_total) * 100) : 0;
    $total_collected += $deducted;
    $total_outstanding += $balance;
    $all_loans[] = $row;
}
$stmt->close();
$total_loans_count = count($all_loans);
$collection_rate = ($total_collected + $total_outstanding) > 0
    ? ($total_collected / ($total_collected + $total_outstanding)) * 100
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Loans - DepEd Loan System</title>
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

        .main-content {
            flex: 1;
            padding: 2.25rem;
            margin-left: 192px; /* 80% of 250px */
            margin-top: 20px;
        }

        .content-section {
            background: white;
            padding: 2.25rem;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.7rem;
            color: #1f2937;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .section-subtitle {
            color: #6b7280;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }

        .table-wrapper {
            border-radius: 14px;
            border: 1px solid #edf2f7;
            overflow-y: auto;
            overflow-x: hidden;
            max-height: 560px;
        }

        .loan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            font-size: 0.92rem;
            table-layout: fixed;
        }

        .loan-table th,
        .loan-table td {
            padding: 0.9rem 1rem;
            text-align: left;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            word-break: break-word;
        }

        .view-btn {
            border: 1px solid rgba(139, 0, 0, 0.2);
            background: #fff5f5;
            color: #8b0000;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .view-btn:hover {
            background: #ffe8e8;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .schedule-btn {
            border: 1px solid rgba(34, 197, 94, 0.3);
            background: #f0fdf4;
            color: #15803d;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .schedule-btn:hover {
            background: #dcfce7;
        }

        .details-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 3000;
        }

        .details-modal-overlay.active {
            display: flex;
        }

        .details-modal {
            background: #fafbfc;
            border-radius: 20px;
            width: min(920px, 97vw);
            max-height: min(92vh, 900px);
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.8);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .details-modal-header {
            padding: 1.15rem 1.5rem;
            background: linear-gradient(135deg, #7f1d1d 0%, #b91c1c 48%, #dc2626 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-shrink: 0;
        }

        .details-modal-header-text {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .details-modal-header-text #detailsModalTitle {
            font-weight: 700;
            font-size: 1.15rem;
            letter-spacing: -0.02em;
        }

        .details-subsection-title {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin: 1rem 0 0.65rem;
        }

        .details-subsection-title i {
            color: #b91c1c;
            font-size: 0.8rem;
        }

        .details-comaker-panel {
            background: #fff;
            border: 1px solid #e8edf3;
            border-radius: 14px;
            padding: 0.65rem 0.75rem 0.85rem;
        }

        .details-modal-body {
            padding: 0;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .details-hero {
            padding: 1.35rem 1.5rem 1.1rem;
            background: linear-gradient(135deg, #fff 0%, #fff5f5 100%);
            border-bottom: 1px solid rgba(185, 28, 28, 0.12);
        }

        .details-hero-top {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .details-hero-name {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.03em;
            line-height: 1.25;
        }

        .details-hero-email {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            margin-top: 0.45rem;
            font-size: 0.9rem;
            color: #475569;
        }

        .details-hero-email i {
            color: #b91c1c;
            font-size: 0.85rem;
        }

        .details-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.85rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .details-status-pill.ongoing {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .details-status-pill.pending-release {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
        }

        .details-status-pill.completed {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .details-status-pill.other {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .details-section {
            padding: 1rem 1.5rem;
        }

        .details-section + .details-section {
            border-top: 1px solid rgba(148, 163, 184, 0.2);
        }

        .details-section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #b91c1c;
            margin-bottom: 0.85rem;
        }

        .details-section-title i {
            opacity: 0.85;
            font-size: 0.85rem;
        }

        .details-fin-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.65rem;
        }

        @media (max-width: 800px) {
            .details-fin-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {
            .details-fin-grid {
                grid-template-columns: 1fr;
            }
        }

        .details-fin-card {
            background: #fff;
            border-radius: 14px;
            padding: 0.85rem 1rem;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .details-fin-card .dfc-label {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            margin-bottom: 0.35rem;
        }

        .details-fin-card .dfc-label i {
            color: #b91c1c;
            font-size: 0.72rem;
            opacity: 0.9;
        }

        .details-fin-card .dfc-value {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        .details-fin-card.highlight {
            background: linear-gradient(145deg, #fff 0%, #fef2f2 100%);
            border-color: rgba(248, 113, 113, 0.35);
        }

        .details-progress-block {
            margin-top: 1rem;
            background: #fff;
            border-radius: 14px;
            padding: 1rem 1.1rem;
            border: 1px solid #e2e8f0;
        }

        .details-progress-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.55rem;
        }

        .details-progress-head strong {
            font-size: 0.8rem;
            color: #334155;
        }

        .details-progress-head span {
            font-size: 0.85rem;
            font-weight: 700;
            color: #b91c1c;
        }

        .details-progress-bar {
            height: 10px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .details-progress-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #b91c1c, #f87171);
            transition: width 0.35s ease;
        }

        .details-progress-foot {
            margin-top: 0.45rem;
            font-size: 0.8rem;
            color: #64748b;
        }

        .details-kv-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.55rem 1.25rem;
        }

        @media (max-width: 600px) {
            .details-kv-grid {
                grid-template-columns: 1fr;
            }
        }

        .details-kv {
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            background: #fff;
            border: 1px solid #e8edf3;
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
        }

        .details-kv-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 10px;
            background: #fef2f2;
            color: #b91c1c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .details-kv-text {
            min-width: 0;
        }

        .details-kv-text .kv-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            margin-bottom: 0.15rem;
        }

        .details-kv-text .kv-value {
            font-size: 0.9rem;
            color: #1e293b;
            font-weight: 600;
            line-height: 1.35;
            word-break: break-word;
        }

        .details-purpose-box {
            background: #fff;
            border: 1px dashed rgba(185, 28, 28, 0.35);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            font-size: 0.9rem;
            color: #334155;
            line-height: 1.5;
        }

        .details-offset-note {
            margin-top: 0.75rem;
            padding: 0.65rem 0.85rem;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            font-size: 0.85rem;
            color: #1e40af;
        }

        .details-modal-footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.6rem;
            padding: 0.85rem 1.5rem 1.1rem;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .details-footer-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }

        .details-footer-btn:active {
            transform: scale(0.98);
        }

        .details-footer-btn.secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .details-footer-btn.secondary:hover {
            background: #e2e8f0;
        }

        .details-footer-btn.primary {
            background: linear-gradient(135deg, #15803d, #22c55e);
            color: #fff;
            box-shadow: 0 2px 8px rgba(22, 163, 74, 0.35);
        }

        .details-footer-btn.primary:hover {
            box-shadow: 0 4px 14px rgba(22, 163, 74, 0.45);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
        }

        @media (max-width: 900px) {
            .details-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .schedule-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 3000;
        }
        .schedule-modal-overlay.active {
            display: flex;
        }
        .schedule-modal {
            background: #fff;
            border-radius: 16px;
            width: min(1100px, 98vw);
            max-height: 85vh;
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .schedule-modal, .schedule-modal * {
            box-sizing: border-box;
        }
        .schedule-modal-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #15803d 0%, #22c55e 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            flex-shrink: 0;
        }
        .schedule-modal-header .schedule-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0.25rem;
        }
        .schedule-modal-body {
            padding: 1rem 1.25rem;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1;
        }
        .schedule-summary {
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: #f8fafc;
            border-radius: 10px;
            font-size: 0.9rem;
        }
        .schedule-summary strong { color: #1e293b; }
        .schedule-calendar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
            width: 100%;
            max-width: 100%;
        }
        .schedule-calendar-month {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem;
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-width: 0;
        }
        .schedule-calendar-month.paid {
            border-color: #bbf7d0;
            background: #f0fdf4;
        }
        .schedule-calendar-month.unpaid {
            border-color: #fed7aa;
            background: #fffbf0;
        }
        .schedule-calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .schedule-calendar-month.paid .schedule-calendar-header {
            border-color: #bbf7d0;
        }
        .schedule-calendar-month.unpaid .schedule-calendar-header {
            border-color: #fed7aa;
        }
        .schedule-calendar-title {
            font-weight: 700;
            font-size: 0.9rem;
            color: #1e293b;
        }
        .schedule-calendar-period {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 600;
        }
        .schedule-calendar-grid-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.2rem;
            margin-top: 0.5rem;
            flex: 1;
            width: 100%;
            max-width: 100%;
        }
        .schedule-calendar-day-header {
            text-align: center;
            font-size: 0.65rem;
            font-weight: 700;
            color: #64748b;
            padding: 0.25rem 0;
            min-width: 0;
        }
        .schedule-calendar-day {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 34px;
            font-size: 0.75rem;
            color: #475569;
            border-radius: 6px;
            position: relative;
            min-width: 0;
        }
        .schedule-calendar-day.other-month {
            color: #cbd5e1;
        }
        .schedule-calendar-day.has-payment {
            background: #22c55e;
            color: #fff;
            font-weight: 700;
        }
        .schedule-calendar-day.due-date {
            background: #f59e0b;
            color: #fff;
            font-weight: 700;
        }
        .schedule-calendar-footer {
            margin-top: auto;
            padding-top: 0.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .schedule-calendar-footer-left {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
        }
        .schedule-calendar-footer-main {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .schedule-calendar-amount {
            font-weight: 600;
            color: #334155;
        }
        .schedule-calendar-cutoffs {
            font-size: 0.75rem;
            color: #64748b;
            line-height: 1.2;
        }
        .schedule-calendar-month.paid .schedule-calendar-footer {
            border-color: #bbf7d0;
        }
        .schedule-calendar-month.unpaid .schedule-calendar-footer {
            border-color: #fed7aa;
        }
        .schedule-calendar-status {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
        }
        .schedule-calendar-status.paid-status {
            background: #dcfce7;
            color: #15803d;
        }
        .schedule-calendar-status.unpaid-status {
            background: #ffedd5;
            color: #c2410c;
        }
        .schedule-calendar-actions {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .schedule-calendar-actions .schedule-skip-btn,
        .schedule-calendar-actions .schedule-payment-btn {
            margin-left: 0;
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        .schedule-receipt-btn {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            border: 1px solid #94a3b8;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .schedule-receipt-btn:hover {
            background: #e2e8f0;
            color: #334155;
        }
        .receipt-viewer-overlay {
            position: fixed;
            inset: 0;
            z-index: 3600;
            background: rgba(15, 23, 42, 0.62);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .receipt-viewer-overlay.active {
            display: flex;
        }
        .receipt-viewer-modal {
            width: min(980px, 96vw);
            height: min(88vh, 760px);
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.26);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .receipt-viewer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.8rem 1rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            font-weight: 700;
        }
        .receipt-viewer-close {
            border: none;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .receipt-viewer-frame {
            width: 100%;
            height: 100%;
            border: 0;
            background: #f8fafc;
        }
        @media (max-width: 768px) {
            .schedule-calendar-grid {
                grid-template-columns: 1fr;
            }
            .schedule-modal {
                width: min(95vw, 400px);
            }
            .receipt-viewer-modal {
                width: 100%;
                height: 92vh;
            }
        }
        .schedule-modal-body .text-danger {
            color: #dc2626;
        }

        .confirm-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 3500;
        }
        .confirm-modal-overlay.active {
            display: flex;
        }
        .confirm-modal {
            background: #fff;
            border-radius: 16px;
            width: min(400px, 95vw);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(139, 0, 0, 0.15);
            overflow: hidden;
        }
        .payment-modal {
            width: min(560px, 95vw);
        }
        .payment-modal-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem 1.5rem;
        }
        .payment-modal-row-full {
            grid-column: 1 / -1;
        }
        .payment-modal-month-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .payment-modal-col {
            min-width: 0;
        }
        @media (max-width: 520px) {
            .payment-modal-body {
                grid-template-columns: 1fr;
            }
        }
        .confirm-modal-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
            font-size: 1rem;
        }
        .confirm-modal-header i {
            font-size: 1.25rem;
        }
        .confirm-modal-body {
            padding: 1.25rem;
            color: #334155;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .confirm-modal-body .confirm-highlight {
            font-weight: 700;
            color: #8b0000;
        }
        .confirm-modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            padding: 0 1.25rem 1.25rem;
        }
        .confirm-modal-actions button {
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .confirm-modal-cancel {
            background: #f1f5f9;
            color: #475569;
        }
        .confirm-modal-cancel:hover {
            background: #e2e8f0;
        }
        .confirm-modal-ok {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
        }
        .confirm-modal-ok:hover {
            filter: brightness(1.08);
            box-shadow: 0 4px 12px rgba(139, 0, 0, 0.35);
        }

        .schedule-skipped-wrap {
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 10px;
        }
        .schedule-skipped-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 0.35rem;
        }
        .schedule-skipped-list {
            font-size: 0.9rem;
            color: #78350f;
        }
        .schedule-skip-btn {
            margin-left: 0.5rem;
            padding: 0.2rem 0.5rem;
            font-size: 0.7rem;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .schedule-skip-btn:hover {
            background: #fde68a;
        }
        .schedule-payment-btn {
            margin-left: 0.5rem;
            padding: 0.2rem 0.5rem;
            font-size: 0.7rem;
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .schedule-payment-btn:hover {
            background: #bbf7d0;
        }

        @media (max-width: 600px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .details-close {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.1rem;
            cursor: pointer;
        }

        .loan-table th {
            background: #f8fafc;
            font-weight: 700;
            color: #374151;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .loan-table tbody tr:nth-child(even) {
            background: #fcfcfd;
        }

        .loan-table tr:hover {
            background: #f3f4f6;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-completed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            padding: 1.15rem 1.25rem;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #eef1f4;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .filter-group label {
            font-weight: 600;
            color: #475569;
        }

        .filter-group select {
            padding: 0.6rem 2.1rem 0.6rem 0.85rem;
            border: 1px solid #d7dce3;
            border-radius: 10px;
            font-size: 14px;
            background: #fff;
            min-width: 130px;
            appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, #8b0000 50%),
                linear-gradient(135deg, #8b0000 50%, transparent 50%),
                linear-gradient(to right, #d7dce3, #d7dce3);
            background-position:
                calc(100% - 18px) calc(50% - 2px),
                calc(100% - 13px) calc(50% - 2px),
                calc(100% - 32px) 50%;
            background-size: 5px 5px, 5px 5px, 1px 20px;
            background-repeat: no-repeat;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.7rem 0.9rem 0.7rem 2.2rem;
            border: 1px solid #d7dce3;
            border-radius: 12px;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .search-box input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.12);
        }

        .category-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            width: 100%;
        }

        .category-btn {
            border: 1px solid #d7dce3;
            background: #fff;
            color: #475569;
            border-radius: 12px;
            padding: 0.7rem 1.1rem;
            font-size: 0.98rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
            min-width: 180px;
            text-align: center;
        }

        .category-btn:hover {
            border-color: #8b0000;
            color: #8b0000;
            background: #fff5f5;
        }

        .category-btn.active {
            border-color: #8b0000;
            color: #fff;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            box-shadow: 0 6px 14px rgba(139, 0, 0, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.9rem;
            margin-bottom: 1.4rem;
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #fffafb 100%);
            border: 1px solid #e3d4da;
            padding: 1rem 1.05rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(127, 29, 29, 0.05);
            display: flex;
            align-items: center;
            gap: 0.8rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .stat-card::after {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #8b0000 0%, #dc143c 100%);
            opacity: 0.9;
        }
        .stat-card:hover {
            transform: translateY(-1px);
            border-color: #d4b7c1;
            box-shadow: 0 7px 18px rgba(127, 29, 29, 0.09);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #fbeef1;
            border: 1px solid #eed8df;
            color: #8b0000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-meta {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            min-width: 0;
        }

        .stat-number {
            font-size: 1.8rem;
            line-height: 1.05;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .stat-card.stat-green .stat-icon {
            background: #ecfdf3;
            border-color: #ccefd9;
            color: #166534;
        }
        .stat-card.stat-green::after {
            background: linear-gradient(90deg, #15803d 0%, #22c55e 100%);
        }
        .stat-card.stat-blue .stat-icon {
            background: #eff6ff;
            border-color: #d4e4ff;
            color: #1d4ed8;
        }
        .stat-card.stat-blue::after {
            background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
        }
        .stat-card.stat-gold .stat-icon {
            background: #fff7ed;
            border-color: #ffe7cb;
            color: #b45309;
        }
        .stat-card.stat-gold::after {
            background: linear-gradient(90deg, #d97706 0%, #f59e0b 100%);
        }
        .stat-card.stat-maroon .stat-icon {
            background: #fbeef1;
            border-color: #eed8df;
            color: #8b0000;
        }
        .stat-card.stat-maroon::after {
            background: linear-gradient(90deg, #8b0000 0%, #dc143c 100%);
        }
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 680px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .stat-number {
                font-size: 1.45rem;
            }
        }

        .amount-muted {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .balance-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            color: #8b0000;
        }

        .progress-track {
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: #f0f0f0;
            overflow: hidden;
            margin-top: 0.35rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            border-radius: inherit;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        /* ===== Admin shell (match admin_dashboard/reports) ===== */
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

        /* Content responsiveness for admin pages */
        @media (max-width: 900px) {
            .main-content {
                padding: 1rem !important;
                overflow-x: hidden;
            }

            .content-section,
            .filters-card,
            .stats-grid {
                overflow-x: visible;
            }

            .table-wrapper {
                max-height: none;
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
            }

            .loan-table {
                min-width: 980px;
                table-layout: auto;
            }

            .loan-table th,
            .loan-table td {
                white-space: nowrap;
                word-break: normal;
            }
        }

        @media (max-width: 600px) {
            .loan-table {
                min-width: 920px;
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
                    <span><i class="fas fa-list"></i> All Loans</span>
                    <span><i class="fas fa-shield-alt"></i> <?php echo $access_label; ?></span>
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
                    <a href="<?php echo $dashboard_url; ?>" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <?php echo $dashboard_label; ?>
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
                    <a href="all_loans.php" class="sidebar-link active">
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
                    <a href="admin_reports.php" class="sidebar-link">
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
                    <div class="sidebar-user-role"><?php echo !empty($is_accounting) ? 'Accountant' : 'Administrator'; ?></div>
                </div>
            </div>
            
        </aside>

        <main class="main-content">
            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    All Loans
                </h2>
                <div class="section-subtitle">Track applications, deductions, and balances across all borrowers.</div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card stat-green">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-meta">
                            <div class="stat-number"><?php echo (int)$total_loans_count; ?></div>
                            <div class="stat-label">Approved Loans</div>
                        </div>
                    </div>
                    <div class="stat-card stat-maroon">
                        <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                        <div class="stat-meta">
                            <div class="stat-number">₱<?php echo number_format($total_collected, 2); ?></div>
                            <div class="stat-label">Total Collected</div>
                        </div>
                    </div>
                    <div class="stat-card stat-gold">
                        <div class="stat-icon"><i class="fas fa-scale-balanced"></i></div>
                        <div class="stat-meta">
                            <div class="stat-number">₱<?php echo number_format($total_outstanding, 2); ?></div>
                            <div class="stat-label">Outstanding</div>
                        </div>
                    </div>
                    <div class="stat-card stat-blue">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-meta">
                            <div class="stat-number"><?php echo number_format($collection_rate, 1); ?>%</div>
                            <div class="stat-label">Collection Rate</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-section">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="search_input" placeholder="Search borrower name or email..." onkeyup="filterLoans()">
                    </div>
                    <div class="category-filters" id="categoryFilters">
                        <button type="button" class="category-btn active" data-category="all" onclick="setLoanCategory('all')">All</button>
                        <button type="button" class="category-btn" data-category="ongoing" onclick="setLoanCategory('ongoing')">Ongoing</button>
                        <button type="button" class="category-btn" data-category="pending_release" onclick="setLoanCategory('pending_release')">Pending Release</button>
                        <button type="button" class="category-btn" data-category="completed" onclick="setLoanCategory('completed')">Completed</button>
                    </div>
                </div>

                <?php if (empty($all_loans)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Loans Found</h3>
                        <p>There are no loan applications in the system yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="loan-table" id="loans_table">
                        <colgroup>
                            <col>
                            <col>
                            <col>
                            <col>
                            <col>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Application Date</th>
                                <th>Applicant</th>
                                <th>Loan Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_loans as $loan):
                                $purpose_attr = preg_replace('/\s+/', ' ', trim((string) ($loan['loan_purpose'] ?? '')));
                                $purpose_attr = function_exists('mb_substr')
                                    ? mb_substr($purpose_attr, 0, 400)
                                    : substr($purpose_attr, 0, 400);
                                ?>
                                <tr
                                    data-loan-id="<?php echo (int) $loan['id']; ?>"
                                    data-status="<?php echo $loan['status']; ?>"
                                    data-name="<?php echo strtolower($loan['full_name'] . ' ' . $loan['email']); ?>"
                                    data-applicant="<?php echo htmlspecialchars($loan['full_name']); ?>"
                                    data-email="<?php echo htmlspecialchars($loan['email']); ?>"
                                    data-application-date="<?php echo date('M d, Y', strtotime($loan['application_date'])); ?>"
                                    data-loan-amount="<?php echo number_format($loan['loan_amount'], 2); ?>"
                                    data-loan-term="<?php echo (int) $loan['loan_term']; ?>"
                                    data-monthly-payment="<?php echo number_format($loan['monthly_payment'] ?? 0, 2); ?>"
                                    data-total-paid="<?php echo number_format($loan['total_deducted'] ?? 0, 2); ?>"
                                    data-balance="<?php echo number_format($loan['balance_remaining'] ?? 0, 2); ?>"
                                    data-principal="<?php echo number_format($loan['principal_total'] ?? 0, 2); ?>"
                                    data-total-interest="<?php echo number_format((float) ($loan['total_interest'] ?? 0), 2); ?>"
                                    data-last-deduction="<?php echo $loan['last_deduction_date'] ? date('M d, Y', strtotime($loan['last_deduction_date'])) : '—'; ?>"
                                    data-employment-status="<?php echo htmlspecialchars($loan['employment_status']); ?>"
                                    data-payment-progress="<?php echo number_format($loan['payment_progress'], 0); ?>"
                                    data-previous-loan-id="<?php echo (int) ($loan['previous_loan_id'] ?? 0); ?>"
                                    data-offset-amount="<?php echo number_format((float) ($loan['offset_amount'] ?? 0), 2); ?>"
                                    data-released="<?php echo !empty($loan['released_at']) ? '1' : '0'; ?>"
                                    data-released-date="<?php echo !empty($loan['released_at']) ? date('M d, Y', strtotime($loan['released_at'])) : ''; ?>"
                                    data-net-pay="<?php echo number_format((float) ($loan['net_pay'] ?? 0), 2); ?>"
                                    data-school="<?php echo htmlspecialchars($loan['school_assignment'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-position="<?php echo htmlspecialchars($loan['position'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-salary-grade="<?php echo htmlspecialchars($loan['salary_grade'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-co-maker="<?php echo htmlspecialchars($loan['co_maker_full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-co-maker-email="<?php echo htmlspecialchars($loan['co_maker_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-co-maker-position="<?php echo htmlspecialchars($loan['co_maker_position'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-co-maker-school="<?php echo htmlspecialchars($loan['co_maker_school_assignment'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-co-maker-net-pay="<?php echo number_format((float) ($loan['co_maker_net_pay'] ?? 0), 2); ?>"
                                    data-co-maker-employment="<?php echo htmlspecialchars($loan['co_maker_employment_status'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-co-maker-dob="<?php echo !empty($loan['co_maker_date_of_birth']) ? date('M d, Y', strtotime($loan['co_maker_date_of_birth'])) : ''; ?>"
                                    data-co-maker-years="<?php echo htmlspecialchars((string) ($loan['co_maker_years_of_service'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-loan-purpose="<?php echo htmlspecialchars($purpose_attr, ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($loan['full_name']); ?></td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $status_label = $loan['status'];
                                        $is_released = !empty($loan['released_at']);
                                        switch($loan['status']) {
                                            case 'pending': $status_class = 'status-pending'; $status_label = 'Pending'; break;
                                            case 'approved':
                                                if (!$is_released) {
                                                    $status_class = 'status-pending';
                                                    $status_label = 'Pending release';
                                                } else {
                                                    $status_class = 'status-approved';
                                                    $status_label = 'Ongoing';
                                                }
                                                break;
                                            case 'rejected': $status_class = 'status-rejected'; $status_label = 'Rejected'; break;
                                            case 'completed': $status_class = 'status-completed'; $status_label = 'Completed'; break;
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="view-btn" data-view-details title="View loan details">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if ($loan['status'] === 'approved' && $is_released): ?>
                                            <button type="button" class="schedule-btn" data-view-schedule title="Payment schedule / Hulugan">
                                                <i class="fas fa-calendar-check"></i> Hulugan
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }

        let selectedLoanCategory = '';

        function setLoanCategory(category) {
            const normalized = String(category || '').toLowerCase();
            if (normalized === 'all') {
                selectedLoanCategory = '';
            } else {
            // Click same button again to clear category filter.
                selectedLoanCategory = selectedLoanCategory === normalized ? '' : normalized;
            }

            const btns = document.querySelectorAll('#categoryFilters .category-btn');
            btns.forEach(btn => {
                const isActive = (btn.dataset.category === 'all' && selectedLoanCategory === '')
                    || btn.dataset.category === selectedLoanCategory;
                btn.classList.toggle('active', isActive);
            });
            filterLoans();
        }

        function normalizeLoanCategory(status, isReleased) {
            const s = String(status || '').toLowerCase();
            if (s === 'approved' && isReleased) return 'ongoing';
            if (s === 'approved' && !isReleased) return 'pending_release';
            if (s === 'completed') return 'completed';
            return s;
        }

        function filterLoans() {
            const searchInput = document.getElementById('search_input').value.toLowerCase();
            const rows = document.querySelectorAll('#loans_table tbody tr');

            rows.forEach(row => {
                const name = row.dataset.name;
                const status = normalizeLoanCategory(row.dataset.status, row.dataset.released === '1');
                const nameMatch = !searchInput || name.includes(searchInput);
                const categoryMatch = !selectedLoanCategory || status === selectedLoanCategory;
                row.style.display = (nameMatch && categoryMatch) ? '' : 'none';
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('profileDropdown');
            const profileIcon = document.querySelector('.profile-trigger');
            
            if (!profileIcon.contains(event.target)) {
                dropdown.classList.remove('active');
            }
        });
    </script>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <div id="detailsModalOverlay" class="details-modal-overlay" aria-hidden="true">
        <div class="details-modal" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
            <div class="details-modal-header">
                <div class="details-modal-header-text">
                    <span id="detailsModalTitle">Loan Details</span>
                </div>
                <button type="button" class="details-close" id="detailsModalClose" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="details-modal-body" id="detailsModalBody">
                <div id="detailsGrid"></div>
            </div>
            <div class="details-modal-footer">
                <button type="button" class="details-footer-btn primary" id="detailsModalHulugan" style="display: none;">
                    <i class="fas fa-calendar-check"></i> Open payment schedule
                </button>
                <button type="button" class="details-footer-btn secondary" id="detailsModalCloseBottom">
                    Close
                </button>
            </div>
        </div>
    </div>

    <div id="scheduleModalOverlay" class="schedule-modal-overlay" aria-hidden="true">
        <div class="schedule-modal" role="dialog" aria-modal="true">
            <div class="schedule-modal-header">
                <span><i class="fas fa-calendar-check"></i> Payment Schedule / Hulugan</span>
                <button type="button" class="schedule-close" id="scheduleModalClose" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="schedule-modal-body">
                <div class="schedule-summary" id="scheduleSummary"></div>
                <div class="schedule-skipped-wrap" id="scheduleSkippedWrap" style="display: none;">
                    <div class="schedule-skipped-title"><i class="fas fa-pause-circle"></i> Skipped months (stop hulog – approved)</div>
                    <div id="scheduleSkipped" class="schedule-skipped-list"></div>
                </div>
                <div class="schedule-calendar-grid" id="scheduleCalendarGrid"></div>
            </div>
        </div>
    </div>

    <div id="receiptViewerOverlay" class="receipt-viewer-overlay" aria-hidden="true">
        <div class="receipt-viewer-modal" role="dialog" aria-modal="true" aria-label="Receipt Viewer">
            <div class="receipt-viewer-header">
                <span><i class="fas fa-receipt"></i> Receipt Viewer</span>
                <button type="button" class="receipt-viewer-close" id="receiptViewerClose" aria-label="Close receipt viewer">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <iframe id="receiptViewerFrame" class="receipt-viewer-frame" src="" title="Receipt preview"></iframe>
        </div>
    </div>

    <div id="confirmModalOverlay" class="confirm-modal-overlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <div class="confirm-modal-header">
                <i class="fas fa-exclamation-circle"></i>
                <span id="confirmModalTitle">Confirm</span>
            </div>
            <div class="confirm-modal-body" id="confirmModalMessage"></div>
            <div class="confirm-modal-actions">
                <button type="button" class="confirm-modal-cancel" id="confirmModalCancel">Cancel</button>
                <button type="button" class="confirm-modal-ok" id="confirmModalOk">Confirm, Skip month</button>
            </div>
        </div>
    </div>

    <div id="paymentModalOverlay" class="confirm-modal-overlay" aria-hidden="true">
        <div class="confirm-modal payment-modal" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
            <div class="confirm-modal-header">
                <i class="fas fa-money-bill-wave"></i>
                <span id="paymentModalTitle">Record Payment</span>
            </div>
            <div class="confirm-modal-body payment-modal-body">
                <div class="payment-modal-row payment-modal-row-full payment-modal-month-row">
                    <label style="font-weight: 600; color: #334155;">Month:</label>
                    <div id="paymentMonthLabel" style="color: #8b0000; font-weight: 600; padding: 0.5rem; background: #fff5f5; border-radius: 8px;"></div>
                </div>
                <div class="payment-modal-col">
                    <label style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155;">Payment Type:</label>
                    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                        <label style="display:flex; align-items:center; gap:0.4rem; font-weight:600; color:#334155;">
                            <input type="radio" name="paymentType" id="paymentTypeHalf" value="half" checked>
                            Half (15/30)
                        </label>
                        <label style="display:flex; align-items:center; gap:0.4rem; font-weight:600; color:#334155;">
                            <input type="radio" name="paymentType" id="paymentTypeFull" value="full">
                            Full month
                        </label>
                    </div>
                    <div style="margin-top:0.35rem; font-size:0.8rem; color:#64748b;">Tip: Choose Half for 15th/30th cutoff, or Full month if paying whole amount at once.</div>
                </div>
                <div class="payment-modal-col">
                    <label for="paymentAmount" style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155;">Amount (₱):</label>
                    <input type="number" id="paymentAmount" step="0.01" min="0.01" readonly style="width: 100%; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; background:#f8fafc;" placeholder="0.00">
                </div>
                <div class="payment-modal-col">
                    <label for="paymentDate" style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155;">Payment Date:</label>
                    <input type="date" id="paymentDate" style="width: 100%; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
                </div>
                <div class="payment-modal-col">
                    <label for="paymentReceipt" style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155;">Receipt (optional):</label>
                    <input type="file" id="paymentReceipt" accept="image/*,.pdf" name="receipt" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem;">
                    <div style="margin-top: 0.35rem; font-size: 0.8rem; color: #64748b;">Image or PDF, max 5MB</div>
                </div>
            </div>
            <div class="confirm-modal-actions">
                <button type="button" class="confirm-modal-cancel" id="paymentModalCancel">Cancel</button>
                <button type="button" class="confirm-modal-ok" id="paymentModalOk">Record Payment</button>
            </div>
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
        const detailsOverlay = document.getElementById('detailsModalOverlay');
        const detailsGrid = document.getElementById('detailsGrid');
        const detailsModalHulugan = document.getElementById('detailsModalHulugan');
        const detailsModalCloseBottom = document.getElementById('detailsModalCloseBottom');
        var detailsModalCurrentLoanId = null;

        function escapeHtmlDetails(s) {
            if (s == null) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function openDetailsModal(row) {
            const data = row.dataset;
            detailsModalCurrentLoanId = data.loanId || null;
            var loanId = data.loanId || '';

            var statusRaw = data.status || '';
            var released = data.released === '1';
            var statusLabel = '';
            var pillClass = 'other';
            if (statusRaw === 'approved' && released) {
                statusLabel = 'Ongoing';
                pillClass = 'ongoing';
            } else if (statusRaw === 'approved' && !released) {
                statusLabel = 'Pending release';
                pillClass = 'pending-release';
            } else if (statusRaw === 'completed') {
                statusLabel = 'Completed';
                pillClass = 'completed';
            } else {
                statusLabel = statusRaw ? statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1) : '—';
            }

            function parseMoneyDetails(v) {
                return parseFloat(String(v || '0').replace(/,/g, '')) || 0;
            }
            var loanAmt = parseMoneyDetails(data.loanAmount);
            var totalInt = parseMoneyDetails(data.totalInterest);
            var termM = parseInt(data.loanTerm || '0', 10) || 0;
            var aprNote = '';
            if (loanAmt > 0 && termM > 0 && totalInt >= 0) {
                var apr = (totalInt / loanAmt) / (termM / 12) * 100;
                if (Number.isFinite(apr)) {
                    aprNote = apr.toLocaleString('en-PH', { maximumFractionDigits: 2, minimumFractionDigits: 0 }) +
                        '% (estimated simple annual rate)';
                }
            }

            var progress = Math.min(100, Math.max(0, parseFloat(data.paymentProgress || '0') || 0));

            function kv(icon, label, value) {
                var v = (value != null && String(value).trim() !== '') ? escapeHtmlDetails(value) : '—';
                return '<div class="details-kv"><div class="details-kv-icon"><i class="fas ' + icon + '"></i></div><div class="details-kv-text"><div class="kv-label">' +
                    escapeHtmlDetails(label) + '</div><div class="kv-value">' + v + '</div></div></div>';
            }

            function finCard(icon, label, value, highlight) {
                return '<div class="details-fin-card' + (highlight ? ' highlight' : '') + '"><div class="dfc-label"><i class="fas ' + icon + '"></i> ' +
                    escapeHtmlDetails(label) + '</div><div class="dfc-value">' + escapeHtmlDetails(value) + '</div></div>';
            }

            var offsetHtml = '';
            var offsetNum = parseMoneyDetails(data.offsetAmount);
            if (data.previousLoanId && data.previousLoanId !== '0' && offsetNum > 0) {
                offsetHtml = '<div class="details-offset-note"><i class="fas fa-exchange-alt"></i> Re-apply: Remaining balance of ₱' + escapeHtmlDetails(data.offsetAmount) +
                    ' will be deducted from your reloan amount of ₱' + escapeHtmlDetails(data.loanAmount) + '.</div>';
            }

            var purpose = (data.loanPurpose || '').trim();
            var purposeBlock = purpose
                ? '<div class="details-purpose-box">' + escapeHtmlDetails(purpose) + '</div>'
                : '<div class="details-purpose-box" style="color:#94a3b8;font-style:italic;">No loan purpose on file.</div>';

            var cmName = (data.coMaker || '').trim();
            var cmEmail = (data.coMakerEmail || '').trim();
            var cmPos = (data.coMakerPosition || '').trim();
            var cmSchool = (data.coMakerSchool || '').trim();
            var cmEmp = (data.coMakerEmployment || '').trim();
            var cmDob = (data.coMakerDob || '').trim();
            var cmYears = (data.coMakerYears || '').trim();
            var cmNet = (data.coMakerNetPay || '').trim();
            var hasCoMaker = !!(cmName || cmEmail || cmPos || cmSchool || cmEmp || cmDob || cmYears || parseMoneyDetails(cmNet) > 0);
            var coMakerHtml = '';
            if (!hasCoMaker) {
                coMakerHtml = '<div class="details-purpose-box" style="border-style:solid;border-color:#e2e8f0;color:#94a3b8;font-style:italic;">No co-maker on file.</div>';
            } else {
                coMakerHtml = '<div class="details-comaker-panel"><div class="details-kv-grid">' +
                    kv('fa-user-friends', 'Full name', cmName || '—') +
                    kv('fa-envelope', 'Email', cmEmail) +
                    kv('fa-id-badge', 'Position', cmPos) +
                    kv('fa-school', 'School / assignment', cmSchool) +
                    kv('fa-user-tie', 'Employment status', cmEmp) +
                    kv('fa-wallet', 'Net pay (monthly)', cmNet ? ('₱' + cmNet) : '') +
                    kv('fa-calendar-day', 'Date of birth', cmDob) +
                    kv('fa-clock', 'Years of service', cmYears) +
                    '</div></div>';
            }

            var releasedDate = (data.releasedDate || '').trim();
            var aprSection = aprNote
                ? '<div class="details-section"><div class="details-section-title"><i class="fas fa-percent"></i> Estimated annual interest</div><div class="details-purpose-box" style="border-style:solid;border-color:#e2e8f0;">' +
                    escapeHtmlDetails(aprNote) + '</div></div>'
                : '';

            detailsGrid.innerHTML =
                '<div class="details-hero">' +
                '<div class="details-hero-top">' +
                '<div><div class="details-hero-name">' + escapeHtmlDetails(data.applicant || '') + '</div>' +
                '<div class="details-hero-email"><i class="fas fa-envelope"></i> ' + escapeHtmlDetails(data.email || '') + '</div></div>' +
                '<span class="details-status-pill ' + pillClass + '"><i class="fas fa-circle" style="font-size:0.45rem;vertical-align:middle;"></i> ' + escapeHtmlDetails(statusLabel) + '</span>' +
                '</div></div>' +

                '<div class="details-section">' +
                '<div class="details-section-title"><i class="fas fa-coins"></i> Financial summary</div>' +
                '<div class="details-fin-grid">' +
                finCard('fa-hand-holding-dollar', 'Principal (loan amount)', '₱' + (data.loanAmount || '0.00')) +
                finCard('fa-chart-line', 'Total interest', '₱' + (data.totalInterest || '0.00')) +
                finCard('fa-receipt', 'Total repayment', '₱' + (data.principal || '0.00'), true) +
                finCard('fa-calendar-day', 'Monthly payment', '₱' + (data.monthlyPayment || '0.00')) +
                '</div>' +
                '<div class="details-progress-block">' +
                '<div class="details-progress-head"><strong>Payment progress</strong><span>' + progress + '% paid</span></div>' +
                '<div class="details-progress-bar"><div class="details-progress-fill" style="width:' + progress + '%;"></div></div>' +
                '<div class="details-progress-foot">Total paid: <strong>₱' + escapeHtmlDetails(String(data.totalPaid || '')) + '</strong> · Remaining: <strong>₱' +
                escapeHtmlDetails(String(data.balance || '')) + '</strong> of <strong>₱' + escapeHtmlDetails(String(data.principal || '')) + '</strong> · Term: <strong>' +
                escapeHtmlDetails(String(data.loanTerm || '')) + (data.loanTerm ? ' months' : '') + '</strong></div>' +
                '</div>' +
                offsetHtml +
                '</div>' +

                '<div class="details-section">' +
                '<div class="details-section-title"><i class="fas fa-clock"></i> Timeline</div>' +
                '<div class="details-kv-grid">' +
                kv('fa-file-signature', 'Application date', data.applicationDate) +
                kv('fa-unlock', 'Release date', releasedDate || (statusRaw === 'approved' && !released ? 'Not yet released' : '—')) +
                kv('fa-arrow-down', 'Last deduction', data.lastDeduction) +
                kv('fa-calendar-plus', 'Next payment', 'Open payment schedule for due dates (15th / 30th)') +
                '</div></div>' +

                '<div class="details-section">' +
                '<div class="details-section-title"><i class="fas fa-briefcase"></i> Employment & school</div>' +
                '<div class="details-kv-grid">' +
                kv('fa-user-tie', 'Employment status', data.employmentStatus) +
                kv('fa-school', 'School / assignment', data.school) +
                kv('fa-id-badge', 'Position', data.position) +
                kv('fa-layer-group', 'Salary grade', data.salaryGrade) +
                kv('fa-wallet', 'Net pay (monthly)', '₱' + (data.netPay || '0.00')) +
                '</div></div>' +

                '<div class="details-section">' +
                '<div class="details-section-title"><i class="fas fa-tag"></i> Loan purpose & co-maker</div>' +
                '<div class="details-subsection-title"><i class="fas fa-file-lines"></i> Purpose</div>' +
                purposeBlock +
                '<div class="details-subsection-title"><i class="fas fa-people-arrows"></i> Co-maker details</div>' +
                coMakerHtml +
                '</div>' +
                aprSection;

            if (detailsModalHulugan) {
                if (statusRaw === 'approved' && released && loanId) {
                    detailsModalHulugan.style.display = '';
                } else {
                    detailsModalHulugan.style.display = 'none';
                }
            }

            detailsOverlay.classList.add('active');
            detailsOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeDetailsModal() {
            detailsOverlay.classList.remove('active');
            detailsOverlay.setAttribute('aria-hidden', 'true');
            detailsModalCurrentLoanId = null;
        }

        document.querySelectorAll('[data-view-details]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const row = btn.closest('tr');
                if (row) openDetailsModal(row);
            });
        });

        document.getElementById('detailsModalClose').addEventListener('click', closeDetailsModal);
        if (detailsModalCloseBottom) {
            detailsModalCloseBottom.addEventListener('click', closeDetailsModal);
        }
        if (detailsModalHulugan) {
            detailsModalHulugan.addEventListener('click', function () {
                if (!detailsModalCurrentLoanId) return;
                var id = detailsModalCurrentLoanId;
                closeDetailsModal();
                openScheduleModal(id);
            });
        }
        detailsOverlay.addEventListener('click', (event) => {
            if (event.target === detailsOverlay) {
                closeDetailsModal();
            }
        });

        const scheduleOverlay = document.getElementById('scheduleModalOverlay');
        const scheduleSummaryEl = document.getElementById('scheduleSummary');
        const scheduleCalendarGridEl = document.getElementById('scheduleCalendarGrid');
        const receiptViewerOverlay = document.getElementById('receiptViewerOverlay');
        const receiptViewerFrame = document.getElementById('receiptViewerFrame');
        var currentScheduleMonthlyAmount = 0;

        function openReceiptViewer(url) {
            if (!receiptViewerOverlay || !receiptViewerFrame || !url) return;
            receiptViewerFrame.src = url;
            receiptViewerOverlay.classList.add('active');
            receiptViewerOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeReceiptViewer() {
            if (!receiptViewerOverlay || !receiptViewerFrame) return;
            receiptViewerOverlay.classList.remove('active');
            receiptViewerOverlay.setAttribute('aria-hidden', 'true');
            receiptViewerFrame.src = '';
        }

        function generateCalendarHTML(ym, monthLabel, period, status, amountDue, amountPaid, loanId, paymentDates) {
            var parts = ym.split('-');
            var year = parseInt(parts[0]);
            var month = parseInt(parts[1]) - 1;
            var firstDay = new Date(year, month, 1);
            var lastDay = new Date(year, month + 1, 0);
            var startDayOfWeek = firstDay.getDay();
            var daysInMonth = lastDay.getDate();
            var isPaid = status === 'paid';
            var statusClass = isPaid ? 'paid' : 'unpaid';
            var badge = isPaid ? 'Nahulog na' : 'Huhulugan pa';
            var amountText = isPaid
                ? '₱' + (amountPaid || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })
                : '₱' + (amountDue || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
            var monthLabelEscaped = (monthLabel || '').replace(/"/g, '&quot;');
            var paymentDays = {};
            var firstCutoffPaid = 0;
            var secondCutoffPaid = 0;
            if (paymentDates && Array.isArray(paymentDates)) {
                paymentDates.forEach(function(p) {
                    if (p.ym === ym) {
                        var d = new Date(p.date);
                        var day = d.getDate();
                        if (day >= 1 && day <= daysInMonth) {
                            paymentDays[day] = true;
                            if (day <= 15) {
                                firstCutoffPaid += (p.amount || 0);
                            } else {
                                secondCutoffPaid += (p.amount || 0);
                            }
                        }
                    }
                });
            }
            var cutoffTotal = amountDue || 0;
            var halfBase = Math.round((cutoffTotal / 2) * 100) / 100;
            var firstPaidRounded = Math.round(firstCutoffPaid * 100) / 100;
            var secondPaidRounded = Math.round(secondCutoffPaid * 100) / 100;
            var remainingFirst = Math.round(Math.max(0, halfBase - firstPaidRounded) * 100) / 100;
            var remainingSecond = Math.round(Math.max(0, halfBase - secondPaidRounded) * 100) / 100;
            var remainingTotal = Math.round(Math.max(0, cutoffTotal - amountPaid) * 100) / 100;
            var receiptsForMonth = [];
            if (paymentDates && Array.isArray(paymentDates)) {
                paymentDates.forEach(function(p) {
                    if (p.ym === ym && p.receipt_filename) {
                        receiptsForMonth.push({ filename: p.receipt_filename, date: p.date, amount: p.amount });
                    }
                });
            }
            var receiptBtns = '';
            if (receiptsForMonth.length > 0) {
                receiptsForMonth.forEach(function(r, idx) {
                    var label = receiptsForMonth.length > 1 ? ('View Receipt ' + (idx + 1)) : 'View Receipt';
                    var receiptUrl = 'view_receipt.php?file=' + encodeURIComponent(r.filename);
                    receiptBtns += '<button type="button" class="schedule-receipt-btn" data-receipt-url="' + receiptUrl + '" title="View uploaded receipt in modal">' +
                        '<i class="fas fa-receipt"></i> ' + label + '</button>';
                });
            }
            var skipBtn = '';
            var paymentBtn = '';
            if (!isPaid && ym && remainingTotal > 0.01) {
                skipBtn = '<button type="button" class="schedule-skip-btn" data-skip-ym="' + ym + '" data-month-label="' + monthLabelEscaped + '" title="Stop hulog for this month (DepEd approved) – timeline will shift">Skip month</button>';
                paymentBtn = '<button type="button" class="schedule-payment-btn" ' +
                    'data-payment-ym="' + ym + '" ' +
                    'data-payment-month="' + monthLabelEscaped + '" ' +
                    'data-payment-monthly="' + (amountDue || 0) + '" ' +
                    'data-payment-half="' + halfBase + '" ' +
                    'data-payment-remaining-first="' + remainingFirst + '" ' +
                    'data-payment-remaining-second="' + remainingSecond + '" ' +
                    'data-payment-remaining-total="' + remainingTotal + '" ' +
                    'title="Record cash payment for this month">Record Payment</button>';
            }
            var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var calendarDays = '';
            for (var i = 0; i < 7; i++) {
                calendarDays += '<div class="schedule-calendar-day-header">' + days[i] + '</div>';
            }
            for (var i = 0; i < startDayOfWeek; i++) {
                calendarDays += '<div class="schedule-calendar-day other-month"></div>';
            }
            var secondCutoffDay = (daysInMonth >= 30) ? 30 : daysInMonth;
            for (var day = 1; day <= daysInMonth; day++) {
                var dayClass = 'schedule-calendar-day';
                if (paymentDays[day]) {
                    dayClass += ' has-payment';
                } else if (
                    !isPaid &&
                    (day === 15 || day === secondCutoffDay)
                ) {
                    dayClass += ' due-date';
                }
                calendarDays += '<div class="' + dayClass + '">' + day + '</div>';
            }
            var remainingCells = (42 - (startDayOfWeek + daysInMonth)) % 7;
            if (remainingCells > 0) {
                for (var i = 0; i < remainingCells; i++) {
                    calendarDays += '<div class="schedule-calendar-day other-month"></div>';
                }
            }
            var cutoffTotal = amountDue || 0;
            // clean 2-decimal halves (e.g. 1721.33 -> 860.67)
            var halfBase = Math.round((cutoffTotal / 2) * 100) / 100;
            var firstPaidRounded = Math.round(firstCutoffPaid * 100) / 100;
            var secondPaidRounded = Math.round(secondCutoffPaid * 100) / 100;
            var firstLabel = '15: ₱' + firstPaidRounded.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                ' / ₱' + halfBase.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            var secondLabel = '30: ₱' + secondPaidRounded.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                ' / ₱' + halfBase.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            return '<div class="schedule-calendar-month ' + statusClass + '">' +
                '<div class="schedule-calendar-header">' +
                '<div class="schedule-calendar-title">' + monthLabel + '</div>' +
                '<div class="schedule-calendar-period">Period #' + period + '</div>' +
                '</div>' +
                '<div class="schedule-calendar-grid-days">' + calendarDays + '</div>' +
                '<div class="schedule-calendar-footer">' +
                '<div class="schedule-calendar-footer-left">' +
                '<div class="schedule-calendar-footer-main">' +
                '<span class="schedule-calendar-status ' + statusClass + '-status">' + badge + '</span>' +
                '<span class="schedule-calendar-amount">' + amountText + '</span>' +
                '</div>' +
                '<div class="schedule-calendar-cutoffs">' + firstLabel + ' &nbsp;|&nbsp; ' + secondLabel + '</div>' +
                '</div>' +
                '<div class="schedule-calendar-actions">' + receiptBtns + paymentBtn + skipBtn + '</div>' +
                '</div>' +
                '</div>';
        }

        function openScheduleModal(loanId) {
            scheduleSummaryEl.innerHTML = '<span><i class="fas fa-spinner fa-spin"></i> Loading...</span>';
            scheduleCalendarGridEl.innerHTML = '';
            var skippedWrap = document.getElementById('scheduleSkippedWrap');
            var skippedEl = document.getElementById('scheduleSkipped');
            skippedWrap.style.display = 'none';
            scheduleOverlay.classList.add('active');
            scheduleOverlay.setAttribute('aria-hidden', 'false');

            fetch('get_loan_schedule.php?loan_id=' + encodeURIComponent(loanId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        scheduleSummaryEl.innerHTML = '<span class="text-danger">' + (data.error || 'Failed to load schedule.') + '</span>';
                        return;
                    }
                    const loan = data.loan;
                    const s = data.schedule || [];
                    const skipped = data.skipped_months || [];
                    const deductions = data.deductions || [];
                    const paidCount = s.filter(x => x.status === 'paid').length;
                    const unpaidCount = s.filter(x => x.status === 'unpaid').length;
                    const monthlyAmount = loan.monthly_payment || 0;
                    currentScheduleMonthlyAmount = parseFloat(monthlyAmount || 0) || 0;
                    const cutoffAmount = monthlyAmount / 2;
                    var summaryLine = '<strong>' + (loan.full_name || 'Borrower') + '</strong><br>' +
                        'Monthly: ₱' + monthlyAmount.toLocaleString('en-PH', { minimumFractionDigits: 2 }) +
                        ' (₱' + cutoffAmount.toLocaleString('en-PH', { minimumFractionDigits: 2 }) + ' every 15 & 30) &nbsp;|&nbsp; ' +
                        'Nahulog na: <strong>' + paidCount + '</strong> &nbsp;|&nbsp; Huhulugan pa: <strong>' + unpaidCount + '</strong>';
                    if (skipped.length > 0) {
                        summaryLine += ' <span style="color:#92400e;">(Timeline shifted – ' + skipped.length + ' month(s) skipped)</span>';
                    }
                    scheduleSummaryEl.innerHTML = summaryLine;
                    if (skipped.length > 0) {
                        skippedWrap.style.display = 'block';
                        skippedEl.innerHTML = skipped.map(function (m) {
                            return m.label + (m.notes ? ' — ' + m.notes : '');
                        }).join(', ');
                    }
                    scheduleCalendarGridEl.innerHTML = s.map(function (row) {
                        return generateCalendarHTML(
                            row.ym || '',
                            row.month_label || '',
                            row.period || 0,
                            row.status || 'unpaid',
                            row.amount_due || 0,
                            row.amount_paid || 0,
                            loanId,
                            deductions
                        );
                    }).join('');
                    var confirmOverlay = document.getElementById('confirmModalOverlay');
                    var confirmMessageEl = document.getElementById('confirmModalMessage');
                    var confirmTitleEl = document.getElementById('confirmModalTitle');
                    var pendingSkip = null;

                    function closeConfirmModal() {
                        confirmOverlay.classList.remove('active');
                        confirmOverlay.setAttribute('aria-hidden', 'true');
                        pendingSkip = null;
                    }

                    function runPendingSkip() {
                        if (!pendingSkip) return;
                        var btn = pendingSkip.btn;
                        var id = pendingSkip.loanId;
                        var ym = pendingSkip.ym;
                        closeConfirmModal();
                        btn.disabled = true;
                        var fd = new FormData();
                        fd.append('loan_id', id);
                        fd.append('skip_ym', ym);
                        fd.append('notes', 'Skip approved (DepEd)');
                        fetch('skip_loan_month.php', { method: 'POST', body: fd })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                if (res.success) {
                                    openScheduleModal(id);
                                } else {
                                    alert(res.error || 'Failed to skip month');
                                    btn.disabled = false;
                                }
                            })
                            .catch(function () {
                                alert('Unable to save.');
                                btn.disabled = false;
                            });
                    }

                    document.getElementById('confirmModalOk').onclick = runPendingSkip;
                    document.getElementById('confirmModalCancel').onclick = closeConfirmModal;
                    confirmOverlay.addEventListener('click', function (e) {
                        if (e.target === confirmOverlay) closeConfirmModal();
                    });

                    scheduleCalendarGridEl.querySelectorAll('.schedule-skip-btn').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var ym = btn.getAttribute('data-skip-ym');
                            var monthLabel = btn.getAttribute('data-month-label') || ym || 'this month';
                            if (!ym) return;
                            confirmTitleEl.textContent = 'Skip Month (Stop Payment)';
                            confirmMessageEl.innerHTML = 'Mark <span class="confirm-highlight">' + monthLabel + '</span> as skipped (stop payment)?<br><br>This month will be excluded from the payment schedule. The remaining payment months will shift forward by one month. Are you sure you want to proceed?';
                            pendingSkip = { btn: btn, loanId: loanId, ym: ym };
                            confirmOverlay.classList.add('active');
                            confirmOverlay.setAttribute('aria-hidden', 'false');
                        });
                    });

                    var paymentOverlay = document.getElementById('paymentModalOverlay');
                    var paymentMonthLabelEl = document.getElementById('paymentMonthLabel');
                    var paymentAmountEl = document.getElementById('paymentAmount');
                    var paymentDateEl = document.getElementById('paymentDate');
                    var paymentTypeHalfEl = document.getElementById('paymentTypeHalf');
                    var paymentTypeFullEl = document.getElementById('paymentTypeFull');
                    var pendingPayment = null;

                    function round2(n) {
                        return Math.round((parseFloat(n || 0)) * 100) / 100;
                    }

                    function pad2(n) {
                        return (n < 10 ? '0' : '') + n;
                    }

                    function setPaymentDateForType(ym, type) {
                        var parts = (ym || '').split('-'); // YYYY-MM
                        var year = parseInt(parts[0], 10);
                        var month = parseInt(parts[1], 10); // 1-12
                        if (isNaN(year) || isNaN(month) || month < 1 || month > 12) return;
                        var day = (type === 'full') ? 30 : 15;
                        var lastDay = new Date(year, month, 0).getDate();
                        if (day > lastDay) day = lastDay;
                        paymentDateEl.value = year + '-' + pad2(month) + '-' + pad2(day);
                    }

                    function syncPaymentAmountAndDate() {
                        if (!pendingPayment) return;
                        var rf = round2(pendingPayment.remainingFirst || 0);
                        var rs = round2(pendingPayment.remainingSecond || 0);
                        var rt = round2(pendingPayment.remainingTotal || 0);
                        var type = (paymentTypeFullEl && paymentTypeFullEl.checked) ? 'full' : 'half';
                        var dateDay = paymentDateEl.value ? parseInt(paymentDateEl.value.split('-')[2], 10) : null;
                        var defaultDay = (type === 'full') ? 30 : (rf > 0.01 ? 15 : 30);
                        var dayForAmount = (dateDay !== null && !isNaN(dateDay)) ? dateDay : defaultDay;
                        var newAmount = (type === 'full') ? rt : (dayForAmount <= 15 ? rf : rs);
                        paymentAmountEl.value = newAmount > 0 ? newAmount.toFixed(2) : '';
                        paymentAmountEl.setAttribute('max', newAmount > 0 ? newAmount : '');
                        setPaymentDateForType(pendingPayment.ym, type);
                        if (dateDay === null || isNaN(dateDay)) {
                            var parts = (pendingPayment.ym || '').split('-');
                            var yr = parseInt(parts[0], 10);
                            var mo = parseInt(parts[1], 10);
                            var lastDay = new Date(yr, mo, 0).getDate();
                            var d = Math.min(defaultDay, lastDay);
                            paymentDateEl.value = yr + '-' + pad2(mo) + '-' + pad2(d);
                        }
                    }

                    function closePaymentModal() {
                        paymentOverlay.classList.remove('active');
                        paymentOverlay.setAttribute('aria-hidden', 'true');
                        paymentAmountEl.value = '';
                        paymentDateEl.value = '';
                        var receiptEl = document.getElementById('paymentReceipt');
                        if (receiptEl) receiptEl.value = '';
                        pendingPayment = null;
                    }

                    function runPendingPayment() {
                        if (!pendingPayment) return;
                        var amount = parseFloat(paymentAmountEl.value);
                        var date = paymentDateEl.value;
                        if (!amount || amount <= 0) {
                            alert('Please enter a valid amount.');
                            return;
                        }
                        if (!date) {
                            alert('Please select a payment date.');
                            return;
                        }
                        var btn = pendingPayment.btn;
                        var id = pendingPayment.loanId;
                        var fd = new FormData();
                        fd.append('loan_id', id);
                        fd.append('amount', amount.toFixed(2));
                        fd.append('payment_date', date);
                        var receiptEl = document.getElementById('paymentReceipt');
                        if (receiptEl && receiptEl.files && receiptEl.files[0]) {
                            fd.append('receipt', receiptEl.files[0]);
                        }
                        closePaymentModal();
                        btn.disabled = true;
                        fetch('record_payment.php', { method: 'POST', body: fd })
                            .then(function (r) { return r.text(); })
                            .then(function (text) {
                                var res;
                                try { res = JSON.parse(text); } catch (e) {
                                    alert('Server error. Please check: ' + (text ? text.substring(0, 150) : 'Unknown'));
                                    btn.disabled = false;
                                    return;
                                }
                                if (res.success) {
                                    openScheduleModal(id);
                                } else {
                                    alert(res.error || 'Failed to record payment');
                                    btn.disabled = false;
                                }
                            })
                            .catch(function (err) {
                                alert('Unable to save payment. ' + (err && err.message ? err.message : ''));
                                btn.disabled = false;
                            });
                    }

                    document.getElementById('paymentModalOk').onclick = runPendingPayment;
                    document.getElementById('paymentModalCancel').onclick = closePaymentModal;
                    paymentOverlay.addEventListener('click', function (e) {
                        if (e.target === paymentOverlay) closePaymentModal();
                    });
                    if (paymentTypeHalfEl) paymentTypeHalfEl.addEventListener('change', syncPaymentAmountAndDate);
                    if (paymentTypeFullEl) paymentTypeFullEl.addEventListener('change', syncPaymentAmountAndDate);

                    scheduleCalendarGridEl.querySelectorAll('.schedule-payment-btn').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var ym = btn.getAttribute('data-payment-ym');
                            var monthLabel = btn.getAttribute('data-payment-month') || ym || '';
                            var raw = (btn.getAttribute('data-payment-amount') || '').toString().replace(/,/g, '').trim();
                            var defaultAmount = parseFloat(raw);
                            if (!isFinite(defaultAmount) || defaultAmount <= 0) defaultAmount = currentScheduleMonthlyAmount;
                            if (!ym) return;
                            paymentMonthLabelEl.textContent = monthLabel;
                            // Default: Half (15/30) for consistent cutoffs
                            if (paymentTypeHalfEl) paymentTypeHalfEl.checked = true;
                            if (paymentTypeFullEl) paymentTypeFullEl.checked = false;
                            pendingPayment = {
                                btn: btn, loanId: loanId, ym: ym, monthlyAmount: defaultAmount,
                                remainingFirst: parseFloat(btn.getAttribute('data-payment-remaining-first')) || 0,
                                remainingSecond: parseFloat(btn.getAttribute('data-payment-remaining-second')) || 0,
                                remainingTotal: parseFloat(btn.getAttribute('data-payment-remaining-total')) || 0
                            };
                            syncPaymentAmountAndDate();
                            paymentOverlay.classList.add('active');
                            paymentOverlay.setAttribute('aria-hidden', 'false');
                            setTimeout(function () { paymentDateEl.focus(); }, 100);
                        });
                    });
                })
                .catch(function () {
                    scheduleSummaryEl.innerHTML = '<span class="text-danger">Unable to load payment schedule.</span>';
                });
        }

        function closeScheduleModal() {
            scheduleOverlay.classList.remove('active');
            scheduleOverlay.setAttribute('aria-hidden', 'true');
            closeReceiptViewer();
        }

        document.querySelectorAll('[data-view-schedule]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const row = btn.closest('tr');
                const loanId = row && row.dataset.loanId;
                if (loanId) openScheduleModal(loanId);
            });
        });
        document.getElementById('scheduleModalClose').addEventListener('click', closeScheduleModal);
        scheduleOverlay.addEventListener('click', function (event) {
            if (event.target === scheduleOverlay) closeScheduleModal();
        });
        scheduleCalendarGridEl.addEventListener('click', function (event) {
            const btn = event.target.closest('.schedule-receipt-btn[data-receipt-url]');
            if (!btn) return;
            event.preventDefault();
            openReceiptViewer(btn.getAttribute('data-receipt-url'));
        });
        document.getElementById('receiptViewerClose').addEventListener('click', closeReceiptViewer);
        receiptViewerOverlay.addEventListener('click', function (event) {
            if (event.target === receiptViewerOverlay) closeReceiptViewer();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            if (receiptViewerOverlay.classList.contains('active')) {
                closeReceiptViewer();
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

<?php
$conn->close();
?>
