<?php
/**
 * Serve loan-related uploads (payslips, ID scans).
 * - Admin/Accountant: can view all documents.
 * - Borrower: can view own loan documents only.
 * GET: id = loan id, doc = whitelist key below.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$is_admin = ($user['role'] ?? '') === 'admin' || ($user['username'] ?? '') === 'admin';
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$user) {
    http_response_code(403);
    echo "Unauthorized";
    exit();
}

$loan_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($loan_id <= 0) {
    http_response_code(400);
    echo "Invalid loan ID";
    exit();
}

$doc = isset($_GET['doc']) ? (string) $_GET['doc'] : '';
$column_map = [
    'borrower_payslip' => 'payslip_filename',
    'co_maker_payslip' => 'co_maker_payslip_filename',
    'borrower_id_front' => 'borrower_id_front_filename',
    'borrower_id_back' => 'borrower_id_back_filename',
    'co_maker_id_front' => 'co_maker_id_front_filename',
    'co_maker_id_back' => 'co_maker_id_back_filename',
];

if (!isset($column_map[$doc])) {
    http_response_code(400);
    echo "Invalid document type";
    exit();
}

$column = $column_map[$doc];
$sql = "SELECT user_id, `{$column}` AS fname FROM loans WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$can_view_any = $is_admin || $is_accounting;
if (!$can_view_any) {
    $owner_id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
    if ($owner_id <= 0 || $owner_id !== $user_id) {
        http_response_code(403);
        echo "Unauthorized";
        exit();
    }
}

if (!$row || empty($row['fname'])) {
    http_response_code(404);
    echo "File not found";
    exit();
}

$fname = $row['fname'];
/* Payslips live under private/payslips; ID scans under private/ids (see apply_loan.php / existing_loans.php). */
$id_doc_keys = ['borrower_id_front', 'borrower_id_back', 'co_maker_id_front', 'co_maker_id_back'];
$is_id = in_array($doc, $id_doc_keys, true);
$base_dir = $is_id
    ? (defined('ID_UPLOAD_DIR') ? ID_UPLOAD_DIR : (__DIR__ . '/storage_private/ids'))
    : (defined('PAYSLIP_UPLOAD_DIR') ? PAYSLIP_UPLOAD_DIR : (__DIR__ . '/storage_private/payslips'));
$file_path = rtrim($base_dir, "/\\") . '/' . $fname;

if (!is_file($file_path) && $is_id) {
    $legacy = __DIR__ . '/../../private/payslips/' . $fname;
    if (is_file($legacy)) {
        $file_path = $legacy;
    }
}

if (!is_file($file_path)) {
    $legacy_base = $is_id
        ? (__DIR__ . '/../../private/ids/' . $fname)
        : (__DIR__ . '/../../private/payslips/' . $fname);
    if (is_file($legacy_base)) {
        $file_path = $legacy_base;
    }
}

if (!is_file($file_path)) {
    http_response_code(404);
    echo "File not found";
    exit();
}

$mime = @mime_content_type($file_path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($row['fname']) . '"');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit();
