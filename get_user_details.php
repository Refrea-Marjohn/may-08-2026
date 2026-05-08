<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$requester_id = $_SESSION['user_id'];
$requester_sql = $conn->prepare("SELECT role, username FROM users WHERE id = ?");
$requester_sql->bind_param("i", $requester_id);
$requester_sql->execute();
$requester = $requester_sql->get_result()->fetch_assoc();
$requester_sql->close();

$is_admin = (bool) ($requester && ((($requester['role'] ?? '') === 'admin') || (($requester['username'] ?? '') === 'admin')));
$is_accountant = (bool) ($requester && user_is_accountant_role($requester['role'] ?? null));

if (!($is_admin || $is_accountant)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$target_id = (int) ($_GET['id'] ?? 0);
if ($target_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']);
    exit;
}

$stmt = $conn->prepare("SELECT id, full_name, username, email, contact_number, role, deped_id, home_address, profile_photo, created_at, COALESCE(NULLIF(user_status,''), 'active') AS user_status FROM users WHERE id = ?");
$stmt->bind_param("i", $target_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Get loan stats for borrowers
$loan_stats = ['total_loans' => 0, 'loan_status' => 'No Loan', 'school_assignment' => null];
if (($u['role'] ?? '') !== 'admin' && !user_is_accountant_role($u['role'] ?? null)) {
    $loan_sql = $conn->prepare("SELECT
        (SELECT school_assignment FROM loans WHERE user_id = ? ORDER BY application_date DESC LIMIT 1) AS school,
        (SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'pending') AS pending_count,
        (SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'approved' AND released_at IS NULL) AS approved_not_released,
        (SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'completed') AS completed_count,
        (SELECT COUNT(*)
            FROM loans l
            LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM deductions GROUP BY loan_id) d ON d.loan_id = l.id
            WHERE l.user_id = ?
              AND l.status = 'approved'
              AND l.released_at IS NOT NULL
              AND COALESCE(d.paid, 0) < COALESCE(l.total_amount, l.loan_amount)
        ) AS ongoing_count,
        (SELECT COUNT(*)
            FROM loans l
            LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM deductions GROUP BY loan_id) d ON d.loan_id = l.id
            WHERE l.user_id = ?
              AND l.status = 'approved'
              AND l.released_at IS NOT NULL
              AND COALESCE(d.paid, 0) >= COALESCE(l.total_amount, l.loan_amount)
        ) AS fully_paid_count");
    $loan_sql->bind_param("iiiiii", $target_id, $target_id, $target_id, $target_id, $target_id, $target_id);
    $loan_sql->execute();
    $loan_row = $loan_sql->get_result()->fetch_assoc();
    $loan_sql->close();
    $pending = (int) ($loan_row['pending_count'] ?? 0);
    $approved_not_released = (int) ($loan_row['approved_not_released'] ?? 0);
    $completed = (int) ($loan_row['completed_count'] ?? 0);
    $ongoing = (int) ($loan_row['ongoing_count'] ?? 0);
    $fully_paid = (int) ($loan_row['fully_paid_count'] ?? 0);

    // Total Loans should include released loans + fully paid (completed), excluding pending/for-release/rejected
    $total = (int) ($ongoing + $fully_paid + $completed);
    $loan_stats['total_loans'] = $total;
    $loan_stats['school_assignment'] = $loan_row['school'] ?? null;

    if ($total === 0) $loan_stats['loan_status'] = 'No Loan';
    elseif ($pending > 0) $loan_stats['loan_status'] = 'Pending Application';
    elseif ($ongoing > 0) $loan_stats['loan_status'] = 'Active Loan';
    elseif ($approved_not_released > 0) $loan_stats['loan_status'] = 'Approved (For Release)';
    elseif (($fully_paid + $completed) > 0) $loan_stats['loan_status'] = 'Fully Paid';
    else $loan_stats['loan_status'] = 'No Loan';
}

echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int) $u['id'],
        'full_name' => $u['full_name'],
        'username' => $u['username'],
        'email' => $u['email'],
        'contact_number' => $u['contact_number'] ?? '',
        'role' => $u['role'] ?? 'borrower',
        'deped_id' => $u['deped_id'] ?? '',
        'home_address' => $u['home_address'] ?? '',
        'profile_photo' => $u['profile_photo'] ?? '',
        'user_status' => $u['user_status'] ?? 'active',
        'created_at' => $u['created_at'],
        'total_loans' => $loan_stats['total_loans'],
        'loan_status' => $loan_stats['loan_status'],
        'school_assignment' => $loan_stats['school_assignment'],
    ]
]);
