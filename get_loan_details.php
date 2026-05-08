<?php
require_once 'config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Check if user is admin
$is_admin = ($user['role'] ?? '') === 'admin' || $user['username'] === 'admin';
$is_accounting = user_is_accountant_role($user['role'] ?? null);
if (!$is_admin && !$is_accounting) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get loan ID from request
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid loan ID']);
    exit();
}

// Fetch loan details with user information
$stmt = $conn->prepare("SELECT l.*, u.full_name, u.email FROM loans l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();

if ($loan = $result->fetch_assoc()) {
    // Ensure payslip fields are always present for the details modal
    $loan['payslip_filename'] = isset($loan['payslip_filename']) ? trim((string) $loan['payslip_filename']) : '';
    $loan['co_maker_payslip_filename'] = isset($loan['co_maker_payslip_filename']) ? trim((string) $loan['co_maker_payslip_filename']) : '';
    // Ensure ID fields exist even if empty
    $loan['borrower_id_front_filename'] = isset($loan['borrower_id_front_filename']) ? trim((string) $loan['borrower_id_front_filename']) : '';
    $loan['borrower_id_back_filename'] = isset($loan['borrower_id_back_filename']) ? trim((string) $loan['borrower_id_back_filename']) : '';
    $loan['co_maker_id_front_filename'] = isset($loan['co_maker_id_front_filename']) ? trim((string) $loan['co_maker_id_front_filename']) : '';
    $loan['co_maker_id_back_filename'] = isset($loan['co_maker_id_back_filename']) ? trim((string) $loan['co_maker_id_back_filename']) : '';
    echo json_encode(['success' => true, 'loan' => $loan]);
} else {
    echo json_encode(['success' => false, 'message' => 'Loan not found']);
}

$stmt->close();
$conn->close();
?>
