<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get current user data
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
// Determine dashboard target based on role/username
$is_admin = ($user_data['role'] ?? '') === 'admin' || $user_data['username'] === 'admin';
$dashboard_url = $is_admin ? 'admin_dashboard.php' : 'borrower_dashboard.php';
$is_embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'password') ? 'password' : 'profile';
$profile_photo = $user_data['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);
unset($is_admin);

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $email = $user_data['email'];
        $deped_id = trim($_POST['deped_id'] ?? '');
        $contact_number = trim($_POST['contact_number']);
        $home_address = trim($_POST['home_address']);
    $profile_photo = $user_data['profile_photo'] ?? '';
    
    // Validation
        if (empty($username) || empty($email) || empty($contact_number) || empty($home_address)) {
        $error = "All fields are required";
        } elseif (!empty($deped_id) && (strlen(preg_replace('/\D/', '', $deped_id)) !== 7)) {
        $error = "Employee Deped No. must be exactly 7 digits";
        } elseif (!preg_match('/^09\d{9}$/', $contact_number)) {
        $error = "Contact number must be 11 digits starting with 09 (format: 09XXXXXXXXX)";
    } else {
        if (!empty($deped_id)) {
            $deped_id = preg_replace('/\D/', '', $deped_id);
        }
        // Ensure profile_photo column exists
        $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
        if ($column_check && $column_check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL");
        }

        // Handle profile photo upload
        if (!empty($_FILES['profile_photo']['name'])) {
            $file = $_FILES['profile_photo'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                if ($file['size'] > 2 * 1024 * 1024) {
                    $error = "Profile photo must be 2MB or less";
                } else {
                    $allowed_mimes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    if (!isset($allowed_mimes[$mime])) {
                        $error = "Profile photo must be JPG, PNG, or WebP";
                    } else {
                        $upload_dir = __DIR__ . '/uploads/profile_pictures';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        $filename = 'user_' . $user_id . '_' . time() . '.' . $allowed_mimes[$mime];
                        $target_path = $upload_dir . '/' . $filename;
                        if (move_uploaded_file($file['tmp_name'], $target_path)) {
                            $profile_photo = 'uploads/profile_pictures/' . $filename;
                        } else {
                            $error = "Failed to upload profile photo";
                        }
                    }
                }
            } else {
                $error = "Failed to upload profile photo";
            }
        }

        if (empty($error)) {
            // Check if email or username already exists (excluding current user)
            $check_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("si", $username, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "Email or username already exists";
            } else {
                // Update profile
                $update_sql = "UPDATE users SET username = ?, email = ?, deped_id = ?, contact_number = ?, home_address = ?, profile_photo = ? WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ssssssi", $username, $email, $deped_id, $contact_number, $home_address, $profile_photo, $user_id);
                
                if ($stmt->execute()) {
                    $success = "Profile updated successfully!";
                    log_audit(
                        $conn,
                        'UPDATE',
                        'Updated profile details.',
                        'Profile Update',
                        "User #{$user_id}"
                    );
                    // Refresh user data
                    $sql = "SELECT * FROM users WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user_data = $result->fetch_assoc();
                    $profile_photo = $user_data['profile_photo'] ?? '';
                    $profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);
                    $_SESSION['deped_id'] = $user_data['deped_id'] ?? null;
                } else {
                    $error = "Profile update failed. Please try again.";
                }
            }
            $stmt->close();
        }
    }
}

