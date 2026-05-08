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

$method = $_SERVER['REQUEST_METHOD'];
$loan_id = isset($_POST['loan_id']) ? (int) $_POST['loan_id'] : (isset($_GET['loan_id']) ? (int) $_GET['loan_id'] : 0);
$skip_ym = isset($_POST['skip_ym']) ? trim($_POST['skip_ym']) : (isset($_GET['skip_ym']) ? trim($_GET['skip_ym']) : '');

if ($method === 'POST') {
    if ($loan_id <= 0 || $skip_ym === '') {
        echo json_encode(['success' => false, 'error' => 'loan_id and skip_ym (YYYY-MM) required']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $skip_ym)) {
        echo json_encode(['success' => false, 'error' => 'skip_ym must be YYYY-MM']);
        exit;
    }
    $stmt = $conn->prepare("SELECT id FROM loans WHERE id = ? AND status = 'approved'");
    $stmt->bind_param("i", $loan_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Loan not found or not approved']);
        exit;
    }
    $stmt->close();
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : 'Skip approved (DepEd)';
    $ins = $conn->prepare("INSERT IGNORE INTO loan_skip_months (loan_id, skip_ym, notes, created_by) VALUES (?, ?, ?, ?)");
    $ins->bind_param("issi", $loan_id, $skip_ym, $notes, $user_id);
    if ($ins->execute() && $conn->affected_rows > 0) {
        if (function_exists('log_audit')) {
            log_audit($conn, 'UPDATE', "Skip month $skip_ym for Loan #$loan_id (approved)", 'All Loans', "Loan #$loan_id", $user_id, $user['full_name'] ?? '', $user['role'] ?? '');
        }
        echo json_encode(['success' => true, 'message' => 'Month marked as skipped (timeline will shift).']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Already skipped or saved.']);
    }
    $ins->close();
    exit;
}

if ($method === 'DELETE' || (isset($_POST['action']) && $_POST['action'] === 'remove')) {
    $skip_ym = isset($_POST['skip_ym']) ? trim($_POST['skip_ym']) : $skip_ym;
    if ($loan_id <= 0 || $skip_ym === '') {
        echo json_encode(['success' => false, 'error' => 'loan_id and skip_ym required']);
        exit;
    }
    $del = $conn->prepare("DELETE FROM loan_skip_months WHERE loan_id = ? AND skip_ym = ?");
    $del->bind_param("is", $loan_id, $skip_ym);
    $del->execute();
    echo json_encode(['success' => true, 'message' => 'Skip removed.']);
    $del->close();
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
