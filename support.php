<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, email, full_name, role, profile_photo, deped_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc() ?: ['full_name' => 'User', 'role' => 'borrower'];
$stmt->close();

$role = $user['role'] ?? 'borrower';
if ($role === 'admin') {
    $dashboard_url = 'admin_dashboard.php';
} elseif (user_is_accountant_role($role)) {
    $dashboard_url = 'accountant_dashboard.php';
} else {
    $dashboard_url = 'borrower_dashboard.php';
}

$profile_photo = $user['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);
$role_label = ucfirst($role);
$notification_count = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
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
            font-size: 1.1rem;
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

        .support-header h1 {
            font-size: 1.8rem;
            color: #8b0000;
        }
        .support-header p {
            color: #6b7280;
            margin-top: 0.25rem;
            margin-bottom: 0.4rem;
        }
        .support-container {
            display: grid;
            gap: 1.6rem;
            margin-top: 1.75rem;
        }
        .support-header {
            margin-bottom: 0.2rem;
        }
        .support-hero {
            background: linear-gradient(120deg, rgba(139, 0, 0, 0.12), rgba(220, 20, 60, 0.18));
            border: 1px solid rgba(220, 20, 60, 0.2);
            border-radius: 18px;
            padding: 1.75rem 2rem;
            display: grid;
            gap: 0.75rem;
            margin-top: 0.4rem;
        }
        .support-hero h2 {
            font-size: 1.5rem;
            color: #111827;
        }
        .support-hero p {
            color: #4b5563;
            max-width: 720px;
        }
        .support-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1.25rem;
        }
        .kpi-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #eef1f4;
            padding: 1rem 1.1rem;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .kpi-card span {
            display: block;
            color: #6b7280;
            font-size: 0.85rem;
        }
        .kpi-card strong {
            color: #111827;
            font-size: 1.1rem;
        }
        .support-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
        }
        .support-card {
            background: white;
            border-radius: 14px;
            border: 1px solid #eef1f4;
            border-left: 4px solid #8b0000;
            padding: 1.4rem;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }
        .support-card h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #111827;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }
        .support-card i {
            color: #8b0000;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .quick-action-link {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 1rem 1.2rem;
            background: #fff;
            border: 1px solid #eef1f4;
            border-radius: 14px;
            border-left: 4px solid #8b0000;
            text-decoration: none;
            color: #1f2937;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
        }
        .quick-action-link:hover {
            background: #fff5f5;
            border-color: rgba(139, 0, 0, 0.3);
            color: #8b0000;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 0, 0, 0.1);
        }
        .quick-action-link i {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(139, 0, 0, 0.1);
            color: #8b0000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        .quick-action-link span { flex: 1; }
        .quick-action-link small {
            display: block;
            font-weight: 400;
            color: #6b7280;
            font-size: 0.8rem;
            margin-top: 0.15rem;
        }
        .faq-accordion .faq-item {
            cursor: pointer;
            padding: 1rem 1.1rem;
            border-radius: 12px;
            margin-bottom: 0.5rem;
            border: 1px solid #eef2f7;
            transition: background 0.2s, border-color 0.2s;
        }
        .faq-accordion .faq-item:hover { background: #f8fafc; border-color: #e2e8f0; }
        .faq-accordion .faq-item.open { background: #fff5f5; border-color: rgba(139, 0, 0, 0.2); }
        .faq-accordion .faq-item h4 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0;
        }
        .faq-accordion .faq-item h4 .fa-chevron-down {
            color: #8b0000;
            font-size: 0.85rem;
            transition: transform 0.2s;
        }
        .faq-accordion .faq-item.open h4 .fa-chevron-down { transform: rotate(180deg); }
        .faq-accordion .faq-item p {
            max-height: 0;
            overflow: hidden;
            margin: 0;
            padding-top: 0;
            transition: max-height 0.25s ease, padding 0.2s ease;
        }
        .faq-accordion .faq-item.open p {
            max-height: 120px;
            padding-top: 0.75rem;
        }
        .contact-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .contact-row a {
            color: #8b0000;
            text-decoration: none;
            font-weight: 500;
        }
        .contact-row a:hover { text-decoration: underline; }
        .copy-email-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.6rem;
            font-size: 0.8rem;
            border-radius: 999px;
            border: 1px solid #8b0000;
            background: #fff;
            color: #8b0000;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        .copy-email-btn:hover { background: #fff5f5; }
        .copy-email-btn.copied { background: #d4edda; border-color: #28a745; color: #155724; }
        .support-list {
            list-style: none;
            display: grid;
            gap: 0.5rem;
            color: #4b5563;
            font-size: 0.95rem;
        }
        .support-list li {
            display: flex;
            gap: 0.5rem;
            align-items: flex-start;
        }
        .support-list i {
            margin-top: 0.2rem;
        }
        .support-two-col {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.25rem;
        }
        .support-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #eef1f4;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.05);
        }
        .support-panel h3 {
            color: #111827;
            margin-bottom: 0.75rem;
            font-size: 1.05rem;
        }
        .support-steps {
            display: grid;
            gap: 0.75rem;
        }
        .step-card {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }
        .step-number {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: rgba(139, 0, 0, 0.1);
            color: #8b0000;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .tag {
            background: #f3f4f6;
            color: #4b5563;
            padding: 0.3rem 0.65rem;
            border-radius: 999px;
            font-size: 0.85rem;
        }
        .support-note {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
        .support-faq {
            background: white;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            border: 1px solid #eef1f4;
        }
        .support-faq h2 {
            margin-bottom: 1rem;
            font-size: 1.3rem;
            color: #111827;
        }
        .faq-item {
            padding: 0.9rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .faq-item:last-child { border-bottom: none; }
        .faq-item h4 {
            font-size: 1rem;
            color: #1f2937;
            margin-bottom: 0.4rem;
        }
        .faq-item p {
            color: #6b7280;
            font-size: 0.95rem;
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
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($role_label); ?></span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-headset"></i> Support</span>
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
                    <span class="profile-initial"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></span>
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
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($user['deped_id'] ?? 'Not set'); ?></div>
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
                        <span class="sidebar-icon">
                            <i class="<?php echo ($role === 'borrower') ? 'fas fa-home' : 'fas fa-tachometer-alt'; ?>"></i>
                        </span>
                        <?php echo ($role === 'admin') ? 'Admin Dashboard' : (user_is_accountant_role($role) ? 'Accountant Dashboard' : 'Borrower Dashboard'); ?>
                    </a>
                </li>
                <?php if ($role === 'borrower'): ?>
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
                        <a href="loan_history.php" class="sidebar-link">
                            <span class="sidebar-icon"><i class="fas fa-history"></i></span>
                            Loan History
                        </a>
                    </li>
                <?php else: ?>
                    <li class="sidebar-item">
                        <a href="loan_applications.php" class="sidebar-link">
                            <span class="sidebar-icon"><i class="fas fa-clipboard-list"></i></span>
                            Loan Applications
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
                    <?php if ($role === 'admin'): ?>
                        <li class="sidebar-item">
                            <a href="manage_users.php" class="sidebar-link">
                                <span class="sidebar-icon"><i class="fas fa-users"></i></span>
                                Manage Users
                            </a>
                        </li>
                    <?php else: ?>
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
                <?php endif; ?>
                <li class="sidebar-item">
                    <a href="support.php" class="sidebar-link active">
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
                        <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                    <span class="sidebar-user-status" aria-hidden="true"></span>
                </div>
                <div class="sidebar-user-meta">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></div>
                    <div class="sidebar-user-role"><?php echo htmlspecialchars(ucfirst($role)); ?></div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="content-section">
                <div class="support-header">
                    <h1>Support Center</h1>
                    <p>Hello, <?php echo htmlspecialchars($user['full_name']); ?>. We are here to help you.</p>
                </div>

                <section class="support-container">
                    <section class="support-hero">
                        <h2>Borrower Help Desk</h2>
                        <p>Get fast answers about your loan, payments, and account updates. We can help you track your application, confirm deductions, and resolve profile concerns.</p>
                        <div class="support-kpis">
                            <div class="kpi-card">
                                <span>Typical response time</span>
                                <strong>Within 1 business day</strong>
                            </div>
                            <div class="kpi-card">
                                <span>Support channels</span>
                                <strong>Email • Phone • On-site</strong>
                            </div>
                            <div class="kpi-card">
                                <span>Ticket updates</span>
                                <strong>Mon–Fri, 8:00 AM – 5:00 PM</strong>
                            </div>
                        </div>
                    </section>

                    <?php if ($role === 'borrower'): ?>
                    <section class="support-panel" style="border-left: 4px solid #8b0000;">
                        <h3><i class="fas fa-bolt"></i> Quick actions — do it yourself</h3>
                        <p style="color: #6b7280; font-size: 0.95rem; margin-bottom: 1rem;">Jump straight to what you need. Most answers are one click away.</p>
                        <div class="quick-actions">
                            <a href="my_loans.php" class="quick-action-link">
                                <i class="fas fa-credit-card"></i>
                                <span>My Loans <small>View balance & payments</small></span>
                            </a>
                            <a href="apply_loan.php" class="quick-action-link">
                                <i class="fas fa-plus-circle"></i>
                                <span>Apply for Loan <small>New or re-apply</small></span>
                            </a>
                            <a href="loan_history.php" class="quick-action-link">
                                <i class="fas fa-history"></i>
                                <span>Loan History <small>Past applications & status</small></span>
                            </a>
                            <a href="#" class="quick-action-link" onclick="openProfileModal('profile'); return false;">
                                <i class="fas fa-user-edit"></i>
                                <span>Update Profile <small>Name, Employee Deped No., photo</small></span>
                            </a>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="support-grid">
                        <div class="support-card">
                            <h3><i class="fas fa-headset"></i> Contact Support</h3>
                            <ul class="support-list">
                                <li>
                                    <i class="fas fa-envelope"></i>
                                    <span class="contact-row">
                                        <a href="mailto:support@depedloan.gov.ph">support@depedloan.gov.ph</a>
                                        <button type="button" class="copy-email-btn" data-copy="support@depedloan.gov.ph" title="Copy email">Copy</button>
                                    </span>
                                </li>
                                <li><i class="fas fa-phone-alt"></i> <a href="tel:+63212345678">(02) 1234-5678</a></li>
                                <li><i class="fas fa-map-marker-alt"></i> SDO Cabuyao City, Laguna</li>
                            </ul>
                        </div>
                        <div class="support-card">
                            <h3><i class="fas fa-clock"></i> Office Hours</h3>
                            <ul class="support-list">
                                <li><i class="fas fa-calendar-day"></i> Monday - Friday: 8:00 AM - 5:00 PM</li>
                                <li><i class="fas fa-ban"></i> Saturday, Sunday & holidays: Closed</li>
                            </ul>
                        </div>
                        <div class="support-card">
                            <h3><i class="fas fa-file-alt"></i> Common Requests</h3>
                            <ul class="support-list">
                                <li><i class="fas fa-check"></i> Loan status updates</li>
                                <li><i class="fas fa-check"></i> Payment breakdown</li>
                                <li><i class="fas fa-check"></i> Account verification</li>
                                <li><i class="fas fa-check"></i> Employee Deped No. correction</li>
                            </ul>
                        </div>
                    </section>

                    <section class="support-two-col">
                        <div class="support-panel">
                            <h3><i class="fas fa-list-check"></i> Quick Self-Service Steps</h3>
                            <div class="support-steps">
                                <div class="step-card">
                                    <div class="step-number">1</div>
                                    <div>
                                        <strong>Check your loan status</strong>
                                        <p>Open <em>My Loans</em> to see active loans, due dates, and balances.</p>
                                    </div>
                                </div>
                                <div class="step-card">
                                    <div class="step-number">2</div>
                                    <div>
                                        <strong>Review deductions</strong>
                                        <p>Use <em>Loan History</em> for a month-to-month deduction summary.</p>
                                    </div>
                                </div>
                                <div class="step-card">
                                    <div class="step-number">3</div>
                                    <div>
                                        <strong>Update your profile</strong>
                                        <p>Correct name or Employee Deped No. in <em>Update Profile</em> for faster verification.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="support-panel">
                            <h3><i class="fas fa-clipboard-list"></i> Before You Contact Us</h3>
                            <ul class="support-list">
                                <li><i class="fas fa-id-card"></i> Prepare your Employee Deped No. and full name</li>
                                <li><i class="fas fa-file-invoice"></i> Provide your loan ID or application date</li>
                                <li><i class="fas fa-receipt"></i> Include the month of the deduction in question</li>
                                <li><i class="fas fa-envelope-open-text"></i> Use your registered email if possible</li>
                            </ul>
                            <div class="support-note">
                                For faster action, include a screenshot of your loan summary or history.
                            </div>
                        </div>
                    </section>

                    <section class="support-two-col">
                        <div class="support-panel">
                            <h3><i class="fas fa-shield-alt"></i> Security Reminders</h3>
                            <ul class="support-list">
                                <li><i class="fas fa-lock"></i> We will never ask for your password via email.</li>
                                <li><i class="fas fa-user-shield"></i> Share only your Employee Deped No. and loan details.</li>
                                <li><i class="fas fa-exclamation-circle"></i> Report suspicious messages to support immediately.</li>
                            </ul>
                        </div>
                        <div class="support-panel">
                            <h3><i class="fas fa-tags"></i> Topics We Handle</h3>
                            <div class="tag-list">
                                <span class="tag">Loan application</span>
                                <span class="tag">Approval status</span>
                                <span class="tag">Payment schedule</span>
                                <span class="tag">Deduction issues</span>
                                <span class="tag">Profile updates</span>
                                <span class="tag">Account access</span>
                            </div>
                        </div>
                    </section>

                    <section class="support-faq">
                        <h2><i class="fas fa-question-circle" style="color: #8b0000;"></i> Frequently Asked Questions</h2>
                        <div class="faq-accordion">
                            <div class="faq-item" data-faq>
                                <h4>How do I check my remaining balance? <i class="fas fa-chevron-down"></i></h4>
                                <p>Go to <strong>My Loans</strong> and open your active loan. You can also click <strong>View Loan</strong> on a card to see the full details including remaining balance.</p>
                            </div>
                            <div class="faq-item" data-faq>
                                <h4>Can I apply for another loan? (Re-apply) <i class="fas fa-chevron-down"></i></h4>
                                <p>Yes. If you have paid at least <strong>30%</strong> of your current loan, you may be eligible to re-apply. Go to <strong>Apply for Loan</strong> — the system will show if you qualify and any amount that will be offset from your previous loan.</p>
                            </div>
                            <div class="faq-item" data-faq>
                                <h4>My Employee Deped No. is incorrect. How can I update it? <i class="fas fa-chevron-down"></i></h4>
                                <p>Click your profile picture (top right) → <strong>Update Profile</strong>, then enter your correct Employee Deped No. and save. Use the same page for admin or accountant accounts.</p>
                            </div>
                            <div class="faq-item" data-faq>
                                <h4>How do I reset my password? <i class="fas fa-chevron-down"></i></h4>
                                <p>On the login page, click <strong>Forgot password</strong> and follow the OTP verification steps sent to your registered email.</p>
                            </div>
                            <div class="faq-item" data-faq>
                                <h4>Who can I contact for payment or deduction issues? <i class="fas fa-chevron-down"></i></h4>
                                <p>Email <a href="mailto:support@depedloan.gov.ph">support@depedloan.gov.ph</a> or call <a href="tel:+63212345678">(02) 1234-5678</a> during office hours. Include your Employee Deped No., loan ID, and the month in question for faster help.</p>
                            </div>
                            <div class="faq-item" data-faq>
                                <h4>How long does loan approval take? <i class="fas fa-chevron-down"></i></h4>
                                <p>It depends on document completeness and review queue. Check <strong>Loan History</strong> (borrowers) or <strong>Loan Applications</strong> (admin/accountant) for status updates.</p>
                            </div>
                        </div>
                    </section>
                </section>
            </div>
        </main>
    </div>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <script>
        function toggleProfileDropdown() {
            var dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }

        document.addEventListener('click', function(event) {
            var profileIcon = document.querySelector('.profile-trigger');
            var profileDropdown = document.getElementById('profileDropdown');
            if (profileIcon && !profileIcon.contains(event.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        function openProfileModal(tab) {
            var overlay = document.getElementById('profileModalOverlay');
            var frame = document.getElementById('profileModalFrame');
            var safeTab = tab === 'password' ? 'password' : 'profile';
            frame.src = 'profile_update.php?tab=' + safeTab + '&embed=1';
            if (tab === 'password') overlay.classList.add('change-password-modal');
            else overlay.classList.remove('change-password-modal');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProfileModal() {
            var overlay = document.getElementById('profileModalOverlay');
            var frame = document.getElementById('profileModalFrame');
            overlay.classList.remove('active', 'change-password-modal');
            document.body.style.overflow = 'auto';
            frame.src = '';
        }

        document.addEventListener('click', function(event) {
            if (event.target && event.target.id === 'profileModalOverlay') {
                closeProfileModal();
            }
        });

        document.querySelectorAll('[data-faq]').forEach(function(item) {
            item.addEventListener('click', function() {
                var open = this.classList.contains('open');
                document.querySelectorAll('.faq-accordion .faq-item.open').forEach(function(o) { o.classList.remove('open'); });
                if (!open) this.classList.add('open');
            });
        });

        document.querySelectorAll('.copy-email-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var email = this.getAttribute('data-copy') || '';
                if (!email) return;
                navigator.clipboard.writeText(email).then(function() {
                    var label = btn.textContent;
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() { btn.textContent = label; btn.classList.remove('copied'); }, 2000);
                });
            });
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
