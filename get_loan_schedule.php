<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$is_admin = ($user['role'] ?? '') === 'admin' || ($user['username'] ?? '') === 'admin';
$is_accountant = user_is_accountant_role($user['role'] ?? null);
if (!$user || (!$is_admin && !$is_accountant)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$loan_id = isset($_GET['loan_id']) ? (int) $_GET['loan_id'] : 0;
if ($loan_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid loan ID']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT l.id, l.user_id, l.loan_amount, l.loan_term, l.monthly_payment, l.total_amount,
            l.application_date, l.released_at, l.reviewed_at,
            u.full_name
     FROM loans l
     JOIN users u ON l.user_id = u.id
     WHERE l.id = ? AND l.status = 'approved'"
);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Loan not found']);
    exit;
}

$start_date = !empty($loan['released_at']) ? $loan['released_at'] : $loan['application_date'];
$start_ts = strtotime($start_date);
$loan_term = (int) $loan['loan_term'];
$monthly_payment = (float) ($loan['monthly_payment'] ?? 0);

$stmt = $conn->prepare(
    "SELECT skip_ym, notes FROM loan_skip_months WHERE loan_id = ? ORDER BY skip_ym ASC"
);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
$skipped_ym_list = [];
$skipped_months_display = [];
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
while ($row = $result->fetch_assoc()) {
    $skipped_ym_list[] = $row['skip_ym'];
    $parts = explode('-', $row['skip_ym']);
    $m = (int) ($parts[1] ?? 0);
    $y = (int) ($parts[0] ?? 0);
    $skipped_months_display[] = [
        'ym' => $row['skip_ym'],
        'label' => ($m >= 1 && $m <= 12 ? $months[$m - 1] . ' ' . $y : $row['skip_ym']),
        'notes' => $row['notes'] ?? '',
    ];
}
$stmt->close();

$has_receipt_col = false;
$col_chk = $conn->query("SHOW COLUMNS FROM deductions LIKE 'receipt_filename'");
if ($col_chk && $col_chk->num_rows > 0) {
    $has_receipt_col = true;
}
$select_cols = $has_receipt_col ? "deduction_date, amount, receipt_filename" : "deduction_date, amount";
$stmt = $conn->prepare("SELECT {$select_cols} FROM deductions WHERE loan_id = ? ORDER BY deduction_date ASC");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
$deductions = [];
while ($row = $result->fetch_assoc()) {
    $receipt = null;
    if ($has_receipt_col && isset($row['receipt_filename']) && $row['receipt_filename'] !== '' && $row['receipt_filename'] !== null) {
        $receipt = $row['receipt_filename'];
    }
    $deductions[] = [
        'date' => $row['deduction_date'],
        'amount' => (float) $row['amount'],
        'ym' => date('Y-m', strtotime($row['deduction_date'])),
        'receipt_filename' => $receipt,
    ];
}
$stmt->close();

// Build schedule with skip logic: payment months shift when a month is skipped (e.g. skip Feb → timeline becomes Mar–Dec)
$schedule = [];
$period = 0;
$calendar_month = 0;
while ($period < $loan_term) {
    $period_ts = strtotime("+{$calendar_month} months", $start_ts);
    $ym = date('Y-m', $period_ts);
    if (in_array($ym, $skipped_ym_list, true)) {
        $calendar_month++;
        continue;
    }
    $period++;
    $month_num = (int) date('n', $period_ts);
    $year = date('Y', $period_ts);
    $month_label = $months[$month_num - 1] . ' ' . $year;
    $amount_paid = 0;
    foreach ($deductions as $d) {
        if ($d['ym'] === $ym) {
            $amount_paid += $d['amount'];
        }
    }
    $status = $amount_paid >= ($monthly_payment - 0.01) ? 'paid' : 'unpaid';
    $schedule[] = [
        'period' => $period,
        'month_label' => $month_label,
        'ym' => $ym,
        'amount_due' => $monthly_payment,
        'amount_paid' => round($amount_paid, 2),
        'status' => $status,
    ];
    $calendar_month++;
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'loan' => [
        'id' => (int) $loan['id'],
        'full_name' => $loan['full_name'],
        'loan_amount' => (float) $loan['loan_amount'],
        'loan_term' => $loan_term,
        'monthly_payment' => $monthly_payment,
        'total_amount' => (float) ($loan['total_amount'] ?? $loan['loan_amount']),
        'start_date' => $start_date,
    ],
    'skipped_months' => $skipped_months_display,
    'schedule' => $schedule,
    'deductions' => $deductions,
]);
