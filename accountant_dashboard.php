<?php
require_once 'config.php';

// Check if user is logged in and is accountant
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

// Check if user is accountant
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$is_accounting) {
    if (($user['role'] ?? '') === 'admin' || $user['username'] === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: borrower_dashboard.php");
    }
    exit();
}

// Get comprehensive statistics
$total_users_sql = "SELECT COUNT(*) as total FROM users";
$stmt = $conn->prepare($total_users_sql);
$stmt->execute();
$total_users = $stmt->get_result()->fetch_assoc()['total'];

$total_loans_sql = "SELECT COUNT(*) as total FROM loans";
$stmt = $conn->prepare($total_loans_sql);
$stmt->execute();
$total_loans = $stmt->get_result()->fetch_assoc()['total'];

$pending_loans_sql = "SELECT COUNT(*) as total FROM loans WHERE status = 'pending'";
$stmt = $conn->prepare($pending_loans_sql);
$stmt->execute();
$pending_loans = $stmt->get_result()->fetch_assoc()['total'];
$pending_loans_count = (int) $pending_loans;

$approved_loans_sql = "SELECT COUNT(*) as total FROM loans WHERE status = 'approved'";
$stmt = $conn->prepare($approved_loans_sql);
$stmt->execute();
$approved_loans = $stmt->get_result()->fetch_assoc()['total'];

$rejected_loans_sql = "SELECT COUNT(*) as total FROM loans WHERE status = 'rejected'";
$stmt = $conn->prepare($rejected_loans_sql);
$stmt->execute();
$rejected_loans = (int) $stmt->get_result()->fetch_assoc()['total'];

$total_amount_sql = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM loans WHERE status IN ('approved', 'completed')";
$stmt = $conn->prepare($total_amount_sql);
$stmt->execute();
$total_amount = $stmt->get_result()->fetch_assoc()['total'];

// Get monthly loan statistics for chart
$monthly_stats_sql = "SELECT DATE_FORMAT(application_date, '%Y-%m') as month, COUNT(*) as count, COALESCE(SUM(loan_amount), 0) as amount FROM loans WHERE application_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(application_date, '%Y-%m') ORDER BY month";
$stmt = $conn->prepare($monthly_stats_sql);
$stmt->execute();
$monthly_stats = $stmt->get_result();

// Get recent users
$recent_users_sql = "SELECT id, username, email, full_name, created_at FROM users ORDER BY created_at DESC LIMIT 5";
$stmt = $conn->prepare($recent_users_sql);
$stmt->execute();
$recent_users = $stmt->get_result();