// Password change is handled via OTP flow (change_password_send_otp, verify_otp, update)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile - Provident Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <style>
        :root {
            --pu-maroon: #8b0000;
            --pu-maroon-mid: #a52a2a;
            --pu-rose: #dc143c;
            --pu-page: #f4f5f7;
            --pu-surface: #ffffff;
            --pu-border: #e5e7eb;
            --pu-border-soft: #eef1f4;
            --pu-text: #1e293b;
            --pu-muted: #64748b;
            --pu-radius: 14px;
            --pu-radius-sm: 10px;
            --pu-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
            --pu-shadow-lg: 0 20px 50px rgba(15, 23, 42, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body.profile-page {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(160deg, #eef2f7 0%, #e2e8f0 45%, #dbeafe 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--pu-text);
        }

        body.profile-page.embed {
            background: var(--pu-surface);
            align-items: flex-start;
            justify-content: flex-start;
            padding: 0;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .pu-container.container {
            background: var(--pu-surface);
            border-radius: var(--pu-radius);
            box-shadow: var(--pu-shadow-lg);
            border: 1px solid var(--pu-border-soft);
            max-width: 880px;
            width: 100%;
            overflow: hidden;
        }

        body.embed .pu-container.container {
            max-width: 100%;
            border-radius: 0;
            box-shadow: none;
            border: none;
            min-height: 100%;
        }

        /* —— Hero header —— */
        .pu-header {
            position: relative;
            padding: 1.5rem 1.75rem 1.25rem;
            background: linear-gradient(135deg, #fffefd 0%, #fef7f7 50%, #fff 100%);
            border-bottom: 1px solid var(--pu-border-soft);
        }

        .pu-header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--pu-maroon), var(--pu-rose));
        }

        .pu-header-top {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .pu-title {
            font-size: clamp(1.35rem, 2.5vw, 1.75rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--pu-text);
            margin-bottom: 0.35rem;
        }

        .pu-lead {
            font-size: 0.95rem;
            color: var(--pu-muted);
            line-height: 1.45;
            max-width: 36rem;
        }

        .pu-header-aside {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.35rem;
        }

        .pu-role-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--pu-maroon);
            background: rgba(139, 0, 0, 0.06);
            border: 1px solid rgba(139, 0, 0, 0.15);
        }

        .pu-brand {
            font-size: 0.78rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .pu-meta-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1.1rem;
        }

        .pu-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
            color: #475569;
            background: #f8fafc;
            border: 1px solid var(--pu-border);
            border-radius: 999px;
        }

        .pu-chip i {
            color: var(--pu-maroon);
            opacity: 0.85;
            font-size: 0.75rem;
        }

        /* —— Content & tabs —— */
        .pu-content.content {
            padding: 1.5rem 1.75rem 1.75rem;
            background: var(--pu-page);
        }

        body.embed .pu-content.content {
            padding: 1.25rem 1.5rem 1.5rem;
            background: var(--pu-surface);
        }

        .pu-tabs.tabs {
            display: flex;
            gap: 0.35rem;
            padding: 0.35rem;
            margin-bottom: 1.35rem;
            background: #f1f5f9;
            border-radius: 12px;
            border: 1px solid var(--pu-border);
        }

        body.embed .pu-tabs.tabs {
            margin-bottom: 1.25rem;
        }

        .pu-tab.tab {
            flex: 1;
            padding: 0.65rem 1rem;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--pu-muted);
            background: transparent;
            transition: background 0.2s, color 0.2s, box-shadow 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }

        .pu-tab.tab:hover {
            color: var(--pu-maroon);
        }

        .pu-tab.tab.active {
            color: #fff;
            background: linear-gradient(135deg, var(--pu-maroon) 0%, var(--pu-rose) 100%);
            box-shadow: 0 4px 14px rgba(139, 0, 0, 0.28);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: puFade 0.25s ease;
        }

        @keyframes puFade {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* —— Sections —— */
        .pu-section {
            margin-bottom: 1.35rem;
        }

        .pu-section:last-of-type {
            margin-bottom: 0;
        }

        .pu-section-head {
            margin-bottom: 0.65rem;
        }

        .pu-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--pu-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pu-section-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.12), rgba(220, 20, 60, 0.08));
            color: var(--pu-maroon);
            font-size: 0.9rem;
        }

        .pu-section-desc {
            font-size: 0.82rem;
            color: var(--pu-muted);
            margin-top: 0.25rem;
            line-height: 1.45;
        }

        .pu-section-card {
            background: var(--pu-surface);
            border: 1px solid var(--pu-border);
            border-radius: var(--pu-radius-sm);
            padding: 1.15rem 1.2rem;
            box-shadow: var(--pu-shadow);
        }

        body.embed .pu-section-card {
            box-shadow: none;
            border-color: var(--pu-border-soft);
        }

        .pu-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem 1.25rem;
        }

        @media (max-width: 720px) {
            .pu-grid {
                grid-template-columns: 1fr;
            }
        }

        .pu-span-rows {
            grid-row: span 2;
        }

        @media (max-width: 720px) {
            .pu-span-rows {
                grid-row: auto;
            }
        }

        .pu-req {
            color: #dc2626;
            font-weight: 700;
        }

        /* —— Photo block —— */
        .pu-photo-layout {
            display: flex;
            flex-wrap: wrap;
            gap: 1.15rem;
            align-items: flex-start;
        }

        .profile-photo.pu-avatar {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f1f5f9, #e2e8f0);
            color: var(--pu-maroon);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.65rem;
            overflow: hidden;
            flex-shrink: 0;
            border: 3px solid #fff;
            box-shadow: 0 4px 16px rgba(139, 0, 0, 0.12);
        }

        .profile-photo.pu-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pu-photo-fields {
            flex: 1;
            min-width: 200px;
        }

        .profile-photo-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .profile-photo-upload {
            flex: 1;
            min-width: 200px;
        }

        .profile-photo-upload label,
        .profile-photo-id label {
            font-weight: 600;
            color: #334155;
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.88rem;
        }

        .profile-photo-upload small {
            color: var(--pu-muted);
            font-size: 0.78rem;
        }

        .pu-file-wrap {
            margin-top: 0.15rem;
        }

        .pu-file-input {
            width: 100%;
            padding: 0.5rem;
            font-size: 0.85rem;
            border: 1px dashed #cbd5e1;
            border-radius: var(--pu-radius-sm);
            background: #fafafa;
            cursor: pointer;
        }

        .pu-file-input::file-selector-button {
            margin-right: 0.75rem;
            padding: 0.45rem 0.85rem;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--pu-maroon), var(--pu-rose));
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        .pu-field-tight.form-group {
            margin-bottom: 0;
        }

        /* —— Form fields —— */
        .form-group {
            margin-bottom: 0;
        }

        .pu-form .form-group:not(:last-child) {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.45rem;
            font-weight: 600;
            font-size: 0.88rem;
            color: #334155;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--pu-border);
            border-radius: var(--pu-radius-sm);
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fafbfc;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: rgba(139, 0, 0, 0.45);
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.08);
            background: #fff;
        }

        .form-group small {
            display: block;
            margin-top: 0.35rem;
            color: var(--pu-muted);
            font-size: 0.78rem;
        }

        .form-group input[readonly] {
            background: #f1f5f9;
            cursor: not-allowed;
            color: #64748b;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.5;
        }

        /* —— Buttons —— */
        .btn {
            background: linear-gradient(135deg, var(--pu-maroon) 0%, var(--pu-rose) 100%);
            color: white;
            border: none;
            padding: 0.7rem 1.35rem;
            border-radius: var(--pu-radius-sm);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(139, 0, 0, 0.25);
        }

        .btn-outline {
            background: var(--pu-surface);
            color: var(--pu-maroon);
            border: 1px solid rgba(139, 0, 0, 0.25);
        }

        .btn-outline:hover {
            background: #fff5f5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .pu-actions {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--pu-border-soft);
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: center;
        }

        .pu-actions--solo {
            margin-top: 1rem;
            padding-top: 0;
            border-top: none;
            justify-content: flex-start;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: center;
        }

        body.embed .back-btn {
            display: none;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Profile modals — DepEd maroon theme (matches system header) */
        .profile-alert-overlay,
        .profile-confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            z-index: 4000;
        }

        .profile-confirm-overlay {
            z-index: 4001;
        }

        .profile-alert-overlay.active,
        .profile-confirm-overlay.active {
            display: flex;
        }

        .profile-alert-modal,
        .profile-confirm-modal {
            background: #ffffff;
            border-radius: 16px;
            width: min(420px, 95vw);
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.25);
            border: 1px solid rgba(139, 0, 0, 0.12);
            overflow: hidden;
        }

        .profile-alert-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .profile-confirm-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #7a0000 0%, #b91c1c 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .profile-alert-header i,
        .profile-confirm-header i {
            font-size: 1.25rem;
            opacity: 0.95;
        }

        .profile-alert-body {
            padding: 1.25rem 1.25rem 0.75rem;
            color: #334155;
            font-size: 0.98rem;
            line-height: 1.55;
        }

        .profile-alert-body.theme-confirm-text p {
            margin: 0;
        }

        .profile-alert-actions {
            display: flex;
            justify-content: flex-end;
            padding: 0 1.25rem 1.25rem;
            gap: 0.65rem;
        }

        .profile-alert-actions--split {
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .profile-modal-btn-primary {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%) !important;
            color: #fff !important;
            border: none !important;
            padding: 0.55rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }

        .profile-modal-btn-primary:hover {
            filter: brightness(1.05);
        }

        .profile-modal-btn-outline {
            background: #ffffff !important;
            color: #8b0000 !important;
            border: 1px solid rgba(139, 0, 0, 0.35) !important;
            padding: 0.55rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }

        .profile-modal-btn-outline:hover {
            background: #fff5f5 !important;
        }

        .profile-modal-btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%) !important;
            color: #fff !important;
            border: none !important;
            padding: 0.55rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Error modal — keep clear red for mistakes */
        .profile-alert-header.error {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
        }

        .profile-alert-header.error + .profile-alert-body {
            color: #7f1d1d;
        }

        /* Change Password tab – OTP professional styling */
        #password-tab .cp-step-label {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #8b0000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.75rem;
        }
        #password-tab .cp-hint {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.55;
            margin-bottom: 1rem;
        }
        #password-tab .cp-hint strong { color: #334155; }
        #password-tab #cpAlert.alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 12px 16px;
            border-radius: 10px;
        }
        #password-tab #cpAlert.alert-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 12px 16px;
            border-radius: 10px;
        }
        #password-tab #profile_cp_otp {
            padding: 14px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1.2rem;
            letter-spacing: 0.4rem;
            text-align: center;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #password-tab #profile_cp_otp:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1);
        }

        #password-tab .form-group input[type="password"] {
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--pu-border);
            border-radius: var(--pu-radius-sm);
            font-size: 0.95rem;
            background: #fafbfc;
            width: 100%;
        }

        #password-tab .form-group input[type="password"]:focus {
            outline: none;
            border-color: rgba(139, 0, 0, 0.45);
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.08);
            background: #fff;
        }

        .pu-back-link {
            margin-top: 0.75rem;
        }

        .pu-back-link a {
            color: var(--pu-maroon);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
        }

        .pu-back-link a:hover {
            text-decoration: underline;
        }

        .alert-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        @media (max-width: 768px) {
            body.profile-page {
                padding: 12px;
            }

            .pu-container.container {
                margin: 0;
                border-radius: 12px;
            }

            body.embed .pu-container.container {
                border-radius: 0;
            }

            .pu-header {
                padding: 1.15rem 1.1rem;
            }

            .pu-header-aside {
                align-items: flex-start;
                width: 100%;
            }

            .pu-meta-chips {
                flex-direction: column;
                align-items: stretch;
            }

            .pu-chip {
                justify-content: flex-start;
            }

            .pu-tabs.tabs {
                flex-direction: column;
            }

            .pu-tab.tab {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body class="profile-page <?php echo $is_embed ? 'embed' : ''; ?>">
    <a href="<?php echo $dashboard_url; ?>" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <div class="container pu-container">
        <header class="pu-header">
            <div class="pu-header-top">
                <div class="pu-header-text">
                    <h1 class="pu-title">Update Profile</h1>
                    <p class="pu-lead">Keep your account details accurate and up to date</p>
                </div>
                <div class="pu-header-aside">
                    <span class="pu-role-pill">
                        <i class="fas fa-id-badge"></i>
                        <?php echo htmlspecialchars(ucfirst($user_data['role'] ?? 'User')); ?>
                    </span>
                    <span class="pu-brand">DepEd Provident Loan System</span>
                </div>
            </div>
            <div class="pu-meta-chips" aria-label="Account summary">
                <span class="pu-chip"><i class="fas fa-user"></i> <?php echo htmlspecialchars($user_data['username']); ?></span>
                <span class="pu-chip"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email']); ?></span>
                <span class="pu-chip"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($user_data['deped_id'] ?? 'Employee Deped No. not set'); ?></span>
                <span class="pu-chip"><i class="fas fa-calendar-check"></i> Last updated: <?php echo date('M d, Y'); ?></span>
            </div>
        </header>

        <div class="content pu-content">
            <?php if (!empty($error)): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        showProfileAlertModal(<?php echo json_encode($error); ?>, true);
                    });
                </script>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
            <?php endif; ?>

            <div class="pu-tabs tabs" role="tablist">
                <button type="button" class="pu-tab tab <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" onclick="showTab('profile', this)">
                    <i class="fas fa-user"></i> Update Profile
                </button>
                <button type="button" class="pu-tab tab <?php echo $active_tab === 'password' ? 'active' : ''; ?>" onclick="showTab('password', this)">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </div>

            <!-- Update Profile Tab -->
            <div id="profile-tab" class="tab-content pu-tab-panel <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                <form method="POST" enctype="multipart/form-data" class="pu-form" id="profileUpdateForm">
                    <input type="hidden" name="update_profile" value="1">

                    <section class="pu-section" aria-labelledby="pu-sec-photo">
                        <div class="pu-section-head">
                            <h2 id="pu-sec-photo" class="pu-section-title"><span class="pu-section-icon"><i class="fas fa-camera"></i></span> Photo &amp; employee number</h2>
                            <p class="pu-section-desc">Upload a clear photo and enter your 7-digit DepEd employee number.</p>
                        </div>
                        <div class="pu-section-card">
                            <div class="pu-photo-layout">
                                <div class="profile-photo pu-avatar" aria-hidden="true">
                                    <?php if ($profile_photo_exists): ?>
                                        <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($user_data['full_name'] ?: $user_data['username'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="pu-photo-fields">
                                    <div class="profile-photo-row">
                                        <div class="profile-photo-upload">
                                            <label for="profile_photo">Profile Photo</label>
                                            <div class="pu-file-wrap">
                                                <input type="file" id="profile_photo" name="profile_photo" accept="image/png,image/jpeg,image/webp" class="pu-file-input">
                                            </div>
                                            <small>JPG, PNG, or WebP up to 2MB.</small>
                                        </div>
                                        <div class="form-group profile-photo-id pu-field-tight">
                                            <label for="deped_id">Employee DepEd No.</label>
                                            <input type="text" id="deped_id" name="deped_id" value="<?php echo htmlspecialchars(preg_replace('/\D/', '', $user_data['deped_id'] ?? '')); ?>" placeholder="1234567" maxlength="7" inputmode="numeric">
                                            <small>7 digits only</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="pu-section" aria-labelledby="pu-sec-account">
                        <div class="pu-section-head">
                            <h2 id="pu-sec-account" class="pu-section-title"><span class="pu-section-icon"><i class="fas fa-user-shield"></i></span> Account &amp; email</h2>
                            <p class="pu-section-desc">Username and your registered email (email is verified by the system).</p>
                        </div>
                        <div class="pu-section-card">
                            <div class="pu-grid">
                                <div class="form-group">
                                    <label for="username">Username <span class="pu-req">*</span></label>
                                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" required autocomplete="username">
                                </div>
                                <div class="form-group">
                                    <label for="email">Email Address <span class="pu-req">*</span></label>
                                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="pu-section" aria-labelledby="pu-sec-contact">
                        <div class="pu-section-head">
                            <h2 id="pu-sec-contact" class="pu-section-title"><span class="pu-section-icon"><i class="fas fa-address-book"></i></span> Contact &amp; address</h2>
                            <p class="pu-section-desc">How we can reach you and where to send official correspondence.</p>
                        </div>
                        <div class="pu-section-card">
                            <div class="pu-grid">
                                <div class="form-group">
                                    <label for="contact_number">Contact Number <span class="pu-req">*</span></label>
                                    <input type="tel" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($user_data['contact_number'] ?? ''); ?>" required placeholder="09123456789" maxlength="11" inputmode="numeric" autocomplete="tel">
                                    <small>Format: 09XXXXXXXXX (11 digits)</small>
                                </div>
                                <div class="form-group pu-span-rows">
                                    <label for="home_address">Home Address <span class="pu-req">*</span></label>
                                    <textarea id="home_address" name="home_address" rows="4" required placeholder="House / street, barangay, city/municipality"><?php echo htmlspecialchars($user_data['home_address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="form-actions pu-actions">
                        <button type="submit" class="btn pu-btn-primary">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                        <button type="button" class="btn btn-outline pu-btn-ghost" onclick="closeParentModal()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Tab -->
            <div id="password-tab" class="tab-content pu-tab-panel <?php echo $active_tab === 'password' ? 'active' : ''; ?>">
                <section class="pu-section pu-section--password">
                    <div class="pu-section-head">
                        <h2 class="pu-section-title"><span class="pu-section-icon"><i class="fas fa-key"></i></span> Change password</h2>
                        <p class="pu-section-desc">Secure update via one-time code sent to your email.</p>
                    </div>
                    <div class="pu-section-card">
                        <div id="cpAlert" class="alert" style="display:none; margin-bottom: 1rem;"></div>
                        <div id="profileCpStep1">
                            <span class="cp-step-label">Step 1 of 3</span>
                            <p class="cp-hint">We'll send a 6-digit code to <strong><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></strong> to verify your identity.</p>
                            <button type="button" class="btn pu-btn-primary" id="profileCpSendOtp"><i class="fas fa-paper-plane"></i> Send OTP</button>
                        </div>
                        <div id="profileCpStep2" style="display:none;">
                            <span class="cp-step-label">Step 2 of 3</span>
                            <p class="cp-hint">Enter the 6-digit code sent to your email.</p>
                            <div class="form-group">
                                <label for="profile_cp_otp">OTP Code</label>
                                <input type="text" id="profile_cp_otp" maxlength="6" placeholder="000000" autocomplete="one-time-code">
                            </div>
                            <button type="button" class="btn pu-btn-primary" id="profileCpVerify"><i class="fas fa-check"></i> Verify &amp; continue</button>
                            <p class="pu-back-link"><a href="#" id="profileCpBack1">← Back</a></p>
                        </div>
                        <div id="profileCpStep3" style="display:none;">
                            <span class="cp-step-label">Step 3 of 3</span>
                            <p class="cp-hint">Enter your new password (min 8 characters, with uppercase, lowercase, and number).</p>
                            <form id="profilePasswordForm">
                                <div class="form-group">
                                    <label for="profile_new_password">New Password <span class="pu-req">*</span></label>
                                    <input type="password" id="profile_new_password" name="new_password" required minlength="8" autocomplete="new-password">
                                </div>
                                <div class="form-group">
                                    <label for="profile_confirm_password">Confirm New Password <span class="pu-req">*</span></label>
                                    <input type="password" id="profile_confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="btn pu-btn-primary" id="profileCpSubmit"><i class="fas fa-key"></i> Change Password</button>
                                </div>
                            </form>
                        </div>
                        <div class="form-actions pu-actions pu-actions--solo">
                            <button type="button" class="btn btn-outline pu-btn-ghost" onclick="closeParentModal()">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Step 1: Confirm before save (two-button) -->
    <div id="profileConfirmOverlay" class="profile-confirm-overlay" aria-hidden="true">
        <div class="profile-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="profileConfirmTitle">
            <div class="profile-confirm-header">
                <i class="fas fa-question-circle" aria-hidden="true"></i>
                <span id="profileConfirmTitle">Confirm update</span>
            </div>
            <div class="profile-alert-body theme-confirm-text">
                <p>Are you sure you want to save your profile changes? Please review your information before continuing.</p>
            </div>
            <div class="profile-alert-actions profile-alert-actions--split">
                <button type="button" class="profile-modal-btn-outline" id="profileConfirmCancel">Cancel</button>
                <button type="button" class="profile-modal-btn-primary" id="profileConfirmConfirm">Yes, save changes</button>
            </div>
        </div>
    </div>

    <!-- Step 2: Result (success or error) -->
    <div id="profileAlertModalOverlay" class="profile-alert-overlay" aria-hidden="true">
        <div class="profile-alert-modal" role="dialog" aria-modal="true" aria-labelledby="profileAlertTitle">
            <div class="profile-alert-header" id="profileAlertHeader">
                <i class="fas fa-check-circle" id="profileAlertIcon"></i>
                <span id="profileAlertTitle">Profile updated</span>
            </div>
            <div class="profile-alert-body" id="profileAlertMessage"></div>
            <div class="profile-alert-actions">
                <button type="button" class="profile-modal-btn-primary" id="profileAlertOk">OK</button>
            </div>
        </div>
    </div>

    <script>
        function showProfileAlertModal(message, isError = false) {
            var overlay = document.getElementById('profileAlertModalOverlay');
            if (!overlay) return;
            var messageEl = document.getElementById('profileAlertMessage');
            var headerEl = document.getElementById('profileAlertHeader');
            var iconEl = document.getElementById('profileAlertIcon');
            var titleEl = document.getElementById('profileAlertTitle');
            var btnEl = document.getElementById('profileAlertOk');

            if (messageEl) {
                messageEl.textContent = message || 'Profile updated successfully!';
            }

            if (isError) {
                headerEl.classList.add('error');
                iconEl.className = 'fas fa-exclamation-circle';
                titleEl.textContent = 'Error';
                btnEl.classList.remove('profile-modal-btn-primary');
                btnEl.classList.add('profile-modal-btn-danger');
            } else {
                headerEl.classList.remove('error');
                iconEl.className = 'fas fa-check-circle';
                titleEl.textContent = 'Profile updated';
                btnEl.classList.remove('profile-modal-btn-danger');
                btnEl.classList.add('profile-modal-btn-primary');
            }

            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');
        }

        function closeProfileConfirmModal() {
            var ov = document.getElementById('profileConfirmOverlay');
            if (ov) {
                ov.classList.remove('active');
                ov.setAttribute('aria-hidden', 'true');
            }
        }

        (function () {
            var form = document.getElementById('profileUpdateForm');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                var ov = document.getElementById('profileConfirmOverlay');
                if (ov) {
                    ov.classList.add('active');
                    ov.setAttribute('aria-hidden', 'false');
                }
            });
            var cancel = document.getElementById('profileConfirmCancel');
            var confirmBtn = document.getElementById('profileConfirmConfirm');
            var ov = document.getElementById('profileConfirmOverlay');
            if (cancel) cancel.addEventListener('click', closeProfileConfirmModal);
            if (ov) {
                ov.addEventListener('click', function (e) {
                    if (e.target === ov) closeProfileConfirmModal();
                });
            }
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    closeProfileConfirmModal();
                    form.submit();
                });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                var c = document.getElementById('profileConfirmOverlay');
                if (c && c.classList.contains('active')) closeProfileConfirmModal();
            });
        })();

        function showTab(tabName, button) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button (or matching button)
            const activeButton = button || document.querySelector('.tab[onclick*="' + tabName + '"]');
            if (activeButton) {
                activeButton.classList.add('active');
            }
        }

        // Auto-select tab when linked with ?tab=password
        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab');
        if (initialTab === 'password') {
            const passwordButton = document.querySelector('.tab[onclick*="password"]');
            showTab('password', passwordButton);
        }

        // Change Password OTP flow (profile page)
        var cpAlert = document.getElementById('cpAlert');
        function showCpAlert(msg, isError) {
            cpAlert.style.display = msg ? 'block' : 'none';
            cpAlert.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
            cpAlert.innerHTML = msg ? (isError ? '<i class="fas fa-exclamation-circle"></i> ' : '<i class="fas fa-check-circle"></i> ') + msg : '';
        }
        document.getElementById('profileCpSendOtp').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            showCpAlert('');
            fetch('change_password_send_otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send OTP';
                    if (data.success) {
                        document.getElementById('profileCpStep1').style.display = 'none';
                        document.getElementById('profileCpStep2').style.display = 'block';
                        document.getElementById('profile_cp_otp').value = '';
                        showCpAlert(data.message, false);
                    } else { showCpAlert(data.message || 'Failed to send OTP', true); }
                })
                .catch(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send OTP'; showCpAlert('Network error.', true); });
        });
        document.getElementById('profileCpVerify').addEventListener('click', function() {
            var otp = document.getElementById('profile_cp_otp').value.trim().replace(/\D/g, '');
            if (otp.length !== 6) { showCpAlert('Please enter the 6-digit code.', true); return; }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            var fd = new FormData();
            fd.append('otp', otp);
            fetch('change_password_verify_otp.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Verify & continue';
                    if (data.success) {
                        document.getElementById('profileCpStep2').style.display = 'none';
                        document.getElementById('profileCpStep3').style.display = 'block';
                        showCpAlert(data.message, false);
                    } else { showCpAlert(data.message || 'Verification failed', true); }
                })
                .catch(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Verify & continue'; showCpAlert('Network error.', true); });
        });
        document.getElementById('profileCpBack1').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('profileCpStep2').style.display = 'none';
            document.getElementById('profileCpStep1').style.display = 'block';
            showCpAlert('');
        });
        document.getElementById('profileCpSubmit').addEventListener('click', function() {
            var newP = document.getElementById('profile_new_password').value;
            var conf = document.getElementById('profile_confirm_password').value;
            if (!newP || !conf) { showCpAlert('Please fill in both password fields.', true); return; }
            if (newP !== conf) { showCpAlert('Passwords do not match.', true); return; }
            if (newP.length < 8) { showCpAlert('Password must be at least 8 characters.', true); return; }
            var fd = new FormData(document.getElementById('profilePasswordForm'));
            fd.append('new_password', newP);
            fd.append('confirm_password', conf);
            fetch('change_password_update.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showCpAlert(data.message, false);
                        document.getElementById('profilePasswordForm').reset();
                        setTimeout(closeParentModal, 2000);
                    } else { showCpAlert(data.message || 'Update failed', true); }
                })
                .catch(function() { showCpAlert('Network error.', true); });
        });

        function closeParentModal() {
            if (window.parent && typeof window.parent.closeProfileModal === 'function') {
                window.parent.closeProfileModal();
                return;
            }
            window.location.href = '<?php echo $dashboard_url; ?>';
        }

        document.getElementById('profile_confirm_password') && document.getElementById('profile_confirm_password').addEventListener('input', function() {
            var np = document.getElementById('profile_new_password').value;
            if (this.value && np !== this.value) this.setCustomValidity('Passwords do not match');
            else this.setCustomValidity('');
        });

        // Employee Deped No.: 7 digits only, no dash
        document.getElementById('deped_id').addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 7);
        });

        // Contact Number validation - restrict to 11 digits starting with 09
        document.getElementById('contact_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) {
                value = value.substring(0, 11);
            }
            e.target.value = value;
        });

        // If PHP set a success message, show it using the alert modal
        <?php if (!empty($success)): ?>
        (function () {
            showProfileAlertModal(<?php echo json_encode($success); ?>, false);
            var okBtn = document.getElementById('profileAlertOk');
            if (okBtn) {
                okBtn.addEventListener('click', function () {
                    var overlay = document.getElementById('profileAlertModalOverlay');
                    if (overlay) {
                        overlay.classList.remove('active');
                        overlay.setAttribute('aria-hidden', 'true');
                    }
                    // After acknowledging success, close the profile modal / go back
                    closeParentModal();
                });
            }
        })();
        <?php else: ?>
        (function () {
            var okBtn = document.getElementById('profileAlertOk');
            if (okBtn) {
                okBtn.addEventListener('click', function () {
                    var overlay = document.getElementById('profileAlertModalOverlay');
                    if (overlay) {
                        overlay.classList.remove('active');
                        overlay.setAttribute('aria-hidden', 'true');
                        var headerEl = document.getElementById('profileAlertHeader');
                        var iconEl = document.getElementById('profileAlertIcon');
                        var titleEl = document.getElementById('profileAlertTitle');
                        var btnEl = document.getElementById('profileAlertOk');
                        headerEl.classList.remove('error');
                        iconEl.className = 'fas fa-check-circle';
                        titleEl.textContent = 'Profile updated';
                        btnEl.classList.remove('profile-modal-btn-danger');
                        btnEl.classList.add('profile-modal-btn-primary');
                    }
                });
            }
        })();
        <?php endif; ?>
    </script>
</body>
</html>
