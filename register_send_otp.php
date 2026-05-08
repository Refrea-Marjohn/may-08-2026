<?php
/**
 * Step 1: Validate registration form, generate OTP, send to email, store in session.
 * Called via AJAX when user clicks "Send OTP" / "Register".
 */

header('Content-Type: application/json');

require_once 'config.php';
require_once 'config_email.php';
require_once 'mail_helper.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$username         = trim($_POST['username'] ?? '');
$email            = trim($_POST['email'] ?? '');
$password         = trim($_POST['password'] ?? '');
$confirm_password = trim($_POST['confirm_password'] ?? '');
$deped_id         = trim($_POST['deped_id'] ?? '');
$contact_number   = trim($_POST['contact_number'] ?? '');
$first_name       = trim($_POST['first_name'] ?? '');
$middle_name      = trim($_POST['middle_name'] ?? '');
$surname          = trim($_POST['surname'] ?? '');
$birth_date       = trim($_POST['birth_date'] ?? '');
$gender           = trim($_POST['gender'] ?? '');
$civil_status     = trim($_POST['civil_status'] ?? '');

// Structured home address fields
$house_unit        = trim($_POST['house_unit'] ?? '');
$street_name       = trim($_POST['street_name'] ?? '');
$barangay          = trim($_POST['barangay'] ?? '');
$city_municipality = trim($_POST['city_municipality'] ?? '');
$province          = trim($_POST['province'] ?? '');
$postal_code       = trim($_POST['postal_code'] ?? '');

// Combine into a single stored home address string
$home_address = trim(sprintf(
    '%s %s, %s, %s, %s %s',
    $house_unit,
    $street_name,
    $barangay,
    $city_municipality,
    $province,
    $postal_code
));

$full_name = trim($first_name . ' ' . $middle_name . ' ' . $surname);

// Validation
if (empty($username) || empty($email) || empty($password) || empty($confirm_password) ||
    empty($deped_id) || empty($contact_number) || empty($first_name) || empty($surname) ||
    empty($birth_date) || empty($gender) || empty($civil_status) ||
    empty($house_unit) || empty($street_name) || empty($barangay)) {
    $response['message'] = 'All required fields must be filled.';
    echo json_encode($response);
    exit;
}
if ($password !== $confirm_password) {
    $response['message'] = 'Passwords do not match.';
    echo json_encode($response);
    exit;
}
if (strlen($password) < 8) {
    $response['message'] = 'Password must be at least 8 characters long.';
    echo json_encode($response);
    exit;
}
if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}$/', $password)) {
    $response['message'] = 'Password must contain at least one uppercase, one lowercase, and one number.';
    echo json_encode($response);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Invalid email format.';
    echo json_encode($response);
    exit;
}
$deped_id = preg_replace('/\D/', '', $deped_id);
if (strlen($deped_id) !== 7) {
    $response['message'] = 'Employee Deped No. must be exactly 7 digits.';
    echo json_encode($response);
    exit;
}
if (!preg_match('/^09\d{9}$/', $contact_number)) {
    $response['message'] = 'Contact number must be 09XXXXXXXXX.';
    echo json_encode($response);
    exit;
}

// Check if email/username already exists
$check_sql = "SELECT id FROM users WHERE email = ? OR username = ?";
$stmt = $conn->prepare($check_sql);
$stmt->bind_param("ss", $email, $username);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result->num_rows > 0) {
    $response['message'] = 'Email or username already exists.';
    echo json_encode($response);
    exit;
}

// Gmail config check
if (empty(MAIL_SMTP_PASS) || strpos(MAIL_FROM_EMAIL, 'your-gmail') !== false) {
    $response['message'] = 'Email (Gmail) is not configured. Please set config_email.php with your Gmail and App Password.';
    echo json_encode($response);
    exit;
}

// Generate 6-digit OTP
$otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$otp_expires = time() + (10 * 60); // 10 minutes

$reg_data = [
    'username'       => $username,
    'email'          => $email,
    'password'       => $password,
    'deped_id'       => $deped_id,
    'contact_number' => $contact_number,
    'first_name'     => $first_name,
    'middle_name'    => $middle_name,
    'surname'        => $surname,
    'birth_date'     => $birth_date,
    'gender'         => $gender,
    'civil_status'   => $civil_status,
    'home_address'   => $home_address,
    // keep structured parts in case we need them later
    'house_unit'        => $house_unit,
    'street_name'       => $street_name,
    'barangay'          => $barangay,
    'city_municipality' => $city_municipality,
    'province'          => $province,
    'postal_code'       => $postal_code,
];

$otp_hash = password_hash($otp, PASSWORD_DEFAULT);

// Store in session for verification
$_SESSION['reg_otp_hash']   = $otp_hash;
$_SESSION['reg_otp_expires'] = $otp_expires;
$_SESSION['reg_data']       = $reg_data;

// Also save to DB so verify works even if session is lost (e.g. different tab/cookie)
$conn->query("DELETE FROM registration_pending WHERE email = '" . $conn->real_escape_string($email) . "'");
$stmt_pending = $conn->prepare("INSERT INTO registration_pending (email, otp_hash, expires_at, reg_data) VALUES (?, ?, ?, ?)");
$reg_data_json = json_encode($reg_data);
if ($stmt_pending) {
    $stmt_pending->bind_param("ssis", $email, $otp_hash, $otp_expires, $reg_data_json);
    $stmt_pending->execute();
    $stmt_pending->close();
}

try {
    sendOtpEmail($email, $otp);
    $response['success'] = true;
    $response['message'] = 'OTP sent to ' . $email . '. Check your inbox (and spam).';
} catch (Exception $e) {
    $response['message'] = 'Failed to send OTP: ' . $e->getMessage();
}

session_write_close();
echo json_encode($response);
