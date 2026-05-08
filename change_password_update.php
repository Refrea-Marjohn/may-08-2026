<?php
/**
 * Change password (from navbar) – Step 3: Update password (after OTP verified).
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

function sendJson($data) {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    sendJson(['success' => false, 'message' => 'Session expired. Please log in again.']);
}

if (empty($_SESSION['cp_verified'])) {
    sendJson(['success' => false, 'message' => 'Please verify your email with the OTP first.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => 'Invalid request']);
}

$new_password = trim($_POST['new_password'] ?? '');
$confirm = trim($_POST['confirm_password'] ?? '');

if (strlen($new_password) < 8) {
    sendJson(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
}
if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}$/', $new_password)) {
    sendJson(['success' => false, 'message' => 'Password must contain at least one uppercase, one lowercase, and one number.']);
}
if ($new_password !== $confirm) {
    sendJson(['success' => false, 'message' => 'Passwords do not match.']);
}

$user_id = (int) $_SESSION['user_id'];
$email = $_SESSION['email'] ?? '';
$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hash, $user_id);
if ($stmt->execute()) {
    $stmt->close();
    $conn->query("DELETE FROM password_reset_pending WHERE email = '" . $conn->real_escape_string($email) . "'");
    unset($_SESSION['cp_verified']);
    $response['success'] = true;
    $response['message'] = 'Password updated successfully.';
    log_audit(
        $conn,
        'UPDATE',
        'Changed account password.',
        'Change Password',
        "User #{$user_id}"
    );
} else {
    $stmt->close();
    $response['message'] = 'Update failed. Please try again.';
}

sendJson($response);
