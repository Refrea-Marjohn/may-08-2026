<?php
require_once 'config.php';
require_once 'config_email.php';
require_once 'mail_helper.php';

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

// Check if user is admin
$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
if (!$is_admin) {
    header("Location: borrower_dashboard.php");
    exit();
}

$pending_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM loans WHERE status = 'pending'");
$pending_stmt->execute();
$pending_loans_count = (int) ($pending_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$pending_stmt->close();

// Ensure user_status column exists for Status (Active/Inactive/Suspended)
$col_status = $conn->query("SHOW COLUMNS FROM users LIKE 'user_status'");
if ($col_status && $col_status->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN user_status VARCHAR(20) NOT NULL DEFAULT 'active'");
}

$error = '';
$success = '';
if (isset($_SESSION['manage_users_success'])) {
    $success = $_SESSION['manage_users_success'];
    unset($_SESSION['manage_users_success']);
}
if (isset($_SESSION['manage_users_error'])) {
    $error = $_SESSION['manage_users_error'];
    unset($_SESSION['manage_users_error']);
}

// Handle admin actions: reset_password, deactivate, activate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    $target_user_id = (int) ($_POST['target_user_id'] ?? 0);
    $admin_action = $_POST['admin_action'] ?? '';
    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    if ($target_user_id > 0 && $target_user_id !== $user_id) {
        $target_stmt = $conn->prepare("SELECT id, full_name, username, email, role FROM users WHERE id = ?");
        $target_stmt->bind_param("i", $target_user_id);
        $target_stmt->execute();
        $target_user = $target_stmt->get_result()->fetch_assoc();
        $target_stmt->close();

        if ($target_user) {
            if ($admin_action === 'edit_user') {
                $username = trim($_POST['edit_username'] ?? '');
                $email = trim($_POST['edit_email'] ?? '');
                $contact_number = trim($_POST['edit_contact_number'] ?? '');
                $deped_id = trim($_POST['edit_deped_id'] ?? '');
                $home_address = trim($_POST['edit_home_address'] ?? '');

                if ($username === '' || $email === '' || $contact_number === '') {
                    $error = 'Username, email, and contact number are required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Invalid email format.';
                } elseif (!preg_match('/^09\d{9}$/', $contact_number)) {
                    $error = 'Contact number must be 11 digits starting with 09 (format: 09XXXXXXXXX).';
                } elseif ($deped_id !== '' && strlen(preg_replace('/\D/', '', $deped_id)) !== 7) {
                    $error = 'Employee Deped No. must be exactly 7 digits.';
                } else {
                    $deped_id_clean = preg_replace('/\D/', '', $deped_id);
                    $check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                    $check->bind_param("ssi", $username, $email, $target_user_id);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if ($exists) {
                        $error = 'Username or email already exists.';
                    } else {
                        $up = $conn->prepare("UPDATE users SET username = ?, email = ?, contact_number = ?, deped_id = ?, home_address = ? WHERE id = ?");
                        $up->bind_param("sssssi", $username, $email, $contact_number, $deped_id_clean, $home_address, $target_user_id);
                        if ($up->execute()) {
                            $success = 'User ' . htmlspecialchars($target_user['full_name']) . ' updated successfully.';
                            log_audit($conn, 'UPDATE', "Admin updated user #{$target_user_id} ({$target_user['full_name']}).", 'Manage Users', "User #{$target_user_id}", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
                        } else {
                            $error = 'Failed to update user.';
                        }
                        $up->close();
                    }
                }
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => $error === '', 'message' => $error === '' ? strip_tags($success) : $error]);
                    exit;
                }
            } elseif ($admin_action === 'reset_password') {
                $new_password = trim($_POST['new_password'] ?? '');
                $confirm_new = trim($_POST['confirm_new_password'] ?? '');
                $is_strong = (bool) preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}$/', $new_password);
                if ($is_strong && $new_password === $confirm_new) {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $up->bind_param("si", $hashed, $target_user_id);
                    if ($up->execute()) {
                        $success = 'Password reset successfully for ' . htmlspecialchars($target_user['full_name']) . '.';
                        log_audit($conn, 'PASSWORD_RESET', "Admin reset password for user #{$target_user_id} ({$target_user['full_name']}).", 'Manage Users', "User #{$target_user_id}", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
                    } else {
                        $error = 'Failed to reset password.';
                    }
                    $up->close();
                } else {
                    $error = 'Password must be strong (8+ chars with uppercase, lowercase, and number) and match confirmation.';
                }
            } elseif ($admin_action === 'deactivate') {
                $up = $conn->prepare("UPDATE users SET user_status = 'inactive' WHERE id = ?");
                $up->bind_param("i", $target_user_id);
                if ($up->execute()) {
                    $success = htmlspecialchars($target_user['full_name']) . ' has been deactivated.';
                    log_audit($conn, 'DEACTIVATE', "Admin deactivated user #{$target_user_id} ({$target_user['full_name']}).", 'Manage Users', "User #{$target_user_id}", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
                } else {
                    $error = 'Failed to deactivate user.';
                }
                $up->close();
            } elseif ($admin_action === 'activate') {
                $up = $conn->prepare("UPDATE users SET user_status = 'active' WHERE id = ?");
                $up->bind_param("i", $target_user_id);
                if ($up->execute()) {
                    $success = htmlspecialchars($target_user['full_name']) . ' has been activated.';
                    log_audit($conn, 'ACTIVATE', "Admin activated user #{$target_user_id} ({$target_user['full_name']}).", 'Manage Users', "User #{$target_user_id}", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
                } else {
                    $error = 'Failed to activate user.';
                }
                $up->close();
            }
        }
    }
    if (!empty($success) || !empty($error)) {
        if (!empty($success)) $_SESSION['manage_users_success'] = $success;
        if (!empty($error)) $_SESSION['manage_users_error'] = $error;
        header('Location: manage_users.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['admin_action'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $confirm_email = trim($_POST['confirm_email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');

    if ($first_name === '' || $last_name === '' || $username === '' || $email === '' || $confirm_email === '' || $password === '' || $confirm_password === '' || $contact_number === '') {
        $error = 'All fields are required.';
    } elseif (strcasecmp($email, $confirm_email) !== 0) {
        $error = 'Email and confirm email do not match.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (!preg_match('/^09\d{9}$/', $contact_number)) {
        $error = 'Contact number must be in format: 09XXXXXXXXX';
    } else {
        $name_parts = array_filter([$first_name, $middle_name, $last_name], function ($value) {
            return $value !== '';
        });
        $full_name = trim(implode(' ', $name_parts));
        $check_sql = "SELECT id FROM users WHERE email = ? OR username = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $existing = $stmt->get_result();
        $stmt->close();

        if ($existing->num_rows > 0) {
            $error = 'Email or username already exists.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $insert_sql = "INSERT INTO users (username, email, password, full_name, role, contact_number) VALUES (?, ?, ?, ?, 'accountant', ?)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("sssss", $username, $email, $hashed_password, $full_name, $contact_number);
            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;
                $success = 'Accountant account created successfully.';
                $target_label = $new_user_id ? "User #{$new_user_id} - {$full_name}" : $full_name;
                log_audit(
                    $conn,
                    'CREATE',
                    "Created accountant account for {$full_name}.",
                    'Manage Users',
                    $target_label,
                    $user_id,
                    $user['full_name'] ?? 'Admin',
                    $user['role'] ?? 'admin'
                );
                // Email accountant their username and temporary password
                try {
                    sendAccountantCredentialsEmail($email, $full_name, $username, $password);
                } catch (Exception $e) {
                    $success .= ' Credentials email could not be sent to the accountant (' . $e->getMessage() . ').';
                }
                $first_name = $middle_name = $last_name = $username = $email = $confirm_email = '';
            } else {
                $error = 'Failed to create accountant account.';
            }
            $stmt->close();
        }
    }
}

$accounting_users_sql = "SELECT id, full_name, username, email, contact_number, profile_photo, COALESCE(NULLIF(user_status,''), 'active') AS user_status, created_at FROM users WHERE role = 'accountant' ORDER BY created_at DESC";
$stmt = $conn->prepare($accounting_users_sql);
$stmt->execute();
$accounting_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$borrower_users_sql = "SELECT u.id, u.full_name, u.username, u.email, u.profile_photo, u.created_at,
    COALESCE(NULLIF(u.user_status,''), 'active') AS user_status,
    (SELECT COUNT(*) FROM loans WHERE user_id = u.id AND status = 'pending') AS pending_count,
    (SELECT COUNT(*) FROM loans WHERE user_id = u.id AND status = 'approved' AND released_at IS NULL) AS approved_not_released,
    (SELECT COUNT(*) FROM loans WHERE user_id = u.id AND status = 'completed') AS completed_count,
    (SELECT COUNT(*)
        FROM loans l
        LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM deductions GROUP BY loan_id) d ON d.loan_id = l.id
        WHERE l.user_id = u.id
          AND l.status = 'approved'
          AND l.released_at IS NOT NULL
          AND COALESCE(d.paid, 0) < COALESCE(l.total_amount, l.loan_amount)
    ) AS ongoing_count,
    (SELECT COUNT(*)
        FROM loans l
        LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM deductions GROUP BY loan_id) d ON d.loan_id = l.id
        WHERE l.user_id = u.id
          AND l.status = 'approved'
          AND l.released_at IS NOT NULL
          AND COALESCE(d.paid, 0) >= COALESCE(l.total_amount, l.loan_amount)
    ) AS fully_paid_count
