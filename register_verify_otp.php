<?php
/**
 * Step 2: Verify OTP from session and create user account.
 * Called via AJAX when user submits OTP.
 */
ob_start();

header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

function sendJson($response) {
    ob_end_clean();
    echo json_encode($response);
    exit;
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => 'Invalid request']);
}

$otp_raw = trim($_POST['otp'] ?? '');
$otp = preg_replace('/\D/', '', $otp_raw);
$email_verify = trim($_POST['email'] ?? '');

if (strlen($otp) !== 6) {
    $response['message'] = 'Please enter the 6-digit OTP code.';
    sendJson($response);
}

$data = null;
$from_session = false;

// Try session first
if (isset($_SESSION['reg_otp_hash'], $_SESSION['reg_otp_expires'], $_SESSION['reg_data'])) {
    if (time() <= $_SESSION['reg_otp_expires']) {
        if (password_verify($otp, $_SESSION['reg_otp_hash'])) {
            $data = $_SESSION['reg_data'];
            $from_session = true;
        }
    } else {
        unset($_SESSION['reg_otp_hash'], $_SESSION['reg_otp_expires'], $_SESSION['reg_data']);
    }
}

// Fallback: load from registration_pending by email (works when session is lost)
if ($data === null && $email_verify !== '') {
    $stmt_p = $conn->prepare("SELECT email, otp_hash, expires_at, reg_data FROM registration_pending WHERE email = ? AND expires_at > ?");
    $now = time();
    if ($stmt_p) {
        $stmt_p->bind_param("si", $email_verify, $now);
        $stmt_p->execute();
        $res_p = $stmt_p->get_result();
        if ($res_p && $row = $res_p->fetch_assoc()) {
            if (password_verify($otp, $row['otp_hash'])) {
                $data = json_decode($row['reg_data'], true);
            }
        }
        $stmt_p->close();
    }
}

if ($data === null) {
    if (!isset($_SESSION['reg_otp_hash']) && $email_verify === '') {
        $response['message'] = 'Session expired. Please fill the form and request OTP again.';
    } elseif (isset($_SESSION['reg_otp_hash']) && time() > $_SESSION['reg_otp_expires']) {
        $response['message'] = 'OTP has expired. Please request a new one.';
    } else {
        $response['message'] = 'Invalid OTP. Please check the code and try again.';
    }
    sendJson($response);
}
$full_name = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['surname']);
$hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);

// Check again if email/username was taken meanwhile
$check_sql = "SELECT id FROM users WHERE email = ? OR username = ?";
$stmt = $conn->prepare($check_sql);
$stmt->bind_param("ss", $data['email'], $data['username']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $stmt->close();
    unset($_SESSION['reg_otp_hash'], $_SESSION['reg_otp_expires'], $_SESSION['reg_data']);
    $response['message'] = 'Email or username already exists. Please login or use another email.';
    sendJson($response);
}
$stmt->close();

// Insert user: try with first_name, middle_name, surname first; fallback to basic columns
$insert_sql = "INSERT INTO users (username, email, password, full_name, deped_id, contact_number, first_name, middle_name, surname, birth_date, gender, civil_status, home_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($insert_sql);
$ok = false;
$new_user_id = null;
$err = '';
if ($stmt) {
    $stmt->bind_param("sssssssssssss",
        $data['username'],
        $data['email'],
        $hashed_password,
        $full_name,
        $data['deped_id'],
        $data['contact_number'],
        $data['first_name'],
        $data['middle_name'],
        $data['surname'],
        $data['birth_date'],
        $data['gender'],
        $data['civil_status'],
        $data['home_address']
    );
    $ok = $stmt->execute();
    if ($ok) {
        $new_user_id = $conn->insert_id;
    }
    $err = $stmt->error ?: '';
    $stmt->close();
} else {
    $err = $conn->error ?: '';
}

if (!$ok && strpos($err, 'Unknown column') !== false) {
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, deped_id, contact_number, birth_date, gender, civil_status, home_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssssssssss",
            $data['username'],
            $data['email'],
            $hashed_password,
            $full_name,
            $data['deped_id'],
            $data['contact_number'],
            $data['birth_date'],
            $data['gender'],
            $data['civil_status'],
            $data['home_address']
        );
        $ok = $stmt->execute();
        if ($ok) {
            $new_user_id = $conn->insert_id;
        }
        $stmt->close();
    }
}

if ($ok) {
    unset($_SESSION['reg_otp_hash'], $_SESSION['reg_otp_expires'], $_SESSION['reg_data']);
    $conn->query("DELETE FROM registration_pending WHERE email = '" . $conn->real_escape_string($data['email']) . "'");
    $response['success'] = true;
    $response['message'] = 'Account created successfully. You can now login.';
    $response['redirect'] = 'login.php';
    $target_label = $new_user_id ? "User #{$new_user_id} - {$full_name}" : $full_name;
    log_audit(
        $conn,
        'CREATE',
        "New borrower account registered for {$full_name}.",
        'Register',
        $target_label,
        $new_user_id,
        $full_name,
        'borrower'
    );
} else {
    $response['message'] = 'Registration failed. Please try again.' . ($err ? ' (' . $err . ')' : '');
}
sendJson($response);
