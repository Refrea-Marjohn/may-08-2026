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
$stmt->close();

$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
if (!$is_admin) {
    header("Location: borrower_dashboard.php");
    exit();
}

$profile_photo = $user['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

$pending_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM loans WHERE status = 'pending'");
$pending_stmt->execute();
$pending_loans_count = (int) ($pending_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$pending_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Audit Trail - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; min-height: 100vh; }
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
        .welcome-message { font-size: 1.2rem; color: #333; }
        .welcome-message strong { color: #8b0000; }
        .nav-icons { display: flex; align-items: center; gap: 1.5rem; position: relative; }
        .icon-button { position: relative; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; transition: color 0.3s; }
        .icon-button:hover { color: #8b0000; }
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

        .container { display: flex; margin-top: 70px; min-height: calc(100vh - 70px); }
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
        .sidebar-logo { width: 64px; height: 64px; margin: 0 auto 0.75rem; display: flex; align-items: center; justify-content: center; }
        .sidebar-logo img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar-title { color: rgba(255, 255, 255, 0.85); font-size: 0.85rem; letter-spacing: 0.02em; }
        .sidebar-menu { list-style: none; flex: 1; padding: 0.5rem 0.5rem 1rem; overflow: hidden; }
        .sidebar-item { margin-bottom: 0.1rem; }
        .sidebar-item.logout { border-top: 1px solid rgba(255, 255, 255, 0.2); position: absolute; bottom: 0; left: 0; right: 0; padding: 1rem 0; text-align: center; }
        .sidebar-item.logout .sidebar-link { justify-content: center; }
        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.65rem 1rem;
            margin: 0.2rem 0.5rem;
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            transition: all 0.3s;
            border-radius: 12px;
            gap: 0.85rem;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .sidebar-link:hover { background: rgba(255, 255, 255, 0.14); color: white; }
        .sidebar-link.active { background: rgba(255, 255, 255, 0.22); color: white; font-weight: 600; }
        .sidebar-icon { font-size: 1.1rem; width: 26px; text-align: center; }

        .main-content { flex: 1; padding: 2rem; margin-left: 192px; /* 80% of 250px */ margin-top: 20px; }
        .content-section {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }
        .section-title { font-size: 1.4rem; color: #333; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .section-title i { color: #8b0000; }

        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .filter-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .filter-group label { font-size: 0.85rem; color: #555; font-weight: 600; }
        .filter-group input, .filter-group select {
            padding: 0.55rem 0.7rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        .filter-actions { display: flex; flex-wrap: wrap; gap: 0.8rem; align-items: center; margin-top: 0.5rem; }
        .btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%); color: white; }
        .btn-secondary { background: #f1f3f5; color: #333; }
        .audit-meta { color: #666; font-size: 0.85rem; }

        .table-wrapper { overflow-x: auto; }
        .audit-table { width: 100%; border-collapse: collapse; min-width: 820px; }
        .audit-table thead th {
            text-align: left;
            padding: 0.85rem 0.75rem;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            color: #333;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .audit-table tbody td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.93rem;
            color: #333;
            vertical-align: top;
        }
        .audit-table tbody tr:nth-child(even) { background: #fbfbfb; }
        .audit-table tbody tr:hover { background: #f6f7f9; }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .pill.view { background: #e7f0ff; color: #1f5fbf; }
        .pill.create { background: #e8f8ef; color: #1b7a41; }
        .pill.update { background: #fff3cd; color: #856404; }
        .pill.delete { background: #f8d7da; color: #721c24; }
        .pill.login { background: #e8f8ef; color: #1b7a41; }
        .pill.logout { background: #e2e3e5; color: #3a3f44; }
        .pill.approve { background: #d4edda; color: #155724; }
        .pill.reject { background: #f8d7da; color: #721c24; }
        .pill.submit { background: #e2f0ff; color: #1f5fbf; }

        .empty-state { text-align: center; color: #666; padding: 2rem; }
        .user-cell { display: flex; flex-direction: column; gap: 0.2rem; }
        .user-name { font-weight: 600; color: #333; }
        .user-email { color: #6b7280; font-size: 0.8rem; }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-left: 0;
            flex-wrap: wrap;
        }
        .pagination-info {
            font-size: 0.85rem;
            color: #6b7280;
        }
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .page-btn {
            padding: 0.45rem 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            color: #374151;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .page-btn:hover { background: #f8f9fa; }
        .page-btn:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }
        .page-pill {
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            background: #f1f5f9;
            color: #374151;
            font-size: 0.82rem;
            font-weight: 600;
        }
        @media (max-width: 900px) {
            .navbar { left: 0; }
            .sidebar { position: relative; width: 100%; height: auto; }
            .main-content { margin-left: 0; }
            .container { flex-direction: column; }
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
                overflow-x: auto;
            }

            .content-section {
                padding: 1rem;
                overflow-x: auto;
            }

            .audit-table {
                min-width: 760px;
            }
        }

        @media (max-width: 600px) {
            .audit-table {
                min-width: 700px;
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
                <div class="welcome-title">
                    Welcome back, <strong><?php echo htmlspecialchars($user['full_name'] ?? ($_SESSION['full_name'] ?? 'Admin')); ?></strong>! 👋
                </div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> Administrator</span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-shield-alt"></i> Admin Access</span>
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
                    <span class="profile-initial"><?php echo strtoupper(substr($user['full_name'] ?? ($_SESSION['full_name'] ?? 'A'), 0, 1)); ?></span>
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
                                    <?php echo strtoupper(substr($user['full_name'] ?? ($_SESSION['full_name'] ?? 'A'), 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($user['full_name'] ?? ($_SESSION['full_name'] ?? 'Admin')); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($user['email'] ?? ($_SESSION['email'] ?? '')); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($_SESSION['deped_id'] ?? 'Not set'); ?></div>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="dropdown-item" onclick="openProfileModal('profile'); return false;">
                        <i class="fas fa-user-edit"></i>
                        Update Profile
                    </a>
                    <a href="#" class="dropdown-item" onclick="openProfileModal('password'); return false;">
                        <i class="fas fa-key"></i>
                        Change Password
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
                    <a href="admin_dashboard.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-tachometer-alt"></i></span>
                        Admin Dashboard
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
                    <a href="manage_users.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-users"></i></span>
                        Manage Users
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="audit_trail.php" class="sidebar-link active">
                        <span class="sidebar-icon"><i class="fas fa-user-shield"></i></span>
                        Audit Trail
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
                    <div class="sidebar-user-role">Administrator</div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="content-section">
                <div class="section-title"><i class="fas fa-user-shield"></i> Audit Trail Logs</div>
                <div class="audit-meta" style="margin-bottom: 1rem;">Audit logs are read-only and cannot be edited or deleted.</div>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="searchInput">Search</label>
                        <input type="text" id="searchInput" placeholder="Search description, page...">
                    </div>
                    <div class="filter-group">
                        <label for="actorInput">Actor Name</label>
                        <input type="text" id="actorInput" placeholder="e.g. Juan Dela Cruz">
                    </div>
                    <div class="filter-group">
                        <label for="roleFilter">User Role</label>
                        <select id="roleFilter">
                            <option value="all">All roles</option>
                            <option value="admin">Admin</option>
                            <option value="accountant">Accountant</option>
                            <option value="borrower">Borrower</option>
                            <option value="guest">Guest</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="actionFilter">Action Type</label>
                        <select id="actionFilter">
                            <option value="all">All actions</option>
                            <option value="VIEW">VIEW</option>
                            <option value="LOGIN">LOGIN</option>
                            <option value="LOGOUT">LOGOUT</option>
                            <option value="CREATE">CREATE</option>
                            <option value="UPDATE">UPDATE</option>
                            <option value="DELETE">DELETE</option>
                            <option value="APPROVE">APPROVE</option>
                            <option value="REJECT">REJECT</option>
                            <option value="SUBMIT">SUBMIT</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="fromDate">From Date</label>
                        <input type="date" id="fromDate">
                    </div>
                    <div class="filter-group">
                        <label for="toDate">To Date</label>
                        <input type="date" id="toDate">
                    </div>
                    <div class="filter-group">
                        <label for="sortBy">Sort</label>
                        <select id="sortBy">
                            <option value="date_desc">Newest first</option>
                            <option value="date_asc">Oldest first</option>
                            <option value="actor_asc">Actor A-Z</option>
                            <option value="actor_desc">Actor Z-A</option>
                            <option value="action_asc">Action A-Z</option>
                            <option value="action_desc">Action Z-A</option>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button class="btn btn-primary" onclick="refreshLogs()"><i class="fas fa-filter"></i> Apply Filters</button>
                    <button class="btn btn-secondary" onclick="resetFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
                    <span class="audit-meta" id="lastUpdated">Last updated: --</span>
                </div>
            </div>

            <div class="content-section">
                <div class="table-wrapper">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Actor</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Page/Module</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody id="auditTableBody">
                            <tr>
                                <td colspan="7" class="empty-state">Loading audit logs...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination">
                    <div class="pagination-info" id="paginationInfo">Showing 0 of 0</div>
                    <div class="pagination-controls">
                        <button class="page-btn" id="prevPageBtn">Previous</button>
                        <span class="page-pill" id="pageIndicator">Page 1</span>
                        <button class="page-btn" id="nextPageBtn">Next</button>
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

        document.addEventListener('click', function(event) {
            const profileIcon = document.querySelector('.profile-trigger');
            const dropdown = document.getElementById('profileDropdown');

            if (profileIcon && !profileIcon.contains(event.target)) {
                dropdown.classList.remove('active');
            }
        });
    </script>

    <script>
        const auditTableBody = document.getElementById('auditTableBody');
        const lastUpdated = document.getElementById('lastUpdated');
        const searchInput = document.getElementById('searchInput');
        const actorInput = document.getElementById('actorInput');
        const roleFilter = document.getElementById('roleFilter');
        const actionFilter = document.getElementById('actionFilter');
        const fromDate = document.getElementById('fromDate');
        const toDate = document.getElementById('toDate');
        const sortBy = document.getElementById('sortBy');
        const paginationInfo = document.getElementById('paginationInfo');
        const prevPageBtn = document.getElementById('prevPageBtn');
        const nextPageBtn = document.getElementById('nextPageBtn');
        const pageIndicator = document.getElementById('pageIndicator');
        const PAGE_LIMIT = 100;
        let currentPage = 1;
        let totalPages = 1;

        function escapeHtml(value) {
            if (value === null || value === undefined) return '';
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function pillClass(action) {
            const normalized = (action || '').toLowerCase();
            return 'pill ' + normalized;
        }

        function buildParams() {
            const params = new URLSearchParams();
            if (searchInput.value.trim()) params.set('q', searchInput.value.trim());
            if (actorInput.value.trim()) params.set('actor', actorInput.value.trim());
            if (roleFilter.value && roleFilter.value !== 'all') params.set('role', roleFilter.value);
            if (actionFilter.value && actionFilter.value !== 'all') params.set('action', actionFilter.value);
            if (fromDate.value) params.set('from', fromDate.value);
            if (toDate.value) params.set('to', toDate.value);
            if (sortBy.value) params.set('sort', sortBy.value);
            params.set('limit', String(PAGE_LIMIT));
            params.set('page', String(currentPage));
            return params.toString();
        }

        function renderLogs(logs) {
            if (!Array.isArray(logs) || logs.length === 0) {
                auditTableBody.innerHTML = '<tr><td colspan="7" class="empty-state">No audit logs found.</td></tr>';
                return;
            }
            auditTableBody.innerHTML = logs.map(log => {
                const action = escapeHtml(log.action_type || '');
                const actionPill = `<span class="${pillClass(action)}">${action}</span>`;
                const username = escapeHtml(log.actor_username || '');
                const email = escapeHtml(log.actor_email || '');
                const userLabel = username || 'Guest';
                const userCell = `<div class="user-cell"><div class="user-name">${userLabel}</div>${email ? `<div class="user-email">${email}</div>` : ''}</div>`;
                return `
                    <tr>
                        <td>${escapeHtml(log.created_at || '')}</td>
                        <td>${escapeHtml(log.actor_name || 'Unknown')}</td>
                        <td>${userCell}</td>
                        <td>${escapeHtml(log.user_role || 'N/A')}</td>
                        <td>${actionPill}</td>
                        <td>${escapeHtml(log.page_name || '-')}</td>
                        <td>${escapeHtml(log.description || '-')}</td>
                    </tr>
                `;
            }).join('');
        }

        function updatePagination(total) {
            totalPages = Math.max(1, Math.ceil(total / PAGE_LIMIT));
            if (currentPage > totalPages) currentPage = totalPages;
            pageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;
            prevPageBtn.disabled = currentPage <= 1;
            nextPageBtn.disabled = currentPage >= totalPages;
            const showingFrom = total === 0 ? 0 : (currentPage - 1) * PAGE_LIMIT + 1;
            const showingTo = Math.min(currentPage * PAGE_LIMIT, total);
            paginationInfo.textContent = `Showing ${showingFrom}-${showingTo} of ${total}`;
        }

        function refreshLogs() {
            fetch('audit_trail_data.php?' + buildParams())
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success) {
                        auditTableBody.innerHTML = '<tr><td colspan="7" class="empty-state">Unable to load audit logs.</td></tr>';
                        return;
                    }
                    renderLogs(data.logs || []);
                    lastUpdated.textContent = 'Last updated: ' + (data.server_time || '--');
                    const total = data.pagination && typeof data.pagination.total === 'number' ? data.pagination.total : 0;
                    updatePagination(total);
                })
                .catch(() => {
                    auditTableBody.innerHTML = '<tr><td colspan="7" class="empty-state">Unable to load audit logs.</td></tr>';
                });
        }

        function resetFilters() {
            searchInput.value = '';
            actorInput.value = '';
            roleFilter.value = 'all';
            actionFilter.value = 'all';
            fromDate.value = '';
            toDate.value = '';
            sortBy.value = 'date_desc';
            currentPage = 1;
            refreshLogs();
        }

        let debounceTimer;
        [searchInput, actorInput].forEach(input => {
            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    currentPage = 1;
                    refreshLogs();
                }, 400);
            });
        });
        [roleFilter, actionFilter, fromDate, toDate, sortBy].forEach(input => {
            input.addEventListener('change', () => {
                currentPage = 1;
                refreshLogs();
            });
        });

        prevPageBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage -= 1;
                refreshLogs();
            }
        });
        nextPageBtn.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage += 1;
                refreshLogs();
            }
        });

        refreshLogs();
        setInterval(refreshLogs, 10000);
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