FROM users u
WHERE (u.role IS NULL OR u.role = '' OR u.role = 'borrower') AND u.username <> 'admin'
ORDER BY u.created_at DESC";
$stmt = $conn->prepare($borrower_users_sql);
$stmt->execute();
$borrower_users_raw = $stmt->get_result();
$borrower_users = [];
while ($row = $borrower_users_raw->fetch_assoc()) {
    $pending = (int) ($row['pending_count'] ?? 0);
    $approved_not_released = (int) ($row['approved_not_released'] ?? 0);
    $completed = (int) ($row['completed_count'] ?? 0);
    $ongoing = (int) ($row['ongoing_count'] ?? 0);
    $fully_paid = (int) ($row['fully_paid_count'] ?? 0);
    // Total Loans should include released loans + fully paid (completed), excluding pending/for-release/rejected
    $total = (int) ($ongoing + $fully_paid + $completed);
    if ($total === 0) {
        $row['loan_status'] = 'No Loan';
    } elseif ($pending > 0) {
        $row['loan_status'] = 'Pending Application';
    } elseif ($ongoing > 0) {
        $row['loan_status'] = 'Active Loan';
    } elseif ($approved_not_released > 0) {
        $row['loan_status'] = 'Approved (For Release)';
    } elseif (($fully_paid + $completed) > 0) {
        $row['loan_status'] = 'Fully Paid';
    } else {
        $row['loan_status'] = 'No Loan';
    }
    $row['total_loans'] = $total;
    $borrower_users[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - DepEd Loan System</title>
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
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-title i {
            color: #8b0000;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
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
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .stat-trend {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
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
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .user-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .user-table tr:hover {
            background: #f8f9fa;
        }

        /* User Management: cards, search, filter, badges, action icons */
        .users-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .users-search-wrap {
            flex: 1;
            min-width: 200px;
            position: relative;
        }
        .users-search-wrap i.search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 0.9rem;
        }
        .users-search-wrap input {
            width: 100%;
            padding: 0.5rem 1rem 0.5rem 2.25rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .users-search-wrap input:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 2px rgba(139, 0, 0, 0.15);
        }
        .users-filter-wrap select {
            padding: 0.5rem 2rem 0.5rem 1rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 0.9rem;
            background: #fff;
        }
        .users-filter-wrap select:focus {
            outline: none;
            border-color: #8b0000;
        }
        .user-management-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .user-management-card .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .user-management-card .card-title i {
            color: #8b0000;
        }
        .user-table-wrapper {
            overflow-x: auto;
        }
        .user-table th, .user-table td {
            white-space: nowrap;
        }
        .user-table th:nth-last-child(1), .user-table td:nth-last-child(1) { white-space: nowrap; }
        .user-table .col-name, .user-table .col-email { white-space: normal; max-width: 180px; }
        .borrowers-cards-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }
        .borrower-user-card {
            background: linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.06);
            padding: 0.9rem 0.9rem 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.72rem;
            min-height: 196px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .borrower-user-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            opacity: 0.9;
        }
        .borrower-user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
            border-color: #d7dbe3;
        }
        .borrower-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.65rem;
            padding-top: 0.1rem;
        }
        .borrower-card-user {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
        }
        .borrower-card-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff1f2;
            color: #8b0000;
            border: 1px solid #fecdd3;
            font-weight: 700;
            font-size: 0.82rem;
            flex-shrink: 0;
            overflow: hidden;
        }
        .borrower-card-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }
        .borrower-card-user-text {
            min-width: 0;
        }
        .borrower-card-name {
            font-weight: 700;
            color: #1f2937;
            font-size: 0.92rem;
            line-height: 1.25;
            margin: 0 0 0.15rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .borrower-card-username {
            color: #6b7280;
            font-size: 0.77rem;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .borrower-card-meta {
            display: grid;
            gap: 0.42rem;
            border-top: 1px solid #f0f2f5;
            padding-top: 0.55rem;
        }
        .borrower-card-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.55rem;
            font-size: 0.8rem;
        }
        .borrower-card-row-label {
            color: #6b7280;
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .borrower-card-row-label i {
            color: #9ca3af;
            font-size: 0.72rem;
            width: 12px;
            text-align: center;
        }
        .borrower-card-row-value {
            color: #111827;
            text-align: right;
            min-width: 0;
            word-break: break-word;
            font-weight: 500;
        }
        .borrower-card-email {
            max-width: 175px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
        }
        .borrower-card-footer {
            margin-top: auto;
            padding-top: 0.55rem;
            border-top: 1px solid #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.55rem;
        }
        .borrower-card-idchip {
            font-size: 0.7rem;
            color: #6b7280;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 999px;
            padding: 0.12rem 0.5rem;
            font-weight: 600;
        }
        .borrower-empty-state {
            border: 1px dashed #d1d5db;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            color: #6b7280;
            background: #fafafa;
        }
        .users-pagination {
            margin-top: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .users-pagination-info {
            color: #6b7280;
            font-size: 0.84rem;
            font-weight: 600;
        }
        .users-pagination-controls {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .page-btn {
            min-width: 34px;
            height: 34px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            padding: 0 0.55rem;
        }
        .page-btn:hover { background: #f9fafb; }
        .page-btn.is-active {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            border-color: transparent;
        }
        .page-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-active { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .badge-inactive { background: #e9ecef; color: #495057; border: 1px solid #dee2e6; }
        .badge-suspended { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .badge-no-loan { background: #e9ecef; color: #495057; }
        .badge-pending-app { background: #fff3cd; color: #856404; }
        .badge-active-loan { background: #cce5ff; color: #004085; }
        .badge-fully-paid { background: #d4edda; color: #155724; }
        .action-icons {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }
        .action-icon-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: #f1f3f5;
            color: #495057;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s, color 0.2s;
        }
        .action-icon-btn:hover { background: #e9ecef; color: #333; }
        .action-icon-btn.view { color: #0d6efd; }
        .action-icon-btn.view:hover { background: #cfe2ff; }
        .action-icon-btn.edit { color: #198754; }
        .action-icon-btn.edit:hover { background: #d1e7dd; }
        .action-icon-btn.reset-pw { color: #fd7e14; }
        .action-icon-btn.reset-pw:hover { background: #ffe5d0; }
        .action-icon-btn.deactivate { color: #dc3545; }
        .action-icon-btn.deactivate:hover { background: #f8d7da; }
        .action-icon-btn.activate { color: #198754; }
        .action-icon-btn.activate:hover { background: #d1e7dd; }
        
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

        .user-form {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .form-grid.three-cols {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .form-group label {
            font-weight: 600;
            color: #333;
            font-size: 0.92rem;
        }

        .form-group input {
            padding: 0.6rem 0.75rem;
            border: 1.5px solid #c5ccd6;
            border-radius: 8px;
            font-size: 0.95rem;
            background: #fff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-group input:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.12);
        }
        .password-field-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-field-wrap input {
            width: 100%;
            padding-right: 2.45rem;
        }
        .password-toggle-btn {
            position: absolute;
            right: 0.45rem;
            top: 50%;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: #6b7280;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease, background 0.2s ease;
        }
        .password-toggle-btn:hover {
            color: #8b0000;
            background: #fff1f2;
        }

        .form-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.75rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .alert-success {
            background: #e7f7ed;
            color: #1e7a3a;
            border: 1px solid #c9ecd5;
        }

        .alert-error {
            background: #fdecea;
            color: #b42318;
            border: 1px solid #f7c6c0;
        }

        .field-hint { font-size: 0.85rem; margin-top: 0.35rem; display: flex; align-items: center; gap: 0.35rem; }
        .field-hint-error { color: #b42318; }

        /* Success modal – theme: DepEd red/maroon */
        .success-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .success-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .success-modal-box {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            max-width: 420px;
            width: 90%;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.25s ease;
        }
        .success-modal-overlay.active .success-modal-box {
            transform: scale(1);
        }
        .success-modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #a52a2a 50%, #dc143c 100%);
            padding: 1.5rem 1.5rem;
            text-align: center;
        }
        .success-modal-header .success-modal-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 0.75rem;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.75rem;
        }
        .success-modal-header .success-modal-title {
            color: #fff;
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
        }
        .success-modal-body {
            padding: 1.5rem 1.5rem;
        }
        .success-modal-body .success-modal-message {
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0;
            text-align: center;
        }
        .success-modal-footer {
            padding: 0 1.5rem 1.5rem;
            text-align: center;
        }
        .success-modal-footer .btn-success-ok {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            border: none;
            padding: 0.65rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .success-modal-footer .btn-success-ok:hover {
            box-shadow: 0 4px 14px rgba(139, 0, 0, 0.4);
            transform: translateY(-1px);
        }

        /* View User Modal */
        .view-modal-overlay, .reset-modal-overlay, .edit-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
        }
        .view-modal-overlay.active, .reset-modal-overlay.active, .edit-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .view-modal-box, .reset-modal-box, .edit-modal-box {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 860px;
            width: min(920px, 92vw);
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .view-modal-header, .reset-modal-header, .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%);
            color: #fff;
        }
        .view-modal-header h3, .reset-modal-header h3, .edit-modal-header h3 {
            font-size: 1.1rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .view-modal-close, .reset-modal-close, .edit-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            padding: 0;
            opacity: 0.9;
        }
        .view-modal-close:hover, .reset-modal-close:hover, .edit-modal-close:hover { opacity: 1; }
        .view-modal-body {
            padding: 1.25rem;
            overflow-y: auto;
        }
        .view-loading { color: #666; font-style: italic; }
        .view-profile-hero {
            border: 1px solid #eadfe2;
            border-radius: 12px;
            padding: 0.9rem 1rem;
            margin-bottom: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: linear-gradient(135deg, #fff9fa 0%, #ffffff 100%);
        }
        .view-profile-main {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            min-width: 0;
        }
        .view-profile-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fef2f2;
            color: #8b0000;
            font-weight: 700;
            border: 2px solid rgba(139, 0, 0, 0.18);
            flex-shrink: 0;
        }
        .view-profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .view-profile-name {
            margin: 0;
            font-weight: 700;
            color: #1f2937;
            font-size: 1.02rem;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .view-profile-username {
            margin: 0.15rem 0 0;
            font-size: 0.83rem;
            color: #6b7280;
        }
        .view-profile-chips {
            display: flex;
            gap: 0.45rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .view-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #374151;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.55rem;
        }
        .view-chip.status-active {
            border-color: #c3e6cb;
            background: #d4edda;
            color: #155724;
        }
        .view-chip.status-suspended {
            border-color: #f5c6cb;
            background: #f8d7da;
            color: #721c24;
        }
        .view-chip.status-inactive {
            border-color: #dee2e6;
            background: #e9ecef;
            color: #495057;
        }
        .view-summary-grid {
            margin-bottom: 0.95rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.7rem;
        }
        /* Admin / accountant: only User ID + Created (no loan columns) */
        .view-summary-grid.view-summary-grid--staff {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .view-summary-item {
            border: 1px solid #eadfe2;
            border-radius: 10px;
            background: #fff;
            padding: 0.55rem 0.7rem;
        }
        .view-summary-label {
            font-size: 0.72rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .view-summary-value {
            font-size: 0.95rem;
            color: #1f2937;
            font-weight: 700;
            line-height: 1.2;
            word-break: break-word;
        }
        .view-sections-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.8rem;
        }
        .view-section-card {
            border: 1px solid #eadfe2;
            border-radius: 10px;
            background: #fff;
            padding: 0.75rem 0.8rem;
        }
        .view-section-title {
            margin: 0 0 0.55rem;
            color: #8b0000;
            font-size: 0.84rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .view-kv-list {
            display: grid;
            gap: 0.42rem;
        }
        .view-kv-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.7rem;
            font-size: 0.88rem;
        }
        .view-kv-key {
            color: #6b7280;
            font-weight: 600;
            flex: 0 0 auto;
        }
        .view-kv-value {
            color: #111827;
            text-align: right;
            font-weight: 600;
            min-width: 0;
            word-break: break-word;
        }
        .view-note-line {
            margin-top: 0.75rem;
            border-top: 1px dashed #e5e7eb;
            padding-top: 0.6rem;
            font-size: 0.82rem;
            color: #4b5563;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .view-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px 14px;
        }
        .view-item {
            border: 1px solid rgba(139, 0, 0, 0.22);
            background: #fff;
            border-radius: 10px;
            padding: 10px 12px;
            min-height: 58px;
        }
        .view-item-label {
            font-weight: 700;
            color: #666;
            font-size: 0.78rem;
            letter-spacing: 0.2px;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .view-item-value { color: #222; font-size: 0.98rem; word-break: break-word; }
        @media (max-width: 900px) {
            .view-profile-hero { flex-direction: column; align-items: flex-start; }
            .view-profile-chips { justify-content: flex-start; }
            .view-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .view-sections-grid { grid-template-columns: 1fr; }
            .view-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 520px) {
            .view-summary-grid { grid-template-columns: 1fr; }
            .view-grid { grid-template-columns: 1fr; }
        }
        .reset-modal-body { padding: 1.25rem; }
        .reset-target-name { font-weight: 600; margin-bottom: 1rem; color: #333; }
        .reset-modal-body .form-group { margin-bottom: 1rem; }
        .reset-modal-body label { display: block; margin-bottom: 0.35rem; font-weight: 500; color: #555; }
        .reset-modal-body input[type="password"] {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .reset-modal-body .form-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.35rem;
            font-style: italic;
        }
        .reset-modal-body .password-strength {
            margin-top: 0.55rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .reset-modal-body .password-strength-bar {
            height: 8px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
            flex: 1;
        }
        .reset-modal-body .password-strength-bar-fill {
            height: 100%;
            width: 0%;
            border-radius: 999px;
            background: #e74c3c;
            transition: width 0.25s ease, background-color 0.25s ease;
        }
        .reset-modal-body .password-strength-text {
            font-size: 0.82rem;
            font-weight: 600;
            min-width: 108px;
            text-align: right;
            color: #6b7280;
        }
        .reset-error { color: #dc3545; font-size: 0.9rem; margin-top: 0.5rem; }
        .reset-modal-footer {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        .btn-cancel { padding: 0.5rem 1rem; border: 1px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer; }
        .btn-cancel:hover { background: #f8f9fa; }
        .reset-modal-footer .btn-submit {
            padding: 0.5rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .reset-modal-footer .btn-submit:hover { opacity: 0.95; }

        /* Edit User Modal */
        .edit-modal-body { padding: 1.25rem; overflow-y: auto; }
        .edit-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem 1rem;
        }
        .edit-form-grid .form-group { margin-bottom: 0; }
        .edit-modal-footer {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            border-top: 1px solid #eee;
        }
        .edit-save-btn {
            padding: 0.55rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }
        .edit-save-btn:disabled { opacity: 0.7; cursor: not-allowed; }
        .edit-inline-alert { margin-bottom: 0.85rem; display:none; }
        .edit-inline-alert.active { display:block; }
        @media (max-width: 640px) {
            .edit-form-grid { grid-template-columns: 1fr; }
        }

        /* Confirmation + Info Modals (theme-consistent) */
        .confirm-modal-overlay, .info-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2200;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
            padding: 1.25rem;
        }
        .confirm-modal-overlay.active, .info-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .confirm-modal-box, .info-modal-box {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 18px 50px rgba(0,0,0,0.25);
            width: min(520px, 96vw);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.06);
        }
        .confirm-modal-header, .info-modal-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #a52a2a 50%, #dc143c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .confirm-modal-header h3, .info-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
        }
        .confirm-modal-close, .info-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            opacity: 0.9;
        }
        .confirm-modal-close:hover, .info-modal-close:hover { opacity: 1; }
        .confirm-modal-body, .info-modal-body {
            padding: 1.1rem 1.25rem;
            color: #374151;
            line-height: 1.55;
        }
        .confirm-modal-note {
            margin-top: 0.65rem;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #92400e;
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            font-size: 0.9rem;
        }
        .confirm-modal-footer, .info-modal-footer {
            padding: 0.95rem 1.25rem;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            border-top: 1px solid #eee;
        }
        .btn-outline {
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-outline:hover { background: #f9fafb; }
        .btn-danger {
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }
        .btn-danger:hover { opacity: 0.96; }
        
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-grid.three-cols {
                grid-template-columns: 1fr;
            }

            .users-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .users-search-wrap { min-width: 0; }
            .user-management-card {
                padding: 1rem;
            }
            .borrowers-cards-grid {
                grid-template-columns: 1fr;
            }
            .users-pagination {
                flex-direction: column;
                align-items: stretch;
            }
            .users-pagination-controls {
                flex-wrap: wrap;
            }
        }
        @media (max-width: 1400px) and (min-width: 1201px) {
            .borrowers-cards-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
        @media (max-width: 1200px) and (min-width: 993px) {
            .borrowers-cards-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 992px) and (min-width: 769px) {
            .borrowers-cards-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
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

            .content-section,
            .user-management-card {
                overflow-x: auto;
            }

            .user-table {
                min-width: 760px;
            }
        }

        @media (max-width: 600px) {
            .user-table {
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
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>! 👋</div>
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
                    <a href="manage_users.php" class="sidebar-link active">
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
                <h2 class="section-title"><i class="fas fa-user-plus"></i> Create Accounting Employee</h2>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>

                <form class="user-form" method="POST" action="manage_users.php">
                    <div class="form-grid three-cols">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" name="middle_name" id="middle_name" value="<?php echo htmlspecialchars($middle_name ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-grid three-cols">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($username ?? ''); ?>" required>
                            <span id="username-taken-msg" class="field-hint field-hint-error" style="display:none;"><i class="fas fa-exclamation-circle"></i> This username already has an account.</span>
                        </div>
                        <div class="form-group">
                            <label for="contact_number">Contact Number</label>
                            <input type="tel" name="contact_number" id="contact_number" placeholder="09XXXXXXXXX" maxlength="11" required>
                            <small style="color: #666; font-size: 12px;">Format: 09XXXXXXXXX</small>
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required autocomplete="email">
                            <span id="email-taken-msg" class="field-hint field-hint-error" style="display:none;"><i class="fas fa-exclamation-circle"></i> This email already has an account.</span>
                        </div>
                    </div>
                    <div class="form-grid three-cols">
                        <div class="form-group">
                            <label for="confirm_email">Confirm Email</label>
                            <input type="email" name="confirm_email" id="confirm_email" value="<?php echo htmlspecialchars($confirm_email ?? ''); ?>" required autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-field-wrap">
                                <input type="password" name="password" id="password" required autocomplete="new-password">
                                <button type="button" class="password-toggle-btn" data-target="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="password-field-wrap">
                                <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password">
                                <button type="button" class="password-toggle-btn" data-target="confirm_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary"><i class="fas fa-user-check"></i> Create Accounting Account</button>
                    </div>
                </form>
            </div>

            <div class="content-section">
                <div class="user-management-card">
                    <h2 class="card-title"><i class="fas fa-users"></i> Accounting Employees</h2>
                    <div class="users-toolbar">
                        <div class="users-search-wrap">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="searchAccounting" placeholder="Search users..." autocomplete="off">
                        </div>
                        <div class="users-filter-wrap">
                            <select id="filterAccounting" aria-label="Filter">
                                <option value="">All Users</option>
                                <option value="active">Active Users</option>
                                <option value="inactive">Inactive Users</option>
                            </select>
                        </div>
                    </div>
                    <div id="tbodyAccounting" class="borrowers-cards-grid">
                        <?php if (!empty($accounting_users)): ?>
                            <?php foreach ($accounting_users as $row): ?>
                                <?php
                                $status = strtolower($row['user_status'] ?? 'active');
                                $status_class = $status === 'active' ? 'badge-active' : ($status === 'suspended' ? 'badge-suspended' : 'badge-inactive');
                                $status_label = ucfirst($status);
                                ?>
                                <article class="borrower-user-card" data-user-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['full_name']); ?>" data-username="<?php echo htmlspecialchars($row['username']); ?>" data-email="<?php echo htmlspecialchars($row['email']); ?>" data-status="<?php echo htmlspecialchars($status); ?>">
                                    <div class="borrower-card-head">
                                        <div class="borrower-card-user">
                                            <span class="borrower-card-avatar">
                                                <?php
                                                $card_profile_photo = (string)($row['profile_photo'] ?? '');
                                                $card_profile_exists = $card_profile_photo !== '' && file_exists(__DIR__ . '/' . $card_profile_photo);
                                                ?>
                                                <?php if ($card_profile_exists): ?>
                                                    <img src="<?php echo htmlspecialchars($card_profile_photo); ?>" alt="<?php echo htmlspecialchars($row['full_name'] ?? 'User'); ?>">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr((string)($row['full_name'] ?? 'U'), 0, 1)); ?>
                                                <?php endif; ?>
                                            </span>
                                            <div class="borrower-card-user-text">
                                                <p class="borrower-card-name"><?php echo htmlspecialchars($row['full_name']); ?></p>
                                                <p class="borrower-card-username">@<?php echo htmlspecialchars($row['username']); ?></p>
                                            </div>
                                        </div>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                    </div>
                                    <div class="borrower-card-meta">
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-envelope"></i>Email</span>
                                            <span class="borrower-card-row-value borrower-card-email" title="<?php echo htmlspecialchars($row['email']); ?>"><?php echo htmlspecialchars($row['email']); ?></span>
                                        </div>
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-phone"></i>Contact</span>
                                            <span class="borrower-card-row-value"><?php echo htmlspecialchars($row['contact_number'] ?? '—'); ?></span>
                                        </div>
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-user-tag"></i>Role</span>
                                            <span class="borrower-card-row-value">Accountant</span>
                                        </div>
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-calendar-alt"></i>Created</span>
                                            <span class="borrower-card-row-value"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="borrower-card-footer">
                                        <span class="borrower-card-idchip">ID #<?php echo (int)$row['id']; ?></span>
                                        <div class="action-icons">
                                            <button type="button" class="action-icon-btn view" title="View" aria-label="View"><i class="fas fa-eye"></i></button>
                                            <button type="button" class="action-icon-btn edit" title="Edit" aria-label="Edit"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="action-icon-btn reset-pw" title="Reset Password" aria-label="Reset Password"><i class="fas fa-key"></i></button>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="borrower-empty-state">No accountant employees yet.</div>
                        <?php endif; ?>
                    </div>
                    <div class="users-pagination" id="paginationAccounting">
                        <div class="users-pagination-info" id="paginationInfoAccounting"></div>
                        <div class="users-pagination-controls" id="paginationControlsAccounting"></div>
                    </div>
                </div>
            </div>

            <div class="content-section">
                <div class="user-management-card">
                    <h2 class="card-title"><i class="fas fa-user-friends"></i> Borrowers</h2>
                    <div class="users-toolbar">
                        <div class="users-search-wrap">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="searchBorrowers" placeholder="Search users..." autocomplete="off">
                        </div>
                        <div class="users-filter-wrap">
                            <select id="filterBorrowers" aria-label="Filter">
                                <option value="">All Users</option>
                                <option value="active">Active Users</option>
                                <option value="inactive">Inactive Users</option>
                                <option value="with_active_loan">With Active Loan</option>
                                <option value="no_loan">No Loan</option>
                            </select>
                        </div>
                    </div>
                    <div id="tbodyBorrowers" class="borrowers-cards-grid">
                        <?php if (!empty($borrower_users)): ?>
                            <?php foreach ($borrower_users as $row): ?>
                                <?php
                                $status = strtolower($row['user_status'] ?? 'active');
                                $status_class = $status === 'active' ? 'badge-active' : ($status === 'suspended' ? 'badge-suspended' : 'badge-inactive');
                                $status_label = ucfirst($status);
                                $ls = $row['loan_status'] ?? 'No Loan';
                                $loan_badge_class = 'badge-no-loan';
                                if ($ls === 'Pending Application') $loan_badge_class = 'badge-pending-app';
                                elseif ($ls === 'Active Loan') $loan_badge_class = 'badge-active-loan';
                                elseif ($ls === 'Approved (For Release)') $loan_badge_class = 'badge-pending-app';
                                elseif ($ls === 'Fully Paid') $loan_badge_class = 'badge-fully-paid';
                                ?>
                                <article class="borrower-user-card" data-user-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['full_name']); ?>" data-username="<?php echo htmlspecialchars($row['username']); ?>" data-email="<?php echo htmlspecialchars($row['email']); ?>" data-status="<?php echo htmlspecialchars($status); ?>" data-loan-status="<?php echo htmlspecialchars($ls); ?>">
                                    <div class="borrower-card-head">
                                        <div class="borrower-card-user">
                                            <span class="borrower-card-avatar">
                                                <?php
                                                $card_profile_photo = (string)($row['profile_photo'] ?? '');
                                                $card_profile_exists = $card_profile_photo !== '' && file_exists(__DIR__ . '/' . $card_profile_photo);
                                                ?>
                                                <?php if ($card_profile_exists): ?>
                                                    <img src="<?php echo htmlspecialchars($card_profile_photo); ?>" alt="<?php echo htmlspecialchars($row['full_name'] ?? 'User'); ?>">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr((string)($row['full_name'] ?? 'U'), 0, 1)); ?>
                                                <?php endif; ?>
                                            </span>
                                            <div class="borrower-card-user-text">
                                                <p class="borrower-card-name"><?php echo htmlspecialchars($row['full_name']); ?></p>
                                                <p class="borrower-card-username">@<?php echo htmlspecialchars($row['username']); ?></p>
                                            </div>
                                        </div>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                    </div>
                                    <div class="borrower-card-meta">
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-envelope"></i>Email</span>
                                            <span class="borrower-card-row-value borrower-card-email" title="<?php echo htmlspecialchars($row['email']); ?>"><?php echo htmlspecialchars($row['email']); ?></span>
                                        </div>
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-file-invoice-dollar"></i>Loan</span>
                                            <span class="borrower-card-row-value"><span class="badge <?php echo $loan_badge_class; ?>"><?php echo htmlspecialchars($ls); ?></span></span>
                                        </div>
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-layer-group"></i>Total</span>
                                            <span class="borrower-card-row-value"><?php echo (int) ($row['total_loans'] ?? 0); ?></span>
                                        </div>
                                        <div class="borrower-card-row">
                                            <span class="borrower-card-row-label"><i class="fas fa-calendar-alt"></i>Created</span>
                                            <span class="borrower-card-row-value"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="borrower-card-footer">
                                        <span class="borrower-card-idchip">ID #<?php echo (int)$row['id']; ?></span>
                                        <div class="action-icons">
                                            <button type="button" class="action-icon-btn view" title="View" aria-label="View"><i class="fas fa-eye"></i></button>
                                            <button type="button" class="action-icon-btn edit" title="Edit" aria-label="Edit"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="action-icon-btn reset-pw" title="Reset Password" aria-label="Reset Password"><i class="fas fa-key"></i></button>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="borrower-empty-state">No borrowers yet.</div>
                        <?php endif; ?>
                    </div>
                    <div class="users-pagination" id="paginationBorrowers">
                        <div class="users-pagination-info" id="paginationInfoBorrowers"></div>
                        <div class="users-pagination-controls" id="paginationControlsBorrowers"></div>
                    </div>
                </div>
            </div>

            <!-- Dashboard overview and charts removed from Manage Users -->
        </main>
    </div>
    
    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <!-- View User Modal -->
    <div id="viewUserModalOverlay" class="view-modal-overlay">
        <div class="view-modal-box" onclick="event.stopPropagation()">
            <div class="view-modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-user"></i> User Details</h3>
                <button type="button" class="view-modal-close" onclick="closeViewModal()" aria-label="Close">&times;</button>
            </div>
            <div class="view-modal-body" id="viewModalBody">
                <p class="view-loading">Loading...</p>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPwModalOverlay" class="reset-modal-overlay">
        <div class="reset-modal-box" onclick="event.stopPropagation()">
            <div class="reset-modal-header">
                <h3><i class="fas fa-key"></i> Reset Password</h3>
                <button type="button" class="reset-modal-close" onclick="closeResetPwModal()" aria-label="Close">&times;</button>
            </div>
            <form id="resetPwForm" method="post" action="manage_users.php">
                <input type="hidden" name="admin_action" value="reset_password">
                <input type="hidden" name="target_user_id" id="resetPwTargetId" value="">
                <div class="reset-modal-body">
                    <p class="reset-target-name" id="resetPwTargetName"></p>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-field-wrap">
                            <input type="password" id="new_password" name="new_password" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one uppercase, one lowercase, one number, and at least 8 characters. For stronger security, add a special character (example: Password_123)." placeholder="At least 8 characters">
                            <button type="button" class="password-toggle-btn" data-target="new_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                        <div class="form-hint">Use at least 8 chars with uppercase, lowercase, number, and ideally a special character. Example: <strong>Password_123</strong></div>
                        <div class="password-strength">
                            <div class="password-strength-bar">
                                <div id="resetPasswordStrengthBarFill" class="password-strength-bar-fill"></div>
                            </div>
                            <span id="resetPasswordStrengthText" class="password-strength-text"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_new_password">Confirm New Password</label>
                        <div class="password-field-wrap">
                            <input type="password" id="confirm_new_password" name="confirm_new_password" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one uppercase, one lowercase, one number, and at least 8 characters. For stronger security, add a special character (example: Password_123)." placeholder="Confirm password">
                            <button type="button" class="password-toggle-btn" data-target="confirm_new_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <p id="resetPwError" class="reset-error" style="display:none;"></p>
                </div>
                <div class="reset-modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeResetPwModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModalOverlay" class="edit-modal-overlay" aria-hidden="true">
        <div class="edit-modal-box" onclick="event.stopPropagation()" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
            <div class="edit-modal-header">
                <h3 id="editModalTitle"><i class="fas fa-user-edit"></i> Edit User</h3>
                <button type="button" class="edit-modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
            </div>
            <form id="editUserForm">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="admin_action" value="edit_user">
                <input type="hidden" name="target_user_id" id="edit_target_user_id" value="">
                <div class="edit-modal-body">
                    <div id="editInlineAlert" class="alert edit-inline-alert"></div>
                    <div class="edit-form-grid">
                        <div class="form-group">
                            <label for="edit_username">Username <span style="color:red;">*</span></label>
                            <input type="text" id="edit_username" name="edit_username" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_email">Email <span style="color:red;">*</span></label>
                            <input type="email" id="edit_email" name="edit_email" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_contact_number">Contact Number <span style="color:red;">*</span></label>
                            <input type="tel" id="edit_contact_number" name="edit_contact_number" required placeholder="09XXXXXXXXX" maxlength="11">
                        </div>
                        <div class="form-group">
                            <label for="edit_deped_id">Employee Deped No.</label>
                            <input type="text" id="edit_deped_id" name="edit_deped_id" placeholder="1234567" maxlength="7">
                        </div>
                        <div class="form-group">
                            <label for="edit_home_address">Home Address</label>
                            <textarea id="edit_home_address" name="edit_home_address" rows="3" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="edit_role">Role</label>
                            <input type="text" id="edit_role" disabled style="width:100%;padding:0.6rem 0.75rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;background:#f8f9fa;">
                        </div>
                    </div>
                </div>
                <div class="edit-modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="edit-save-btn" id="editSaveBtn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModalOverlay" class="confirm-modal-overlay" aria-hidden="true">
        <div class="confirm-modal-box" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" onclick="event.stopPropagation()">
            <div class="confirm-modal-header">
                <h3 id="confirmModalTitle"><i class="fas fa-shield-alt"></i> Confirm Action</h3>
                <button type="button" class="confirm-modal-close" onclick="closeConfirmModal()" aria-label="Close">&times;</button>
            </div>
            <div class="confirm-modal-body">
                <div id="confirmModalMessage"></div>
                <div id="confirmModalNote" class="confirm-modal-note" style="display:none;"></div>
            </div>
            <div class="confirm-modal-footer">
                <button type="button" class="btn-outline" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" class="btn-danger" id="confirmModalPrimaryBtn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Info Modal (client-side) -->
    <div id="infoModalOverlay" class="info-modal-overlay" aria-hidden="true">
        <div class="info-modal-box" role="dialog" aria-modal="true" aria-labelledby="infoModalTitle" onclick="event.stopPropagation()">
            <div class="info-modal-header">
                <h3 id="infoModalTitle"><i class="fas fa-info-circle"></i> Information</h3>
                <button type="button" class="info-modal-close" onclick="closeInfoModal()" aria-label="Close">&times;</button>
            </div>
            <div class="info-modal-body">
                <div id="infoModalMessage"></div>
            </div>
            <div class="info-modal-footer">
                <button type="button" class="btn-danger" onclick="closeInfoModal()">OK</button>
            </div>
        </div>
    </div>

    <!-- Deactivate/Activate Confirmation Modal -->
    <form id="deactivateForm" method="post" action="manage_users.php" style="display:none;">
        <input type="hidden" name="admin_action" id="deactivateAction" value="">
        <input type="hidden" name="target_user_id" id="deactivateTargetId" value="">
    </form>

    <?php if (!empty($success)): ?>
    <div id="successModalOverlay" class="success-modal-overlay active">
        <div class="success-modal-box" onclick="event.stopPropagation()">
            <div class="success-modal-header">
                <div class="success-modal-icon"><i class="fas fa-check"></i></div>
                <h3 class="success-modal-title">Success</h3>
            </div>
            <div class="success-modal-body">
                <p class="success-modal-message"><?php echo htmlspecialchars($success); ?></p>
            </div>
            <div class="success-modal-footer">
                <button type="button" class="btn-success-ok" onclick="closeSuccessModal()">OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function closeSuccessModal() {
            var el = document.getElementById('successModalOverlay');
            if (el) el.classList.remove('active');
        }
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'successModalOverlay') closeSuccessModal();
        });
    </script>

    <script>
        (function() {
            var emailInput = document.getElementById('email');
            var usernameInput = document.getElementById('username');
            var emailMsg = document.getElementById('email-taken-msg');
            var usernameMsg = document.getElementById('username-taken-msg');
            var checkTimeout = null;

            function checkExists() {
                var email = (emailInput && emailInput.value) ? emailInput.value.trim() : '';
                var username = (usernameInput && usernameInput.value) ? usernameInput.value.trim() : '';
                if (!email && !username) {
                    if (emailMsg) emailMsg.style.display = 'none';
                    if (usernameMsg) usernameMsg.style.display = 'none';
                    return;
                }
                var url = 'check_manage_user_exists.php?email=' + encodeURIComponent(email) + '&username=' + encodeURIComponent(username);
                fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.error) return;
                    if (emailMsg) emailMsg.style.display = (data.emailExists && email) ? 'flex' : 'none';
                    if (usernameMsg) usernameMsg.style.display = (data.usernameExists && username) ? 'flex' : 'none';
                }).catch(function() {});
            }

            function scheduleCheck() {
                if (checkTimeout) clearTimeout(checkTimeout);
                checkTimeout = setTimeout(checkExists, 400);
            }

            if (emailInput) { emailInput.addEventListener('blur', scheduleCheck); emailInput.addEventListener('input', function() { if (emailMsg) emailMsg.style.display = 'none'; }); }
            if (usernameInput) { usernameInput.addEventListener('blur', scheduleCheck); usernameInput.addEventListener('input', function() { if (usernameMsg) usernameMsg.style.display = 'none'; }); }
        })();
    </script>

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

        // User tables: search and filter
        function applySearchFilter(tableBodyId, searchId, filterId, options) {
            const tbody = document.getElementById(tableBodyId);
            const searchEl = document.getElementById(searchId);
            const filterEl = document.getElementById(filterId);
            if (!tbody || !searchEl) return;
            const rows = Array.from(tbody.querySelectorAll('[data-user-id]'));
            const hasLoanCol = options && options.hasLoanStatus;
            const pageSize = 12;
            let currentPage = 1;
            let filteredRows = rows.slice();
            const suffix = tableBodyId.replace('tbody', '');
            const infoEl = document.getElementById('paginationInfo' + suffix);
            const controlsEl = document.getElementById('paginationControls' + suffix);

            function matches(tr, q, filter) {
                const name = (tr.getAttribute('data-name') || '').toLowerCase();
                const username = (tr.getAttribute('data-username') || '').toLowerCase();
                const email = (tr.getAttribute('data-email') || '').toLowerCase();
                const status = (tr.getAttribute('data-status') || '').toLowerCase();
                const loanStatus = (tr.getAttribute('data-loan-status') || '').toLowerCase();

                const matchSearch = !q || name.indexOf(q) !== -1 || username.indexOf(q) !== -1 || email.indexOf(q) !== -1;
                let matchFilter = true;
                if (filter === 'active') matchFilter = status === 'active';
                else if (filter === 'inactive') matchFilter = status === 'inactive';
                else if (hasLoanCol && filter === 'with_active_loan') matchFilter = loanStatus === 'active loan';
                else if (hasLoanCol && filter === 'no_loan') matchFilter = loanStatus === 'no loan';
                return matchSearch && matchFilter;
            }

            function renderPagination(totalItems) {
                if (!infoEl || !controlsEl) return;
                const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                const start = totalItems === 0 ? 0 : ((currentPage - 1) * pageSize + 1);
                const end = Math.min(currentPage * pageSize, totalItems);
                infoEl.textContent = 'Showing ' + start + '–' + end + ' of ' + totalItems;

                controlsEl.innerHTML = '';
                const prev = document.createElement('button');
                prev.type = 'button';
                prev.className = 'page-btn';
                prev.textContent = 'Prev';
                prev.disabled = currentPage === 1;
                prev.addEventListener('click', function () {
                    currentPage -= 1;
                    render();
                });
                controlsEl.appendChild(prev);

                for (let p = 1; p <= totalPages; p++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'page-btn' + (p === currentPage ? ' is-active' : '');
                    btn.textContent = String(p);
                    btn.addEventListener('click', function () {
                        currentPage = p;
                        render();
                    });
                    controlsEl.appendChild(btn);
                }

                const next = document.createElement('button');
                next.type = 'button';
                next.className = 'page-btn';
                next.textContent = 'Next';
                next.disabled = currentPage === totalPages;
                next.addEventListener('click', function () {
                    currentPage += 1;
                    render();
                });
                controlsEl.appendChild(next);
            }

            function render() {
                const q = (searchEl.value || '').trim().toLowerCase();
                const filter = filterEl ? (filterEl.value || '').toLowerCase() : '';
                filteredRows = rows.filter(function (tr) { return matches(tr, q, filter); });
                const startIdx = (currentPage - 1) * pageSize;
                const endIdx = startIdx + pageSize;

                rows.forEach(function (tr) { tr.style.display = 'none'; });
                filteredRows.slice(startIdx, endIdx).forEach(function (tr) { tr.style.display = ''; });
                renderPagination(filteredRows.length);
            }

            function resetAndRender() {
                currentPage = 1;
                render();
            }

            searchEl.addEventListener('input', resetAndRender);
            searchEl.addEventListener('keyup', resetAndRender);
            if (filterEl) filterEl.addEventListener('change', resetAndRender);
            render();
        }
        applySearchFilter('tbodyAccounting', 'searchAccounting', 'filterAccounting', {});
        applySearchFilter('tbodyBorrowers', 'searchBorrowers', 'filterBorrowers', { hasLoanStatus: true });

        // Action buttons: View, Edit, Reset Password, Deactivate/Activate
        function openViewModal(userId) {
            var overlay = document.getElementById('viewUserModalOverlay');
            var body = document.getElementById('viewModalBody');
            var titleEl = document.getElementById('viewModalTitle');
            if (!overlay || !body) return;
            if (titleEl) titleEl.innerHTML = '<i class="fas fa-user"></i> User Details';
            body.innerHTML = '<p class="view-loading">Loading...</p>';
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            fetch('get_user_details.php?id=' + encodeURIComponent(userId))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.user) {
                        body.innerHTML = '<p class="reset-error">Failed to load user details.</p>';
                        return;
                    }
                    var u = data.user;
                    var roleLower = String(u.role || 'borrower').toLowerCase();
                    var isStaff = roleLower === 'admin' || roleLower === 'accountant' || roleLower === 'accounting';
                    var modalTitle = 'User Details';
                    var modalIcon = 'fa-user';
                    if (roleLower === 'admin') {
                        modalTitle = 'Administrator Details';
                        modalIcon = 'fa-user-shield';
                    } else if (roleLower === 'accountant' || roleLower === 'accounting') {
                        modalTitle = 'Accountant Details';
                        modalIcon = 'fa-user-tie';
                    } else if (roleLower === 'borrower') {
                        modalTitle = 'Borrower Details';
                        modalIcon = 'fa-hand-holding-usd';
                    }
                    if (titleEl) titleEl.innerHTML = '<i class="fas ' + modalIcon + '"></i> ' + escapeHtml(modalTitle);

                    var status = String(u.user_status || 'active').toLowerCase();
                    var statusClass = 'status-inactive';
                    if (status === 'active') statusClass = 'status-active';
                    else if (status === 'suspended') statusClass = 'status-suspended';
                    var roleText = String(u.role || 'borrower');
                    var initial = ((u.full_name || 'U').trim().charAt(0) || 'U').toUpperCase();
                    var profilePhoto = String(u.profile_photo || '').trim();
                    var totalLoans = Number(u.total_loans || 0);
                    var hasContact = !!String(u.contact_number || '').trim();
                    var hasDeped = !!String(u.deped_id || '').trim();
                    var hasAddress = !!String(u.home_address || '').trim();
                    var avatarHtml = profilePhoto
                        ? '<img src="' + escapeHtml(profilePhoto) + '" alt="' + escapeHtml(u.full_name || 'User') + '">'
                        : escapeHtml(initial);

                    var createdText = String(u.created_at || '').replace('T', ' ');
                    var loanStatusText = !isStaff ? String(u.loan_status || 'No Loan') : '';
                    var adminInsight = [];
                    if (!isStaff) {
                        if (loanStatusText === 'Active Loan') adminInsight.push('Borrower currently has an active repayment');
                        else if (loanStatusText === 'Pending Application') adminInsight.push('Borrower has a pending application to review');
                        else if (loanStatusText === 'No Loan') adminInsight.push('Borrower has no active or pending loan');
                    }
                    if (!hasContact) adminInsight.push('Contact number is missing');
                    if (!hasDeped) adminInsight.push('DepEd number is not set');
                    if (!hasAddress) adminInsight.push('Home address is not set');
                    if (adminInsight.length === 0) adminInsight.push('Profile record appears complete');

                    var html = ''
                        + '<div class="view-profile-hero">'
                        +   '<div class="view-profile-main">'
                        +     '<div class="view-profile-avatar">' + avatarHtml + '</div>'
                        +     '<div>'
                        +       '<p class="view-profile-name">' + escapeHtml(u.full_name || 'User') + '</p>'
                        +       '<p class="view-profile-username">@' + escapeHtml(u.username || '') + '</p>'
                        +     '</div>'
                        +   '</div>'
                        +   '<div class="view-profile-chips">'
                        +     '<span class="view-chip"><i class="fas fa-id-badge"></i> ' + escapeHtml(roleText) + '</span>'
                        +     '<span class="view-chip ' + statusClass + '"><i class="fas fa-shield-alt"></i> ' + escapeHtml(status) + '</span>'
                        +   '</div>'
                        + '</div>';

                    html += '<div class="view-summary-grid' + (isStaff ? ' view-summary-grid--staff' : '') + '">'
                        + '<div class="view-summary-item"><div class="view-summary-label">User ID</div><div class="view-summary-value">#' + escapeHtml(String(u.id || '')) + '</div></div>';
                    if (!isStaff) {
                        html += '<div class="view-summary-item"><div class="view-summary-label">Loan Status</div><div class="view-summary-value">' + escapeHtml(loanStatusText) + '</div></div>'
                            + '<div class="view-summary-item"><div class="view-summary-label">Total Loans</div><div class="view-summary-value">' + escapeHtml(String(totalLoans)) + '</div></div>';
                    }
                    html += '<div class="view-summary-item"><div class="view-summary-label">Created</div><div class="view-summary-value">' + escapeHtml(createdText || '—') + '</div></div>'
                        + '</div>';

                    html += '<div class="view-sections-grid">'
                        +   '<section class="view-section-card">'
                        +     '<h4 class="view-section-title"><i class="fas fa-user-circle"></i> Account Profile</h4>'
                        +     '<div class="view-kv-list">'
                        +       '<div class="view-kv-row"><span class="view-kv-key">Name</span><span class="view-kv-value">' + escapeHtml(u.full_name || '—') + '</span></div>'
                        +       '<div class="view-kv-row"><span class="view-kv-key">Username</span><span class="view-kv-value">' + escapeHtml(u.username || '—') + '</span></div>'
                        +       '<div class="view-kv-row"><span class="view-kv-key">Role</span><span class="view-kv-value">' + escapeHtml(roleText) + '</span></div>'
                        +       '<div class="view-kv-row"><span class="view-kv-key">Status</span><span class="view-kv-value">' + escapeHtml(status) + '</span></div>'
                        +     '</div>'
                        +   '</section>'
                        +   '<section class="view-section-card">'
                        +     '<h4 class="view-section-title"><i class="fas fa-address-book"></i> Contact & Identity</h4>'
                        +     '<div class="view-kv-list">'
                        +       '<div class="view-kv-row"><span class="view-kv-key">Email</span><span class="view-kv-value">' + escapeHtml(u.email || '—') + '</span></div>'
                        +       '<div class="view-kv-row"><span class="view-kv-key">Contact</span><span class="view-kv-value">' + escapeHtml(u.contact_number || '—') + '</span></div>'
                        +       '<div class="view-kv-row"><span class="view-kv-key">DepEd No.</span><span class="view-kv-value">' + escapeHtml(u.deped_id || '—') + '</span></div>'
                        +     '</div>'
                        +   '</section>';

                    if (!isStaff) {
                        html += '<section class="view-section-card">'
                            +   '<h4 class="view-section-title"><i class="fas fa-landmark"></i> Loan Snapshot</h4>'
                            +   '<div class="view-kv-list">'
                            +     '<div class="view-kv-row"><span class="view-kv-key">Loan status</span><span class="view-kv-value">' + escapeHtml(loanStatusText) + '</span></div>'
                            +     '<div class="view-kv-row"><span class="view-kv-key">Total loans</span><span class="view-kv-value">' + escapeHtml(String(totalLoans)) + '</span></div>'
                            +     '<div class="view-kv-row"><span class="view-kv-key">School/Assignment</span><span class="view-kv-value">' + escapeHtml(u.school_assignment || '—') + '</span></div>'
                            +   '</div>'
                            + '</section>';
                    }

                    html += '<section class="view-section-card">'
                        +   '<h4 class="view-section-title"><i class="fas fa-map-marker-alt"></i> Location</h4>'
                        +   '<div class="view-kv-list">'
                        +     '<div class="view-kv-row"><span class="view-kv-key">Address</span><span class="view-kv-value">' + escapeHtml(u.home_address || '—') + '</span></div>'
                        +   '</div>'
                        + '</section>'
                        + '</div>';

                    html += '<div class="view-note-line"><i class="fas fa-lightbulb"></i>' + escapeHtml(adminInsight[0]) + '</div>';
                    body.innerHTML = html;
                })
                .catch(function() {
                    body.innerHTML = '<p class="reset-error">Failed to load user details.</p>';
                });
        }
        function closeViewModal() {
            var overlay = document.getElementById('viewUserModalOverlay');
            if (overlay) { overlay.classList.remove('active'); document.body.style.overflow = 'auto'; }
        }
        function escapeHtml(s) {
            if (s == null) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        function openResetPwModal(userId, userName) {
            document.getElementById('resetPwTargetId').value = userId;
            document.getElementById('resetPwTargetName').textContent = 'Reset password for: ' + (userName || 'User #' + userId);
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_new_password').value = '';
            document.getElementById('resetPwError').style.display = 'none';
            updateResetPasswordStrength('');
            document.getElementById('resetPwModalOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeResetPwModal() {
            document.getElementById('resetPwModalOverlay').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        document.getElementById('resetPwForm').addEventListener('submit', function(e) {
            var np = document.getElementById('new_password').value;
            var cp = document.getElementById('confirm_new_password').value;
            var errEl = document.getElementById('resetPwError');
            if (np.length < 8) {
                e.preventDefault();
                errEl.textContent = 'Password must be at least 8 characters.';
                errEl.style.display = 'block';
                return;
            }
            if (!/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}$/.test(np)) {
                e.preventDefault();
                errEl.textContent = 'Password must contain at least one uppercase, one lowercase, and one number.';
                errEl.style.display = 'block';
                return;
            }
            if (np !== cp) {
                e.preventDefault();
                errEl.textContent = 'Passwords do not match.';
                errEl.style.display = 'block';
                return;
            }
            // Security confirmation (step 1 of 2) then final confirmation (step 2 of 2)
            e.preventDefault();
            errEl.style.display = 'none';
            openConfirmModal({
                title: 'Confirm Password Reset',
                message: 'You are about to reset this user\'s password. This action will immediately replace their old password.',
                note: 'For security: Only share the new password with the user through the official channel.',
                primaryText: 'Yes, reset password',
                onConfirm: function () {
                    openConfirmModal({
                        title: 'Final Confirmation',
                        message: 'Please confirm again to proceed with resetting the password.',
                        note: 'This action cannot be undone.',
                        primaryText: 'Confirm reset',
                        onConfirm: function () { document.getElementById('resetPwForm').submit(); }
                    });
                }
            });
        });
        function checkResetPasswordStrength(password) {
            var strength = 0;
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            return strength;
        }
        function updateResetPasswordStrength(password) {
            var strengthTextEl = document.getElementById('resetPasswordStrengthText');
            var strengthBarFill = document.getElementById('resetPasswordStrengthBarFill');
            if (!strengthTextEl || !strengthBarFill) return;
            if (!password) {
                strengthTextEl.textContent = '';
                strengthTextEl.style.color = '#6b7280';
                strengthBarFill.style.width = '0%';
                strengthBarFill.style.backgroundColor = '#e74c3c';
                return;
            }
            var strength = checkResetPasswordStrength(password);
            if (strength <= 2) {
                strengthTextEl.textContent = 'Weak password';
                strengthTextEl.style.color = '#e74c3c';
                strengthBarFill.style.width = '33%';
                strengthBarFill.style.backgroundColor = '#e74c3c';
            } else if (strength <= 4) {
                strengthTextEl.textContent = 'Medium strength';
                strengthTextEl.style.color = '#f39c12';
                strengthBarFill.style.width = '66%';
                strengthBarFill.style.backgroundColor = '#f39c12';
            } else {
                strengthTextEl.textContent = 'Strong password';
                strengthTextEl.style.color = '#27ae60';
                strengthBarFill.style.width = '100%';
                strengthBarFill.style.backgroundColor = '#27ae60';
            }
        }
        var resetPasswordInput = document.getElementById('new_password');
        if (resetPasswordInput) {
            resetPasswordInput.addEventListener('input', function () {
                updateResetPasswordStrength(this.value || '');
            });
        }
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'viewUserModalOverlay') closeViewModal();
            if (e.target && e.target.id === 'resetPwModalOverlay') closeResetPwModal();
            if (e.target && e.target.id === 'editUserModalOverlay') closeEditModal();
            if (e.target && e.target.id === 'confirmModalOverlay') closeConfirmModal();
            if (e.target && e.target.id === 'infoModalOverlay') closeInfoModal();
        });

        // Confirm + Info modal helpers
        var _confirmAction = null;
        function openConfirmModal(opts) {
            var overlay = document.getElementById('confirmModalOverlay');
            var title = document.getElementById('confirmModalTitle');
            var msg = document.getElementById('confirmModalMessage');
            var note = document.getElementById('confirmModalNote');
            var btn = document.getElementById('confirmModalPrimaryBtn');
            if (!overlay || !btn) return;
            _confirmAction = (opts && typeof opts.onConfirm === 'function') ? opts.onConfirm : null;
            if (title) title.innerHTML = '<i class="fas fa-shield-alt"></i> ' + escapeHtml(opts.title || 'Confirm Action');
            if (msg) msg.textContent = opts.message || '';
            if (note) {
                if (opts.note) { note.style.display = 'block'; note.textContent = opts.note; }
                else { note.style.display = 'none'; note.textContent = ''; }
            }
            btn.textContent = opts.primaryText || 'Confirm';
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeConfirmModal() {
            var overlay = document.getElementById('confirmModalOverlay');
            if (overlay) overlay.classList.remove('active');
            _confirmAction = null;
            document.body.style.overflow = 'auto';
        }
        document.getElementById('confirmModalPrimaryBtn').addEventListener('click', function () {
            var fn = _confirmAction;
            closeConfirmModal();
            if (fn) fn();
        });
        function openInfoModal(titleText, messageText) {
            var overlay = document.getElementById('infoModalOverlay');
            var title = document.getElementById('infoModalTitle');
            var msg = document.getElementById('infoModalMessage');
            if (!overlay) return;
            if (title) title.innerHTML = '<i class="fas fa-info-circle"></i> ' + escapeHtml(titleText || 'Information');
            if (msg) msg.textContent = messageText || '';
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeInfoModal() {
            var overlay = document.getElementById('infoModalOverlay');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeConfirmModal();
                closeInfoModal();
            }
        });

        function openEditModal(userId, userName) {
            var overlay = document.getElementById('editUserModalOverlay');
            var alertEl = document.getElementById('editInlineAlert');
            var saveBtn = document.getElementById('editSaveBtn');
            if (!overlay) return;
            if (alertEl) { alertEl.className = 'alert edit-inline-alert'; alertEl.textContent = ''; }
            document.getElementById('edit_target_user_id').value = userId;
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';

            if (saveBtn) saveBtn.disabled = true;
            fetch('get_user_details.php?id=' + encodeURIComponent(userId))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data.success || !data.user) throw new Error('Failed to load user.');
                    var u = data.user;
                    document.getElementById('edit_username').value = u.username || '';
                    document.getElementById('edit_email').value = u.email || '';
                    document.getElementById('edit_contact_number').value = u.contact_number || '';
                    document.getElementById('edit_deped_id').value = u.deped_id || '';
                    document.getElementById('edit_home_address').value = u.home_address || '';
                    document.getElementById('edit_role').value = (u.role || '');
                })
                .catch(function(err){
                    if (alertEl) {
                        alertEl.className = 'alert alert-error edit-inline-alert active';
                        alertEl.textContent = (err && err.message) ? err.message : 'Failed to load user.';
                    }
                })
                .finally(function(){
                    if (saveBtn) saveBtn.disabled = false;
                });
        }

        function closeEditModal() {
            var overlay = document.getElementById('editUserModalOverlay');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function delegateAction(ev) {
            var btn = ev.target.closest('.action-icon-btn');
            if (!btn) return;
            var tr = btn.closest('[data-user-id]');
            if (!tr) return;
            var userId = parseInt(tr.getAttribute('data-user-id') || '', 10);
            if (!Number.isFinite(userId) || userId <= 0) return;
            var name = tr.getAttribute('data-name') || '';
            if (btn.classList.contains('view')) {
                openViewModal(userId);
            } else if (btn.classList.contains('edit')) {
                openEditModal(userId, name);
            } else if (btn.classList.contains('reset-pw')) {
                openResetPwModal(userId, name);
            }
        }
        document.getElementById('tbodyAccounting').addEventListener('click', delegateAction);
        document.getElementById('tbodyBorrowers').addEventListener('click', delegateAction);

        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var form = this;
            var btn = document.getElementById('editSaveBtn');
            var userId = document.getElementById('edit_target_user_id').value;
            var userName = (document.getElementById('edit_username').value || '').trim();

            // Security confirmation (step 1 of 2) then final confirmation (step 2 of 2)
            openConfirmModal({
                title: 'Confirm Save Changes',
                message: 'Save changes for this user now? Please double-check the email and contact number before continuing.',
                note: 'For information: These updates affect the user\'s account access and contact details.',
                primaryText: 'Yes, save changes',
                onConfirm: function () {
                    openConfirmModal({
                        title: 'Final Confirmation',
                        message: 'Confirm again to save these changes to the user account.',
                        note: 'Make sure the details are correct before proceeding.',
                        primaryText: 'Confirm save',
                        onConfirm: function () {
                            if (btn) btn.disabled = true;
                            fetch('manage_users.php', { method: 'POST', body: new FormData(form) })
                                .then(function(r){ return r.json(); })
                                .then(function(data){
                                    if (!data || !data.success) throw new Error((data && data.message) ? data.message : 'Failed to save.');
                                    openInfoModal('Saved', data.message || 'Saved successfully.');
                                    setTimeout(function(){ closeEditModal(); window.location.reload(); }, 650);
                                })
                                .catch(function(err){
                                    openInfoModal('Unable to Save', (err && err.message) ? err.message : 'Failed to save.');
                                })
                                .finally(function(){ if (btn) btn.disabled = false; });
                        }
                    });
                }
            });
        });

        // Edit modal input normalization
        var editContact = document.getElementById('edit_contact_number');
        if (editContact) editContact.addEventListener('input', function(e) {
            var v = (e.target.value || '').replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 0 && !v.startsWith('09')) v = '09' + v.substring(2);
            e.target.value = v;
        });
        var editDeped = document.getElementById('edit_deped_id');
        if (editDeped) editDeped.addEventListener('input', function(e) {
            e.target.value = (e.target.value || '').replace(/\D/g, '').substring(0, 7);
        });

        // Create Accounting Employee password visibility toggles
        document.querySelectorAll('.password-toggle-btn[data-target]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = btn.getAttribute('data-target');
                var input = document.getElementById(targetId);
                var icon = btn.querySelector('i');
                if (!input || !icon) return;
                var isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                icon.classList.toggle('fa-eye', !isHidden);
                icon.classList.toggle('fa-eye-slash', isHidden);
                btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        });

        // Contact number validation (Create Accounting Employee form)
        var contactInput = document.getElementById('contact_number');
        if (contactInput) contactInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            if (value.length > 0 && !value.startsWith('09')) {
                value = '09' + value.substring(2);
            }
            e.target.value = value;
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
