<?php
/**
 * Forgot password – Step 2: Verify OTP, then allow password change.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => 'Invalid request']);
}

$email = trim($_POST['email'] ?? '');
$otp_raw = trim($_POST['otp'] ?? '');
$otp = preg_replace('/\D/', '', $otp_raw);

if (strlen($otp) !== 6 || empty($email)) {
    $response['message'] = 'Please enter your email and the 6-digit code.';
    sendJson($response);
}

$stmt = $conn->prepare("SELECT email, otp_hash, expires_at FROM password_reset_pending WHERE email = ? AND expires_at > ?");
$now = time();
$stmt->bind_param("si", $email, $now);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if (!$result || !$row = $result->fetch_assoc()) {
    $response['message'] = 'Code expired or invalid. Please request a new code.';
    sendJson($response);
}

if (!password_verify($otp, $row['otp_hash'])) {
    $response['message'] = 'Invalid code. Please check and try again.';
    sendJson($response);
}

$_SESSION['fp_verified'] = true;
$_SESSION['fp_email'] = $email;
$response['success'] = true;
$response['message'] = 'Code verified. You can now set a new password.';

sendJson($response);
