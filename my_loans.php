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

// Fetch approved/active loans for borrower with deductions summary
$loans = [];
$loan_stmt = $conn->prepare(
    "SELECT 
        l.id,
        l.loan_amount,
        l.loan_term,
        l.status,
        l.application_date,
        l.reviewed_at,
        l.released_at,
        l.monthly_payment,
        l.total_amount,
        l.total_interest,
        l.reviewed_by_name,
        l.reviewed_by_role,
        COALESCE(SUM(d.amount), 0) AS total_paid
     FROM loans l
     LEFT JOIN deductions d ON d.loan_id = l.id
     WHERE l.user_id = ? AND l.status IN ('approved', 'completed')
     GROUP BY l.id
     ORDER BY l.application_date DESC"
);
$loan_stmt->bind_param("i", $user_id);
$loan_stmt->execute();
$loan_result = $loan_stmt->get_result();
while ($row = $loan_result->fetch_assoc()) {
    $loans[] = $row;
}
$loan_stmt->close();

// Fetch deductions grouped by loan for payments view
$deductions_by_loan = [];
$deductions_stmt = $conn->prepare(
    "SELECT id, loan_id, deduction_date, amount, receipt_filename
     FROM deductions
     WHERE borrower_id = ?
     ORDER BY deduction_date ASC"
);
$deductions_stmt->bind_param("i", $user_id);
$deductions_stmt->execute();
$deductions_result = $deductions_stmt->get_result();
while ($row = $deductions_result->fetch_assoc()) {
    $loan_id = (int) $row['loan_id'];
    if (!isset($deductions_by_loan[$loan_id])) {
        $deductions_by_loan[$loan_id] = [];
    }
    $deductions_by_loan[$loan_id][] = [
        'id' => (int) $row['id'],
        'date' => $row['deduction_date'],
        'amount' => (float) $row['amount'],
        'receipt' => (string) ($row['receipt_filename'] ?? ''),
    ];
}
$deductions_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loans - DepEd Loan System</title>
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
            margin-top: 20px;
        }
        
        .content-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 1.5rem;
        }
        
        .loan-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1100px) {
            .loan-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .loan-grid { grid-template-columns: 1fr; }
        }
        
        .loan-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            border: 1px solid #eef1f4;
            border-left: 6px solid #8b0000;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .loan-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
        }
        
        .loan-status {
            display: inline-block;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 0.9rem;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .loan-amount {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2933;
            margin-bottom: 0.75rem;
        }
        .loan-meta-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.55rem;
            margin-bottom: 0.85rem;
        }
        .loan-meta-item {
            border: 1px solid #e7edf4;
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.48rem 0.6rem;
        }
        .loan-meta-label {
            display: block;
            font-size: 0.68rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            margin-bottom: 0.15rem;
        }
        .loan-meta-value {
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
        }

        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .info-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .info-btn {
            border: 1px solid #8b0000;
            background: #8b0000;
            color: #fff;
        }

        .info-btn:hover {
            background: #6d0000;
            box-shadow: 0 8px 16px rgba(139, 0, 0, 0.25);
        }

        .payments-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 3000;
            animation: paymentsFadeIn 0.2s ease;
        }

        @keyframes paymentsFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .payments-modal-overlay.active {
            display: flex;
        }

        .payments-modal {
            background: #ffffff;
            border-radius: 20px;
            width: min(480px, 95vw);
            max-height: 85vh;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(255, 255, 255, 0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: paymentsSlideIn 0.3s ease;
        }

        @keyframes paymentsSlideIn {
            from {
                opacity: 0;
                transform: scale(0.96) translateY(-10px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .payments-modal-header {
            padding: 1.25rem 1.5rem;
            background: linear-gradient(135deg, #7f1d1d 0%, #8b0000 50%, #b91c1c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-shrink: 0;
        }

        .payments-modal-header .payments-modal-title-wrap {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .payments-modal-header .payments-modal-title-wrap i {
            font-size: 1.25rem;
            opacity: 0.95;
        }

        .payments-modal-header span {
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.02em;
        }

        .payments-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: #fff;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .payments-close:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }

        .payments-modal-body {
            padding: 0;
            max-height: 60vh;
            overflow-y: auto;
            flex: 1;
        }

        .payments-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .payments-modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .payments-modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .payments-modal-body::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .payments-summary {
            padding: 1rem 1.5rem;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.875rem;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .payments-summary strong {
            color: #0f172a;
            font-weight: 600;
        }

        .payments-list {
            padding: 1rem 1.5rem 1.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
        }

        .payments-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem 0.9rem;
            border-radius: 12px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .payments-item:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .payments-item-date {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #475569;
            font-weight: 500;
        }

        .payments-item-date i {
            font-size: 0.85rem;
            color: #8b0000;
            opacity: 0.8;
        }

        .payments-item-amount {
            font-weight: 700;
            color: #0f172a;
            font-size: 1rem;
            letter-spacing: 0.02em;
        }
        .payments-item-right {
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .receipt-btn {
            border: 1px solid #dbe4f0;
            background: #f8fafc;
            color: #334155;
            border-radius: 999px;
            padding: 0.3rem 0.6rem;
            font-size: 0.76rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.32rem;
        }
        .receipt-btn:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .receipt-missing {
            font-size: 0.74rem;
            color: #94a3b8;
            font-weight: 600;
        }

        .payments-empty {
            text-align: center;
            padding: 2.5rem 1.5rem;
            color: #64748b;
            grid-column: 1 / -1;
        }

        .payments-empty i {
            font-size: 2.5rem;
            color: #cbd5e1;
            margin-bottom: 0.75rem;
            display: block;
        }

        .payments-empty .payments-empty-title {
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.25rem;
        }

        /* View Loan (info) modal */
        .info-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 3000;
        }
        .info-modal-overlay.active { display: flex; }
        .info-modal {
            background: #fff;
            border-radius: 20px;
            width: min(860px, 96vw);
            max-height: 85vh;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
        }
        .info-modal-header {
            padding: 1.25rem 1.5rem;
            background: linear-gradient(135deg, #7f1d1d 0%, #8b0000 50%, #b91c1c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .info-modal-header span { font-weight: 700; font-size: 1.1rem; }
        .info-modal-close {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(255,255,255,0.15);
            border: none;
            color: #fff;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .info-modal-close:hover { background: rgba(255,255,255,0.25); }
        .info-modal-body {
            padding: 1.25rem 1.5rem;
            overflow-y: auto;
        }
        .info-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem 1rem;
            margin-bottom: 0.9rem;
        }
        .info-detail-item {
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-radius: 10px;
            padding: 0.6rem 0.75rem;
            font-size: 0.88rem;
            color: #475569;
        }
        .info-detail-item strong {
            display: block;
            font-size: 0.78rem;
            color: #8b0000;
            margin-bottom: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .loan-hero {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            padding: 0.9rem 1rem;
            margin-bottom: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .loan-hero-title {
            font-weight: 800;
            color: #111827;
            font-size: 1.05rem;
        }
        .loan-hero-sub {
            color: #6b7280;
            font-size: 0.85rem;
            margin-top: 0.15rem;
        }
        .loan-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            color: #334155;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            white-space: nowrap;
        }
        .loan-chip.status-active { background: #dcfce7; border-color: #bbf7d0; color: #166534; }
        .loan-chip.status-pending { background: #fef3c7; border-color: #fde68a; color: #92400e; }
        .loan-chip.status-completed { background: #e2e8f0; border-color: #cbd5e1; color: #334155; }
        .info-section-title {
            margin: 0.2rem 0 0.55rem;
            color: #7f1d1d;
            font-size: 0.83rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .loan-payments-inline {
            border-top: 1px solid #e5e7eb;
            padding-top: 0.75rem;
        }
        .loan-payments-total {
            margin: 0 0 0.65rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.84rem;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 0.28rem 0.62rem;
            font-weight: 600;
        }
        .loan-payments-total strong {
            color: #0f172a;
            font-size: 0.86rem;
        }
        .loan-payments-inline-list {
            display: grid;
            gap: 0.5rem;
        }
        .loan-payment-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.55rem 0.7rem;
            background: #fff;
        }
        .loan-payment-left {
            font-size: 0.84rem;
            color: #475569;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .loan-payment-right {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
        }
        .loan-payment-amt {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.86rem;
        }
        .inline-empty {
            text-align: center;
            color: #94a3b8;
            padding: 0.9rem 0.5rem;
            border: 1px dashed #dbe3ee;
            border-radius: 10px;
            background: #f8fafc;
            font-size: 0.83rem;
        }
        .receipt-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 3100;
        }
        .receipt-modal-overlay.active { display: flex; }
        .receipt-modal {
            width: min(980px, 96vw);
            height: min(86vh, 760px);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .receipt-modal-header {
            padding: 0.85rem 1rem;
            background: linear-gradient(135deg, #7f1d1d 0%, #8b0000 50%, #b91c1c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .receipt-modal-title {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.96rem;
            font-weight: 700;
        }
        .receipt-modal-close {
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
        .receipt-modal-close:hover { background: rgba(255,255,255,0.26); }
        .receipt-modal-body {
            flex: 1;
            background: #e2e8f0;
        }
        .receipt-frame {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
        @media (max-width: 480px) {
            .info-details-grid { grid-template-columns: 1fr; }
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
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
            
            .welcome-message {
                font-size: 1rem;
            }

            .payments-list {
                grid-template-columns: 1fr;
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

            .loan-grid {
                gap: 0.85rem;
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
                    <span><i class="fas fa-credit-card"></i> My Loans</span>
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
                    <a href="my_loans.php" class="sidebar-link active">
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
                    <a href="loan_history.php" class="sidebar-link">
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
                <h2 class="section-title">My Loans</h2>
                <?php if (!empty($loans)): ?>
                    <div class="loan-grid">
                        <?php foreach ($loans as $loan): ?>
                            <?php
                                $status = strtolower($loan['status'] ?? '');
                                $is_released = !empty($loan['released_at']);
                                if ($status === 'completed') {
                                    $status_label = 'Completed';
                                    $status_class = 'status-completed';
                                } elseif ($status === 'approved' && !$is_released) {
                                    $status_label = 'Pending release';
                                    $status_class = 'status-pending';
                                } else {
                                    $status_label = 'Active';
                                    $status_class = 'status-active';
                                }
                                $applied_at = !empty($loan['application_date']) ? date('M d, Y', strtotime($loan['application_date'])) : 'N/A';
                                $approved_at = !empty($loan['released_at']) ? date('M d, Y', strtotime($loan['released_at'])) : (!empty($loan['reviewed_at']) ? date('M d, Y', strtotime($loan['reviewed_at'])) : 'N/A');
                                $monthly_payment = (float) ($loan['monthly_payment'] ?? 0);
                                $total_amount = (float) (($loan['total_amount'] ?? 0) ?: ($loan['loan_amount'] ?? 0));
                                $total_interest = (float) ($loan['total_interest'] ?? 0);
                                $total_paid = (float) ($loan['total_paid'] ?? 0);
                                $remaining_balance = max($total_amount - $total_paid, 0);
                                $approver_name = $loan['reviewed_by_name'] ?? 'N/A';
                                $approver_role = $loan['reviewed_by_role'] ?? '';
                                $approver_label = $approver_name !== 'N/A' ? $approver_name . ($approver_role ? " ({$approver_role})" : '') : 'N/A';
                            ?>
                            <div class="loan-card"
                                data-loan-id="<?php echo (int) $loan['id']; ?>"
                                data-status-label="<?php echo htmlspecialchars($status_label); ?>"
                                data-status-class="<?php echo htmlspecialchars($status_class); ?>"
                                data-loan-amount="<?php echo number_format((float) $loan['loan_amount'], 2); ?>"
                                data-term="<?php echo (int) $loan['loan_term']; ?>"
                                data-applied="<?php echo htmlspecialchars($applied_at); ?>"
                                data-approved="<?php echo htmlspecialchars($approved_at); ?>"
                                data-monthly="<?php echo number_format($monthly_payment, 2); ?>"
                                data-total-interest="<?php echo number_format($total_interest, 2); ?>"
                                data-total-paid="<?php echo number_format($total_paid, 2); ?>"
                                data-remaining="<?php echo number_format($remaining_balance, 2); ?>"
                                data-approver="<?php echo htmlspecialchars($approver_label); ?>">
                                <span class="loan-status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                <div class="loan-amount">₱<?php echo number_format((float) $loan['loan_amount'], 2); ?></div>
                                <div class="loan-meta-row">
                                    <div class="loan-meta-item">
                                        <span class="loan-meta-label">Total Paid</span>
                                        <span class="loan-meta-value">₱<?php echo number_format($total_paid, 2); ?></span>
                                    </div>
                                    <div class="loan-meta-item">
                                        <span class="loan-meta-label">Remaining</span>
                                        <span class="loan-meta-value">₱<?php echo number_format($remaining_balance, 2); ?></span>
                                    </div>
                                </div>
                                <?php if ($status === 'approved' && !$is_released): ?>
                                <p class="pending-release-note" style="font-size: 0.85rem; color: #856404; background: #fff3cd; padding: 0.5rem 0.75rem; border-radius: 8px; margin-bottom: 0.75rem;">Please go to the office with your requirements (in two (2) copies—Provident Fund Application Form, Letter Request to SDS, Original Payslip, Photocopy of Latest Payslip, DepED/Government ID with COE from HR, Co-borrowers' ID). Payments will be available after release.</p>
                                <?php endif; ?>
                                <div class="card-actions">
                                    <button type="button" class="info-btn" data-open-info aria-label="View loan details">
                                        <i class="fas fa-info-circle"></i> View Loan
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-credit-card"></i>
                        <h3>No loans found</h3>
                        <p>You don't have any active loans at the moment.</p>
                        <p style="margin-top: 1rem;">
                            <a href="apply_loan.php" style="color: #8b0000; text-decoration: none; font-weight: 600;">
                                <i class="fas fa-plus-circle"></i> Apply for your first loan
                            </a>
                        </p>
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
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileIcon = document.querySelector('.profile-trigger');
            const dropdown = document.getElementById('profileDropdown');
            
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

    <div id="infoModalOverlay" class="info-modal-overlay" aria-hidden="true">
        <div class="info-modal" role="dialog" aria-modal="true" aria-labelledby="infoModalTitle">
            <div class="info-modal-header">
                <span id="infoModalTitle">Loan Details</span>
                <button type="button" class="info-modal-close" id="infoModalClose" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="info-modal-body">
                <div id="infoDetailsGrid"></div>
            </div>
        </div>
    </div>

    <div id="receiptModalOverlay" class="receipt-modal-overlay" aria-hidden="true">
        <div class="receipt-modal" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
            <div class="receipt-modal-header">
                <div id="receiptModalTitle" class="receipt-modal-title">
                    <i class="fas fa-file-invoice"></i> Payment Receipt
                </div>
                <button type="button" class="receipt-modal-close" id="receiptModalClose" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="receipt-modal-body">
                <iframe id="receiptModalFrame" class="receipt-frame" src="" title="Receipt Preview"></iframe>
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
        const loanPayments = <?php echo json_encode($deductions_by_loan); ?>;
        const infoModalOverlay = document.getElementById('infoModalOverlay');
        const infoDetailsGrid = document.getElementById('infoDetailsGrid');
        const infoModalTitle = document.getElementById('infoModalTitle');
        function formatDateLabel(value) {
            if (!value) return '—';
            const date = new Date(value);
            if (!Number.isNaN(date.getTime())) {
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            return String(value);
        }

        function openInfoModal(card) {
            const d = card.dataset;
            infoModalTitle.textContent = 'Loan Details';
            const statusClass = d.statusClass || '';
            const statusChipClass = statusClass === 'status-completed' ? 'status-completed' : (statusClass === 'status-pending' ? 'status-pending' : 'status-active');
            const summaryItems = [
                ['Term', (d.term || '') + ' months'],
                ['Applied', d.applied || '—'],
                ['Approved', d.approved || '—'],
                ['Monthly Payment', '₱' + (d.monthly || '0.00')],
                ['Total Interest', '₱' + (d.totalInterest || '0.00')],
                ['Total Paid', '₱' + (d.totalPaid || '0.00')],
                ['Remaining Balance', '₱' + (d.remaining || '0.00')],
                ['Approved By', d.approver || '—']
            ];
            const summaryHtml = summaryItems.map(([label, value]) =>
                '<div class="info-detail-item"><strong>' + label + '</strong>' + (value || '—') + '</div>'
            ).join('');
            const paymentsInline = loanPayments[d.loanId] || [];
            const paymentRowsHtml = paymentsInline.length
                ? paymentsInline.map((p) => {
                    const amount = Number(p.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
                    const receipt = p.receipt && String(p.receipt).trim() !== ''
                        ? `<button type="button" class="receipt-btn" data-receipt-file="${encodeURIComponent(p.receipt)}"><i class="fas fa-file-invoice"></i> Receipt</button>`
                        : '<span class="receipt-missing">No receipt</span>';
                    return `
                        <div class="loan-payment-row">
                            <span class="loan-payment-left"><i class="fas fa-calendar-day"></i>${formatDateLabel(p.date)}</span>
                            <span class="loan-payment-right">
                                <span class="loan-payment-amt">₱${amount}</span>
                                ${receipt}
                            </span>
                        </div>
                    `;
                }).join('')
                : '<div class="inline-empty">No monthly payments posted yet for this loan.</div>';

            infoDetailsGrid.innerHTML = `
                <div class="loan-hero">
                    <div>
                        <div class="loan-hero-title">Loan Overview</div>
                        <div class="loan-hero-sub">Amount: ₱${d.loanAmount || '0.00'}</div>
                    </div>
                    <span class="loan-chip ${statusChipClass}"><i class="fas fa-shield-alt"></i> ${d.statusLabel || 'Active'}</span>
                </div>
                <div class="info-section-title"><i class="fas fa-chart-bar"></i>Loan Summary</div>
                <div class="info-details-grid">${summaryHtml}</div>
                <div class="loan-payments-inline">
                    <div class="info-section-title"><i class="fas fa-receipt"></i>Monthly Payment Receipts</div>
                    <div class="loan-payments-total"><i class="fas fa-coins"></i> Total Paid: <strong>₱${d.totalPaid || '0.00'}</strong></div>
                    <div class="loan-payments-inline-list">${paymentRowsHtml}</div>
                </div>
            `;
            infoModalOverlay.classList.add('active');
            infoModalOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeInfoModal() {
            infoModalOverlay.classList.remove('active');
            infoModalOverlay.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('[data-open-info]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.loan-card');
                if (card) openInfoModal(card);
            });
        });
        document.getElementById('infoModalClose').addEventListener('click', closeInfoModal);
        infoModalOverlay.addEventListener('click', (event) => {
            if (event.target === infoModalOverlay) closeInfoModal();
        });

        const receiptModalOverlay = document.getElementById('receiptModalOverlay');
        const receiptModalFrame = document.getElementById('receiptModalFrame');
        function openReceiptModal(encodedFile) {
            if (!encodedFile) return;
            receiptModalFrame.src = 'view_receipt.php?file=' + encodedFile;
            receiptModalOverlay.classList.add('active');
            receiptModalOverlay.setAttribute('aria-hidden', 'false');
        }
        function closeReceiptModal() {
            receiptModalOverlay.classList.remove('active');
            receiptModalOverlay.setAttribute('aria-hidden', 'true');
            receiptModalFrame.src = '';
        }
        document.getElementById('receiptModalClose').addEventListener('click', closeReceiptModal);
        receiptModalOverlay.addEventListener('click', (event) => {
            if (event.target === receiptModalOverlay) closeReceiptModal();
        });
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-receipt-file]');
            if (!btn) return;
            openReceiptModal(btn.getAttribute('data-receipt-file'));
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
