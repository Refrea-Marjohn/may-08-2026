<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not signed in.']);
    exit;
}

$staff_session_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $staff_session_id);
$stmt->execute();
$staff_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staff_user) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid session.']);
    exit;
}

$is_admin = ($staff_user['role'] ?? '') === 'admin' || ($staff_user['username'] ?? '') === 'admin';
$is_accounting = function_exists('user_is_accountant_role') && user_is_accountant_role($staff_user['role'] ?? null);
if (!$is_admin && !$is_accounting) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid loan id.']);
    exit;
}

$est = $conn->prepare(
    "SELECT l.*, u.full_name AS borrower_name, u.email AS borrower_email,
            COALESCE((SELECT SUM(d.amount) FROM deductions d WHERE d.loan_id = l.id), 0) AS total_deducted
     FROM loans l
     JOIN users u ON u.id = l.user_id
     WHERE l.id = ? AND l.status IN ('approved','completed') AND l.is_existing_loan = 1"
);
$est->bind_param('i', $id);
$est->execute();
$row = $est->get_result()->fetch_assoc();
$est->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Loan not found or not editable here.']);
    exit;
}

$co_full = $row['co_maker_full_name'] ?? '';
$parts = explode(', ', $co_full, 2);
$co_last = $parts[0] ?? '';
$rest = $parts[1] ?? '';
$name_parts = explode(' ', trim($rest), 2);
$co_first = $name_parts[0] ?? '';
$co_middle = $name_parts[1] ?? '';

$row['co_maker_last_name'] = $co_last;
$row['co_maker_first_name'] = $co_first;
$row['co_maker_middle_name'] = $co_middle;

echo json_encode(['ok' => true, 'loan' => $row], JSON_UNESCAPED_UNICODE);
