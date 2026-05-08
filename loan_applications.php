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

$success = '';
$success_alert_kind = 'success';
$success_alert_title = 'Loan application status updated';
$error = '';

// Handle Release Loan (set released_at when borrower has completed office process)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'release_loan') {
    $loan_id = (int) ($_POST['loan_id'] ?? 0);
    if ($loan_id > 0) {
        $release_info = null;
        $release_info_stmt = $conn->prepare("SELECT l.id, l.loan_amount, l.user_id, u.full_name, u.email FROM loans l JOIN users u ON l.user_id = u.id WHERE l.id = ? LIMIT 1");
        if ($release_info_stmt) {
            $release_info_stmt->bind_param("i", $loan_id);
            $release_info_stmt->execute();
            $release_info = $release_info_stmt->get_result()->fetch_assoc();
            $release_info_stmt->close();
        }

        $rel = $conn->prepare("UPDATE loans SET released_at = NOW() WHERE id = ? AND status = 'approved' AND released_at IS NULL");
        $rel->bind_param("i", $loan_id);
        if ($rel->execute() && $rel->affected_rows > 0) {
            $rel->close();

            // Notify borrower in-app after loan is formally released
            if (!empty($release_info['user_id'])) {
                $borrower_id = (int) $release_info['user_id'];
                $borrower_name = (string) ($release_info['full_name'] ?? 'Borrower');
                $loan_amount = (float) ($release_info['loan_amount'] ?? 0);
                $notif_title = 'Loan Released';
                $amount_note = $loan_amount > 0 ? ' Amount: ₱' . number_format($loan_amount, 2) . '.' : '';
                $notif_message = 'Your loan application has been released.' . $amount_note;
                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'success')");
                if ($notif_stmt) {
                    $notif_stmt->bind_param("iss", $borrower_id, $notif_title, $notif_message);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }

                // Email borrower (do not block success if email fails)
                $borrower_email = !empty($release_info['email']) ? trim((string)$release_info['email']) : '';
                if ($borrower_email !== '' && filter_var($borrower_email, FILTER_VALIDATE_EMAIL) && file_exists(__DIR__ . '/config_email.php')) {
                    require_once __DIR__ . '/config_email.php';
                    require_once __DIR__ . '/mail_helper.php';
                    try {
                        sendLoanReleasedEmail($borrower_email, $borrower_name, $loan_amount, $loan_id);
                    } catch (Exception $e) {
                        // Keep release successful even if email sending fails
                    }
                }
            }

            if (function_exists('log_audit')) {
                log_audit($conn, 'RELEASE', "Loan #{$loan_id} marked as released (released_at set).", 'Loan Applications', "Loan #{$loan_id}", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
            }
            header('Location: loan_applications.php?released=1');
            exit;
        }
        $rel->close();
    }
    header('Location: loan_applications.php');
    exit;
}

// Handle loan approval/rejection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $loan_id = $_POST['loan_id'];
    $action = $_POST['action'];
    $action_comment = trim($_POST['action_comment'] ?? '');
    $reviewed_by_id = $user_id;
    $reviewed_by_name = $user['full_name'] ?? 'Unknown';
    $reviewed_by_role = $user['role'] ?? ($user['username'] === 'admin' ? 'admin' : 'accountant');
    $loan_label = "Loan Application";
    $loan_amount = null;
    $borrower_name = null;
    $borrower_id = null;
    $borrower_email = null;
    $loan_purpose = null;
    $co_maker_email = null;
    $co_maker_name = null;
    $loan_info_stmt = $conn->prepare("SELECT l.loan_amount, l.loan_purpose, l.co_maker_email, l.co_maker_full_name, l.user_id, u.full_name, u.email FROM loans l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
    if ($loan_info_stmt) {
        $loan_info_stmt->bind_param("i", $loan_id);
        $loan_info_stmt->execute();
        $loan_info = $loan_info_stmt->get_result()->fetch_assoc();
        $loan_info_stmt->close();
        if ($loan_info) {
            $loan_amount = $loan_info['loan_amount'];
            $loan_purpose = $loan_info['loan_purpose'] ?? null;
            $borrower_name = $loan_info['full_name'];
            $borrower_id = (int) $loan_info['user_id'];
            $borrower_email = !empty($loan_info['email']) ? trim($loan_info['email']) : null;
            $co_maker_email = !empty($loan_info['co_maker_email']) ? trim($loan_info['co_maker_email']) : null;
            $co_maker_name = !empty($loan_info['co_maker_full_name']) ? trim($loan_info['co_maker_full_name']) : null;
            $loan_label = "Loan Application - {$borrower_name}";
        }
    }
    
    // Validation: Comment is required for rejected loans
    if ($action === 'reject' && empty($action_comment)) {
        $error = "Comment is required when rejecting a loan application.";
    } else {
    if ($action === 'approve') {
        // Approved = borrower may go to office with requirements; released_at set when they actually release the loan
        $stmt = $conn->prepare("UPDATE loans SET status = 'approved', reviewed_by_id = ?, reviewed_by_role = ?, reviewed_by_name = ?, reviewed_at = NOW(), admin_comment = ? WHERE id = ?");
        $stmt->bind_param("isssi", $reviewed_by_id, $reviewed_by_role, $reviewed_by_name, $action_comment, $loan_id);
        $message = "The loan application has been approved by the DepEd Accounting Unit. The borrower is advised to complete the required documentary compliance at the office. Please select \"Release Loan\" only upon actual loan release.";
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE loans SET status = 'rejected', reviewed_by_id = ?, reviewed_by_role = ?, reviewed_by_name = ?, reviewed_at = NOW(), admin_comment = ? WHERE id = ?");
        $stmt->bind_param("isssi", $reviewed_by_id, $reviewed_by_role, $reviewed_by_name, $action_comment, $loan_id);
        $message = "The loan application has been disapproved by the DepEd Accounting Unit.";
    }
    
    if ($stmt->execute()) {
        $success = $message;
        // When approving a re-apply loan: deduct offset to old loan and mark old loan completed
        if ($action === 'approve' && $borrower_id) {
            $offset_stmt = $conn->prepare("SELECT previous_loan_id, offset_amount FROM loans WHERE id = ?");
            $offset_stmt->bind_param("i", $loan_id);
            $offset_stmt->execute();
            $offset_row = $offset_stmt->get_result()->fetch_assoc();
            $offset_stmt->close();
            $prev_loan_id = isset($offset_row['previous_loan_id']) ? (int) $offset_row['previous_loan_id'] : 0;
            $off_amt = isset($offset_row['offset_amount']) ? (float) $offset_row['offset_amount'] : 0;
            if ($prev_loan_id > 0 && $off_amt > 0) {
                $today = date('Y-m-d');
                $ded_ins = $conn->prepare("INSERT INTO deductions (loan_id, borrower_id, deduction_date, amount, posted_by) VALUES (?, ?, ?, ?, ?)");
                $ded_ins->bind_param("iisdi", $prev_loan_id, $borrower_id, $today, $off_amt, $user_id);
                if ($ded_ins->execute()) {
                    @$conn->query("ALTER TABLE loans MODIFY COLUMN status ENUM('pending','approved','rejected','completed') DEFAULT 'pending'");
                    $conn->query("UPDATE loans SET status = 'completed' WHERE id = " . (int) $prev_loan_id);
                    if (function_exists('log_audit')) {
                        log_audit($conn, 'CREATE', "Offset applied: ₱" . number_format($off_amt, 2) . " from new Loan #{$loan_id} to previous Loan #{$prev_loan_id} (previous loan marked completed).", 'Loan Applications', "Loan #{$prev_loan_id}", $user_id, $reviewed_by_name, $reviewed_by_role);
                    }
                }
                $ded_ins->close();
            }
        }
        $amount_note = $loan_amount !== null ? " for ₱" . number_format($loan_amount, 2) : '';
        $action_type = $action === 'approve' ? 'APPROVE' : 'REJECT';
        $action_label = $action === 'approve' ? 'Approved' : 'Rejected';
        $comment_note = $action_comment !== '' ? " Comment: " . $action_comment : '';
        if ($borrower_id) {
            $notif_title = $action === 'approve' ? 'Loan Approved' : 'Loan Rejected';
            $notif_message = "{$action_label} {$loan_label}{$amount_note}.{$comment_note}";
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
            if ($notif_stmt) {
                $notif_type = $action === 'approve' ? 'success' : 'danger';
                $notif_stmt->bind_param("isss", $borrower_id, $notif_title, $notif_message, $notif_type);
                $notif_stmt->execute();
                $notif_stmt->close();
            }
        }

        // Notify co-maker (in-app) if they have an account and this loan has a co-maker email
        if ($co_maker_email && filter_var($co_maker_email, FILTER_VALIDATE_EMAIL)) {
            $cm_user_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            if ($cm_user_stmt) {
                $cm_user_stmt->bind_param("s", $co_maker_email);
                $cm_user_stmt->execute();
                $cm_user = $cm_user_stmt->get_result()->fetch_assoc();
                $cm_user_stmt->close();
                if ($cm_user && !empty($cm_user['id'])) {
                    $cm_uid = (int) $cm_user['id'];
                    $cm_title = $action === 'approve' ? 'Loan Approved (Co-Maker)' : 'Loan Rejected (Co-Maker)';
                    $purpose_note = $loan_purpose !== null && $loan_purpose !== '' ? " Loan Purpose: " . $loan_purpose . "." : '';
                    $cm_msg = "{$action_label} {$loan_label}{$amount_note}.{$purpose_note}{$comment_note}";
                    $cm_notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                    if ($cm_notif) {
                        $cm_type = $action === 'approve' ? 'success' : 'danger';
                        $cm_notif->bind_param("isss", $cm_uid, $cm_title, $cm_msg, $cm_type);
                        $cm_notif->execute();
                        $cm_notif->close();
                    }
                }
            }
        }

        // Email borrower about approve/reject
        if ($borrower_email && filter_var($borrower_email, FILTER_VALIDATE_EMAIL) && $loan_amount !== null && file_exists(__DIR__ . '/config_email.php')) {
            require_once __DIR__ . '/config_email.php';
            require_once __DIR__ . '/mail_helper.php';
            try {
                if ($action === 'approve') {
                    sendLoanApprovedEmail($borrower_email, $borrower_name, $loan_amount, $loan_id);
                } else {
                    sendLoanRejectedEmail($borrower_email, $borrower_name, $loan_amount, $loan_id, $action_comment);
                }
            } catch (Exception $e) {
                // Don't block success; email failure is logged silently (admin can check SMTP logs)
            }
        }

        // Email co-maker about approve/reject (copy) if borrower used them as co-maker
        if ($co_maker_email && filter_var($co_maker_email, FILTER_VALIDATE_EMAIL) && $loan_amount !== null && file_exists(__DIR__ . '/config_email.php')) {
            require_once __DIR__ . '/config_email.php';
            require_once __DIR__ . '/mail_helper.php';
            try {
                $cm_name_for_email = $co_maker_name ?: 'Co-Maker';
                $purpose_for_email = $loan_purpose !== null ? (string) $loan_purpose : '';
                if ($action === 'approve') {
                    sendCoMakerLoanApprovedEmail($co_maker_email, $cm_name_for_email, (string) $borrower_name, $loan_amount, $loan_id, $purpose_for_email);
                } else {
                    sendCoMakerLoanRejectedEmail($co_maker_email, $cm_name_for_email, (string) $borrower_name, $loan_amount, $loan_id, (string) $action_comment, $purpose_for_email);
                }
            } catch (Exception $e) {
                // Don't block success
            }
        }
        log_audit(
            $conn,
            $action_type,
            "{$action_label} {$loan_label}{$amount_note}.{$comment_note}",
            'Loan Applications',
            $loan_label,
            $reviewed_by_id,
            $reviewed_by_name,
            $reviewed_by_role
        );
        $stmt->close();
        $result_q = ($action === 'approve') ? 'approved' : 'rejected';
        header('Location: loan_applications.php?success=1&result=' . $result_q);
        exit;
    } else {
        $error = "Error updating loan status.";
    }
    $stmt->close();
    }
}

