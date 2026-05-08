<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role, username, full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$is_admin = ($user['role'] ?? '') === 'admin' || ($user['username'] ?? '') === 'admin';
$is_accountant = user_is_accountant_role($user['role'] ?? null);
if (!$user || (!$is_admin && !$is_accountant)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$loan_id = isset($_POST['loan_id']) ? (int) $_POST['loan_id'] : 0;
$amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
$payment_date = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';

if ($loan_id <= 0 || $amount === '' || $payment_date === '') {
    echo json_encode(['success' => false, 'error' => 'loan_id, amount, and payment_date are required']);
    exit;
}

$amount_float = (float) $amount;
if ($amount_float <= 0) {
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
    echo json_encode(['success' => false, 'error' => 'payment_date must be YYYY-MM-DD']);
    exit;
}

$stmt = $conn->prepare("SELECT id, user_id, monthly_payment FROM loans WHERE id = ? AND status = 'approved'");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    echo json_encode(['success' => false, 'error' => 'Loan not found or not approved']);
    exit;
}

$borrower_id = (int) $loan['user_id'];
$monthly_payment = (float) $loan['monthly_payment'];

// Payment month from payment_date (YYYY-MM-DD)
$payment_ym = substr($payment_date, 0, 7);

// Cap: amount must not exceed remaining due for that month
$sum_stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM deductions
    WHERE loan_id = ? AND DATE_FORMAT(deduction_date, '%Y-%m') = ?
");
$sum_stmt->bind_param("is", $loan_id, $payment_ym);
$sum_stmt->execute();
$sum_row = $sum_stmt->get_result()->fetch_assoc();
$sum_stmt->close();
$total_paid_this_month = (float) ($sum_row['total_paid'] ?? 0);
$remaining = round(max(0, $monthly_payment - $total_paid_this_month), 2);
if ($amount_float > $remaining + 0.01) {
    echo json_encode(['success' => false, 'error' => 'Amount exceeds remaining due for this month (₱' . number_format($remaining, 2) . '). No overpayment allowed.']);
    exit;
}

// Ensure receipt_filename column exists (migration)
$col_check = $conn->query("SHOW COLUMNS FROM deductions LIKE 'receipt_filename'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE deductions ADD COLUMN receipt_filename VARCHAR(255) NULL DEFAULT NULL");
}

// Receipt handling (optional)
$receipt_filename = null;
if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['receipt'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }
    if (!$mime && function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }
    if (!$mime || !in_array($mime, $allowed_types)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Use image (JPEG/PNG/GIF/WebP) or PDF.']);
        exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB
        echo json_encode(['success' => false, 'error' => 'Receipt file must be 5MB or less.']);
        exit;
    }
    $uploads_dir = defined('RECEIPT_UPLOAD_DIR') ? RECEIPT_UPLOAD_DIR : (__DIR__ . '/storage_private/receipts');
    if (!is_dir($uploads_dir)) {
        if (!@mkdir($uploads_dir, 0755, true) && !is_dir($uploads_dir)) {
            echo json_encode(['success' => false, 'error' => 'Server storage is not ready for receipts.']);
            exit;
        }
    }
    if (!is_writable($uploads_dir)) {
        echo json_encode(['success' => false, 'error' => 'Server storage is not writable for receipts.']);
        exit;
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: (strpos($mime, 'pdf') !== false ? 'pdf' : 'jpg');
    $safe_ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    $receipt_filename = 'loan_' . $loan_id . '_' . str_replace('-', '', $payment_date) . '_' . time() . '.' . $safe_ext;
    $dest = $uploads_dir . '/' . $receipt_filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save receipt file.']);
        exit;
    }
}

$ins = $conn->prepare("INSERT INTO deductions (loan_id, borrower_id, deduction_date, amount, posted_by, receipt_filename) VALUES (?, ?, ?, ?, ?, ?)");
$ins->bind_param("iisdis", $loan_id, $borrower_id, $payment_date, $amount_float, $user_id, $receipt_filename);
if ($ins->execute()) {
    if (function_exists('log_audit')) {
        log_audit($conn, 'CREATE', "Recorded payment of ₱" . number_format($amount_float, 2) . " for Loan #$loan_id (Date: $payment_date)", 'All Loans', "Loan #$loan_id", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
    }
    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully.']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to record payment: ' . $conn->error]);
}
$ins->close();
