<?php
/**
 * Forgot password – Step 3: Set new password (after OTP verified).
 */

require_once 'config.php';

if (empty($_SESSION['fp_verified']) || empty($_SESSION['fp_email'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if (strlen($password) < 8) {
        header('Location: login.php?forgot_err=1');
        exit;
    }
    if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}$/', $password)) {
        header('Location: login.php?forgot_err=1');
        exit;
    }
    if ($password !== $confirm) {
        header('Location: login.php?forgot_err=1');
        exit;
    }

    $email = $_SESSION['fp_email'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $user_id = null;
    $user_name = null;
    $user_role = null;
    $info_stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE email = ?");
    if ($info_stmt) {
        $info_stmt->bind_param("s", $email);
        $info_stmt->execute();
        $info = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();
        if ($info) {
            $user_id = $info['id'];
            $user_name = $info['full_name'];
            $user_role = $info['role'];
        }
    }
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $hash, $email);
    if ($stmt->execute()) {
        $stmt->close();
        $conn->query("DELETE FROM password_reset_pending WHERE email = '" . $conn->real_escape_string($email) . "'");
        unset($_SESSION['fp_verified'], $_SESSION['fp_email']);
        log_audit(
            $conn,
            'UPDATE',
            'Reset account password via forgot password.',
            'Forgot Password',
            $user_id ? "User #{$user_id}" : $email,
            $user_id,
            $user_name ?: $email,
            $user_role ?: 'borrower'
        );
        header('Location: login.php?reset=1');
        exit;
    }
    $stmt->close();
}

header('Location: login.php?forgot_err=1');
exit;