// Fetch all loan applications with user details
$all_loans = [];
$stmt = $conn->prepare("SELECT l.*, u.full_name, u.email FROM loans l JOIN users u ON l.user_id = u.id ORDER BY l.application_date DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_loans[] = $row;
}
$stmt->close();

// Show success message after redirect from approve/reject / release
if (!empty($_GET['released'])) {
    $success = 'Loan marked as released. Borrower can now see the loan as active.';
    $success_alert_kind = 'released';
    $success_alert_title = 'Loan released';
} elseif (!empty($_GET['success'])) {
    $result = isset($_GET['result']) ? (string) $_GET['result'] : '';
    if ($result === 'rejected') {
        $success = 'The loan application has been disapproved by the DepEd Accounting Unit. The borrower and co-maker (if applicable) have been formally notified.';
        $success_alert_kind = 'rejected';
        $success_alert_title = 'Loan application disapproved';
    } elseif ($result === 'approved') {
        $success = 'The loan application has been approved by the DepEd Accounting Unit. The borrower must complete the required documentary compliance at the office. Select “Release Loan” only after actual loan release.';
        $success_alert_kind = 'approved';
        $success_alert_title = 'Loan application approved';
    } else {
        $success = 'Loan application status updated successfully.';
        $success_alert_kind = 'success';
        $success_alert_title = 'Loan application status updated';
    }
}