// Get recent loan applications
$recent_applications_sql = "SELECT l.loan_amount, l.loan_purpose, l.status, l.application_date, u.full_name FROM loans l JOIN users u ON l.user_id = u.id ORDER BY l.application_date DESC LIMIT 5";
$stmt = $conn->prepare($recent_applications_sql);
$stmt->execute();
$recent_applications = $stmt->get_result();

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accountant Dashboard - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #ffffff;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-panel {
            position: fixed;
            top: 70px;
            right: 20px;
            width: 350px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #eadfe2;
            box-shadow: 0 12px 30px rgba(17, 24, 39, 0.12);
            z-index: 1002;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-panel.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid #f0f0f0;
            font-weight: 600;
            color: #1d2433;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);
        }

        .notification-title-row {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            color: #8b0000;
            font-size: 0.95rem;
        }

        .notification-actions {
            margin: 0;
        }

        .notification-mark-btn {
            border: 1px solid #eadfe2;
            background: #ffffff;
            color: #8b0000;
            font-size: 0.75rem;
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
        }

        .notification-mark-btn:hover {
            background: #fff5f5;
            box-shadow: 0 6px 12px rgba(139, 0, 0, 0.12);
        }

        .notification-item {
            padding: 0.95rem 1.2rem;
            border-bottom: 1px solid #f5f2f3;
            transition: background 0.3s ease, transform 0.3s ease;
            position: relative;
        }

        .notification-item:hover {
            background: #fff9f9;
            transform: translateX(-2px);
        }

        .notification-item::before {
            content: "";
            position: absolute;
            left: 0;
            top: 18%;
            height: 64%;
            width: 3px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            border-radius: 0 3px 3px 0;
            opacity: 0.65;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-title {
            font-weight: 600;
            color: #1f2933;
            margin-bottom: 0.2rem;
        }

        .notification-message {
            color: #4b5563;
            font-size: 0.88rem;
            line-height: 1.4;
        }

        .notification-time {
            margin-top: 0.4rem;
            font-size: 0.75rem;
            color: #8d97a3;
        }

        .notification-empty {
            padding: 1.5rem;
            text-align: center;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        
        .container {
            display: flex;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
        }
        
        .sidebar {
            width: 192px; /* 80% of 240px */
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
            padding: 1rem;
            margin-left: 192px; /* 80% of 250px */
            margin-top: 20px;
        }
        
        .content-section {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }
        
        .section-title {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .section-title i {
            color: #8b0000;
        }
        
        .chart-container {
            position: relative;
            height: 200px;
            margin-bottom: 1rem;
        }
        
        .chart-sample-note {
            font-size: 0.9rem;
            color: #6b7280;
            margin: -0.5rem 0 0.75rem 0;
            padding: 0.5rem 0;
        }
        
        .chart-sample-note i {
            margin-right: 0.35rem;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .status-pending {
            background: #ffc107;
        }
        
        .status-approved {
            background: #28a745;
        }
        
        .status-rejected {
            background: #dc3545;
        }
        
        .tooltip {
            position: relative;
            cursor: help;
        }
        
        .tooltip::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 0.5rem 0.8rem;
            border-radius: 6px;
            font-size: 0.85rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .tooltip:hover::after {
            opacity: 1;
            visibility: visible;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-icon {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-number {
            font-size: 1.6rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.3rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .stat-trend {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            font-size: 0.7rem;
            padding: 0.1rem 0.3rem;
            border-radius: 8px;
            font-weight: 600;
        }
        
        .trend-up {
            background: #d4edda;
            color: #155724;
        }
        
        .trend-down {
            background: #f8d7da;
            color: #721c24;
        }
        
        .trend-neutral {
            background: #fff3cd;
            color: #856404;
        }

        .accountant-quick-actions-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .accountant-qa-card {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            text-decoration: none;
            color: #374151;
            font-weight: 600;
            font-size: 0.9rem;
            transition: box-shadow 0.2s, border-color 0.2s, color 0.2s;
        }

        .accountant-qa-card:hover {
            border-color: #8b0000;
            box-shadow: 0 4px 14px rgba(139, 0, 0, 0.12);
            color: #8b0000;
        }

        .accountant-qa-card i {
            color: #8b0000;
            opacity: 0.9;
        }

        .accountant-qa-card-accent {
            background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);
            border-color: rgba(139, 0, 0, 0.28);
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .user-table th,
        .user-table td {
            padding: 0.5rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.85rem;
        }
        
        .user-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .user-table tr:hover {
            background: #f8f9fa;
        }

        .table-scroll-wrap {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        
        .action-btn {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
        }
        
        .action-btn.secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .user-table {
                font-size: 0.9rem;
            }
            
            .user-table th,
            .user-table td {
                padding: 0.5rem;
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

            .table-scroll-wrap {
                margin-top: 0.5rem;
            }

            .user-table {
                min-width: 640px;
                table-layout: auto;
            }

            .user-table th,
            .user-table td {
                white-space: nowrap;
                word-break: normal;
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
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> Accountant</span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-shield-alt"></i> Accountant Access</span>
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
                    <a href="accountant_dashboard.php" class="sidebar-link active">
                        <span class="sidebar-icon"><i class="fas fa-tachometer-alt"></i></span>
                        Accountant Dashboard
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
                <li class="sidebar-item">
                    <a href="accountant_manage_users.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-users"></i></span>
                        Manage Users
                    </a>
                </li>
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
                    <div class="sidebar-user-role">Accountant</div>
                </div>
            </div>
            
        </aside>
        
        <main class="main-content">
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-tachometer-alt"></i> Accountant Dashboard Overview</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-trend trend-up">Active</div>
                        <div class="stat-icon tooltip" data-tooltip="Total registered users"><i class="fas fa-users"></i></div>
                        <div class="stat-number"><?php echo $total_users; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-trend trend-up">Active</div>
                        <div class="stat-icon tooltip" data-tooltip="Total loan applications"><i class="fas fa-clipboard-list"></i></div>
                        <div class="stat-number"><?php echo $total_loans; ?></div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-trend trend-neutral">Pending</div>
                        <div class="stat-icon tooltip" data-tooltip="Applications awaiting review"><i class="fas fa-clock"></i></div>
                        <div class="stat-number"><?php echo $pending_loans; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-trend trend-up">Total</div>
                        <div class="stat-icon tooltip" data-tooltip="Total amount disbursed"><i class="fas fa-peso-sign"></i></div>
                        <div class="stat-number">₱<?php echo number_format($total_amount, 2); ?></div>
                        <div class="stat-label">Total Disbursed</div>
                    </div>
                </div>
            </div>

            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-link"></i> Quick access</h2>
                <div class="accountant-quick-actions-grid">
                    <a href="loan_applications.php" class="accountant-qa-card">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Loan applications</span>
                    </a>
                    <a href="all_loans.php" class="accountant-qa-card">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>All loans</span>
                    </a>
                    <a href="existing_loans.php" class="accountant-qa-card accountant-qa-card-accent">
                        <i class="fas fa-landmark"></i>
                        <span>Existing loans</span>
                    </a>
                    <a href="accountant_manage_users.php" class="accountant-qa-card">
                        <i class="fas fa-users"></i>
                        <span>Manage users</span>
                    </a>
                    <a href="admin_reports.php" class="accountant-qa-card">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="content-section">
                    <h2 class="section-title"><i class="fas fa-chart-line"></i> Loan Trends</h2>
                    <p id="trendSampleNote" class="chart-sample-note" style="display: none;"><i class="fas fa-info-circle"></i> Showing sample data. Chart will display real data once loan applications exist.</p>
                    <div class="chart-container">
                        <canvas id="loanTrendChart"></canvas>
                        <div id="noTrendData" style="display: none; text-align: center; padding: 3rem; color: #666;">
                            <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                            <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No loan trends available</p>
                            <p style="font-size: 0.9rem;">Loan application data will appear here once users start applying for loans.</p>
                        </div>
                    </div>
                </div>
                
                <div class="content-section">
                    <h2 class="section-title"><i class="fas fa-chart-pie"></i> Loan Status Distribution</h2>
                    <p id="statusSampleNote" class="chart-sample-note" style="display: none;"><i class="fas fa-info-circle"></i> Showing sample data. Chart will display real data once loan applications exist.</p>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                        <div id="noStatusData" style="display: none; text-align: center; padding: 3rem; color: #666;">
                            <i class="fas fa-chart-pie" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                            <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No loan status data available</p>
                            <p style="font-size: 0.9rem;">Loan status distribution will appear here once applications are received.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="content-section">
                    <h2 class="section-title"><i class="fas fa-users"></i> Recent User Registrations</h2>
                    <div class="table-scroll-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Registration Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $recent_users->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                
                <div class="content-section">
                    <h2 class="section-title"><i class="fas fa-clipboard-list"></i> Recent Loan Applications</h2>
                    <div class="table-scroll-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($app = $recent_applications->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($app['full_name']); ?></td>
                                <td>₱<?php echo number_format($app['loan_amount'], 2); ?></td>
                                <td>
                                    <span class="status-indicator status-<?php echo $app['status']; ?>"></span>
                                    <?php echo ucfirst($app['status']); ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
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

        document.addEventListener('click', function(event) {
            if (event.target && event.target.id === 'profileModalOverlay') {
                closeProfileModal();
            }
        });
    </script>

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
        
        // Initialize Charts
        // Loan Trend Chart
        const trendCtx = document.getElementById('loanTrendChart').getContext('2d');
        const noTrendData = document.getElementById('noTrendData');
        const months = [];
        const loanCounts = [];
        const loanAmounts = [];
        
        <?php 
        $monthly_stats->data_seek(0);
        while ($month = $monthly_stats->fetch_assoc()): ?>
            months.push('<?php echo date('M Y', strtotime($month['month'] . '-01')); ?>');
            loanCounts.push(<?php echo $month['count']; ?>);
            loanAmounts.push(<?php echo $month['amount']; ?>);
        <?php endwhile; ?>
        
        const hasTrendData = loanCounts.length > 0 && loanCounts.some(count => count > 0);
        
        const trendSampleNote = document.getElementById('trendSampleNote');
        if (hasTrendData) {
            noTrendData.style.display = 'none';
            if (trendSampleNote) trendSampleNote.style.display = 'none';
        } else {
            noTrendData.style.display = 'none';
            if (trendSampleNote) trendSampleNote.style.display = 'block';
        }
        
        // Real data when available; otherwise sample/placeholder
        const trendLabels = hasTrendData && months.length > 0 ? months : ['Jan 2024', 'Feb 2024', 'Mar 2024', 'Apr 2024', 'May 2024', 'Jun 2024'];
        const trendData = hasTrendData ? loanCounts : [3, 5, 4, 7, 6, 8];
        
        const loanTrendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: hasTrendData ? 'Number of Loans' : 'Number of Loans (Sample)',
                    data: trendData,
                    borderColor: 'rgba(139, 0, 0, 1)',
                    backgroundColor: 'rgba(139, 0, 0, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        display: true
                    },
                    x: {
                        display: true
                    }
                }
            }
        });
        
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const noStatusData = document.getElementById('noStatusData');
        
        const pendingLoans = <?php echo $pending_loans ?? 0; ?>;
        const approvedLoans = <?php echo $approved_loans ?? 0; ?>;
        const rejectedLoans = <?php echo $rejected_loans ?? 0; ?>;
        const completedLoans = 0;
        const hasStatusData = pendingLoans > 0 || approvedLoans > 0 || rejectedLoans > 0;
        
        const statusSampleNote = document.getElementById('statusSampleNote');
        if (hasStatusData) {
            noStatusData.style.display = 'none';
            if (statusSampleNote) statusSampleNote.style.display = 'none';
        } else {
            noStatusData.style.display = 'none';
            if (statusSampleNote) statusSampleNote.style.display = 'block';
        }
        
        // Real data when available; otherwise sample/placeholder
        const statusData = hasStatusData ? [pendingLoans, approvedLoans, completedLoans, rejectedLoans] : [4, 8, 3, 1];
        const statusLabels = hasStatusData ? ['Pending', 'Approved', 'Completed', 'Rejected'] : ['Pending (Sample)', 'Approved (Sample)', 'Completed (Sample)', 'Rejected (Sample)'];
        
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: [
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(23, 162, 184, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderColor: [
                        'rgba(255, 193, 7, 1)',
                        'rgba(40, 167, 69, 1)',
                        'rgba(23, 162, 184, 1)',
                        'rgba(220, 53, 69, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = statusData.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                const displayLabel = hasStatusData ? label : label.replace(' (Sample)', '');
                                return displayLabel + ': ' + value + ' (' + percentage + '%)' + (hasStatusData ? '' : ' [Sample]');
                            }
                        }
                    }
                }
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
