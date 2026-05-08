<?php
/**
 * Forgot password – Step 1: Send OTP to user's email.
 * Checks if email exists, generates OTP, sends email, stores in password_reset_pending.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => 'Invalid request']);
}

$email = trim($_POST['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Please enter a valid email address.';
    sendJson($response);
}

// Check if email exists in users
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result->num_rows === 0) {
    $response['message'] = 'No account found with this email address.';
    sendJson($response);
}

if (empty(MAIL_SMTP_PASS) || strpos(MAIL_FROM_EMAIL, 'your-gmail') !== false) {
    $response['message'] = 'Email is not configured. Please contact the administrator.';
    sendJson($response);
}

$otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_hash = password_hash($otp, PASSWORD_DEFAULT);
$expires_at = time() + (10 * 60);

// Delete old pending resets for this email
$conn->query("DELETE FROM password_reset_pending WHERE email = '" . $conn->real_escape_string($email) . "'");

$stmt = $conn->prepare("INSERT INTO password_reset_pending (email, otp_hash, expires_at) VALUES (?, ?, ?)");
if (!$stmt) {
    $response['message'] = 'Unable to process request. Please try again.';
    sendJson($response);
}
$stmt->bind_param("ssi", $email, $otp_hash, $expires_at);
$stmt->execute();
$stmt->close();

try {
    sendPasswordResetOtpEmail($email, $otp);
    $response['success'] = true;
    $response['message'] = 'A 6-digit code was sent to ' . $email . '. Check your inbox (and spam).';
} catch (Exception $e) {
    $response['message'] = 'Failed to send email: ' . $e->getMessage();
}

sendJson($response);