// Separate loans by status
$pending_loans = array_filter($all_loans, fn($loan) => $loan['status'] == 'pending');
$pending_release_loans = array_filter($all_loans, fn($l) => ($l['status'] ?? '') === 'approved' && empty($l['released_at']));
$completed_loans = array_filter($all_loans, fn($loan) => $loan['status'] != 'pending' && !(($loan['status'] ?? '') === 'approved' && empty($loan['released_at'])));
$pending_loans_count = count($pending_loans);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Applications - DepEd Loan System</title>
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

        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 192px; /* 80% of 250px */
            margin-top: 20px;
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

        .section-badge {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #ffffff;
            -webkit-text-fill-color: #ffffff;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: auto;
        }

        .content-section {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            align-items: stretch;
        }

        .summary-card {
            background: white;
            border-radius: 18px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            border: 1px solid #eef1f4;
            display: grid;
            grid-template-columns: 56px 1fr;
            grid-template-rows: auto auto;
            align-items: center;
            column-gap: 1rem;
            row-gap: 0.45rem;
            transition: all 0.3s ease;
            border-left: 6px solid;
            min-height: 120px;
            height: 100%;
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
        }

        .summary-card.pending {
            border-left-color: #ffc107;
        }

        .summary-card.approved {
            border-left-color: #28a745;
        }

        .summary-card.rejected {
            border-left-color: #dc3545;
        }

        .summary-card.total {
            border-left-color: #8b0000;
        }

        .summary-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            background: #ffffff;
            border: 2px solid transparent;
            justify-self: center;
            grid-row: 1 / span 2;
        }

        .summary-card.pending .summary-icon {
            border-color: #ffc107;
            color: #b86a00;
        }

        .summary-card.approved .summary-icon {
            border-color: #28a745;
            color: #1d7a34;
        }

        .summary-card.rejected .summary-icon {
            border-color: #dc3545;
            color: #b02a37;
        }

        .summary-card.total .summary-icon {
            border-color: #8b0000;
            color: #8b0000;
        }

        .summary-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
            gap: 0.15rem;
        }

        .summary-number {
            font-size: 2.1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.1rem;
            line-height: 1;
        }

        .summary-label {
            font-size: 0.95rem;
            color: #6b7280;
            font-weight: 600;
            line-height: 1.2;
            min-height: 2.4em;
        }

        .summary-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.9rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            align-self: center;
            justify-self: start;
            grid-column: 2;
            min-width: 120px;
            justify-content: center;
        }

        @media (max-width: 1200px) {
            .summary-cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
        }

        .pending-status {
            background: #fff7e0;
            color: #856404;
        }

        .approved-status {
            background: #e8f5ee;
            color: #155724;
        }

        .rejected-status {
            background: #fdebec;
            color: #721c24;
        }

        .total-status {
            background: #f4e7ea;
            color: #8b0000;
        }

        .loan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .loan-table th,
        .loan-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .loan-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .loan-table tr:hover {
            background: #f8f9fa;
        }

        .loan-table .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
            line-height: 1.2;
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
            border-color: #ffc107;
        }

        /* Approved but not yet released — distinct from Released (green) */
        .status-badge.status-approved {
            background: #fffbeb;
            color: #b45309;
            border-color: #f59e0b;
        }

        /* Funds released to borrower — system green */
        .status-badge.status-released {
            background: #dcfce7;
            color: #166534;
            border-color: #22c55e;
        }

        /* Rejected — system maroon/red */
        .status-badge.status-rejected {
            background: #fee2e2;
            color: #991b1b;
            border-color: #dc2626;
        }

        .status-badge.status-completed {
            background: #e0e7ff;
            color: #3730a3;
            border-color: #6366f1;
        }

        .la-pagination {
            margin-top: 0.85rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.7rem;
            align-items: center;
            justify-content: space-between;
        }
        .la-pagination-info {
            color: #64748b;
            font-size: 0.84rem;
            font-weight: 600;
        }
        .la-pagination-controls {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }
        .la-page-btn {
            border: 1px solid #d7dce3;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 0.35rem 0.58rem;
            min-width: 34px;
            cursor: pointer;
            transition: all 0.18s ease;
        }
        .la-page-btn:hover:not(:disabled) {
            border-color: #8b0000;
            color: #8b0000;
            background: #fff7f8;
        }
        .la-page-btn.is-active {
            border-color: #8b0000;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
        }
        .la-page-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-approve {
            background: #28a745;
            color: white;
        }

        .btn-approve:hover {
            background: #218838;
        }

        .btn-reject {
            background: #dc3545;
            color: white;
        }

        .btn-reject:hover {
            background: #c82333;
        }

        .confirm-modal-overlay {
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

        .confirm-modal-overlay.active {
            display: flex;
        }

        .confirm-modal {
            background: #ffffff;
            border-radius: 16px;
            width: min(420px, 95vw);
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.25);
            border: 1px solid rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .confirm-modal-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
        }

        .confirm-modal-body {
            padding: 1.25rem;
            color: #374151;
            font-size: 0.95rem;
        }

        .confirm-modal-actions {
            padding: 1rem 1.25rem 1.25rem;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .confirm-btn {
            border: none;
            border-radius: 10px;
            padding: 0.55rem 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .confirm-btn-primary {
            background: #8b0000;
            color: #fff;
            box-shadow: 0 8px 18px rgba(139, 0, 0, 0.25);
        }

        .confirm-btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .confirm-btn:hover {
            transform: translateY(-1px);
        }

        .confirm-modal textarea {
            width: 100%;
            min-height: 90px;
            margin-top: 0.75rem;
            padding: 0.65rem 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.9rem;
            resize: vertical;
        }

        .confirm-modal textarea.input-error {
            border-color: rgba(220, 53, 69, 0.9);
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.12);
        }

        /* Release Confirm Modal (theme-matched) */
        .release-confirm-overlay {
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
        .release-confirm-overlay.active {
            display: flex;
        }
        .release-confirm-modal {
            background: #ffffff;
            border-radius: 16px;
            width: min(440px, 95vw);
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.25);
            border: 1px solid rgba(139, 0, 0, 0.12);
            overflow: hidden;
        }
        .release-confirm-modal .release-confirm-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
        }
        .release-confirm-modal .release-confirm-body {
            padding: 1.25rem;
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .release-confirm-modal .release-confirm-actions {
            padding: 1rem 1.25rem 1.25rem;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        .release-confirm-modal .release-confirm-btn {
            border: none;
            border-radius: 10px;
            padding: 0.55rem 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .release-confirm-modal .release-confirm-btn-cancel {
            background: #f3f4f6;
            color: #374151;
        }
        .release-confirm-modal .release-confirm-btn-ok {
            background: #8b0000;
            color: #fff;
            box-shadow: 0 8px 18px rgba(139, 0, 0, 0.25);
        }
        .release-confirm-modal .release-confirm-btn:hover {
            transform: translateY(-1px);
        }

        /* Alert Modal (replace browser alert) */
        .alert-modal-overlay {
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

        .alert-modal-overlay.active {
            display: flex;
        }

        .alert-modal {
            width: min(520px, 96vw);
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 26px 54px rgba(15, 23, 42, 0.28);
            border: 1px solid rgba(0, 0, 0, 0.06);
            overflow: hidden;
            transform: translateY(-6px);
            animation: alertPop 180ms ease-out;
        }

        @keyframes alertPop {
            from { opacity: 0; transform: translateY(-14px) scale(0.98); }
            to { opacity: 1; transform: translateY(-6px) scale(1); }
        }

        .alert-modal-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            font-weight: 700;
        }

        .alert-modal-header .alert-title {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
        }

        .alert-modal-header .alert-title span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .alert-modal-close {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease, background 0.15s ease;
            flex-shrink: 0;
        }

        .alert-modal-close:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: scale(1.03);
        }

        .alert-modal-body {
            padding: 1.2rem 1.25rem 0.5rem;
            color: #111827;
            font-size: 0.98rem;
            line-height: 1.5;
        }

        .alert-modal-message {
            color: #374151;
            margin: 0;
            white-space: pre-line;
        }

        .alert-modal-actions {
            padding: 1rem 1.25rem 1.25rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .alert-btn {
            border: none;
            border-radius: 999px;
            padding: 0.6rem 1.25rem;
            font-weight: 700;
            cursor: pointer;
            background: #8b0000;
            color: #fff;
            box-shadow: 0 10px 22px rgba(139, 0, 0, 0.22);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .alert-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(139, 0, 0, 0.28);
        }

        /* Approved, released, rejected — DepEd maroon theme (no green success banner) */
        .alert-modal.alert-approved .alert-modal-header,
        .alert-modal.alert-rejected .alert-modal-header,
        .alert-modal.alert-released .alert-modal-header {
            background: linear-gradient(135deg, #7f1d1d 0%, #8b0000 48%, #b91c1c 100%);
        }

        .alert-modal.alert-approved .alert-title i,
        .alert-modal.alert-rejected .alert-title i,
        .alert-modal.alert-released .alert-title i {
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.95);
            color: #8b0000;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
        }

        .alert-modal.alert-approved .alert-btn,
        .alert-modal.alert-rejected .alert-btn,
        .alert-modal.alert-released .alert-btn {
            background: linear-gradient(135deg, #8b0000 0%, #991b1b 100%);
            color: #fff;
            box-shadow: 0 10px 22px rgba(139, 0, 0, 0.28);
        }

        .alert-modal.alert-approved .alert-btn:hover,
        .alert-modal.alert-rejected .alert-btn:hover,
        .alert-modal.alert-released .alert-btn:hover {
            box-shadow: 0 14px 26px rgba(139, 0, 0, 0.32);
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }

        .loan-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .loan-stats .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 4px solid #8b0000;
        }

        .loan-stats .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .loan-stats .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
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
            width: 93%;
            max-width: 1180px;
            max-height: 92vh;
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
            padding: 0.7rem 1.15rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        #loanModal .modal-header h3 {
            margin: 0;
            font-size: 1.18rem;
            font-weight: 600;
        }

        #loanModal .modal-header .close {
            color: white;
            font-size: 21px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            opacity: 0.7;
        }

        #loanModal .modal-body {
            padding: 1rem 1.15rem 1.1rem;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1 1 auto;
            min-height: 0;
            -webkit-overflow-scrolling: touch;
            box-sizing: border-box;
            /* Parehong lapad ng label column sa LAHAT ng card (Applicant, Co-Maker, Loan, Uploads, …) */
            --loan-detail-label-w: min(13.5rem, 40%);
        }

        #loanModal .loan-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.95rem;
            max-width: 100%;
            box-sizing: border-box;
        }

        #loanModal .detail-section {
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #eceff3;
            padding: 0.7rem 0.85rem;
            min-width: 0;
            overflow: hidden;
            box-sizing: border-box;
        }

        #loanModal .detail-section h4 {
            margin: 0 0 0.4rem;
            font-size: 0.86rem;
            font-weight: 700;
            color: #8b0000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        #loanModal .detail-section h4 i {
            font-size: 0.8rem;
        }

        #loanModal .detail-list {
            display: flex;
            flex-direction: column;
            gap: 0.28rem;
        }

        #loanModal .detail-row {
            display: grid;
            grid-template-columns: var(--loan-detail-label-w) minmax(0, 1fr);
            column-gap: 0.35rem;
            align-items: start;
            font-size: 0.85rem;
            line-height: 1.4;
            min-width: 0;
        }

        #loanModal .detail-label {
            color: #555;
            font-weight: 600;
            font-size: 0.84rem;
            flex-shrink: 0;
            white-space: normal;
            min-width: 0;
        }

        #loanModal .detail-label::after {
            content: ':';
        }

        #loanModal .detail-value {
            color: #333;
            font-weight: 500;
            font-size: 0.85rem;
            min-width: 0;
            max-width: 100%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        #loanModal .detail-value strong {
            color: #8b0000;
            font-size: 0.88rem;
        }

        #loanModal .modal-body .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.76rem;
            padding: 0.3rem 0.65rem;
            border-radius: 999px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
        }

        #loanModal .modal-body .view-btn {
            padding: 0.32rem 0.68rem;
            font-size: 0.82rem;
        }

        #loanModal .detail-muted {
            color: #6b7280;
            font-style: italic;
            font-size: 0.84rem;
        }

        #loanModal .detail-section-uploads .detail-row {
            align-items: center;
        }

        #loanModal .detail-section-uploads .detail-label {
            white-space: nowrap;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            #loanModal .loan-detail-grid {
                grid-template-columns: 1fr;
            }

            #loanModal .detail-list {
                display: flex !important;
                flex-direction: column;
                gap: 0.28rem;
            }

            #loanModal .detail-row {
                display: grid !important;
                grid-template-columns: 1fr !important;
                grid-column: auto !important;
                column-gap: 0;
            }

            #loanModal .detail-section-uploads .detail-label {
                white-space: normal;
            }
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #f5c6cb;
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

        /* Payslip Preview Modal Styles */
        .payslip-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 4000;
        }

        .payslip-modal-overlay.active {
            display: flex;
        }

        .payslip-modal {
            background: white;
            border-radius: 16px;
            width: min(95vw, 1200px);
            max-height: 95vh;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .payslip-modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .payslip-modal-header h3 {
            margin: 0;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .payslip-modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .payslip-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .payslip-modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }

        .payslip-image {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            object-fit: contain;
        }

        .payslip-iframe {
            width: 100%;
            height: 70vh;
            border: none;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        .payslip-download {
            margin-top: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: #8b0000;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .payslip-download:hover {
            background: #dc143c;
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(139, 0, 0, 0.3);
        }

        .payslip-error {
            text-align: center;
            color: #666;
            padding: 2rem;
        }

        .payslip-error i {
            font-size: 3rem;
            color: #dc3545;
            margin-bottom: 1rem;
        }

        .payslip-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            color: #666;
        }

        .payslip-loading i {
            font-size: 2rem;
            color: #8b0000;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            background: #17a2b8;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .view-btn:hover {
            background: #138496;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(23, 162, 184, 0.3);
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
            .filters-card,
            .stats-grid {
                overflow-x: auto;
            }

            .loan-table {
                min-width: 780px;
            }
        }

        @media (max-width: 600px) {
            .loan-table {
                min-width: 720px;
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
                    <span><i class="fas fa-clipboard-check"></i> Loan Applications</span>
                    <span><i class="fas fa-shield-alt"></i> <?php echo $access_label; ?></span>
                </div>
            </div>
        </div>
        <div class="nav-icons">
            <?php include __DIR__ . '/notifications.php'; ?>
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
                    <a href="loan_applications.php" class="sidebar-link active">
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
            <?php if (!empty($success)): ?>
                <div class="success js-success-banner" hidden aria-hidden="true"
                     data-message="<?php echo htmlspecialchars($success); ?>"
                     data-alert-kind="<?php echo htmlspecialchars($success_alert_kind); ?>"
                     data-alert-title="<?php echo htmlspecialchars($success_alert_title); ?>"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Summary Cards Section -->
            <div class="summary-cards">
                <div class="summary-card pending">
                    <div class="summary-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-number"><?php echo count($pending_loans); ?></div>
                        <div class="summary-label">Pending Applications</div>
                    </div>
                    <div class="summary-status pending-status">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>For Review</span>
                    </div>
                </div>

                <div class="summary-card approved">
                    <div class="summary-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-number"><?php echo count(array_filter($all_loans, fn($loan) => $loan['status'] == 'approved')); ?></div>
                        <div class="summary-label">Approved Applications</div>
                    </div>
                    <div class="summary-status approved-status">
                        <i class="fas fa-check"></i>
                        <span>Completed</span>
                    </div>
                </div>

                <div class="summary-card rejected">
                    <div class="summary-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-number"><?php echo count(array_filter($all_loans, fn($loan) => $loan['status'] == 'rejected')); ?></div>
                        <div class="summary-label">Rejected Applications</div>
                    </div>
                    <div class="summary-status rejected-status">
                        <i class="fas fa-times"></i>
                        <span>Declined</span>
                    </div>
                </div>

                <div class="summary-card total">
                    <div class="summary-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-number"><?php echo count($all_loans); ?></div>
                        <div class="summary-label">Total Applications</div>
                    </div>
                    <div class="summary-status total-status">
                        <i class="fas fa-chart-bar"></i>
                        <span>All Time</span>
                    </div>
                </div>
            </div>

            <!-- Pending Applications Section -->
            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-clock"></i>
                    Pending Loan Applications
                    <span class="section-badge"><?php echo count($pending_loans); ?></span>
                </h2>

                <?php if (empty($pending_loans)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Pending Applications</h3>
                        <p>There are currently no pending loan applications to review.</p>
                    </div>
                <?php else: ?>
                    <table class="loan-table">
                        <thead>
                            <tr>
                                <th>Application Date</th>
                                <th>Applicant</th>
                                <th>Email</th>
                                <th>Loan Amount</th>
                                <th>Term</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_loans as $loan): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($loan['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($loan['email']); ?></td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><?php echo $loan['loan_term']; ?> months</td>
                                    <td><span class="status-badge status-pending">Pending</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-info" onclick="viewLoanInfo(<?php echo $loan['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="action_comment" class="action-comment" value="">
                                                <button type="button" class="btn btn-approve" data-confirm="Approve this loan application?" data-confirm-title="Approve Application">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="action_comment" class="action-comment" value="">
                                                <button type="button" class="btn btn-reject" data-confirm="Reject this loan application?" data-confirm-title="Reject Application">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Approved - Pending Release Section -->
            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-file-signature"></i>
                    Approved — Pending Release
                    <span class="section-badge"><?php echo count($pending_release_loans); ?></span>
                </h2>
                <p class="section-desc" style="color: #666; margin-bottom: 1rem; font-size: 0.95rem;">Borrower has been approved and must complete requirements at the office. Click <strong>Release Loan</strong> when the loan has been actually released to the borrower.</p>
                <?php if (empty($pending_release_loans)): ?>
                    <div class="empty-state" style="padding: 1.5rem;">
                        <p style="color: #888;">No approved loans pending release.</p>
                    </div>
                <?php else: ?>
                    <table class="loan-table">
                        <thead>
                            <tr>
                                <th>Application Date</th>
                                <th>Applicant</th>
                                <th>Email</th>
                                <th>Loan Amount</th>
                                <th>Term</th>
                                <th>Approved By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_release_loans as $loan): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($loan['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($loan['email']); ?></td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><?php echo $loan['loan_term']; ?> months</td>
                                    <td><?php echo htmlspecialchars($loan['reviewed_by_name'] ?? '—'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <form method="post" class="release-loan-form" style="display: inline;" data-release-msg="Mark this loan as released? (Borrower has completed office requirements and loan has been released.)">
                                                <input type="hidden" name="action" value="release_loan">
                                                <input type="hidden" name="loan_id" value="<?php echo (int) $loan['id']; ?>">
                                                <button type="button" class="btn btn-success btn-release-loan">
                                                    <i class="fas fa-hand-holding-usd"></i> Release Loan
                                                </button>
                                            </form>
                                            <button class="btn btn-info" onclick="viewLoanInfo(<?php echo $loan['id']; ?>)">
                                                <i class="fas fa-eye"></i> View Info
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Completed Applications Section -->
            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-check-circle"></i>
                    Completed Applications
                    <span class="section-badge"><?php echo count($completed_loans); ?></span>
                </h2>

                <?php if (empty($completed_loans)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Completed Applications</h3>
                        <p>There are no approved or rejected loan applications yet.</p>
                    </div>
                <?php else: ?>
                    <table class="loan-table">
                        <thead>
                            <tr>
                                <th>Application Date</th>
                                <th>Applicant</th>
                                <th>Email</th>
                                <th>Loan Amount</th>
                                <th>Term</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="completedApplicationsBody">
                            <?php foreach ($completed_loans as $loan): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($loan['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($loan['email']); ?></td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><?php echo $loan['loan_term']; ?> months</td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        $statusLabel = $loan['status'];
                                        if ($loan['status'] === 'approved' && !empty($loan['released_at'])) {
                                            $statusLabel = 'Released';
                                            $statusClass = 'status-released';
                                        } elseif ($loan['status'] === 'approved') {
                                            $statusClass = 'status-approved';
                                        } elseif ($loan['status'] === 'rejected') {
                                            $statusClass = 'status-rejected';
                                        } elseif ($loan['status'] === 'completed') {
                                            $statusClass = 'status-completed';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </span>
                                        <?php if (!empty($loan['reviewed_by_name'])): ?>
                                            <div style="margin-top: 4px; font-size: 0.78rem; color: #666;">
                                                By <?php echo htmlspecialchars($loan['reviewed_by_name']); ?>
                                                (<?php echo htmlspecialchars(ucfirst($loan['reviewed_by_role'] ?? '')); ?>)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-info" onclick="viewLoanInfo(<?php echo $loan['id']; ?>)">
                                                <i class="fas fa-eye"></i> View Info
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="la-pagination" id="completedPaginationWrap">
                        <div class="la-pagination-info" id="completedPaginationInfo">Showing 0-0 of 0</div>
                        <div class="la-pagination-controls" id="completedPaginationControls"></div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Loan Information Modal - OUTSIDE main-content to avoid zoom:0.8 override -->
    <div id="loanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Loan Application Details</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="loanDetails">
                <!-- Loan details will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        function viewLoanInfo(loanId) {
            // Fetch loan details via AJAX
            fetch(`get_loan_details.php?id=${loanId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const details = data.loan;
                        const releasedAt = details.released_at;
                        const isReleased = details.status === 'approved' && releasedAt && String(releasedAt).trim() !== '' && !String(releasedAt).startsWith('0000-00-00');
                        const statusBadgeSuffix = isReleased ? 'released' : details.status;
                        const statusDisplayLabel = isReleased ? 'Released' : (details.status.charAt(0).toUpperCase() + details.status.slice(1));
                        let statusFa = 'circle';
                        if (details.status === 'pending') statusFa = 'clock';
                        else if (isReleased) statusFa = 'circle-check';
                        else if (details.status === 'approved') statusFa = 'clipboard-check';
                        else if (details.status === 'completed') statusFa = 'check-circle';
                        else if (details.status === 'rejected') statusFa = 'times-circle';
                        const isExistingLoan = Number(details.is_existing_loan || 0) === 1;
                        const missingUploadText = isExistingLoan
                            ? 'Not required (Existing Loan record)'
                            : 'Not available';
                        const detailsHtml = `
                            <div class="loan-detail-grid">
                                <div class="detail-section">
                                    <h4><i class="fas fa-user"></i> Applicant Info</h4>
                                    <div class="detail-list">
                                        <div class="detail-row">
                                            <div class="detail-label">Name</div>
                                            <div class="detail-value"><strong>${details.full_name}</strong></div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Email</div>
                                            <div class="detail-value">${details.email}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Application Date</div>
                                            <div class="detail-value">${new Date(details.application_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Date of Birth</div>
                                            <div class="detail-value">${details.borrower_date_of_birth ? new Date(details.borrower_date_of_birth).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '—'}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Years of Service</div>
                                            <div class="detail-value">${(details.borrower_years_of_service != null && details.borrower_years_of_service !== '') ? details.borrower_years_of_service : '—'}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-section">
                                    <h4><i class="fas fa-file-contract"></i> Loan Details</h4>
                                    <div class="detail-list">
                                        <div class="detail-row">
                                            <div class="detail-label">Loan Amount</div>
                                            <div class="detail-value"><strong>₱${parseFloat(details.loan_amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong></div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Loan Purpose</div>
                                            <div class="detail-value">${details.loan_purpose}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Loan Term</div>
                                            <div class="detail-value">${details.loan_term} months</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Net Pay</div>
                                            <div class="detail-value">₱${parseFloat(details.net_pay).toLocaleString('en-PH', {minimumFractionDigits: 2})}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Monthly Payment</div>
                                            <div class="detail-value"><strong>₱${parseFloat(details.monthly_payment).toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong></div>
                                        </div>
                                        ${(details.previous_loan_id != null && details.previous_loan_id !== '' && details.offset_amount != null && parseFloat(details.offset_amount) > 0) ? `
                                        <div class="detail-row detail-row-highlight" style="margin-top: 0.3rem; padding-top: 0.3rem; border-top: 1px solid #dee2e6;">
                                            <div class="detail-label">Re-apply</div>
                                            <div class="detail-value">
                                                <span class="reapply-badge" style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #0d6efd; background: #e7f1ff; padding: 0.25rem 0.5rem; border-radius: 6px;"><i class="fas fa-sync-alt"></i> Remaining balance of ₱${parseFloat(details.offset_amount).toLocaleString('en-PH', {minimumFractionDigits: 2})} will be deducted from your reloan amount of ₱${parseFloat(details.loan_amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}.</span>
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Amount to be released</div>
                                            <div class="detail-value"><strong>₱${(parseFloat(details.loan_amount) - parseFloat(details.offset_amount)).toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong></div>
                                        </div>
                                        ` : ''}
                                    </div>
                                </div>

                                <div class="detail-section">
                                    <h4><i class="fas fa-user-tie"></i> Co-Maker Info</h4>
                                    <div class="detail-list">
                                        <div class="detail-row">
                                            <div class="detail-label">Name</div>
                                            <div class="detail-value"><strong>${details.co_maker_full_name}</strong></div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Position</div>
                                            <div class="detail-value">${details.co_maker_position}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Assignment</div>
                                            <div class="detail-value">${details.co_maker_school_assignment}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Employment Status</div>
                                            <div class="detail-value">${details.co_maker_employment_status}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Date of Birth</div>
                                            <div class="detail-value">${details.co_maker_date_of_birth ? new Date(details.co_maker_date_of_birth).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '—'}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Years of Service</div>
                                            <div class="detail-value">${(details.co_maker_years_of_service != null && details.co_maker_years_of_service !== '') ? details.co_maker_years_of_service : '—'}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Net Pay</div>
                                            <div class="detail-value">₱${parseFloat(details.co_maker_net_pay).toLocaleString('en-PH', {minimumFractionDigits: 2})}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-section detail-section-uploads">
                                    <h4><i class="fas fa-file-upload"></i> Uploads</h4>
                                    <div class="detail-list">
                                        <div class="detail-row">
                                            <div class="detail-label">Borrower ID (Front)</div>
                                            <div class="detail-value">
                                                ${(details.borrower_id_front_filename != null && String(details.borrower_id_front_filename).trim() !== '') ? `<button type="button" class="view-btn" onclick="viewLoanDocument(${details.id}, 'borrower_id_front')"><i class="fas fa-eye"></i> View ID (Front)</button>` : `<span class="detail-muted">${missingUploadText}</span>`}
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Borrower ID (Back)</div>
                                            <div class="detail-value">
                                                ${(details.borrower_id_back_filename != null && String(details.borrower_id_back_filename).trim() !== '') ? `<button type="button" class="view-btn" onclick="viewLoanDocument(${details.id}, 'borrower_id_back')"><i class="fas fa-eye"></i> View ID (Back)</button>` : `<span class="detail-muted">${missingUploadText}</span>`}
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Borrower Payslip</div>
                                            <div class="detail-value">
                                                ${(details.payslip_filename != null && String(details.payslip_filename).trim() !== '') ? `<button type="button" class="view-btn" onclick="viewLoanDocument(${details.id}, 'borrower_payslip')"><i class="fas fa-file-download"></i> View Payslip</button>` : `<span class="detail-muted">${missingUploadText}</span>`}
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Co-maker ID (Front)</div>
                                            <div class="detail-value">
                                                ${(details.co_maker_id_front_filename != null && String(details.co_maker_id_front_filename).trim() !== '') ? `<button type="button" class="view-btn" onclick="viewLoanDocument(${details.id}, 'co_maker_id_front')"><i class="fas fa-eye"></i> View ID (Front)</button>` : `<span class="detail-muted">${missingUploadText}</span>`}
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Co-maker ID (Back)</div>
                                            <div class="detail-value">
                                                ${(details.co_maker_id_back_filename != null && String(details.co_maker_id_back_filename).trim() !== '') ? `<button type="button" class="view-btn" onclick="viewLoanDocument(${details.id}, 'co_maker_id_back')"><i class="fas fa-eye"></i> View ID (Back)</button>` : `<span class="detail-muted">${missingUploadText}</span>`}
                                            </div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Co-maker Payslip</div>
                                            <div class="detail-value">
                                                ${(details.co_maker_payslip_filename != null && String(details.co_maker_payslip_filename).trim() !== '') ? `<button type="button" class="view-btn" onclick="viewLoanDocument(${details.id}, 'co_maker_payslip')"><i class="fas fa-file-download"></i> View Payslip</button>` : `<span class="detail-muted">${missingUploadText}</span>`}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-section">
                                    <h4><i class="fas fa-calculator"></i> Totals</h4>
                                    <div class="detail-list">
                                        <div class="detail-row">
                                            <div class="detail-label">Total Amount</div>
                                            <div class="detail-value"><strong>₱${parseFloat(details.total_amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong></div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Total Interest</div>
                                            <div class="detail-value">₱${parseFloat(details.total_interest).toLocaleString('en-PH', {minimumFractionDigits: 2})}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-section">
                                    <h4><i class="fas fa-check-circle"></i> Review Status</h4>
                                    <div class="detail-list">
                                        <div class="detail-row">
                                            <div class="detail-label">Reviewed By</div>
                                            <div class="detail-value">${details.reviewed_by_name ? `${details.reviewed_by_name} (${details.reviewed_by_role || 'N/A'})` : 'Not reviewed yet'}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Reviewed At</div>
                                            <div class="detail-value">${details.reviewed_at ? new Date(details.reviewed_at).toLocaleString() : 'N/A'}</div>
                                        </div>
                                        <div class="detail-row">
                                            <div class="detail-label">Status</div>
                                            <div class="detail-value">
                                                <span class="status-badge status-${statusBadgeSuffix}">
                                                    <i class="fas fa-${statusFa}"></i>
                                                    ${statusDisplayLabel}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.getElementById('loanDetails').innerHTML = detailsHtml;
                        document.getElementById('loanModal').style.display = 'flex';
                    } else {
                        openAlertModal('Error loading loan details.', 'Something went wrong');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    openAlertModal('Error loading loan details.', 'Something went wrong');
                });
        }

        function closeModal() {
            document.getElementById('loanModal').style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('loanModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
            
            // Close payslip modal when clicking outside
            const payslipModal = document.getElementById('payslipModalOverlay');
            if (event.target == payslipModal) {
                closePayslipModal();
            }
        }

        const loanDocTitles = {
            borrower_payslip: 'Borrower Payslip Preview',
            co_maker_payslip: 'Co-Maker Payslip Preview',
            borrower_id_front: 'Borrower ID (Front)',
            borrower_id_back: 'Borrower ID (Back)',
            co_maker_id_front: 'Co-Maker ID (Front)',
            co_maker_id_back: 'Co-Maker ID (Back)'
        };

        function loanDocumentUrl(loanId, doc) {
            return `download_loan_document.php?id=${loanId}&doc=${encodeURIComponent(doc)}`;
        }

        let loanDocPreviewObjectUrl = null;

        function revokeLoanDocPreviewUrl() {
            if (loanDocPreviewObjectUrl) {
                URL.revokeObjectURL(loanDocPreviewObjectUrl);
                loanDocPreviewObjectUrl = null;
            }
        }

        /** Preview payslip or ID scan (admin/accountant). Uses GET + blob — HEAD often breaks on PHP/XAMPP. */
        function viewLoanDocument(loanId, doc) {
            const modal = document.getElementById('payslipModalOverlay');
            const body = document.getElementById('payslipModalBody');
            const titleText = document.getElementById('payslipModalTitleText');
            const url = loanDocumentUrl(loanId, doc);
            const title = loanDocTitles[doc] || 'Document Preview';
            const titleEsc = title.replace(/"/g, '&quot;');

            revokeLoanDocPreviewUrl();

            titleText.textContent = title;

            body.innerHTML = `
                <div class="payslip-loading">
                    <i class="fas fa-spinner"></i>
                    <span>Loading document...</span>
                </div>
            `;

            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            fetch(url, { credentials: 'same-origin' })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Document not found');
                    }
                    const rawCt = response.headers.get('Content-Type') || '';
                    const contentType = rawCt.split(';')[0].trim().toLowerCase();
                    if (contentType.includes('text/html')) {
                        throw new Error('Unexpected HTML response');
                    }
                    return response.blob().then(function(blob) {
                        return { blob, contentType };
                    });
                })
                .then(function(ref) {
                    const blob = ref.blob;
                    let ct = ref.contentType;
                    if (!ct && blob.type) {
                        ct = blob.type.split(';')[0].trim().toLowerCase();
                    }
                    const isImage = ct.indexOf('image/') === 0;

                    loanDocPreviewObjectUrl = URL.createObjectURL(blob);
                    const objectUrl = loanDocPreviewObjectUrl;

                    if (isImage) {
                        body.innerHTML = `
                            <img src="${objectUrl}" alt="${titleEsc}" class="payslip-image">
                            <a href="${url}" class="payslip-download" download>
                                <i class="fas fa-download"></i>
                                Download
                            </a>
                        `;
                    } else {
                        body.innerHTML = `
                            <iframe src="${objectUrl}" class="payslip-iframe" title="${titleEsc}"></iframe>
                            <a href="${url}" class="payslip-download" download>
                                <i class="fas fa-download"></i>
                                Download
                            </a>
                        `;
                    }
                })
                .catch(function(error) {
                    revokeLoanDocPreviewUrl();
                    console.error('Error loading document:', error);
                    showDocumentErrorState();
                });
        }

        function viewPayslip(loanId, type) {
            viewLoanDocument(loanId, type === 'co_maker' ? 'co_maker_payslip' : 'borrower_payslip');
        }

        function closePayslipModal() {
            revokeLoanDocPreviewUrl();
            const modal = document.getElementById('payslipModalOverlay');
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = 'auto';
            const bodyEl = document.getElementById('payslipModalBody');
            if (bodyEl) {
                bodyEl.innerHTML = '';
            }
        }

        function showDocumentErrorState() {
            const body = document.getElementById('payslipModalBody');
            body.innerHTML = `
                <div class="payslip-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Unable to Load Document</h3>
                    <p>The file could not be loaded. It may be corrupted or no longer available.</p>
                    <p>Try downloading from the list or contact support if the issue persists.</p>
                </div>
            `;
        }

        // Close payslip modal with button
        document.addEventListener('DOMContentLoaded', function() {
            const closeBtn = document.getElementById('payslipModalClose');
            if (closeBtn) {
                closeBtn.addEventListener('click', closePayslipModal);
            }
            
            // Close with Escape key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    const payslipModal = document.getElementById('payslipModalOverlay');
                    if (payslipModal.classList.contains('active')) {
                        closePayslipModal();
                    }
                }
            });
        });
    </script>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <div id="confirmModalOverlay" class="confirm-modal-overlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <div class="confirm-modal-header">
                <i class="fas fa-exclamation-circle"></i>
                <span id="confirmModalTitle">Confirm Action</span>
            </div>
            <div class="confirm-modal-body" id="confirmModalMessage">
                Are you sure you want to proceed?
                <textarea id="confirmModalComment" placeholder="Add comment (optional)"></textarea>
            </div>
            <div class="confirm-modal-actions">
                <button type="button" class="confirm-btn confirm-btn-secondary" id="confirmModalCancel">Cancel</button>
                <button type="button" class="confirm-btn confirm-btn-primary" id="confirmModalOk">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Release Confirm Modal (theme-matched) -->
    <div id="releaseConfirmOverlay" class="release-confirm-overlay" aria-hidden="true">
        <div class="release-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="releaseConfirmTitle">
            <div class="release-confirm-header">
                <i class="fas fa-hand-holding-usd"></i>
                <span id="releaseConfirmTitle">Confirm Release</span>
            </div>
            <div class="release-confirm-body" id="releaseConfirmMessage">
                Mark this loan as released? (Borrower has completed office requirements and loan has been released.)
            </div>
            <div class="release-confirm-actions">
                <button type="button" class="release-confirm-btn release-confirm-btn-cancel" id="releaseConfirmCancel">Cancel</button>
                <button type="button" class="release-confirm-btn release-confirm-btn-ok" id="releaseConfirmOk">OK</button>
            </div>
        </div>
    </div>

    <!-- Alert Modal (replaces browser alert) -->
    <div id="alertModalOverlay" class="alert-modal-overlay" aria-hidden="true">
        <div class="alert-modal" role="alertdialog" aria-modal="true" aria-labelledby="alertModalTitle" aria-describedby="alertModalMessage">
            <div class="alert-modal-header">
                <div class="alert-title">
                    <i id="alertModalIcon" class="fas fa-triangle-exclamation"></i>
                    <span id="alertModalTitle">Notice</span>
                </div>
                <button type="button" class="alert-modal-close" id="alertModalClose" aria-label="Close alert">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="alert-modal-body">
                <p class="alert-modal-message" id="alertModalMessage">Message</p>
            </div>
            <div class="alert-modal-actions">
                <button type="button" class="alert-btn" id="alertModalOk">OK</button>
            </div>
        </div>
    </div>

    <!-- Payslip Preview Modal -->
    <div id="payslipModalOverlay" class="payslip-modal-overlay" aria-hidden="true">
        <div class="payslip-modal" role="dialog" aria-modal="true" aria-labelledby="payslipModalTitle">
            <div class="payslip-modal-header">
                <h3 id="payslipModalTitle">
                    <i class="fas fa-file-image"></i>
                    <span id="payslipModalTitleText">Payslip Preview</span>
                </h3>
                <button type="button" class="payslip-modal-close" id="payslipModalClose" aria-label="Close payslip preview">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="payslip-modal-body" id="payslipModalBody">
                <div class="payslip-loading">
                    <i class="fas fa-spinner"></i>
                    <span>Loading payslip...</span>
                </div>
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

        let pendingConfirmForm = null;
        const confirmOverlay = document.getElementById('confirmModalOverlay');
        const confirmTitle = document.getElementById('confirmModalTitle');
        const confirmMessage = document.getElementById('confirmModalMessage');
        const confirmComment = document.getElementById('confirmModalComment');

        const alertOverlay = document.getElementById('alertModalOverlay');
        const alertTitleEl = document.getElementById('alertModalTitle');
        const alertMessageEl = document.getElementById('alertModalMessage');
        const alertOkBtn = document.getElementById('alertModalOk');
        const alertCloseBtn = document.getElementById('alertModalClose');
        const alertIconEl = document.getElementById('alertModalIcon');
        const alertBoxEl = alertOverlay ? alertOverlay.querySelector('.alert-modal') : null;

        function openAlertModal(message, title, variant) {
            const kind = variant || 'danger';
            alertTitleEl.textContent = title || (kind === 'success' || kind === 'approved' ? 'Success' : kind === 'rejected' ? 'Loan application rejected' : kind === 'released' ? 'Loan released' : 'Notice');
            alertMessageEl.textContent = message || '';

            if (alertBoxEl) {
                alertBoxEl.classList.remove('alert-approved', 'alert-rejected', 'alert-released');
                if (kind === 'success' || kind === 'approved') {
                    alertBoxEl.classList.add('alert-approved');
                } else if (kind === 'rejected') {
                    alertBoxEl.classList.add('alert-rejected');
                } else if (kind === 'released') {
                    alertBoxEl.classList.add('alert-released');
                }
            }

            if (alertIconEl) {
                if (kind === 'success' || kind === 'approved' || kind === 'released') {
                    alertIconEl.className = 'fas fa-check-circle';
                } else if (kind === 'rejected') {
                    alertIconEl.className = 'fas fa-times-circle';
                } else {
                    alertIconEl.className = 'fas fa-triangle-exclamation';
                }
            }

            alertOverlay.classList.add('active');
            alertOverlay.setAttribute('aria-hidden', 'false');
            setTimeout(() => alertOkBtn && alertOkBtn.focus(), 0);
        }

        function closeAlertModal() {
            alertOverlay.classList.remove('active');
            alertOverlay.setAttribute('aria-hidden', 'true');
        }

        if (alertOkBtn) alertOkBtn.addEventListener('click', closeAlertModal);
        if (alertCloseBtn) alertCloseBtn.addEventListener('click', closeAlertModal);
        if (alertOverlay) {
            alertOverlay.addEventListener('click', (event) => {
                if (event.target === alertOverlay) closeAlertModal();
            });
        }

        let pendingReleaseForm = null;
        const releaseConfirmOverlay = document.getElementById('releaseConfirmOverlay');
        const releaseConfirmMessage = document.getElementById('releaseConfirmMessage');
        const releaseConfirmCancel = document.getElementById('releaseConfirmCancel');
        const releaseConfirmOk = document.getElementById('releaseConfirmOk');

        function openReleaseConfirmModal(form) {
            const msg = form && form.getAttribute('data-release-msg');
            if (releaseConfirmMessage) releaseConfirmMessage.textContent = msg || 'Mark this loan as released? (Borrower has completed office requirements and loan has been released.)';
            pendingReleaseForm = form;
            if (releaseConfirmOverlay) {
                releaseConfirmOverlay.classList.add('active');
                releaseConfirmOverlay.setAttribute('aria-hidden', 'false');
            }
        }

        function closeReleaseConfirmModal() {
            pendingReleaseForm = null;
            if (releaseConfirmOverlay) {
                releaseConfirmOverlay.classList.remove('active');
                releaseConfirmOverlay.setAttribute('aria-hidden', 'true');
            }
        }

        if (releaseConfirmOk) releaseConfirmOk.addEventListener('click', function() {
            if (pendingReleaseForm) {
                pendingReleaseForm.submit();
            }
            closeReleaseConfirmModal();
        });
        if (releaseConfirmCancel) releaseConfirmCancel.addEventListener('click', closeReleaseConfirmModal);
        if (releaseConfirmOverlay) {
            releaseConfirmOverlay.addEventListener('click', function(event) {
                if (event.target === releaseConfirmOverlay) closeReleaseConfirmModal();
            });
        }

        document.querySelectorAll('.btn-release-loan').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const form = btn.closest('.release-loan-form');
                if (form) openReleaseConfirmModal(form);
            });
        });

        function openConfirmModal(message, title, form) {
            confirmTitle.textContent = title || 'Confirm Action';
            confirmMessage.childNodes[0].textContent = message || 'Are you sure you want to proceed?';
            
            // Check if this is a rejection action
            const isRejection = form && form.querySelector('input[name="action"][value="reject"]');
            const commentLabel = confirmMessage.querySelector('label');
            
            if (isRejection) {
                confirmComment.placeholder = "Comment is required when rejecting a loan application";
                confirmComment.required = true;
                if (commentLabel) {
                    commentLabel.textContent = "Comment (Required):";
                    commentLabel.style.color = "#dc3545";
                } else {
                    // Add label if it doesn't exist
                    const label = document.createElement('label');
                    label.textContent = "Comment (Required):";
                    label.style.color = "#dc3545";
                    label.style.display = "block";
                    label.style.marginBottom = "0.5rem";
                    label.style.fontWeight = "600";
                    confirmMessage.insertBefore(label, confirmComment);
                }
            } else {
                confirmComment.placeholder = "Add comment (optional)";
                confirmComment.required = false;
                if (commentLabel) {
                    commentLabel.textContent = "Comment (Optional):";
                    commentLabel.style.color = "#333";
                }
            }
            
            confirmComment.value = '';
            pendingConfirmForm = form;
            confirmOverlay.classList.add('active');
            confirmOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeConfirmModal() {
            pendingConfirmForm = null;
            confirmOverlay.classList.remove('active');
            confirmOverlay.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('button[data-confirm]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const message = button.getAttribute('data-confirm');
                const title = button.getAttribute('data-confirm-title');
                const form = button.closest('form');
                if (form) {
                    openConfirmModal(message, title, form);
                }
            });
        });

        document.getElementById('confirmModalCancel').addEventListener('click', closeConfirmModal);
        document.getElementById('confirmModalOk').addEventListener('click', () => {
            if (pendingConfirmForm) {
                const isRejection = pendingConfirmForm.querySelector('input[name="action"][value="reject"]');
                
                // Validate comment for rejections
                if (isRejection && confirmComment.value.trim() === '') {
                    confirmComment.classList.add('input-error');
                    openAlertModal(
                        'Comment is required when rejecting a loan application.\nPlease provide a reason for rejection.',
                        'Comment Required'
                    );
                    confirmComment.focus();
                    return;
                }
                
                const commentInput = pendingConfirmForm.querySelector('.action-comment');
                if (commentInput) {
                    commentInput.value = confirmComment.value.trim();
                }
                pendingConfirmForm.submit();
            }
            closeConfirmModal();
        });

        confirmComment.addEventListener('input', () => {
            if (confirmComment.classList.contains('input-error') && confirmComment.value.trim() !== '') {
                confirmComment.classList.remove('input-error');
            }
        });

        confirmOverlay.addEventListener('click', (event) => {
            if (event.target === confirmOverlay) {
                closeConfirmModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (alertOverlay && alertOverlay.classList.contains('active')) {
                    closeAlertModal();
                } else if (releaseConfirmOverlay && releaseConfirmOverlay.classList.contains('active')) {
                    closeReleaseConfirmModal();
                }
            }
        });

        (function () {
            const tbody = document.getElementById('completedApplicationsBody');
            const infoEl = document.getElementById('completedPaginationInfo');
            const controlsEl = document.getElementById('completedPaginationControls');
            const wrapEl = document.getElementById('completedPaginationWrap');
            if (!tbody || !infoEl || !controlsEl || !wrapEl) return;

            const rows = Array.from(tbody.querySelectorAll('tr'));
            const pageSize = 15;
            let currentPage = 1;

            function getTotalPages() {
                return Math.max(1, Math.ceil(rows.length / pageSize));
            }

            function makeBtn(label, page, disabled, active) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'la-page-btn' + (active ? ' is-active' : '');
                btn.textContent = label;
                btn.disabled = !!disabled;
                btn.addEventListener('click', function () {
                    currentPage = page;
                    render();
                });
                return btn;
            }

            function render() {
                const total = rows.length;
                const totalPages = getTotalPages();
                if (currentPage > totalPages) currentPage = totalPages;
                const startIdx = (currentPage - 1) * pageSize;
                const endIdx = Math.min(startIdx + pageSize, total);

                rows.forEach((row, idx) => {
                    row.style.display = (idx >= startIdx && idx < endIdx) ? '' : 'none';
                });

                infoEl.textContent = total === 0
                    ? 'Showing 0-0 of 0'
                    : 'Showing ' + (startIdx + 1) + '-' + endIdx + ' of ' + total;

                controlsEl.innerHTML = '';
                wrapEl.style.display = '';

                controlsEl.appendChild(makeBtn('Prev', currentPage - 1, currentPage === 1, false));
                for (let p = 1; p <= totalPages; p++) {
                    controlsEl.appendChild(makeBtn(String(p), p, false, p === currentPage));
                }
                controlsEl.appendChild(makeBtn('Next', currentPage + 1, currentPage === totalPages, false));
            }

            render();
        })();

        document.addEventListener('DOMContentLoaded', function() {
            const successBanner = document.querySelector('.js-success-banner');
            if (successBanner) {
                const msg = successBanner.dataset.message || successBanner.textContent.trim();
                const kind = successBanner.dataset.alertKind || 'success';
                const alertTitle = successBanner.dataset.alertTitle || (kind === 'rejected' ? 'Loan application rejected' : kind === 'released' ? 'Loan released' : 'Loan application status updated');
                successBanner.style.display = 'none';
                openAlertModal(msg, alertTitle, kind);
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
