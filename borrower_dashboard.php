<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user statistics
$user_id = $_SESSION['user_id'];

// Get real loan statistics from database
$active_loans_sql = "SELECT COUNT(*) as count FROM loans WHERE user_id = ? AND status = 'approved'";
$stmt = $conn->prepare($active_loans_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_loans = $stmt->get_result()->fetch_assoc()['count'];

$pending_applications_sql = "SELECT COUNT(*) as count FROM loans WHERE user_id = ? AND status = 'pending'";
$stmt = $conn->prepare($pending_applications_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pending_applications = $stmt->get_result()->fetch_assoc()['count'];

$completed_loans_sql = "SELECT COUNT(*) as count FROM loans WHERE user_id = ? AND status = 'completed'";
$stmt = $conn->prepare($completed_loans_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$completed_loans = $stmt->get_result()->fetch_assoc()['count'];

$total_borrowed_sql = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM loans WHERE user_id = ? AND status IN ('approved', 'completed')";
$stmt = $conn->prepare($total_borrowed_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_borrowed = $stmt->get_result()->fetch_assoc()['total'];

// Get current loan summary (most recent approved or active loan)
$current_loan_sql = "SELECT l.*, 
                    COALESCE(SUM(d.amount), 0) as total_paid,
                    l.loan_amount - COALESCE(SUM(d.amount), 0) as remaining,
                    CASE WHEN l.released_at IS NULL THEN 0 ELSE 1 END as released,
                    CASE WHEN l.loan_amount > 0 THEN ((COALESCE(SUM(d.amount), 0) / l.loan_amount) * 100) ELSE 0 END as progress_pct
                    FROM loans l 
                    LEFT JOIN deductions d ON l.id = d.loan_id 
                    WHERE l.user_id = ? AND l.status = 'approved' 
                    ORDER BY l.application_date DESC LIMIT 1";
$stmt = $conn->prepare($current_loan_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_loan_result = $stmt->get_result();
$current_loan_summary = $current_loan_result->fetch_assoc();

// Get recent loan activity (show latest 3 only)
$recent_loans_sql = "SELECT loan_amount, loan_purpose, status, application_date, admin_comment, reviewed_by_name, reviewed_by_role FROM loans WHERE user_id = ? ORDER BY application_date DESC LIMIT 3";
$stmt = $conn->prepare($recent_loans_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_loans = $stmt->get_result();

// Get user profile information
$column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
if ($column_check && $column_check->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL");
}
$user_sql = "SELECT username, email, full_name, role, created_at, profile_photo, deped_id FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$stmt->close();
$user_data = $user_data ?: null;
if (!$user_data) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}
$profile_photo = $user_data['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

// Format registration date
$registration_date = date('F j, Y', strtotime($user_data['created_at']));
$account_age = date_diff(date_create($user_data['created_at']), date_create())->format('%y years, %m months');

// Handle AJAX requests for modal forms (profile only; password change uses change_password_*.php)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    // Handle profile update
    if (isset($_POST['username'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $contact_number = trim($_POST['contact_number']);
        $home_address = trim($_POST['home_address']);
        
        // Validation
        if (empty($username) || empty($email) || empty($contact_number) || empty($home_address)) {
            $response['message'] = "All fields are required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = "Invalid email format";
        } elseif (!preg_match('/^09\d{9}$/', $contact_number)) {
            $response['message'] = "Contact number must be in format: 09XXXXXXXXX";
        } else {
            // Check if email or username already exists (excluding current user)
            $check_sql = "SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("ssi", $email, $username, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $response['message'] = "Email or username already exists";
            } else {
                // Update profile
                $update_sql = "UPDATE users SET username = ?, email = ?, contact_number = ?, home_address = ? WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ssssi", $username, $email, $contact_number, $home_address, $user_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Profile updated successfully!";
                    log_audit(
                        $conn,
                        'UPDATE',
                        'Updated profile details.',
                        'Borrower Dashboard',
                        "User #{$user_id}"
                    );
                } else {
                    $response['message'] = "Profile update failed. Please try again.";
                }
            }
            $stmt->close();
        }
    }
    
    echo json_encode($response);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrower Dashboard - DepEd Loan System</title>
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
            font-size: 1.1rem;
            color: #333;
        }
        
        .welcome-message strong {
            color: #8b0000;
        }
        
        .user-info-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            min-width: 250px;
        }
        
        .user-info-badge {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            background: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
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
            padding: 0.75rem;
            margin-left: 192px; /* 80% of 250px */
            margin-top: 20px;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #666;
            font-size: 1.1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
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
            border-top: 5px solid #8b0000;
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
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.6rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.3rem;
            position: relative;
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
        
        .content-section {
            background: white;
            padding: 0.75rem;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 0.75rem;
        }
        
        .section-title {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .section-title i {
            color: #8b0000;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .quick-action-btn {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.95rem;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(139, 0, 0, 0.3);
        }
        
        .quick-action-btn.secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .activity-list {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .activity-item {
            padding: 1.25rem 1.25rem 1rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border: 1px solid #eee;
            display: flex;
            align-items: flex-start;
            gap: 1.1rem;
            transition: box-shadow 0.25s ease, border-color 0.25s ease;
        }
        
        .activity-item:hover {
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
            border-color: #e0e0e0;
        }
        
        .activity-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        .activity-icon.pending {
            background: linear-gradient(145deg, #fff8e1 0%, #ffecb3 100%);
            color: #f57c00;
        }
        
        .activity-icon.approved {
            background: linear-gradient(145deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #2e7d32;
        }
        
        .activity-icon.rejected {
            background: linear-gradient(145deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
        }
        
        .activity-details {
            flex: 1;
            min-width: 0;
        }
        
        .activity-title {
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 0.35rem;
            font-size: 1rem;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .activity-meta {
            font-size: 0.8125rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        
        .activity-meta .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.25rem;
        }
        
        .activity-meta .status-badge.pending {
            background: #fef3c7;
            color: #b45309;
        }
        
        .activity-meta .status-badge.approved {
            background: #d1fae5;
            color: #047857;
        }
        
        .activity-meta .status-badge.rejected {
            background: #fee2e2;
            color: #b91c1c;
        }
        
        .activity-comment {
            margin-top: 0.75rem;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%);
            border-radius: 8px;
            border-left: 4px solid #8b0000;
            font-size: 0.875rem;
            line-height: 1.5;
            color: #374151;
        }
        
        .activity-comment strong {
            color: #1f2937;
            font-size: 0.8125rem;
            letter-spacing: 0.02em;
        }
        
        .activity-comment .comment-meta {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.4rem;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .activity-comment .comment-meta::before {
            content: '\2014';
            margin-right: 0.15rem;
        }
        
        .activity-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
        }
        
        .activity-amount {
            font-weight: 700;
            color: #8b0000;
            font-size: 1.125rem;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        
        .activity-section-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .activity-section-header .section-title {
            margin-bottom: 0;
        }
        
        .activity-section-header .section-title i {
            color: #8b0000;
            opacity: 0.9;
        }
        
        .activity-empty {
            text-align: center;
            padding: 3rem 2rem;
            background: linear-gradient(180deg, #fafafa 0%, #f5f5f5 100%);
            border-radius: 12px;
            border: 1px dashed #e2e8f0;
        }
        
        .activity-empty .empty-icon {
            font-size: 2.5rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .activity-empty .empty-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.35rem;
        }
        
        .activity-empty .empty-text {
            font-size: 0.9rem;
            color: #64748b;
        }
        
        .btn-edit {
            padding: 0.4rem 0.75rem !important;
            font-size: 0.8125rem !important;
            font-weight: 600;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
            color: #1c1917 !important;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            box-shadow: 0 1px 3px rgba(245, 158, 11, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .btn-edit:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
        }
        
        .chart-container {
            position: relative;
            height: 250px;
            margin-bottom: 1.5rem;
        }

        .overview-activity-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            align-items: stretch;
        }

        @media (max-width: 900px) {
            .overview-activity-grid {
                grid-template-columns: 1fr;
            }
        }

        .loan-overview-card .chart-container {
            flex: 1;
            height: auto;
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            width: 100%;
            max-height: 380px;
        }

        @media (max-width: 900px) {
            .loan-overview-card .chart-container {
                flex: 0 0 auto;
                height: 260px;
            }
        }

        .loan-overview-card .chart-container canvas {
            width: 100% !important;
            height: 100% !important;
            max-width: 100% !important;
            max-height: 100% !important;
        }

        .loan-overview-card {
            height: 520px;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 900px) {
            .loan-overview-card {
                height: auto;
            }
        }

        .recent-activity-card {
            height: 520px;
            display: flex;
            flex-direction: column;
        }

        .recent-activity-card .activity-scroll {
            flex: 1;
            overflow: auto;
            padding-right: 0.25rem;
        }

        .recent-activity-card .activity-note {
            font-size: 0.9rem;
            color: #6b7280;
            margin: 0 0 0.75rem 0;
        }

        .recent-activity-card .activity-footer {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
        }

        .btn-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #8b0000;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .btn-link:hover {
            text-decoration: underline;
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
        
        .current-loan-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 0.75rem;
        }
        .current-loan-card {
            background: #ffffff;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            padding: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            font-size: 0.85rem;
            color: #374151;
        }
        .current-loan-card h4 {
            margin: 0 0 0.3rem 0;
            font-size: 0.9rem;
            color: #111827;
        }

        /* Override shared.css fancy styles with cleaner dashboard look */
        .main-content .current-loan-card::before,
        .main-content .current-loan-card::after,
        .main-content .current-loan-card h4::before,
        .main-content .current-loan-card h4::after {
            content: none !important;
        }

        .main-content .current-loan-card {
            transform: none !important;
            border-color: #e5e7eb !important;
            background: #fff !important;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08) !important;
            padding: 1rem !important;
        }

        .main-content .current-loan-card h4 {
            font-size: 1.02rem !important;
            font-weight: 700;
            margin-bottom: 0.85rem !important;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f1f5f9;
            color: #111827 !important;
            text-shadow: none !important;
        }

        .main-content .current-loan-card > div {
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            margin-bottom: 0.8rem !important;
            font-size: 0.95rem !important;
            color: #334155 !important;
            line-height: 1.35;
            transform: none !important;
        }

        .main-content .current-loan-card > div i {
            font-size: 1rem !important;
            width: 18px;
            color: #e11d48 !important;
            animation: none !important;
        }

        .main-content .current-loan-card strong {
            font-size: 0.92rem !important;
            color: #1f2937 !important;
            font-weight: 700;
            margin-right: 0.45rem;
        }

        .main-content .current-loan-card .amount,
        .main-content .current-loan-card .amount-negative {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            padding: 0.42rem 0.72rem !important;
            border-radius: 12px !important;
            font-size: 0.96rem !important;
            font-weight: 800 !important;
            min-width: 0 !important;
            width: auto !important;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            animation: none !important;
        }

        .main-content .current-loan-card .amount::before,
        .main-content .current-loan-card .amount-negative::before {
            content: none !important;
        }

        .main-content .current-loan-card .amount {
            color: #0f766e !important;
            background: #d1fae5 !important;
            border: 2px solid #34d399 !important;
            box-shadow: none !important;
        }

        .main-content .current-loan-card .amount-negative {
            color: #b91c1c !important;
            background: #fee2e2 !important;
            border: 2px solid #fca5a5 !important;
            box-shadow: none !important;
        }

        .current-loan-meta {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.35rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.55rem 0.7rem;
        }
        .progress-bar-wrap {
            margin-top: 0.35rem;
            background: #f3f4f6;
            border-radius: 999px;
            overflow: hidden;
            height: 0.55rem;
            border: 1px solid #dbe3ee;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%);
            width: 0%;
            animation: none !important;
        }
        
        .notification-panel {
            position: fixed;
            top: 70px;
            right: 20px;
            width: 350px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
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
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            font-weight: 600;
            color: #333;
        }
        
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.3s ease;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item:last-child {
            border-bottom: none;
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
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            animation: fadeIn 0.3s ease;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 700px;
            width: 95%;
            height: auto;
            max-height: none;
            overflow: visible;
            animation: slideUp 0.3s ease;
        }
        #passwordModal .modal-content {
            max-width: 420px;
            border-radius: 16px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.14), 0 0 0 1px rgba(0, 0, 0, 0.04);
        }
        #passwordModal .modal-header {
            border-radius: 16px 16px 0 0;
        }
        #passwordModal .cp-step-label {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #8b0000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.75rem;
        }
        #passwordModal .cp-hint {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.55;
            margin-bottom: 1rem;
        }
        #passwordModal .cp-hint strong { color: #334155; }
        #passwordModal #modal_cp_otp {
            padding: 14px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1.25rem;
            letter-spacing: 0.4rem;
            text-align: center;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #passwordModal #modal_cp_otp:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1);
        }
        #passwordModal .modal-alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 1.25rem;
        }
        #passwordModal .modal-alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        #passwordModal .modal-alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        #passwordModal .modal-body {
            padding: 1.75rem 2rem;
        }
        #passwordModal .modal-footer {
            padding: 0 2rem 1.75rem 2rem;
            justify-content: space-between;
        }
        #passwordModal .cp-back-row {
            text-align: left;
            margin-top: 1rem;
            margin-bottom: 0;
        }
        #passwordModal .cp-back-link {
            color: #8b0000;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
        }
        #passwordModal .cp-back-link:hover {
            text-decoration: underline;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s;
            padding: 0.5rem;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }
        
        .cp-hint { color: #555; font-size: 0.9rem; margin-bottom: 1rem; line-height: 1.5; }
        .cp-step { margin-top: 0.25rem; }
        .modal-body {
            padding: 2rem;
        }
        
        .modal-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .modal-form .form-group {
            margin-bottom: 1rem;
        }
        
        .modal-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .modal-form input,
        .modal-form textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        
        .modal-form input:focus,
        .modal-form textarea:focus {
            outline: none;
            border-color: #8b0000;
        }
        
        .modal-form textarea {
            resize: vertical;
            min-height: 60px;
            max-height: 120px;
        }
        
        .modal-form small {
            color: #666;
            font-size: 0.85rem;
            margin-top: 0.25rem;
            display: block;
        }
        
        .modal-footer {
            padding: 0 2rem 2rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        .modal-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-btn-primary {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
        }
        
        .modal-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 0, 0, 0.3);
        }
        
        .modal-btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
        }
        
        .modal-btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
        }
        
        .modal-alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .modal-alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(50px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
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

            .welcome-section,
            .welcome-message { min-width: 0; flex: 1 1 auto !important; width: auto !important; max-width: none !important; }
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

            .stats-grid,
            .dashboard-grid,
            .current-loan-grid {
                gap: 0.85rem;
            }

            .current-loan-grid {
                grid-template-columns: 1fr !important;
            }

            .main-content .current-loan-card > div {
                font-size: 0.9rem !important;
                gap: 0.55rem;
            }

            .main-content .current-loan-card .amount,
            .main-content .current-loan-card .amount-negative {
                font-size: 0.88rem !important;
                padding: 0.36rem 0.56rem !important;
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
        <div class="welcome-section">
            <div class="welcome-block">
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($user_data['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> Borrower</span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-wallet"></i> Loan Portal</span>
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
                    <a href="borrower_dashboard.php" class="sidebar-link active">
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
                <h2 class="section-title"><i class="fas fa-tachometer-alt"></i> Borrower Dashboard Overview</h2>
                
                <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-trend trend-neutral">Active</div>
                    <div class="stat-icon tooltip" data-tooltip="Currently active loans"><i class="fas fa-peso-sign"></i></div>
                    <div class="stat-number"><?php echo $active_loans; ?></div>
                    <div class="stat-label">Active Loans</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-trend trend-up">Pending</div>
                    <div class="stat-icon tooltip" data-tooltip="Applications under review"><i class="fas fa-clipboard-list"></i></div>
                    <div class="stat-number"><?php echo $pending_applications; ?></div>
                    <div class="stat-label">Pending Applications</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-trend trend-up">Completed</div>
                    <div class="stat-icon tooltip" data-tooltip="Successfully completed loans"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo $completed_loans; ?></div>
                    <div class="stat-label">Completed Loans</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-trend trend-neutral">Total</div>
                    <div class="stat-icon tooltip" data-tooltip="Total amount borrowed"><i class="fas fa-wallet"></i></div>
                    <div class="stat-number">₱<?php echo number_format($total_borrowed, 2); ?></div>
                    <div class="stat-label">Total Borrowed</div>
                </div>
            </div>
            </div>
            
            <?php if ($current_loan_summary): ?>
            <?php
                $cls = ($current_loan_summary['status'] === 'approved' && !$current_loan_summary['released']) ? 'pending' : 'active';
            ?>
            <?php if ($current_loan_summary['status'] === 'approved' && !$current_loan_summary['released']): ?>
            <div class="content-section" style="border-left: 4px solid #f59e0b;">
                <h2 class="section-title"><i class="fas fa-info-circle"></i> Loan approved — pending release</h2>
                <div style="font-size: 0.95rem; color: #92400e; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 0.75rem;">
                    <p style="margin: 0 0 0.6rem 0;">
                        Your loan has been <strong>approved</strong>. Please go to the office and submit the following requirements in <strong>two (2) copies</strong>:
                    </p>
                    <ul style="margin: 0 0 0.7rem 1.1rem; padding: 0; line-height: 1.7;">
                        <li>Provident Fund Application Form (<a href="download_provident_form.php" style="color: #92400e; text-decoration: underline;">Download form here</a>)</li>
                        <li>Letter Request addressed to SDS (attach Pictures / Registration Form / Bills, etc.)</li>
                        <li>Original Payslip (latest month available at Cash Unit)</li>
                        <li>Photocopy of Latest Payslip (Co-borrower only; net pay Php 5,000.00 after amortization)</li>
                        <li>Photocopy of Employee DepEd No. or valid government ID with Certificate of Employment from HR (with three (3) specimen signatures)</li>
                        <li>Photocopy of Co-borrower's Employee DepEd No. or valid government ID (with three (3) specimen signatures)</li>
                    </ul>
                    <p style="margin: 0;">
                        Loan release is expected <strong>within seven (7) working days</strong>, subject to complete documentary compliance and standard verification procedures.
                        <br><strong>Office hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM.
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-file-invoice-dollar"></i> Current Loan Snapshot</h2>
                <div class="current-loan-grid">
                    <div class="current-loan-card">
                        <h4>Loan Summary</h4>
                        <div><i class="fas fa-money-bill-wave"></i><strong>Loan Amount:</strong> <span class="amount">₱<?php echo number_format($current_loan_summary['loan_amount'], 2); ?></span></div>
                        <div><i class="fas fa-balance-scale"></i><strong>Remaining Balance:</strong> <span class="amount amount-negative">₱<?php echo number_format($current_loan_summary['remaining'], 2); ?></span></div>
                        <div class="current-loan-meta">
                            <span class="status-badge <?php echo $current_loan_summary['status'] === 'completed' ? 'completed' : ($current_loan_summary['released'] ? 'active' : 'pending'); ?>">
                                <i class="fas fa-circle" style="font-size: 0.6rem;"></i>
                                <?php echo $current_loan_summary['status'] === 'completed' ? 'Completed' : ($current_loan_summary['released'] ? 'Active' : 'Pending release'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="current-loan-card">
                        <h4>Payments</h4>
                        <div><i class="fas fa-calendar-check"></i><strong>Monthly Payment:</strong> <span class="amount">₱<?php echo number_format($current_loan_summary['monthly_payment'], 2); ?></span></div>
                        <div><i class="fas fa-hand-holding-usd"></i><strong>Total Paid:</strong> <span class="amount">₱<?php echo number_format($current_loan_summary['total_paid'], 2); ?></span></div>
                        <div class="current-loan-meta">
                            <i class="fas fa-calendar-alt"></i> Payments are deducted every 15th & 30th (per payroll)
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" style="width: <?php echo number_format($current_loan_summary['progress_pct'], 0); ?>%;"></div>
                        </div>
                        <div class="current-loan-meta">
                            <i class="fas fa-chart-pie"></i> <?php echo number_format($current_loan_summary['progress_pct'], 0); ?>% of this loan has been paid
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="overview-activity-grid">
                <div class="content-section loan-overview-card">
                    <h2 class="section-title"><i class="fas fa-chart-line"></i> Loan Overview</h2>
                    <p id="loanChartSampleNote" class="chart-sample-note" style="display: none;"><i class="fas fa-info-circle"></i> Showing sample data. Chart will display your real loan data once you have applications.</p>
                    <div class="chart-container">
                        <canvas id="loanChart"></canvas>
                        <div id="noDataMessage" style="display: none; text-align: center; padding: 3rem; color: #666;">
                            <i class="fas fa-chart-pie" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                            <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No loan data available</p>
                            <p style="font-size: 0.9rem;">Start by applying for your first loan to see your loan overview here!</p>
                        </div>
                    </div>
                </div>
                
                <div class="content-section recent-activity-card">
                    <div class="activity-section-header">
                        <h2 class="section-title"><i class="fas fa-clock"></i> Recent Activity</h2>
                    </div>
                    <?php if ($recent_loans->num_rows > 0): ?>
                        <p class="activity-note">
                            Showing your latest <strong>3</strong> applications.
                        </p>
                        <div class="activity-scroll">
                            <ul class="activity-list">
                                <?php while ($loan = $recent_loans->fetch_assoc()): ?>
                                    <li class="activity-item">
                                        <div class="activity-icon <?php echo $loan['status']; ?>">
                                            <?php if ($loan['status'] == 'pending'): ?>
                                                <i class="fas fa-clock"></i>
                                            <?php elseif ($loan['status'] == 'approved'): ?>
                                                <i class="fas fa-check"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-title"><?php echo htmlspecialchars($loan['loan_purpose']); ?></div>
                                            <div class="activity-meta">
                                                Applied on <?php echo date('M d, Y', strtotime($loan['application_date'])); ?> •
                                                <span class="status-badge <?php echo $loan['status']; ?>"><?php echo ucfirst($loan['status']); ?></span>
                                            </div>
                                            <?php if (!empty($loan['admin_comment']) && in_array($loan['status'], ['approved', 'rejected'])): ?>
                                                <div class="activity-comment">
                                                    <strong>Reviewer's Comment:</strong> <?php echo nl2br(htmlspecialchars($loan['admin_comment'])); ?>
                                                    <?php if (!empty($loan['reviewed_by_name'])): ?>
                                                        <div class="comment-meta">
                                                            <?php echo htmlspecialchars($loan['reviewed_by_name']); ?>
                                                            <?php if (!empty($loan['reviewed_by_role'])): ?>
                                                                (<?php echo htmlspecialchars(ucfirst($loan['reviewed_by_role'])); ?>)
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-right">
                                            <div class="activity-amount">₱<?php echo number_format($loan['loan_amount'], 2); ?></div>
                                            <?php if ($loan['status'] == 'rejected' && !empty($loan['id'])): ?>
                                                <a href="edit_loan.php?id=<?php echo (int) $loan['id']; ?>" class="btn btn-edit"><i class="fas fa-edit"></i> Edit</a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endwhile; ?>
                            </ul>
                        </div>
                        <div class="activity-footer">
                            <a href="loan_history.php" class="btn-link">View Full History <i class="fas fa-arrow-right"></i></a>
                        </div>
                    <?php else: ?>
                        <div class="activity-scroll">
                            <div class="activity-empty">
                                <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                <p class="empty-title">No recent activity</p>
                                <p class="empty-text">Start by applying for your first loan to see your history here.</p>
                            </div>
                        </div>
                        <div class="activity-footer">
                            <a href="loan_history.php" class="btn-link">View Full History <i class="fas fa-arrow-right"></i></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Profile Modal -->
    <div id="profileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Update Profile</h3>
                <button class="modal-close" onclick="closeModal('profileModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="profileAlert"></div>
                <form id="profileForm" class="modal-form">
                    <div class="form-group">
                        <label for="modal_username">Username <span style="color: red;">*</span></label>
                        <input type="text" id="modal_username" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_email">Email Address <span style="color: red;">*</span></label>
                        <input type="email" id="modal_email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_contact">Contact Number <span style="color: red;">*</span></label>
                        <input type="tel" id="modal_contact" name="contact_number" value="<?php echo htmlspecialchars($user_data['contact_number'] ?? ''); ?>" required placeholder="09XXXXXXXXX">
                        <small>Format: 09XXXXXXXXX</small>
                    </div>
                    <div class="form-group">
                        <label for="modal_address">Home Address <span style="color: red;">*</span></label>
                        <textarea id="modal_address" name="home_address" required><?php echo htmlspecialchars($user_data['home_address'] ?? ''); ?></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('profileModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" form="profileForm" class="modal-btn modal-btn-primary">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </div>
        </div>
    </div>
    
    <!-- Change Password Modal (OTP flow: Send OTP → Verify → New password) -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Change Password</h3>
                <button class="modal-close" onclick="closeModal('passwordModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="passwordAlert"></div>
                <div id="cpStep1" class="cp-step">
                    <span class="cp-step-label">Step 1 of 3</span>
                    <p class="cp-hint">We'll send a 6-digit code to <strong><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></strong> to verify your identity.</p>
                    <button type="button" class="modal-btn modal-btn-primary" id="cpBtnSendOtp" style="width:100%;margin-top:0.5rem;">Send OTP</button>
                </div>
                <div id="cpStep2" class="cp-step" style="display:none;">
                    <span class="cp-step-label">Step 2 of 3</span>
                    <p class="cp-hint">Enter the 6-digit code sent to your email.</p>
                    <div class="form-group">
                        <label for="modal_cp_otp">OTP Code</label>
                        <input type="text" id="modal_cp_otp" maxlength="6" placeholder="000000" autocomplete="one-time-code">
                    </div>
                    <button type="button" class="modal-btn modal-btn-primary" id="cpBtnVerify" style="width:100%;margin-top:0.5rem;">Verify & continue</button>
                    <p class="cp-back-row"><a href="#" id="cpBackToStep1" class="cp-back-link">← Back</a></p>
                </div>
                <div id="cpStep3" class="cp-step" style="display:none;">
                    <span class="cp-step-label">Step 3 of 3</span>
                    <p class="cp-hint">Enter your new password (min 8 characters, with uppercase, lowercase, and number).</p>
                    <form id="passwordForm" class="modal-form">
                        <div class="form-group">
                            <label for="modal_new_password">New Password <span style="color: red;">*</span></label>
                            <input type="password" id="modal_new_password" name="new_password" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label for="modal_confirm_password">Confirm New Password <span style="color: red;">*</span></label>
                            <input type="password" id="modal_confirm_password" name="confirm_password" required minlength="8">
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('passwordModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" id="cpBtnSubmit" class="modal-btn modal-btn-primary" style="display:none;" form="passwordForm">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const profileIcon = document.querySelector('.profile-trigger');
            const profileDropdown = document.getElementById('profileDropdown');
            
            if (!profileIcon.contains(event.target)) {
                profileDropdown.classList.remove('active');
            }
        });
        
        // Initialize Chart
        const ctx = document.getElementById('loanChart').getContext('2d');
        const noDataMessage = document.getElementById('noDataMessage');
        
        // Chart data with fallback values
        const activeLoans = <?php echo $active_loans ?? 0; ?>;
        const pendingApplications = <?php echo $pending_applications ?? 0; ?>;
        const completedLoans = <?php echo $completed_loans ?? 0; ?>;
        
        // Check if there's any data to display
        const hasData = activeLoans > 0 || pendingApplications > 0 || completedLoans > 0;
        
        const loanChartSampleNote = document.getElementById('loanChartSampleNote');
        if (hasData) {
            noDataMessage.style.display = 'none';
            if (loanChartSampleNote) loanChartSampleNote.style.display = 'none';
        } else {
            noDataMessage.style.display = 'none';
            if (loanChartSampleNote) loanChartSampleNote.style.display = 'block';
        }
        
        // Real data when available; otherwise sample/placeholder
        const chartData = hasData ? [activeLoans, pendingApplications, completedLoans] : [2, 1, 3];
        const chartLabels = hasData ? ['Active Loans', 'Pending Applications', 'Completed Loans'] : ['Active Loans (Sample)', 'Pending Applications (Sample)', 'Completed Loans (Sample)'];
        
        const loanChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartLabels,
                datasets: [{
                    data: chartData,
                    radius: '88%',
                    backgroundColor: [
                        'rgba(139, 0, 0, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(40, 167, 69, 0.8)'
                    ],
                    borderColor: [
                        'rgba(139, 0, 0, 1)',
                        'rgba(255, 193, 7, 1)',
                        'rgba(40, 167, 69, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 0, right: 0, bottom: 0, left: 0 }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 8,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = hasData ? (context.parsed || 0) : context.parsed || 0;
                                const total = chartData.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                const displayLabel = hasData ? label : label.replace(' (Sample)', '');
                                return displayLabel + ': ' + value + ' (' + percentage + '%)' + (hasData ? '' : ' [Sample]');
                            }
                        }
                    }
                }
            }
        });
        
        // Modal functions
        function openModal(modalId) {
            event.preventDefault();
            const modal = document.getElementById(modalId);
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Close dropdown when opening modal
            document.getElementById('profileDropdown').classList.remove('active');
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
            
            // Clear alerts when closing
            const alertId = modalId.replace('Modal', 'Alert');
            document.getElementById(alertId).innerHTML = '';
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        function openPasswordModal() {
            document.getElementById('cpStep1').style.display = 'block';
            document.getElementById('cpStep2').style.display = 'none';
            document.getElementById('cpStep3').style.display = 'none';
            document.getElementById('cpBtnSubmit').style.display = 'none';
            document.getElementById('passwordAlert').innerHTML = '';
            document.getElementById('modal_cp_otp').value = '';
            if (document.getElementById('passwordForm')) document.getElementById('passwordForm').reset();
            openModal('passwordModal');
        }

        // Change Password – Step 1: Send OTP
        document.getElementById('cpBtnSendOtp').addEventListener('click', function() {
            var btn = this;
            var alertDiv = document.getElementById('passwordAlert');
            btn.disabled = true;
            btn.textContent = 'Sending...';
            alertDiv.innerHTML = '';
            fetch('change_password_send_otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = 'Send OTP';
                    if (data.success) {
                        document.getElementById('cpStep1').style.display = 'none';
                        document.getElementById('cpStep2').style.display = 'block';
                        alertDiv.innerHTML = '<div class="modal-alert modal-alert-success"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                    } else {
                        alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Failed to send OTP') + '</div>';
                    }
                })
                .catch(function() { btn.disabled = false; btn.textContent = 'Send OTP'; alertDiv.innerHTML = '<div class="modal-alert modal-alert-error">Network error.</div>'; });
        });

        // Change Password – Step 2: Verify OTP
        document.getElementById('cpBtnVerify').addEventListener('click', function() {
            var otp = document.getElementById('modal_cp_otp').value.trim().replace(/\D/g, '');
            var alertDiv = document.getElementById('passwordAlert');
            if (otp.length !== 6) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> Please enter the 6-digit code.</div>';
                return;
            }
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Verifying...';
            var fd = new FormData();
            fd.append('otp', otp);
            fetch('change_password_verify_otp.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = 'Verify & continue';
                    if (data.success) {
                        document.getElementById('cpStep2').style.display = 'none';
                        document.getElementById('cpStep3').style.display = 'block';
                        document.getElementById('cpBtnSubmit').style.display = 'inline-block';
                        alertDiv.innerHTML = '<div class="modal-alert modal-alert-success"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                    } else {
                        alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Verification failed') + '</div>';
                    }
                })
                .catch(function() { btn.disabled = false; btn.textContent = 'Verify & continue'; alertDiv.innerHTML = '<div class="modal-alert modal-alert-error">Network error.</div>'; });
        });

        document.getElementById('cpBackToStep1').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('cpStep2').style.display = 'none';
            document.getElementById('cpStep1').style.display = 'block';
            document.getElementById('passwordAlert').innerHTML = '';
        });

        // Change Password – Step 3: Submit new password
        document.getElementById('cpBtnSubmit').addEventListener('click', function() {
            var newPass = document.getElementById('modal_new_password').value;
            var confirmPass = document.getElementById('modal_confirm_password').value;
            var alertDiv = document.getElementById('passwordAlert');
            if (!newPass || !confirmPass) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> Please fill in both password fields.</div>';
                return;
            }
            if (newPass !== confirmPass) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> Passwords do not match.</div>';
                return;
            }
            if (newPass.length < 8) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> Password must be at least 8 characters.</div>';
                return;
            }
            var fd = new FormData(document.getElementById('passwordForm'));
            fd.append('new_password', newPass);
            fd.append('confirm_password', confirmPass);
            fetch('change_password_update.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        alertDiv.innerHTML = '<div class="modal-alert modal-alert-success"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                        setTimeout(function() { closeModal('passwordModal'); document.getElementById('passwordForm').reset(); }, 2000);
                    } else {
                        alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Update failed') + '</div>';
                    }
                })
                .catch(function() { alertDiv.innerHTML = '<div class="modal-alert modal-alert-error">Network error.</div>'; });
        });
        
        // Profile form submission
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const alertDiv = document.getElementById('profileAlert');
            
            // Client-side validation
            const username = formData.get('username');
            const email = formData.get('email');
            const contact = formData.get('contact_number');
            const address = formData.get('home_address');
            
            if (!username || !email || !contact || !address) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> All fields are required</div>';
                return;
            }
            
            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> Invalid email format</div>';
                return;
            }
            
            if (!contact.match(/^09\d{9}$/)) {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> Contact number must be in format: 09XXXXXXXXX</div>';
                return;
            }
            
            // Send AJAX request
            fetch('borrower_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="modal-alert modal-alert-success"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                    setTimeout(() => {
                        closeModal('profileModal');
                        location.reload(); // Reload to show updated data
                    }, 2000);
                } else {
                    alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> ' + data.message + '</div>';
                }
            })
            .catch(error => {
                alertDiv.innerHTML = '<div class="modal-alert modal-alert-error"><i class="fas fa-exclamation-circle"></i> An error occurred. Please try again.</div>';
            });
        });
        
        // Password confirmation validation (for step 3)
        document.getElementById('modal_confirm_password') && document.getElementById('modal_confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('modal_new_password').value;
            const confirmPassword = this.value;
            if (confirmPassword && newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
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
