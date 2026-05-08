<?php
/**
 * Change password (from navbar) – Step 1: Send OTP to logged-in user's email.
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';
require_once 'config_email.php';
require_once 'mail_helper.php';

function sendJson($data) {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'], $_SESSION['email'])) {
    sendJson(['success' => false, 'message' => 'Session expired. Please log in again.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => 'Invalid request']);
}

$email = trim($_SESSION['email']);
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJson(['success' => false, 'message' => 'Invalid email in session.']);
}

if (empty(MAIL_SMTP_PASS) || strpos(MAIL_FROM_EMAIL, 'your-gmail') !== false) {
    sendJson(['success' => false, 'message' => 'Email is not configured.']);
}

$otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_hash = password_hash($otp, PASSWORD_DEFAULT);
$expires_at = time() + (10 * 60);

$conn->query("DELETE FROM password_reset_pending WHERE email = '" . $conn->real_escape_string($email) . "'");
$stmt = $conn->prepare("INSERT INTO password_reset_pending (email, otp_hash, expires_at) VALUES (?, ?, ?)");
if (!$stmt) {
    sendJson(['success' => false, 'message' => 'Unable to process. Try again.']);
}
$stmt->bind_param("ssi", $email, $otp_hash, $expires_at);
$stmt->execute();
$stmt->close();

try {
    sendPasswordResetOtpEmail($email, $otp);
    $response['success'] = true;
    $response['message'] = 'A 6-digit code was sent to your email. Check your inbox (and spam).';
} catch (Exception $e) {
    $response['message'] = 'Failed to send email: ' . $e->getMessage();
}

sendJson($response);
