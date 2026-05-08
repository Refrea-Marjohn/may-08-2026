<?php
/**
 * AJAX: Check if email or username already exists (for Manage Users – Create Accountant).
 * Admin only. Returns JSON { "emailExists": bool, "usernameExists": bool }.
 */
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$sql = "SELECT role FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_admin = ($user['role'] ?? '') === 'admin';
if (!$is_admin) {
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$username = trim($_GET['username'] ?? $_POST['username'] ?? '');

$email_exists = false;
$username_exists = false;

if ($email !== '') {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $email_exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

if ($username !== '') {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $username_exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

echo json_encode([
    'emailExists' => $email_exists,
    'usernameExists' => $username_exists
]);
