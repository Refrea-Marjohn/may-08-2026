<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$user || (!$is_admin && !$is_accounting)) {
    http_response_code(403);
    echo "Unauthorized";
    exit();
}

$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($loan_id <= 0) {
    http_response_code(400);
    echo "Invalid loan ID";
    exit();
}

$type = isset($_GET['type']) && $_GET['type'] === 'co_maker' ? 'co_maker' : 'borrower';
$column = $type === 'co_maker' ? 'co_maker_payslip_filename' : 'payslip_filename';

$stmt = $conn->prepare("SELECT $column AS payslip_filename FROM loans WHERE id = ?");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
$loan = $result->fetch_assoc();
$stmt->close();

if (!$loan || empty($loan['payslip_filename'])) {
    http_response_code(404);
    echo "Payslip not found";
    exit();
}

$primary_dir = defined('PAYSLIP_UPLOAD_DIR') ? PAYSLIP_UPLOAD_DIR : (__DIR__ . '/storage_private/payslips');
$file_path = rtrim($primary_dir, "/\\") . '/' . $loan['payslip_filename'];
if (!is_file($file_path)) {
    // Legacy fallback for previously uploaded files.
    $legacy_path = __DIR__ . '/../../private/payslips/' . $loan['payslip_filename'];
    if (is_file($legacy_path)) {
        $file_path = $legacy_path;
    }
}
if (!is_file($file_path)) {
    http_response_code(404);
    echo "Payslip not found";
    exit();
}

$mime = mime_content_type($file_path);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($loan['payslip_filename']) . '"');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit();
