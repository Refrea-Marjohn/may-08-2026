<?php
require_once 'config.php';
require_once 'includes/office_school_options.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$staff_session_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $staff_session_id);
$stmt->execute();
$staff_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$profile_photo = $staff_user['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

$is_admin = ($staff_user['role'] ?? '') === 'admin' || ($staff_user['username'] ?? '') === 'admin';
$is_accounting = user_is_accountant_role($staff_user['role'] ?? null);
if (!$is_admin && !$is_accounting) {
    header('Location: borrower_dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
    header('Location: existing_loans.php?open_edit=' . (int) $_GET['edit']);
    exit();
}

$dashboard_url = $is_accounting ? 'accountant_dashboard.php' : 'admin_dashboard.php';
$dashboard_label = $is_accounting ? 'Accountant Dashboard' : 'Admin Dashboard';
$role_label = $is_accounting ? 'Accountant' : 'Administrator';
$access_label = $is_accounting ? 'Accountant Access' : 'Admin Access';

$pending_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM loans WHERE status = ?');
$st_pending = 'pending';
$pending_stmt->bind_param('s', $st_pending);
$pending_stmt->execute();
$pending_loans_count = (int) ($pending_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$pending_stmt->close();

function el_compute_totals(float $loan_amount, int $loan_term): array
{
    $annualInterestRate = 0.06;
    $monthlyInterestRate = $annualInterestRate / 12;
    if ($loan_term < 1) {
        $loan_term = 12;
    }
    $monthlyPayment = $loan_amount * ($monthlyInterestRate * pow(1 + $monthlyInterestRate, $loan_term))
        / (pow(1 + $monthlyInterestRate, $loan_term) - 1);
    $totalAmount = $monthlyPayment * $loan_term;
    $totalInterest = $totalAmount - $loan_amount;
    return [$monthlyPayment, $totalAmount, $totalInterest];
}

function el_generate_temp_password(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function el_generate_unique_username(mysqli $conn, string $email, string $full_name): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/', '', strstr($email, '@', true) ?: ''));
    if ($base === '') {
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', str_replace(' ', '', $full_name)));
    }
    if ($base === '') {
        $base = 'borrower';
    }
    $base = substr($base, 0, 18);
    $candidate = $base;
    $tries = 0;
    while (true) {
        $check = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->bind_param('s', $candidate);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$exists) {
            return $candidate;
        }
        $tries++;
        $candidate = substr($base, 0, 14) . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if ($tries > 20) {
            $candidate = 'borrower' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        }
    }
}

/**
 * @return array{error:string, payslip_filename:string, co_maker_payslip_filename:string, borrower_id_front_filename:string, borrower_id_back_filename:string, co_maker_id_front_filename:string, co_maker_id_back_filename:string}
 */
function el_process_uploads(int $borrower_user_id, ?array $existing, bool $require_all): array
{
    $out = [
        'error' => '',
        'payslip_filename' => $existing['payslip_filename'] ?? '',
        'co_maker_payslip_filename' => $existing['co_maker_payslip_filename'] ?? '',
        'borrower_id_front_filename' => $existing['borrower_id_front_filename'] ?? '',
        'borrower_id_back_filename' => $existing['borrower_id_back_filename'] ?? '',
        'co_maker_id_front_filename' => $existing['co_maker_id_front_filename'] ?? '',
        'co_maker_id_back_filename' => $existing['co_maker_id_back_filename'] ?? '',
    ];

    $has_borrower_payslip = !empty($_FILES['payslip_file']['name']) && (int) $_FILES['payslip_file']['error'] === UPLOAD_ERR_OK;
    $has_co_maker_payslip = !empty($_FILES['co_maker_payslip_file']['name']) && (int) $_FILES['co_maker_payslip_file']['error'] === UPLOAD_ERR_OK;
    $has_borrower_id_front = !empty($_FILES['borrower_id_front']['name']) && (int) $_FILES['borrower_id_front']['error'] === UPLOAD_ERR_OK;
    $has_borrower_id_back = !empty($_FILES['borrower_id_back']['name']) && (int) $_FILES['borrower_id_back']['error'] === UPLOAD_ERR_OK;
    $has_co_maker_id_front = !empty($_FILES['co_maker_id_front']['name']) && (int) $_FILES['co_maker_id_front']['error'] === UPLOAD_ERR_OK;
    $has_co_maker_id_back = !empty($_FILES['co_maker_id_back']['name']) && (int) $_FILES['co_maker_id_back']['error'] === UPLOAD_ERR_OK;

    if ($require_all) {
        if (!$has_borrower_payslip && empty($out['payslip_filename'])) {
            $out['error'] = 'Borrower payslip is required.';
            return $out;
        }
        if (!$has_co_maker_payslip && empty($out['co_maker_payslip_filename'])) {
            $out['error'] = 'Co-maker payslip is required.';
            return $out;
        }
        if (!$has_borrower_id_front && empty($out['borrower_id_front_filename'])) {
            $out['error'] = 'Borrower ID (front) is required.';
            return $out;
        }
        if (!$has_borrower_id_back && empty($out['borrower_id_back_filename'])) {
            $out['error'] = 'Borrower ID (back) is required.';
            return $out;
        }
        if (!$has_co_maker_id_front && empty($out['co_maker_id_front_filename'])) {
            $out['error'] = 'Co-maker ID (front) is required.';
            return $out;
        }
        if (!$has_co_maker_id_back && empty($out['co_maker_id_back_filename'])) {
            $out['error'] = 'Co-maker ID (back) is required.';
            return $out;
        }
    }

    $upload_dir = defined('PAYSLIP_UPLOAD_DIR') ? PAYSLIP_UPLOAD_DIR : (__DIR__ . '/storage_private/payslips');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $id_upload_dir = defined('ID_UPLOAD_DIR') ? ID_UPLOAD_DIR : (__DIR__ . '/storage_private/ids');
    if (!is_dir($id_upload_dir)) {
        mkdir($id_upload_dir, 0755, true);
    }

    $allowed_mimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
    ];

    $validate_upload = function ($file, $label) use ($allowed_mimes, $upload_dir, $borrower_user_id) {
        if ($file['size'] > 5 * 1024 * 1024) {
            return [$label . ' payslip must be 5MB or less', null];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowed_mimes[$mime])) {
            return [$label . ' payslip must be PDF, JPG, or PNG', null];
        }
        $filename = $label . '_payslip_' . $borrower_user_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$mime];
        $target_path = $upload_dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            return ['Failed to upload ' . strtolower($label) . ' payslip', null];
        }
        return [null, $filename];
    };

    $validate_id_upload = function ($file, $label, $side) use ($allowed_mimes, $id_upload_dir, $borrower_user_id) {
        if ($file['size'] > 5 * 1024 * 1024) {
            return [$label . ' ' . $side . ' ID must be 5MB or less', null];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowed_mimes[$mime])) {
            return [$label . ' ' . $side . ' ID must be PDF, JPG, or PNG', null];
        }
        $filename = $label . '_id_' . $side . '_' . $borrower_user_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$mime];
        $target_path = $id_upload_dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            return ['Failed to upload ' . strtolower($label) . ' ' . $side . ' ID', null];
        }
        return [null, $filename];
    };

    if ($has_borrower_payslip) {
        [$e, $fn] = $validate_upload($_FILES['payslip_file'], 'borrower');
        if ($e) {
            $out['error'] = $e;
            return $out;
        }
        $out['payslip_filename'] = $fn;
    }
    if ($has_co_maker_payslip) {
        [$e, $fn] = $validate_upload($_FILES['co_maker_payslip_file'], 'co_maker');
        if ($e) {
            $out['error'] = $e;
            return $out;
        }
        $out['co_maker_payslip_filename'] = $fn;
    }
    if ($has_borrower_id_front) {
        [$e, $fn] = $validate_id_upload($_FILES['borrower_id_front'], 'borrower', 'front');
        if ($e) {
            $out['error'] = $e;
            return $out;
        }
        $out['borrower_id_front_filename'] = $fn;
    }
    if ($has_borrower_id_back) {
        [$e, $fn] = $validate_id_upload($_FILES['borrower_id_back'], 'borrower', 'back');
        if ($e) {
            $out['error'] = $e;
            return $out;
        }
        $out['borrower_id_back_filename'] = $fn;
    }
    if ($has_co_maker_id_front) {
        [$e, $fn] = $validate_id_upload($_FILES['co_maker_id_front'], 'co_maker', 'front');
        if ($e) {
            $out['error'] = $e;
            return $out;
        }
        $out['co_maker_id_front_filename'] = $fn;
    }
    if ($has_co_maker_id_back) {
        [$e, $fn] = $validate_id_upload($_FILES['co_maker_id_back'], 'co_maker', 'back');
        if ($e) {
            $out['error'] = $e;
            return $out;
        }
        $out['co_maker_id_back_filename'] = $fn;
    }

    return $out;
}

$borrowers_list = [];
$bq = $conn->query("SELECT id, full_name, email FROM users WHERE role = 'borrower' ORDER BY full_name ASC");
if ($bq) {
    while ($row = $bq->fetch_assoc()) {
        $borrowers_list[] = $row;
    }
}

$co_maker_last_name = '';
$co_maker_first_name = '';
$co_maker_middle_name = '';
$open_modal_loan_id = isset($_GET['open_edit']) ? (int) $_GET['open_edit'] : 0;
$modal_reopen_on_load = null;

$error = '';
$success = '';
$existing_row = null;
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['existing_loan_action'])) {
    $action = $_POST['existing_loan_action'];
    $loan_amount = (float) ($_POST['loan_amount'] ?? 0);
    if ($loan_amount > 100000) {
        $loan_amount = 100000.0;
    }
    $loan_purpose = trim((string) ($_POST['loan_purpose'] ?? ''));
    $loan_term = max(1, (int) ($_POST['loan_term'] ?? 12));
    $net_pay = (float) ($_POST['net_pay'] ?? 0);
    $school_assignment = trim((string) ($_POST['school_assignment'] ?? ''));
    $position = trim((string) ($_POST['position'] ?? ''));
    $salary_grade = trim((string) ($_POST['salary_grade'] ?? ''));
    $employment_status = trim((string) ($_POST['employment_status'] ?? ''));
    $borrower_date_of_birth = trim((string) ($_POST['borrower_date_of_birth'] ?? ''));
    $borrower_years_of_service = (int) ($_POST['borrower_years_of_service'] ?? 0);
    $co_maker_last_name = trim((string) ($_POST['co_maker_last_name'] ?? ''));
    $co_maker_first_name = trim((string) ($_POST['co_maker_first_name'] ?? ''));
    $co_maker_middle_name = trim((string) ($_POST['co_maker_middle_name'] ?? ''));
    $co_maker_full_name = trim($co_maker_last_name . ', ' . $co_maker_first_name . ' ' . $co_maker_middle_name);
    $co_maker_email = trim((string) ($_POST['co_maker_email'] ?? ''));
    $co_maker_position = trim((string) ($_POST['co_maker_position'] ?? ''));
    $co_maker_school_assignment = trim((string) ($_POST['co_maker_school_assignment'] ?? ''));
    $co_maker_net_pay = (float) ($_POST['co_maker_net_pay'] ?? 0);
    $co_maker_employment_status = trim((string) ($_POST['co_maker_employment_status'] ?? ''));
    $co_maker_date_of_birth = trim((string) ($_POST['co_maker_date_of_birth'] ?? ''));
    $co_maker_years_of_service = (int) ($_POST['co_maker_years_of_service'] ?? 0);
    $loan_status = trim((string) ($_POST['loan_status'] ?? 'approved'));
    if (!in_array($loan_status, ['approved', 'completed'], true)) {
        $loan_status = 'approved';
    }
    $application_date_raw = trim((string) ($_POST['application_date'] ?? ''));
    $released_at_raw = trim((string) ($_POST['released_at'] ?? ''));
    $already_paid_amount = max(0, (float) ($_POST['already_paid_amount'] ?? 0));

    if ($loan_amount < 1000 || $loan_purpose === '' || $school_assignment === '' || $position === '') {
        $error = 'Please fill required loan and borrower fields.';
    } elseif ($co_maker_full_name === '' || $co_maker_email === '') {
        $error = 'Co-maker name and email are required.';
    }

    if ($error === '' && $application_date_raw === '') {
        $error = 'Application / start date is required.';
    }

    $borrower_user_id = 0;
    if ($error === '') {
        if ($action === 'create') {
            $create_new_borrower = isset($_POST['create_new_borrower']) && $_POST['create_new_borrower'] === '1';
            if ($create_new_borrower) {
                $borrower_deped_id_clean = preg_replace('/\D/', '', (string) ($_POST['borrower_deped_id'] ?? ''));
                $new_borrower_first = trim((string) ($_POST['new_borrower_first_name'] ?? ''));
                $new_borrower_middle = trim((string) ($_POST['new_borrower_middle_name'] ?? ''));
                $new_borrower_last = trim((string) ($_POST['new_borrower_last_name'] ?? ''));
                $new_borrower_tail = trim($new_borrower_first . ($new_borrower_middle !== '' ? ' ' . $new_borrower_middle : ''));
                $new_borrower_full_name = ($new_borrower_last !== '' && $new_borrower_tail !== '')
                    ? ($new_borrower_last . ', ' . $new_borrower_tail)
                    : trim($new_borrower_last . ' ' . $new_borrower_tail);
                $new_borrower_email = trim((string) ($_POST['new_borrower_email'] ?? ''));
                $new_borrower_contact = preg_replace('/\D/', '', (string) ($_POST['new_borrower_contact'] ?? ''));
                $new_borrower_birth_date = trim((string) ($_POST['new_borrower_birth_date'] ?? ''));
                if ($new_borrower_first === '' || $new_borrower_last === '' || $new_borrower_email === '') {
                    $error = 'Borrower first name, last name, and email are required when creating an account.';
                } elseif (!filter_var($new_borrower_email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Borrower email format is invalid.';
                } elseif ($new_borrower_contact !== '' && !preg_match('/^09\d{9}$/', $new_borrower_contact)) {
                    $error = 'Borrower contact must be exactly 11 digits: 09 followed by 9 digits (09XXXXXXXXX).';
                } elseif ($borrower_deped_id_clean !== '' && strlen($borrower_deped_id_clean) !== 7) {
                    $error = 'Employee DepEd No. must be exactly 7 digits when provided.';
                } else {
                    if ($new_borrower_birth_date !== '') {
                        $bd_check = DateTime::createFromFormat('Y-m-d', $new_borrower_birth_date);
                        if (!$bd_check || $bd_check->format('Y-m-d') !== $new_borrower_birth_date) {
                            $error = 'Borrower birth date is invalid.';
                        }
                    }
                    if ($error === '') {
                    $check_email = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                    $check_email->bind_param('s', $new_borrower_email);
                    $check_email->execute();
                    $email_exists = $check_email->get_result()->num_rows > 0;
                    $check_email->close();
                    if ($email_exists) {
                        $error = 'Borrower email already exists. Select that account instead.';
                    } else {
                        $generated_username = el_generate_unique_username($conn, $new_borrower_email, $new_borrower_full_name);
                        $generated_password = el_generate_temp_password(10);
                        $hashed_password = password_hash($generated_password, PASSWORD_DEFAULT);
                        $role_borrower = 'borrower';
                        $new_user_deped = $borrower_deped_id_clean === '' ? '' : $borrower_deped_id_clean;
                        $new_birth_sql = $new_borrower_birth_date === '' ? null : $new_borrower_birth_date;
                        $ins_user = $conn->prepare('INSERT INTO users (username, email, password, full_name, role, contact_number, deped_id, first_name, middle_name, surname, birth_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins_user->bind_param('sssssssssss', $generated_username, $new_borrower_email, $hashed_password, $new_borrower_full_name, $role_borrower, $new_borrower_contact, $new_user_deped, $new_borrower_first, $new_borrower_middle, $new_borrower_last, $new_birth_sql);
                        if (!$ins_user->execute()) {
                            $error = 'Failed to create borrower account.';
                        } else {
                            $borrower_user_id = (int) $conn->insert_id;
                            $email_notice = '';
                            if (file_exists(__DIR__ . '/config_email.php') && file_exists(__DIR__ . '/mail_helper.php')) {
                                require_once __DIR__ . '/config_email.php';
                                require_once __DIR__ . '/mail_helper.php';
                                try {
                                    sendBorrowerCredentialsEmail($new_borrower_email, $new_borrower_full_name, $generated_username, $generated_password);
                                } catch (Exception $e) {
                                    $email_notice = ' Borrower account was created, but credentials email could not be sent (' . $e->getMessage() . ').';
                                }
                            } else {
                                $email_notice = ' Borrower account was created, but email setup files are missing.';
                            }
                            $success = 'Borrower account created (' . $generated_username . ') and linked to this loan.' . $email_notice;
                            log_audit($conn, 'CREATE', "Created borrower account {$generated_username} for existing loan entry.", 'Existing loans', "User #{$borrower_user_id}");
                        }
                        $ins_user->close();
                    }
                    }
                }
            } else {
                $borrower_user_id = (int) ($_POST['borrower_user_id'] ?? 0);
                if ($borrower_user_id <= 0) {
                    $error = 'Select a borrower or create a new borrower account.';
                } else {
                    $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'borrower'");
                    $chk->bind_param('i', $borrower_user_id);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) {
                        $error = 'Invalid borrower account.';
                    }
                    $chk->close();
                }
            }
        } else {
            $update_loan_id = (int) ($_POST['loan_id'] ?? 0);
            if ($update_loan_id <= 0) {
                $error = 'Invalid loan.';
            } else {
                $chk = $conn->prepare("SELECT l.*, COALESCE((SELECT SUM(d.amount) FROM deductions d WHERE d.loan_id = l.id), 0) AS total_deducted
                                       FROM loans l WHERE l.id = ? AND l.status IN ('approved','completed') AND l.is_existing_loan = 1");
                $chk->bind_param('i', $update_loan_id);
                $chk->execute();
                $existing_row = $chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$existing_row) {
                    $error = 'Loan not found or cannot be edited here.';
                } else {
                    $borrower_user_id = (int) $existing_row['user_id'];
                }
            }
        }
    }

    if ($error === '') {
        [$monthly_payment, $total_amount, $total_interest] = el_compute_totals($loan_amount, $loan_term);
        if ($already_paid_amount > $total_amount) {
            $error = 'Already paid amount cannot be greater than total amount payable.';
        }
    }

    if ($error === '') {
        $application_date_db = $application_date_raw . ' 00:00:00';
        $released_at_bind = ($released_at_raw !== '') ? $released_at_raw : null;

        if ($action === 'create') {
            $borrower_id_front_filename = '';
            $borrower_id_back_filename = '';
            $co_maker_id_front_filename = '';
            $co_maker_id_back_filename = '';
            $payslip_filename = '';
            $co_maker_payslip_filename = '';

            $ins = $conn->prepare('INSERT INTO loans (
                    user_id, loan_amount, loan_purpose, loan_term, net_pay, school_assignment, position, salary_grade, employment_status,
                    borrower_date_of_birth, borrower_years_of_service, borrower_id_front_filename, borrower_id_back_filename,
                    co_maker_full_name, co_maker_email, co_maker_position, co_maker_school_assignment, co_maker_net_pay, co_maker_employment_status,
                    co_maker_date_of_birth, co_maker_years_of_service, co_maker_id_front_filename, co_maker_id_back_filename,
                    payslip_filename, co_maker_payslip_filename, monthly_payment, total_amount, total_interest,
                    application_date, status, released_at, reviewed_by_id, reviewed_by_role, reviewed_by_name, reviewed_at, is_existing_loan
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),1)');
            $rb_id = $staff_session_id;
            $rb_role = $staff_user['role'] ?? 'admin';
            $rb_name = $staff_user['full_name'] ?? 'Staff';
            $st_app = $loan_status;
            $bdob_null = $borrower_date_of_birth !== '' ? $borrower_date_of_birth : null;
            $cmdob_null = $co_maker_date_of_birth !== '' ? $co_maker_date_of_birth : null;

            $ins_bind_types = 'idsid' . str_repeat('s', 4) . 'siss' . str_repeat('s', 4) . 'd' . 'ssiss' . 'ss' . 'ddd' . 'sss' . 'iss';
            $ins->bind_param(
                $ins_bind_types,
                $borrower_user_id,
                $loan_amount,
                $loan_purpose,
                $loan_term,
                $net_pay,
                $school_assignment,
                $position,
                $salary_grade,
                $employment_status,
                $bdob_null,
                $borrower_years_of_service,
                $borrower_id_front_filename,
                $borrower_id_back_filename,
                $co_maker_full_name,
                $co_maker_email,
                $co_maker_position,
                $co_maker_school_assignment,
                $co_maker_net_pay,
                $co_maker_employment_status,
                $cmdob_null,
                $co_maker_years_of_service,
                $co_maker_id_front_filename,
                $co_maker_id_back_filename,
                $payslip_filename,
                $co_maker_payslip_filename,
                $monthly_payment,
                $total_amount,
                $total_interest,
                $application_date_db,
                $st_app,
                $released_at_bind,
                $rb_id,
                $rb_role,
                $rb_name
            );
            $bind_ok = $ins->execute();
            if (!$bind_ok) {
                $error = 'Could not save loan: ' . htmlspecialchars($ins->error);
                $ins->close();
            } else {
                $new_id = (int) $conn->insert_id;
                if ($already_paid_amount > 0) {
                    $deduction_date = $application_date_raw;
                    $ins_ded = $conn->prepare("INSERT INTO deductions (loan_id, borrower_id, deduction_date, amount, posted_by) VALUES (?, ?, ?, ?, ?)");
                    $ins_ded->bind_param('iisdi', $new_id, $borrower_user_id, $deduction_date, $already_paid_amount, $staff_session_id);
                    $ins_ded->execute();
                    $ins_ded->close();
                }
                log_audit($conn, 'INSERT', "Added existing/on-file loan #{$new_id} for borrower user #{$borrower_user_id}.", 'Existing loans', "Loan #{$new_id}");
                $ins->close();
                    $_SESSION['existing_loans_success'] = ($success !== '' ? $success . ' ' : '') . 'Loan record saved successfully.';
                header('Location: existing_loans.php?success=1');
                exit();
            }
        } elseif ($action === 'update' && isset($existing_row)) {
            $current_paid_total = (float) ($existing_row['total_deducted'] ?? 0);
            if ($already_paid_amount < $current_paid_total) {
                $error = 'Already paid cannot be lower than current recorded deductions (₱' . number_format($current_paid_total, 2) . ').';
            }
            if ($error === '') {
                $borrower_id_front_filename = (string)($existing_row['borrower_id_front_filename'] ?? '');
                $borrower_id_back_filename = (string)($existing_row['borrower_id_back_filename'] ?? '');
                $co_maker_id_front_filename = (string)($existing_row['co_maker_id_front_filename'] ?? '');
                $co_maker_id_back_filename = (string)($existing_row['co_maker_id_back_filename'] ?? '');
                $payslip_filename = (string)($existing_row['payslip_filename'] ?? '');
                $co_maker_payslip_filename = (string)($existing_row['co_maker_payslip_filename'] ?? '');
                $upd = $conn->prepare('UPDATE loans SET
                    loan_amount = ?, loan_purpose = ?, loan_term = ?, net_pay = ?, school_assignment = ?, position = ?, salary_grade = ?, employment_status = ?,
                    borrower_date_of_birth = ?, borrower_years_of_service = ?, borrower_id_front_filename = ?, borrower_id_back_filename = ?,
                    co_maker_full_name = ?, co_maker_email = ?, co_maker_position = ?, co_maker_school_assignment = ?, co_maker_net_pay = ?, co_maker_employment_status = ?,
                    co_maker_date_of_birth = ?, co_maker_years_of_service = ?, co_maker_id_front_filename = ?, co_maker_id_back_filename = ?,
                    payslip_filename = ?, co_maker_payslip_filename = ?, monthly_payment = ?, total_amount = ?, total_interest = ?,
                    application_date = ?, status = ?, released_at = ?, is_existing_loan = 1
                    WHERE id = ? AND status IN (\'approved\',\'completed\') AND is_existing_loan = 1');
                $lid = (int) $existing_row['id'];
                $bdob_null = $borrower_date_of_birth !== '' ? $borrower_date_of_birth : null;
                $cmdob_null = $co_maker_date_of_birth !== '' ? $co_maker_date_of_birth : null;
                $upd_bind_types = 'dsid' . 'ssss' . 'siss' . str_repeat('s', 4) . 'd' . 'ssiss' . 'ss' . 'ddd' . 'sss' . 'i';
                $upd->bind_param(
                    $upd_bind_types,
                    $loan_amount,
                    $loan_purpose,
                    $loan_term,
                    $net_pay,
                    $school_assignment,
                    $position,
                    $salary_grade,
                    $employment_status,
                    $bdob_null,
                    $borrower_years_of_service,
                    $borrower_id_front_filename,
                    $borrower_id_back_filename,
                    $co_maker_full_name,
                    $co_maker_email,
                    $co_maker_position,
                    $co_maker_school_assignment,
                    $co_maker_net_pay,
                    $co_maker_employment_status,
                    $cmdob_null,
                    $co_maker_years_of_service,
                    $co_maker_id_front_filename,
                    $co_maker_id_back_filename,
                    $payslip_filename,
                    $co_maker_payslip_filename,
                    $monthly_payment,
                    $total_amount,
                    $total_interest,
                    $application_date_db,
                    $loan_status,
                    $released_at_bind,
                    $lid
                );
                if (!$upd->execute()) {
                    $error = 'Could not update loan: ' . htmlspecialchars($upd->error);
                    $upd->close();
                } else {
                    $delta_paid = round($already_paid_amount - $current_paid_total, 2);
                    if ($delta_paid > 0) {
                        $deduction_date = $application_date_raw;
                        $ins_ded = $conn->prepare("INSERT INTO deductions (loan_id, borrower_id, deduction_date, amount, posted_by) VALUES (?, ?, ?, ?, ?)");
                        $ins_ded->bind_param('iisdi', $lid, $borrower_user_id, $deduction_date, $delta_paid, $staff_session_id);
                        $ins_ded->execute();
                        $ins_ded->close();
                    }
                    log_audit($conn, 'UPDATE', "Updated existing/on-file loan #{$lid}.", 'Existing loans', "Loan #{$lid}");
                    $upd->close();
                    $_SESSION['existing_loans_success'] = 'Loan record saved successfully.';
                    header('Location: existing_loans.php?success=1');
                    exit();
                }
            }
        }
    }

    if ($error !== '' && $action === 'update') {
        $modal_reopen_on_load = ['error' => $error, 'post' => $_POST];
    }
}

if (!empty($_SESSION['existing_loans_success'])) {
    $success = (string) $_SESSION['existing_loans_success'];
    unset($_SESSION['existing_loans_success']);
} elseif (isset($_GET['success'])) {
    $success = 'Loan record saved successfully.';
}

$table_loans = [];
$tq = $conn->prepare(
    "SELECT l.id, l.application_date, l.loan_amount, l.status, l.released_at, u.full_name, u.email
     FROM loans l JOIN users u ON l.user_id = u.id
     WHERE l.status IN ('approved','completed') AND l.is_existing_loan = 1
     ORDER BY l.application_date DESC
     LIMIT 200"
);
$tq->execute();
$tr = $tq->get_result();
while ($row = $tr->fetch_assoc()) {
    $table_loans[] = $row;
}
$tq->close();

$creating_borrower_checked = isset($_POST['create_new_borrower']) && $_POST['create_new_borrower'] === '1';

$lf = function ($key, $default = '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['existing_loan_action'] ?? '') === 'create' && array_key_exists($key, $_POST)) {
        $v = $_POST[$key];
        return is_array($v) ? $default : $v;
    }
    return $default;
};

$el_modal_reopen_json = 'null';
if (!empty($modal_reopen_on_load)) {
    $tmp = json_encode($modal_reopen_on_load, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $el_modal_reopen_json = $tmp !== false ? $tmp : 'null';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Existing Loans - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; color: #333; }
        .navbar {
            background: white; padding: 1rem 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed; top: 0; left: 192px; right: 0; z-index: 1000;
        }
        .welcome-message { font-size: 1.2rem; color: #333; }
        .welcome-message strong { color: #8b0000; }
        .nav-icons { display: flex; align-items: center; gap: 1.5rem; position: relative; }
        .container { display: flex; margin-top: 70px; min-height: calc(100vh - 70px); }
        .sidebar {
            width: 240px; background: rgba(179, 0, 0, 0.9); backdrop-filter: blur(10px);
            box-shadow: 2px 0 10px rgba(0,0,0,0.1); padding-top: 0.25rem; position: fixed;
            top: 0; left: 0; height: 100vh; overflow: hidden; z-index: 999;
            display: flex; flex-direction: column;
        }
        .sidebar-header { padding: 1.5rem 1.25rem 1rem; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .sidebar-logo { width: 64px; height: 64px; margin: 0 auto 0.75rem; display: flex; align-items: center; justify-content: center; }
        .sidebar-logo img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar-title { color: rgba(255,255,255,0.85); font-size: 0.85rem; letter-spacing: 0.02em; }
        .sidebar-menu { list-style: none; flex: 1; padding: 0.5rem 0.5rem 1rem; overflow-y: auto; }
        .sidebar-item { margin-bottom: 0.1rem; }
        .sidebar-link {
            display: flex; align-items: center; padding: 0.65rem 1rem; margin: 0.2rem 0.5rem;
            color: rgba(255,255,255,0.92); text-decoration: none; transition: all 0.3s; border-radius: 12px;
            gap: 0.85rem; font-size: 0.95rem; font-weight: 500;
        }
        .sidebar-link:hover { background: rgba(255,255,255,0.14); color: #fff; }
        .sidebar-link.active { background: rgba(255,255,255,0.22); color: #fff; font-weight: 600; }
        .sidebar-icon { margin-right: 0; font-size: 1.1rem; width: 26px; text-align: center; }
        .main-content { flex: 1; padding: 2.25rem; margin-left: 192px; margin-top: 20px; }
        .content-section {
            background: #fff; padding: 2.25rem; border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.08); margin-bottom: 2rem;
        }
        .section-title { font-size: 2.05rem; color: #1f2937; margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.7rem; }
        .section-subtitle { color: #6b7280; margin-bottom: 1.6rem; font-size: 1.05rem; line-height: 1.6; }
        .alert { padding: 0.9rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-weight: 500; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .el-form-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.95rem 1rem;
            align-items: start;
        }
        .el-form-grid .form-group { min-width: 0; }
        .el-form-grid textarea { min-height: 100px; resize: vertical; }
        .form-group label { display: block; font-weight: 700; font-size: 0.94rem; color: #2f3a4a; margin-bottom: 0.42rem; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.68rem 0.78rem;
            border: 1.5px solid #aebacf;
            border-radius: 10px;
            font-size: 0.92rem;
            line-height: 1.25;
            background: #fffefe;
        }
        .form-group input:hover, .form-group select:hover, .form-group textarea:hover {
            border-color: #9faec6;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #8ca1bf;
            box-shadow: 0 0 0 2px rgba(140, 161, 191, 0.22);
        }
        .form-group small { display: block; color: #5f6b7a; font-size: 0.82rem; margin-top: 0.35rem; }
        .el-section-card {
            border: 1px solid #d4dbe6;
            background: #ffffff;
            border-radius: 14px;
            padding: 0.95rem 0.95rem 1rem;
            margin-bottom: 0.9rem;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.05);
        }
        .el-section-head {
            margin: 0 0 0.7rem;
            color: #7f1d1d;
            font-size: 0.98rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .el-summary-panel {
            border: 1px solid #d9e0ea;
            background: #f8fafc;
            border-radius: 14px;
            padding: 0.8rem 0.9rem;
            margin: 0 0 0.9rem;
            position: sticky;
            top: 76px;
            z-index: 9;
        }
        .el-summary-title {
            margin: 0 0 0.45rem;
            color: #7f1d1d;
            font-weight: 700;
            font-size: 0.86rem;
            letter-spacing: 0.01em;
        }
        .el-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.55rem 0.8rem;
        }
        .el-summary-item {
            border: 1px solid #d5dde8;
            background: #ffffff;
            border-radius: 10px;
            padding: 0.5rem 0.6rem;
            min-width: 0;
        }
        .el-summary-label {
            color: #5b6778;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.1rem;
        }
        .el-summary-value {
            color: #243042;
            font-size: 0.83rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .borrower-mode-card {
            border: 1px solid #f3c8cf;
            background: linear-gradient(180deg, #fff7f8 0%, #fff1f3 100%);
            border-radius: 12px;
            padding: 0.9rem 1rem;
            margin-bottom: 0.85rem;
        }
        .borrower-mode-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: #5b111b;
            margin-bottom: 0.35rem;
            cursor: pointer;
            padding: 0.4rem 0.5rem;
            border-radius: 10px;
            border: 1px solid transparent;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        .borrower-mode-toggle:hover {
            background: rgba(139, 0, 0, 0.06);
            border-color: rgba(139, 0, 0, 0.2);
        }
        .borrower-mode-toggle input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #8b0000;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .borrower-mode-toggle input[type="checkbox"]:focus-visible {
            outline: 2px solid #b91c1c;
            outline-offset: 2px;
        }
        .borrower-mode-help { color: #4b5563; font-size: 0.84rem; }
        .el-span-2 { grid-column: 1 / -1; }
        .el-tail-span-2 { grid-column: span 2; }
        /* Keep this row consistent with the 5-column form rhythm */
        .el-release-status-school-row {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.95rem 1rem;
            align-items: start;
        }
        @media (max-width: 1400px) {
            .el-form-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .el-release-status-school-row {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .el-summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 992px) {
            .el-form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .el-release-status-school-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .el-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .el-tail-span-2 {
                grid-column: 1 / -1;
            }
            .el-summary-panel {
                position: static;
            }
        }
        @media (max-width: 680px) {
            .el-form-grid {
                grid-template-columns: 1fr;
            }
            .el-release-status-school-row {
                grid-template-columns: 1fr;
            }
            .el-summary-grid {
                grid-template-columns: 1fr;
            }
            .el-tail-span-2 {
                grid-column: 1 / -1;
            }
        }
        .form-actions { margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }
        .el-sticky-actions {
            position: sticky;
            bottom: 10px;
            z-index: 12;
            background: rgba(255, 255, 255, 0.96);
            border: 1.5px solid #c6aeb8;
            border-radius: 12px;
            box-shadow: 0 7px 18px rgba(127, 29, 29, 0.08);
            padding: 0.55rem 0.7rem;
            backdrop-filter: blur(2px);
        }
        .btn-primary {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%); color: #fff; border: none;
            padding: 0.65rem 1.4rem; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 0.95rem;
        }
        .btn-primary:hover { opacity: 0.92; }
        .btn-ghost { background: #fff; color: #8b0000; border: 1px solid rgba(139,0,0,0.35); padding: 0.65rem 1.25rem; border-radius: 10px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; }
        .el-loans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 0.9rem;
        }
        /* Approved list: four cards per row on wide screens */
        .el-loans-grid.el-loans-grid--records {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        @media (max-width: 1280px) {
            .el-loans-grid.el-loans-grid--records {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 900px) {
            .el-loans-grid.el-loans-grid--records {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 520px) {
            .el-loans-grid.el-loans-grid--records {
                grid-template-columns: 1fr;
            }
        }
        .el-loans-grid--records .el-loan-card-v2 {
            flex-direction: column;
            align-items: stretch;
        }
        .el-loans-grid--records .el-loan-card-v2-accent {
            width: 100%;
            height: 4px;
            min-height: 4px;
        }
        .el-loans-grid--records .el-loan-card-v2-actions {
            border-left: none;
            border-top: 1px solid #e8e0e0;
            justify-content: stretch;
            padding: 0.65rem 0.85rem;
        }
        .el-loans-grid--records .el-btn-edit-modal {
            width: 100%;
            justify-content: center;
        }
        .el-loan-card {
            border: 1px solid #d8dfeb;
            border-radius: 14px;
            background: #ffffff;
            padding: 0.95rem;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
            min-height: 164px;
            display: flex;
            flex-direction: column;
        }
        .el-loan-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            margin-bottom: 0.5rem;
        }
        .el-loan-status-badge {
            font-size: 0.72rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.2rem 0.6rem;
            border: 1px solid transparent;
            text-transform: capitalize;
        }
        .el-loan-status-badge.approved { color: #065f46; background: #ecfdf5; border-color: #a7f3d0; }
        .el-loan-status-badge.completed { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
        .el-loan-borrower {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.42rem;
        }
        .el-loan-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.45rem 0.7rem;
            margin-bottom: 0.75rem;
        }
        .el-loan-meta-label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            margin-bottom: 0.08rem;
        }
        .el-loan-meta-value {
            font-size: 0.85rem;
            color: #0f172a;
            font-weight: 600;
            word-break: break-word;
        }
        .el-loan-actions { display: flex; justify-content: flex-end; margin-top: auto; }
        .link-edit {
            border: 1px solid rgba(139, 0, 0, 0.25);
            background: #fff1f2;
            color: #8b0000;
            border-radius: 10px;
            padding: 0.46rem 0.72rem;
            font-size: 0.83rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .link-edit:hover { background: #ffe4e6; }
        .file-hint { font-size: 0.8rem; color: #059669; margin-top: 0.25rem; }
        /* Two-step save confirmation (edit / Save changes) */
        .el-dc-overlay {
            position: fixed; inset: 0; z-index: 10060; display: none; align-items: center; justify-content: center;
            padding: 1rem; background: rgba(17, 24, 39, 0.45); backdrop-filter: blur(2px);
        }
        /* Edit loan modal — theme matches sidebar / primary buttons (maroon) */
        .el-edit-overlay {
            position: fixed; inset: 0; z-index: 10040; display: none; align-items: center; justify-content: center;
            padding: 1rem 1rem 2rem;
            background: rgba(62, 7, 7, 0.48);
            backdrop-filter: blur(5px);
        }
        .el-edit-overlay.is-open { display: flex; }
        .el-edit-modal {
            width: min(100%, 96vw);
            max-width: 1320px;
            max-height: min(92vh, 900px);
            min-height: 0;
            display: flex; flex-direction: column;
            background: #f5f5f5;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28), 0 0 0 1px rgba(255,255,255,0.06) inset;
            border: 1px solid rgba(139, 0, 0, 0.2);
        }
        /* Form fills space under header so inner body can scroll */
        .existing-loan-modal-form {
            display: flex;
            flex-direction: column;
            flex: 1 1 0%;
            min-height: 0;
            overflow: hidden;
        }
        .el-edit-modal-head {
            flex-shrink: 0;
            flex-grow: 0;
            display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
            padding: 1.15rem 1.35rem 1.1rem;
            background: #8b0000;
            color: #fff;
        }
        .el-edit-kicker {
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase;
            color: rgba(255, 255, 255, 0.88); margin: 0 0 0.25rem;
        }
        .el-edit-modal-head h2 {
            margin: 0; font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em;
        }
        .el-edit-sub { margin: 0.35rem 0 0; font-size: 0.88rem; color: rgba(255, 255, 255, 0.92); max-width: 52rem; }
        .el-edit-close {
            flex-shrink: 0; width: 40px; height: 40px; border: none; border-radius: 12px;
            background: rgba(255,255,255,0.15); color: #fff; font-size: 1.45rem; line-height: 1; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: background 0.15s;
        }
        .el-edit-close:hover { background: rgba(255,255,255,0.28); }
        .el-edit-modal-alert {
            flex-shrink: 0;
            margin: 0; padding: 0.75rem 1.35rem; background: #fef2f2; color: #991b1b; font-weight: 600; font-size: 0.9rem;
            border-bottom: 1px solid #fecaca;
        }
        .el-edit-modal-body {
            flex: 1 1 0%;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0.85rem 1.35rem 1.25rem;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }
        .el-edit-modal-toc {
            display: flex; flex-wrap: wrap; align-items: center; gap: 0.45rem 0.65rem;
            margin: 0 0 0.9rem; padding: 0.55rem 0.65rem;
            background: #fff; border: 1px solid #d4dbe6; border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }
        .el-edit-modal-toc-label {
            font-size: 0.78rem; font-weight: 700; color: #7f1d1d; margin-right: 0.25rem;
            display: inline-flex; align-items: center; gap: 0.35rem;
        }
        .el-edit-modal-toc a {
            font-size: 0.82rem; font-weight: 600; color: #8b0000; text-decoration: none;
            padding: 0.28rem 0.55rem; border-radius: 8px; border: 1px solid transparent;
        }
        .el-edit-modal-toc a:hover {
            background: #fff1f2; border-color: rgba(139, 0, 0, 0.2);
        }
        .el-edit-sec { scroll-margin-top: 0.75rem; }
        .el-edit-scroll-hint {
            grid-column: 1 / -1;
            margin: 0;
            padding: 0.55rem 0.65rem;
            font-size: 0.84rem;
            color: #5f6b7a;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            line-height: 1.45;
        }
        .el-edit-scroll-hint i { color: #b45309; margin-right: 0.25rem; }
        .el-edit-modal-body .el-section-card {
            background: #ffffff;
            border-color: #d4dbe6;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.05);
        }
        .el-edit-modal-body .el-section-head { color: #7f1d1d; }
        /* Modal: 4-column grids (main page stays 5 columns) */
        .el-edit-modal-body .el-form-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .el-edit-modal-body .el-release-status-school-row {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .el-edit-modal-body .el-tail-span-2 {
            grid-column: span 2;
        }
        @media (max-width: 1100px) {
            .el-edit-modal-body .el-form-grid,
            .el-edit-modal-body .el-release-status-school-row {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 720px) {
            .el-edit-modal-body .el-form-grid,
            .el-edit-modal-body .el-release-status-school-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .el-edit-modal-body .el-tail-span-2 {
                grid-column: 1 / -1;
            }
        }
        @media (max-width: 460px) {
            .el-edit-modal-body .el-form-grid,
            .el-edit-modal-body .el-release-status-school-row {
                grid-template-columns: 1fr;
            }
        }
        .el-edit-modal-foot {
            flex-shrink: 0;
            flex-grow: 0;
            display: flex; flex-wrap: wrap; gap: 0.65rem; justify-content: flex-end;
            padding: 0.85rem 1.35rem 1.1rem;
            background: rgba(255, 255, 255, 0.96);
            border-top: 1.5px solid #c6aeb8;
            box-shadow: 0 -4px 18px rgba(127, 29, 29, 0.06);
        }
        .el-edit-modal-foot .btn-ghost {
            border-color: rgba(139, 0, 0, 0.35); color: #8b0000;
        }
        .el-edit-modal-foot .btn-primary {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
        }
        .el-readonly-soft {
            background: #fafafa !important;
            color: #1f2937 !important;
            border-color: #d4dbe6 !important;
        }
        /* List cards — same maroon accent as rest of page */
        .el-loan-card-v2 {
            display: flex; align-items: stretch; gap: 0; flex-wrap: wrap;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #d4dbe6;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .el-loan-card-v2:hover {
            border-color: rgba(139, 0, 0, 0.28);
            box-shadow: 0 12px 32px rgba(139, 0, 0, 0.1);
        }
        .el-loan-card-v2-accent {
            width: 5px; flex-shrink: 0;
            background: linear-gradient(180deg, #b91c1c 0%, #8b0000 100%);
        }
        .el-loan-card-v2-main {
            flex: 1; min-width: 200px; padding: 0.85rem 1rem 0.85rem 0.75rem;
            display: flex; flex-direction: column; gap: 0.45rem;
        }
        .el-loan-card-v2-top {
            display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap;
        }
        .el-loan-card-v2-name {
            font-size: 1rem; font-weight: 700; color: #1f2937; line-height: 1.3;
        }
        .el-loan-card-v2-meta {
            display: flex; flex-wrap: wrap; gap: 1rem 1.5rem;
        }
        .el-loan-card-v2-meta span { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
        .el-loan-card-v2-meta strong { display: block; margin-top: 0.12rem; font-size: 0.92rem; color: #1f2937; font-weight: 700; }
        .el-loan-card-v2-actions {
            display: flex; align-items: center; padding: 0.65rem 1rem;
            border-left: 1px solid #e8e0e0;
            background: linear-gradient(180deg, #fffbfb 0%, #fff5f5 100%);
        }
        .el-btn-edit-modal {
            border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 0.45rem;
            padding: 0.55rem 1rem; border-radius: 12px; font-weight: 700; font-size: 0.88rem;
            background: #fff1f2;
            color: #8b0000;
            border: 1px solid rgba(139, 0, 0, 0.25);
            transition: transform 0.12s, box-shadow 0.12s, background 0.12s;
        }
        .el-btn-edit-modal:hover {
            transform: translateY(-1px);
            background: #ffe4e6;
            box-shadow: 0 4px 12px rgba(139, 0, 0, 0.12);
        }
        .el-dc-overlay.is-open { display: flex; }
        .el-dc-modal {
            width: 100%; max-width: 440px; background: #fff; border-radius: 14px; overflow: hidden;
            box-shadow: 0 22px 55px rgba(0, 0, 0, 0.22);
        }
        .el-dc-header {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff; padding: 1rem 1.35rem; font-weight: 700; font-size: 1.05rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .el-dc-header i { opacity: 0.95; }
        .el-dc-body { padding: 1.35rem 1.35rem 0.5rem; color: #374151; line-height: 1.55; font-size: 0.95rem; }
        .el-dc-step[hidden] { display: none !important; }
        .el-dc-actions {
            display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: flex-end; padding: 1rem 1.35rem 1.35rem;
        }
        /* `hidden` must win over display:flex (otherwise both button rows show). */
        .el-dc-actions[hidden] { display: none !important; }
        .el-dc-actions .btn-primary, .el-dc-actions .btn-ghost { margin: 0; }

        /* ===== Admin shell (match admin_dashboard/reports) ===== */
        .sidebar-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #eadfe2;
            border-radius: 10px;
            background: #fff;
            color: #8b0000;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.08);
        }

        .sidebar-backdrop { display: none; }
        .sidebar-close { display: none; }

        @media (max-width: 700px) {
            .navbar {
                left: 0 !important;
                width: 100% !important;
                padding: 0.75rem 0.85rem;
                gap: 0.55rem;
                display: flex;
                flex-wrap: nowrap !important;
                align-items: center !important;
            }

            .sidebar-toggle { display: inline-flex; flex-shrink: 0; }

            .welcome-message { font-size: 1rem; min-width: 0; flex: 1 1 auto !important; width: auto !important; max-width: none !important; }
            .welcome-title { font-size: 0.94rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .welcome-meta { font-size: 0.74rem; gap: 0.35rem 0.55rem; flex-wrap: nowrap; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .welcome-meta span:last-child { display: none; }

            .nav-icons { gap: 0.55rem; margin-left: auto; flex: 0 0 auto !important; width: auto !important; justify-content: flex-end !important; }
            .profile-chevron { display: none; }

            .sidebar {
                --mobile-sidebar-width: clamp(200px, 62vw, 240px);
                position: fixed !important;
                top: 0 !important;
                left: calc(-1 * var(--mobile-sidebar-width) - 12px) !important;
                width: var(--mobile-sidebar-width) !important;
                max-width: var(--mobile-sidebar-width) !important;
                min-width: var(--mobile-sidebar-width) !important;
                height: 100vh !important;
                transition: left 0.24s ease !important;
                z-index: 1003 !important;
                overflow-y: auto !important;
                border-radius: 0 16px 16px 0;
                box-shadow: 0 20px 42px rgba(15, 23, 42, 0.24);
            }

            body.sidebar-open .sidebar { left: 0 !important; }

            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                border: 0;
                background: rgba(15, 23, 42, 0.45);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 0.2s ease, visibility 0.2s ease;
                z-index: 1002;
            }

            body.sidebar-open .sidebar-backdrop {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }

            .sidebar-close {
                display: inline-flex;
                position: absolute;
                top: 10px;
                right: 10px;
                width: 34px;
                height: 34px;
                border: 1px solid rgba(255, 255, 255, 0.25);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.12);
                color: #fff;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                z-index: 2;
            }

            .container { margin-top: 78px !important; min-height: 100vh; display: block !important; }
            .main-content { margin-left: 0 !important; padding: 1rem; margin-top: 0; }

            /* Disable collapsed sidebar visuals from shared.css on mobile widths */
            .sidebar-title,
            .sidebar-user-meta,
            .sidebar-badge,
            .sidebar-link .sidebar-label {
                opacity: 1 !important;
                transform: none !important;
                width: auto !important;
                max-width: none !important;
                max-height: none !important;
                overflow: visible !important;
                pointer-events: auto !important;
            }
        }

        @media (min-width: 701px) and (max-width: 900px) {
            .navbar { left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)) !important; }
        }

        /* Content responsiveness for admin pages */
        @media (max-width: 900px) {
            .main-content {
                padding: 1rem !important;
                overflow-x: auto;
            }

            .content-section,
            .filters-card,
            .loan-list-grid {
                overflow-x: auto;
            }

            .existing-loans-table,
            .loan-table {
                min-width: 760px;
            }
        }

        @media (max-width: 600px) {
            .existing-loans-table,
            .loan-table {
                min-width: 700px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Toggle menu" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>
        <div class="welcome-message">
            <div class="welcome-block">
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($role_label); ?></span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-landmark"></i> Existing Loans</span>
                    <span><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($access_label); ?></span>
                </div>
            </div>
        </div>
        <div class="nav-icons">
            <?php include 'notifications.php'; ?>
            <div class="profile-trigger" title="Profile menu" onclick="toggleProfileDropdown()">
                <div class="profile-trigger-main">
                    <div class="profile-icon">
                        <?php if ($profile_photo_exists): ?>
                            <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <span class="profile-initial"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?></span>
                        <?php endif; ?>
                        <div class="status-indicator"></div>
                    </div>
                    <span class="profile-chevron" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-user-info">
                            <div class="dropdown-user-avatar">
                                <?php if ($profile_photo_exists): ?>
                                    <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($_SESSION['deped_id'] ?? 'Not set'); ?></div>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="dropdown-item" onclick="openProfileModal('profile'); return false;">
                        <i class="fas fa-user-edit"></i> Update Profile
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <button id="sidebarBackdrop" class="sidebar-backdrop" type="button" aria-label="Close menu"></button>

    <div class="container">
        <aside class="sidebar">
            <button id="sidebarClose" class="sidebar-close" type="button" aria-label="Hide sidebar">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-header">
                <div class="sidebar-logo"><img src="SDO.jpg" alt="DepEd Loan System Logo"></div>
                <div class="sidebar-title">DepEd Loan System</div>
            </div>
            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <?php echo htmlspecialchars($dashboard_label); ?>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="loan_applications.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-clipboard-list"></i></span>
                        Loan Applications
                        <?php if ($pending_loans_count > 0): ?>
                            <span class="sidebar-badge"><?php echo (int) $pending_loans_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="all_loans.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                        All Loans
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="existing_loans.php" class="sidebar-link active">
                        <span class="sidebar-icon"><i class="fas fa-landmark"></i></span>
                        Existing Loans
                    </a>
                </li>
                <?php if ($is_admin): ?>
                    <li class="sidebar-item">
                        <a href="manage_users.php" class="sidebar-link"><span class="sidebar-icon"><i class="fas fa-users"></i></span> Manage Users</a>
                    </li>
                    <li class="sidebar-item">
                        <a href="audit_trail.php" class="sidebar-link"><span class="sidebar-icon"><i class="fas fa-user-shield"></i></span> Audit Trail</a>
                    </li>
                <?php else: ?>
                    <li class="sidebar-item">
                        <a href="accountant_manage_users.php" class="sidebar-link"><span class="sidebar-icon"><i class="fas fa-users"></i></span> Manage Users</a>
                    </li>
                <?php endif; ?>
                <li class="sidebar-item">
                    <a href="admin_reports.php" class="sidebar-link"><span class="sidebar-icon"><i class="fas fa-chart-bar"></i></span> Reports</a>
                </li>
            </ul>
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar">
                    <?php if ($profile_photo_exists): ?>
                        <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="User avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                    <span class="sidebar-user-status" aria-hidden="true"></span>
                </div>
                <div class="sidebar-user-meta">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                    <div class="sidebar-user-role"><?php echo htmlspecialchars($role_label ?? 'Staff'); ?></div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-landmark"></i> Existing loans (on file)</h2>
                <p class="section-subtitle">Register or update full loan records for borrowers who already had a loan before this system, or correct ongoing / completed loans. Files are stored the same way as new applications (private payslips / IDs).</p>

                <?php if ($error !== ''): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($success !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="existing-loan-form">
                    <input type="hidden" name="existing_loan_action" value="create">
                    <div class="el-summary-panel" aria-live="polite">
                        <p class="el-summary-title"><i class="fas fa-clipboard-check"></i> Quick review before save</p>
                        <div class="el-summary-grid">
                            <div class="el-summary-item"><div class="el-summary-label">Borrower</div><div class="el-summary-value" id="elSummaryBorrower">Not selected</div></div>
                            <div class="el-summary-item"><div class="el-summary-label">Loan amount</div><div class="el-summary-value" id="elSummaryAmount">—</div></div>
                            <div class="el-summary-item"><div class="el-summary-label">Term</div><div class="el-summary-value" id="elSummaryTerm">—</div></div>
                            <div class="el-summary-item"><div class="el-summary-label">Record status</div><div class="el-summary-value" id="elSummaryStatus">—</div></div>
                            <div class="el-summary-item"><div class="el-summary-label">Office / school</div><div class="el-summary-value" id="elSummarySchool">—</div></div>
                        </div>
                    </div>

                    <section class="el-section-card">
                    <h3 class="el-section-head"><i class="fas fa-id-card"></i> Borrower details</h3>
                    <div class="form-group el-span-2 borrower-mode-card">
                        <label class="borrower-mode-toggle">
                            <input type="checkbox" id="create_new_borrower" name="create_new_borrower" value="1" <?php echo (isset($_POST['create_new_borrower']) && $_POST['create_new_borrower'] === '1') ? 'checked' : ''; ?>>
                            Create new borrower account (if no account yet)
                        </label>
                        <div class="borrower-mode-help">If checked, the system will create a borrower account and email login credentials automatically.</div>
                    </div>
                    <div id="existingBorrowerWrap" class="form-group el-span-2" style="margin-bottom:1.25rem;">
                        <label for="borrower_user_id">Borrower *</label>
                        <select name="borrower_user_id" id="borrower_user_id" required>
                            <option value="">— Select borrower —</option>
                            <?php foreach ($borrowers_list as $br): ?>
                                <option value="<?php echo (int) $br['id']; ?>" <?php echo (isset($_POST['borrower_user_id']) && (int)$_POST['borrower_user_id'] === (int)$br['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['full_name'] . ' — ' . $br['email']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Borrower must already have an account. Uploads are tied to this user ID.</small>
                    </div>
                    <div id="newBorrowerWrap" class="el-form-grid el-span-2" style="margin-bottom:1.25rem; display:none;">
                        <div class="form-group">
                            <label for="new_borrower_first_name">First name *</label>
                            <input type="text" id="new_borrower_first_name" name="new_borrower_first_name" autocomplete="given-name" value="<?php echo htmlspecialchars((string)($_POST['new_borrower_first_name'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="new_borrower_middle_name">Middle name</label>
                            <input type="text" id="new_borrower_middle_name" name="new_borrower_middle_name" autocomplete="additional-name" value="<?php echo htmlspecialchars((string)($_POST['new_borrower_middle_name'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="new_borrower_last_name">Last name *</label>
                            <input type="text" id="new_borrower_last_name" name="new_borrower_last_name" autocomplete="family-name" value="<?php echo htmlspecialchars((string)($_POST['new_borrower_last_name'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="new_borrower_email">Borrower email *</label>
                            <input type="email" id="new_borrower_email" name="new_borrower_email" value="<?php echo htmlspecialchars((string)($_POST['new_borrower_email'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="new_borrower_contact">Borrower contact number</label>
                            <input type="text" id="new_borrower_contact" name="new_borrower_contact" placeholder="09XXXXXXXXX" inputmode="numeric" autocomplete="tel" maxlength="11" pattern="09[0-9]{9}" title="Exactly 11 digits: 09 and 9 more numbers" value="<?php echo htmlspecialchars(preg_replace('/\D/', '', (string)($_POST['new_borrower_contact'] ?? ''))); ?>">
                            <small>Optional. If provided, must be exactly 11 digits (09XXXXXXXXX).</small>
                        </div>
                        <div class="form-group">
                            <label for="new_borrower_birth_date">Birth date</label>
                            <input type="date" name="new_borrower_birth_date" id="new_borrower_birth_date" value="<?php echo htmlspecialchars((string) ($_POST['new_borrower_birth_date'] ?? '')); ?>">
                            <small>Saved to the borrower profile when provided.</small>
                        </div>
                        <div class="form-group" id="borrowerDepedBesideSchoolWrap">
                            <label for="borrower_deped_id">Employee DepEd No.</label>
                            <input type="text" id="borrower_deped_id" name="borrower_deped_id" placeholder="1234567" maxlength="7" inputmode="numeric" autocomplete="off" value="<?php echo htmlspecialchars(preg_replace('/\D/', '', (string) ($_POST['borrower_deped_id'] ?? ''))); ?>">
                            <small>Optional; exactly 7 digits if filled.</small>
                        </div>
                    </div>
                    </section>

                    <section class="el-section-card">
                    <h3 class="el-section-head"><i class="fas fa-user"></i> Loan &amp; borrower</h3>
                    <div class="el-form-grid">
                        <div class="form-group">
                            <label for="loan_amount">Loan amount (₱) *</label>
                            <input type="number" name="loan_amount" id="loan_amount" min="1000" max="100000" step="1" inputmode="decimal" required value="<?php echo htmlspecialchars((string) $lf('loan_amount', '')); ?>" title="Loan amount must be from ₱1,000 to ₱100,000">
                        </div>
                        <div class="form-group">
                            <label for="loan_term">Term (months) *</label>
                            <select name="loan_term" id="loan_term" required>
                                <?php
                                $lt = (int) $lf('loan_term', 12);
                                foreach ([6, 12, 18, 24, 30, 36, 42, 48, 54, 60] as $mo) {
                                    $sel = $lt === $mo ? ' selected' : '';
                                    echo '<option value="' . $mo . '"' . $sel . '>' . $mo . ' months</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="net_pay">Net pay (₱) *</label>
                            <input type="number" name="net_pay" id="net_pay" step="0.01" min="0" required value="<?php echo htmlspecialchars((string) $lf('net_pay', '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="already_paid_amount">Already paid / nahulog na (₱)</label>
                            <input type="number" name="already_paid_amount" id="already_paid_amount" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($_POST['already_paid_amount'] ?? '0')); ?>">
                            <small>Total deductions already paid before this entry (used to seed/update payment records).</small>
                        </div>
                        <div class="form-group">
                            <label for="application_date">Application / start date *</label>
                            <input type="date" name="application_date" id="application_date" required value="<?php echo htmlspecialchars($lf('application_date') ? date('Y-m-d', strtotime($lf('application_date'))) : date('Y-m-d')); ?>">
                        </div>
                        <div class="el-release-status-school-row" id="elReleaseStatusSchoolRow">
                            <div class="form-group">
                                <label for="released_at">Released at</label>
                                <input type="datetime-local" name="released_at" id="released_at" value="<?php
                                $ra = $lf('released_at');
                                echo $ra ? date('Y-m-d\TH:i', strtotime($ra)) : '';
                                ?>">
                                <small>For ongoing collections, set when the loan was released (optional).</small>
                            </div>
                            <div class="form-group">
                                <label for="loan_status">Record status *</label>
                                <select name="loan_status" id="loan_status" required>
                                    <?php $st = $lf('status', 'approved'); ?>
                                    <option value="approved"<?php echo $st === 'approved' ? ' selected' : ''; ?>>Approved (paying / active)</option>
                                    <option value="completed"<?php echo $st === 'completed' ? ' selected' : ''; ?>>Completed</option>
                                </select>
                                <small>Interest is fixed at 6% per annum for existing loans.</small>
                            </div>
                            <div class="form-group">
                                <label for="school_assignment">Office / school *</label>
                                <select name="school_assignment" id="school_assignment" required>
                                    <option value="">— Select —</option>
                                    <?php $cs = (string) $lf('school_assignment', ''); ?>
                                    <?php foreach ($office_school_options as $group => $items): ?>
                                        <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                            <?php foreach ($items as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>"<?php echo $cs === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="position">Position *</label>
                                <?php $cp = (string) $lf('position', ''); ?>
                                <input type="text" name="position" id="position" required value="<?php echo htmlspecialchars($cp); ?>" placeholder="Enter position">
                            </div>
                            <div class="form-group">
                                <label for="salary_grade">Salary grade *</label>
                                <select name="salary_grade" id="salary_grade" required>
                                    <option value="">—</option>
                                    <?php $sg = (string) $lf('salary_grade', ''); ?>
                                    <?php foreach (range(1, 33) as $grade): ?>
                                        <option value="<?php echo $grade; ?>"<?php echo (string) $sg === (string) $grade ? ' selected' : ''; ?>>Grade <?php echo $grade; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="employment_status">Employment status *</label>
                            <select name="employment_status" id="employment_status" required>
                                <option value="">— Select —</option>
                                <?php $es = (string) $lf('employment_status', ''); ?>
                                <?php foreach (['Permanent', 'Contractual', 'Substitute', 'Provisional', 'Probationary'] as $opt): ?>
                                    <option value="<?php echo $opt; ?>"<?php echo $es === $opt ? ' selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="borrower_date_of_birth">Borrower DOB</label>
                            <input type="date" name="borrower_date_of_birth" id="borrower_date_of_birth" value="<?php echo htmlspecialchars($lf('borrower_date_of_birth') ? date('Y-m-d', strtotime($lf('borrower_date_of_birth'))) : ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="borrower_years_of_service">Borrower years of service</label>
                            <input type="number" name="borrower_years_of_service" id="borrower_years_of_service" min="0" step="1" value="<?php echo htmlspecialchars((string) $lf('borrower_years_of_service', '0')); ?>">
                        </div>
                        <div class="form-group el-tail-span-2">
                            <label for="loan_purpose">Loan purpose *</label>
                            <textarea name="loan_purpose" id="loan_purpose" required><?php echo htmlspecialchars((string) $lf('loan_purpose', '')); ?></textarea>
                        </div>
                    </div>
                    </section>

                    <section class="el-section-card">
                    <h3 class="el-section-head"><i class="fas fa-user-friends"></i> Co-maker</h3>
                    <div class="el-form-grid">
                        <div class="form-group">
                            <label for="co_maker_last_name">Co-maker last name *</label>
                            <input type="text" name="co_maker_last_name" id="co_maker_last_name" required value="<?php echo htmlspecialchars($co_maker_last_name); ?>">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_first_name">Co-maker first name *</label>
                            <input type="text" name="co_maker_first_name" id="co_maker_first_name" required value="<?php echo htmlspecialchars($co_maker_first_name); ?>">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_middle_name">Co-maker middle name *</label>
                            <input type="text" name="co_maker_middle_name" id="co_maker_middle_name" required value="<?php echo htmlspecialchars($co_maker_middle_name); ?>">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_email">Co-maker email *</label>
                            <input type="email" name="co_maker_email" id="co_maker_email" required value="<?php echo htmlspecialchars((string) $lf('co_maker_email', '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_position">Co-maker position *</label>
                            <?php $cmp = (string) $lf('co_maker_position', ''); ?>
                            <input type="text" name="co_maker_position" id="co_maker_position" required value="<?php echo htmlspecialchars($cmp); ?>" placeholder="Enter co-maker position">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_school_assignment">Co-maker school *</label>
                            <select name="co_maker_school_assignment" id="co_maker_school_assignment" required>
                                <option value="">— Select —</option>
                                <?php $cms = (string) $lf('co_maker_school_assignment', ''); ?>
                                <?php foreach ($office_school_options as $group => $items): ?>
                                    <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                        <?php foreach ($items as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>"<?php echo $cms === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="co_maker_net_pay">Co-maker net pay *</label>
                            <input type="number" name="co_maker_net_pay" id="co_maker_net_pay" step="0.01" min="0" required value="<?php echo htmlspecialchars((string) $lf('co_maker_net_pay', '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_employment_status">Co-maker employment *</label>
                                <select name="co_maker_employment_status" id="co_maker_employment_status" required>
                                <option value="">— Select —</option>
                                <?php $cmes = (string) $lf('co_maker_employment_status', ''); ?>
                                <?php foreach (['Permanent', 'Contractual', 'Substitute', 'Provisional', 'Probationary'] as $opt): ?>
                                    <option value="<?php echo $opt; ?>"<?php echo $cmes === $opt ? ' selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="co_maker_date_of_birth">Co-maker DOB</label>
                            <input type="date" name="co_maker_date_of_birth" id="co_maker_date_of_birth" value="<?php echo htmlspecialchars($lf('co_maker_date_of_birth') ? date('Y-m-d', strtotime($lf('co_maker_date_of_birth'))) : ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_years_of_service">Co-maker years of service</label>
                            <input type="number" name="co_maker_years_of_service" id="co_maker_years_of_service" min="0" step="1" value="<?php echo htmlspecialchars((string) $lf('co_maker_years_of_service', '0')); ?>">
                        </div>
                    </div>
                    </section>

                    <div class="form-actions el-sticky-actions">
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save existing loan</button>
                    </div>
                </form>
            </div>

            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-list"></i> Approved &amp; completed</h2>
                <p class="section-subtitle" style="margin-top:-0.75rem;margin-bottom:1rem;">Click <strong>Edit existing loan</strong> to open the form in a modal—correct amounts, dates, co-maker, or status without leaving this page.</p>
                <?php if (empty($table_loans)): ?>
                    <p style="color:#6b7280;">No approved or completed loans yet.</p>
                <?php else: ?>
                    <div class="el-loans-grid el-loans-grid--records">
                        <?php foreach ($table_loans as $tl): ?>
                            <?php $status_class = strtolower((string) ($tl['status'] ?? 'approved')); ?>
                            <article class="el-loan-card-v2" data-loan-id="<?php echo (int) $tl['id']; ?>">
                                <div class="el-loan-card-v2-accent" aria-hidden="true"></div>
                                <div class="el-loan-card-v2-main">
                                    <div class="el-loan-card-v2-top">
                                        <span class="el-loan-status-badge <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars((string) $tl['status']); ?></span>
                                    </div>
                                    <div class="el-loan-card-v2-name"><?php echo htmlspecialchars((string) $tl['full_name']); ?></div>
                                    <div class="el-loan-card-v2-meta">
                                        <div><span>Start date</span><strong><?php echo date('M j, Y', strtotime((string) $tl['application_date'])); ?></strong></div>
                                        <div><span>Principal</span><strong>₱<?php echo number_format((float) $tl['loan_amount'], 2); ?></strong></div>
                                    </div>
                                </div>
                                <div class="el-loan-card-v2-actions">
                                    <button type="button" class="el-btn-edit-modal el-open-edit-modal" data-loan-id="<?php echo (int) $tl['id']; ?>">
                                        <i class="fas fa-edit"></i> Edit existing loan
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div id="elEditLoanOverlay" class="el-edit-overlay" aria-hidden="true">
        <div class="el-edit-modal" role="dialog" aria-modal="true" aria-labelledby="elEditModalTitle" aria-describedby="elEditModalSub">
            <header class="el-edit-modal-head">
                <div>
                    <p class="el-edit-kicker">Correct on-file data</p>
                    <h2 id="elEditModalTitle">Edit existing loan</h2>
                    <p id="elEditModalSub" class="el-edit-sub">Update loan amounts, term, office, co-maker, release date, or status. Saving still uses the two-step confirmation.</p>
                </div>
                <button type="button" class="el-edit-close" id="elEditCloseX" aria-label="Close editor">&times;</button>
            </header>
            <div id="elEditModalError" class="el-edit-modal-alert" hidden role="alert"></div>
            <?php require __DIR__ . '/includes/existing_loan_edit_modal.php'; ?>
        </div>
    </div>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <div id="elDoubleConfirmOverlay" class="el-dc-overlay" aria-hidden="true">
        <div class="el-dc-modal" role="dialog" aria-modal="true" aria-labelledby="elDcHeading1">
            <div class="el-dc-header"><i class="fas fa-shield-alt"></i> <span id="elDcHeading1">Step 1 of 2 — Confirm save</span></div>
            <div class="el-dc-body">
                <div id="elDcStep1" class="el-dc-step">
                    You are about to save changes to this <strong>existing loan</strong> record. Review the form, then continue to the final confirmation.
                </div>
                <div id="elDcStep2" class="el-dc-step" hidden>
                    <strong>Final confirmation:</strong> your updates will be written to the database (loan details, co-maker, amounts, and status as entered). Continue only if everything is correct.
                </div>
            </div>
            <div class="el-dc-actions" id="elDcActions1">
                <button type="button" class="btn-ghost" id="elDcCancel1"><i class="fas fa-times"></i> Cancel</button>
                <button type="button" class="btn-primary" id="elDcNext"><i class="fas fa-arrow-right"></i> Continue</button>
            </div>
            <div class="el-dc-actions" id="elDcActions2" hidden>
                <button type="button" class="btn-ghost" id="elDcBack"><i class="fas fa-arrow-left"></i> Back</button>
                <button type="button" class="btn-primary" id="elDcFinalSave"><i class="fas fa-save"></i> Save changes</button>
            </div>
        </div>
    </div>

    <script>
        function openProfileModal(tab) {
            const overlay = document.getElementById('profileModalOverlay');
            const frame = document.getElementById('profileModalFrame');
            const safeTab = tab === 'password' ? 'password' : 'profile';
            frame.src = 'profile_update.php?tab=' + safeTab + '&embed=1';
            if (tab === 'password') overlay.classList.add('change-password-modal');
            else overlay.classList.remove('change-password-modal');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        document.addEventListener('click', function (event) {
            if (event.target && event.target.id === 'profileModalOverlay') {
                const overlay = document.getElementById('profileModalOverlay');
                const frame = document.getElementById('profileModalFrame');
                overlay.classList.remove('active', 'change-password-modal');
                document.body.style.overflow = 'auto';
                frame.src = '';
            }
        });

        (function () {
            const toggle = document.getElementById('create_new_borrower');
            const existingWrap = document.getElementById('existingBorrowerWrap');
            const newWrap = document.getElementById('newBorrowerWrap');
            const existingSelect = document.getElementById('borrower_user_id');
            const newFirst = document.getElementById('new_borrower_first_name');
            const newMiddle = document.getElementById('new_borrower_middle_name');
            const newLast = document.getElementById('new_borrower_last_name');
            const newEmail = document.getElementById('new_borrower_email');
            const newContact = document.getElementById('new_borrower_contact');
            const newDeped = document.getElementById('borrower_deped_id');

            if (!toggle || !existingWrap || !newWrap || !existingSelect || !newFirst || !newLast || !newEmail) {
                return;
            }

            const statusRow = document.getElementById('elReleaseStatusSchoolRow');
            const depedWrap = document.getElementById('borrowerDepedBesideSchoolWrap');

            const sync = () => {
                const creating = toggle.checked;
                existingWrap.style.display = creating ? 'none' : '';
                newWrap.style.display = creating ? 'grid' : 'none';
                existingSelect.required = !creating;
                newFirst.required = creating;
                newMiddle.required = false;
                newLast.required = creating;
                newEmail.required = creating;
                if (statusRow && depedWrap && newDeped) {
                    statusRow.classList.toggle('el-release-with-deped', creating);
                    depedWrap.style.display = creating ? '' : 'none';
                    newDeped.disabled = !creating;
                }
            };

            toggle.addEventListener('change', sync);
            sync();

            if (newContact) {
                newContact.addEventListener('input', function () {
                    this.value = this.value.replace(/\D/g, '').slice(0, 11);
                });
            }
            if (newDeped) {
                newDeped.addEventListener('input', function () {
                    this.value = this.value.replace(/\D/g, '').slice(0, 7);
                });
            }
        })();

        (function () {
            const MAX_LOAN_AMOUNT = 100000;
            function bindClamp(el) {
                if (!el) return;
                function clampLoanAmountInput() {
                    const raw = el.value.trim();
                    if (raw === '' || raw === '-') return;
                    const v = parseFloat(raw);
                    if (!Number.isFinite(v)) return;
                    if (v > MAX_LOAN_AMOUNT) {
                        el.value = String(MAX_LOAN_AMOUNT);
                    }
                }
                const v0 = parseFloat(el.value);
                if (Number.isFinite(v0) && v0 > MAX_LOAN_AMOUNT) {
                    el.value = String(MAX_LOAN_AMOUNT);
                }
                el.addEventListener('input', clampLoanAmountInput, true);
                el.addEventListener('wheel', function (e) {
                    if (document.activeElement === this) {
                        e.preventDefault();
                    }
                }, { passive: false });
            }
            bindClamp(document.getElementById('loan_amount'));
            bindClamp(document.getElementById('mel_loan_amount'));
        })();

        (function () {
            const form = document.querySelector('form.existing-loan-form');
            if (!form) return;

            const summaryBorrower = document.getElementById('elSummaryBorrower');
            const summaryAmount = document.getElementById('elSummaryAmount');
            const summaryTerm = document.getElementById('elSummaryTerm');
            const summaryStatus = document.getElementById('elSummaryStatus');
            const summarySchool = document.getElementById('elSummarySchool');
            if (!summaryBorrower || !summaryAmount || !summaryTerm || !summaryStatus || !summarySchool) return;

            const borrowerSelect = document.getElementById('borrower_user_id');
            const firstName = document.getElementById('new_borrower_first_name');
            const middleName = document.getElementById('new_borrower_middle_name');
            const lastName = document.getElementById('new_borrower_last_name');
            const createToggle = document.getElementById('create_new_borrower');
            const loanAmount = document.getElementById('loan_amount');
            const loanTerm = document.getElementById('loan_term');
            const loanStatus = document.getElementById('loan_status');
            const school = document.getElementById('school_assignment');
            const editingBorrowerInput = form.querySelector('input[readonly]');

            const fmtMoney = (raw) => {
                const val = Number(raw || 0);
                if (!Number.isFinite(val) || val <= 0) return '—';
                return '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };
            const niceText = (sel, fallback = '—') => {
                if (!sel) return fallback;
                const t = sel.options && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text.trim() : '';
                return t && !/^—\s*select/i.test(t) ? t : fallback;
            };

            const render = () => {
                if (createToggle && createToggle.checked) {
                    const full = [firstName?.value || '', middleName?.value || '', lastName?.value || '']
                        .map((x) => x.trim()).filter(Boolean).join(' ');
                    summaryBorrower.textContent = full || 'New borrower';
                } else if (borrowerSelect) {
                    summaryBorrower.textContent = niceText(borrowerSelect, 'Not selected');
                } else if (editingBorrowerInput) {
                    summaryBorrower.textContent = editingBorrowerInput.value || 'Not selected';
                } else {
                    summaryBorrower.textContent = 'Not selected';
                }
                summaryAmount.textContent = fmtMoney(loanAmount?.value);
                summaryTerm.textContent = niceText(loanTerm);
                summaryStatus.textContent = niceText(loanStatus);
                summarySchool.textContent = niceText(school);
            };

            form.addEventListener('input', render);
            form.addEventListener('change', render);
            render();
        })();

        (function () {
            const dcForms = document.querySelectorAll('form[data-require-dc="yes"]');
            if (!dcForms.length) return;

            const overlay = document.getElementById('elDoubleConfirmOverlay');
            const step1 = document.getElementById('elDcStep1');
            const step2 = document.getElementById('elDcStep2');
            const actions1 = document.getElementById('elDcActions1');
            const actions2 = document.getElementById('elDcActions2');
            const heading = document.getElementById('elDcHeading1');
            let pendingForm = null;

            function openDoubleConfirm() {
                if (!overlay || !step1 || !step2) return;
                step1.hidden = false;
                step2.hidden = true;
                actions1.hidden = false;
                actions2.hidden = true;
                if (heading) {
                    heading.textContent = 'Step 1 of 2 — Confirm save';
                }
                overlay.classList.add('is-open');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeDoubleConfirm() {
                if (!overlay) return;
                const focused = document.activeElement;
                if (focused && overlay.contains(focused)) {
                    focused.blur();
                }
                overlay.classList.remove('is-open');
                document.body.style.overflow = '';
                const pf = pendingForm;
                pendingForm = null;
                overlay.setAttribute('aria-hidden', 'true');
                if (pf) {
                    pf.setAttribute('tabindex', '-1');
                    pf.focus({ preventScroll: true });
                    pf.removeAttribute('tabindex');
                }
            }

            function showStep2() {
                step1.hidden = true;
                step2.hidden = false;
                actions1.hidden = true;
                actions2.hidden = false;
                if (heading) {
                    heading.textContent = 'Step 2 of 2 — Final confirmation';
                }
            }

            document.getElementById('elDcCancel1')?.addEventListener('click', closeDoubleConfirm);
            document.getElementById('elDcNext')?.addEventListener('click', showStep2);
            document.getElementById('elDcBack')?.addEventListener('click', function () {
                step1.hidden = false;
                step2.hidden = true;
                actions1.hidden = false;
                actions2.hidden = true;
                if (heading) {
                    heading.textContent = 'Step 1 of 2 — Confirm save';
                }
            });
            document.getElementById('elDcFinalSave')?.addEventListener('click', function () {
                if (!pendingForm) return;
                pendingForm.dataset.elConfirmOk = '1';
                closeDoubleConfirm();
                if (HTMLFormElement.prototype.submit) {
                    HTMLFormElement.prototype.submit.call(pendingForm);
                } else {
                    pendingForm.submit();
                }
            });

            overlay?.addEventListener('click', function (e) {
                if (e.target === overlay) closeDoubleConfirm();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay?.classList.contains('is-open')) {
                    closeDoubleConfirm();
                }
            });

            dcForms.forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    const a = form.querySelector('[name="existing_loan_action"]');
                    if (!a || a.value !== 'update') return;
                    if (form.dataset.elConfirmOk === '1') {
                        delete form.dataset.elConfirmOk;
                        return;
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    pendingForm = form;
                    openDoubleConfirm();
                }, true);
            });
        })();

        window.__EL_OPEN_MODAL_ID__ = <?php echo (int) $open_modal_loan_id; ?>;
        window.__EL_MODAL_REOPEN__ = <?php echo $el_modal_reopen_json; ?>;

        (function () {
            const overlay = document.getElementById('elEditLoanOverlay');
            const modalForm = document.getElementById('elModalEditForm');
            const modalBody = document.querySelector('.el-edit-modal-body');
            const errEl = document.getElementById('elEditModalError');
            const titleEl = document.getElementById('elEditModalTitle');
            if (!overlay || !modalForm) return;

            function showErr(msg) {
                if (!errEl) return;
                if (msg) {
                    errEl.textContent = msg;
                    errEl.hidden = false;
                } else {
                    errEl.textContent = '';
                    errEl.hidden = true;
                }
            }

            function openEditOverlay() {
                overlay.classList.add('is-open');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                if (modalBody) {
                    modalBody.scrollTop = 0;
                }
            }

            function closeEditOverlay() {
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                showErr('');
            }

            function setVal(id, val) {
                const el = document.getElementById(id);
                if (!el) return;
                if (val === null || val === undefined) {
                    el.value = '';
                    return;
                }
                el.value = String(val);
            }

            function ymdFromDb(s) {
                if (!s || String(s).indexOf('0000-00-00') === 0) return '';
                return String(s).slice(0, 10);
            }

            function datetimeLocalFromDb(s) {
                if (!s || String(s).indexOf('0000-00-00') === 0) return '';
                const d = new Date(String(s).replace(' ', 'T'));
                if (Number.isNaN(d.getTime())) return '';
                const p = (n) => String(n).padStart(2, '0');
                return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
            }

            function fillFromLoan(loan) {
                setVal('mel_loan_id', loan.id);
                setVal('mel_borrower_display', ((loan.borrower_name || '') + ' — ' + (loan.borrower_email || '')).trim());
                setVal('mel_loan_amount', loan.loan_amount);
                setVal('mel_loan_term', loan.loan_term);
                setVal('mel_net_pay', loan.net_pay);
                setVal('mel_already_paid_amount', loan.total_deducted != null ? loan.total_deducted : '');
                setVal('mel_application_date', ymdFromDb(loan.application_date));
                setVal('mel_released_at', datetimeLocalFromDb(loan.released_at));
                setVal('mel_loan_status', loan.status || 'approved');
                setVal('mel_school_assignment', loan.school_assignment || '');
                setVal('mel_position', loan.position || '');
                setVal('mel_salary_grade', loan.salary_grade || '');
                setVal('mel_employment_status', loan.employment_status || '');
                setVal('mel_borrower_date_of_birth', ymdFromDb(loan.borrower_date_of_birth));
                setVal('mel_borrower_years_of_service', loan.borrower_years_of_service != null ? loan.borrower_years_of_service : '0');
                setVal('mel_loan_purpose', loan.loan_purpose || '');
                setVal('mel_co_maker_last_name', loan.co_maker_last_name || '');
                setVal('mel_co_maker_first_name', loan.co_maker_first_name || '');
                setVal('mel_co_maker_middle_name', loan.co_maker_middle_name || '');
                setVal('mel_co_maker_email', loan.co_maker_email || '');
                setVal('mel_co_maker_position', loan.co_maker_position || '');
                setVal('mel_co_maker_school_assignment', loan.co_maker_school_assignment || '');
                setVal('mel_co_maker_net_pay', loan.co_maker_net_pay);
                setVal('mel_co_maker_employment_status', loan.co_maker_employment_status || '');
                setVal('mel_co_maker_date_of_birth', ymdFromDb(loan.co_maker_date_of_birth));
                setVal('mel_co_maker_years_of_service', loan.co_maker_years_of_service != null ? loan.co_maker_years_of_service : '0');
            }

            function fillFromPost(post) {
                if (!post || typeof post !== 'object') return;
                Object.keys(post).forEach(function (k) {
                    const el = modalForm.elements.namedItem(k);
                    if (!el || el.nodeName === 'BUTTON') return;
                    if ('value' in el && !el.multiple) {
                        el.value = post[k] == null ? '' : String(post[k]);
                    }
                });
            }

            async function loadAndOpen(id) {
                showErr('');
                if (titleEl) titleEl.textContent = 'Loading…';
                openEditOverlay();
                try {
                    const r = await fetch('existing_loan_fetch.php?id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' });
                    const j = await r.json();
                    if (!j.ok) {
                        if (titleEl) titleEl.textContent = 'Edit existing loan';
                        showErr(j.message || 'Could not load this record.');
                        return;
                    }
                    if (titleEl) titleEl.textContent = 'Edit existing loan';
                    fillFromLoan(j.loan);
                    if (modalBody) {
                        modalBody.scrollTop = 0;
                    }
                } catch (err) {
                    if (titleEl) titleEl.textContent = 'Edit existing loan';
                    showErr('Could not load this record. Check your connection and try again.');
                }
            }

            document.querySelectorAll('.el-open-edit-modal').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const id = btn.getAttribute('data-loan-id');
                    if (id) loadAndOpen(id);
                });
            });

            document.getElementById('elEditCloseX')?.addEventListener('click', closeEditOverlay);
            document.getElementById('elEditModalCancel')?.addEventListener('click', closeEditOverlay);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeEditOverlay();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
                    const dc = document.getElementById('elDoubleConfirmOverlay');
                    if (dc && dc.classList.contains('is-open')) return;
                    closeEditOverlay();
                }
            });

            const reopen = window.__EL_MODAL_REOPEN__;
            if (reopen && reopen.post) {
                fillFromPost(reopen.post);
                showErr(reopen.error || '');
                if (titleEl) {
                    titleEl.textContent = 'Edit existing loan';
                }
                openEditOverlay();
            } else if (window.__EL_OPEN_MODAL_ID__) {
                loadAndOpen(window.__EL_OPEN_MODAL_ID__);
            }
        })();
    </script>

    <script>
        (function () {
            const toggleBtn = document.getElementById('sidebarToggle');
            const backdrop = document.getElementById('sidebarBackdrop');
            const closeBtn = document.getElementById('sidebarClose');
            if (!toggleBtn || !backdrop) return;

            function setSidebarOpen(open) {
                document.body.classList.toggle('sidebar-open', open);
                toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.body.style.overflow = open ? 'hidden' : '';
            }

            toggleBtn.addEventListener('click', function () {
                setSidebarOpen(!document.body.classList.contains('sidebar-open'));
            });

            backdrop.addEventListener('click', function () { setSidebarOpen(false); });
            if (closeBtn) closeBtn.addEventListener('click', function () { setSidebarOpen(false); });

            window.addEventListener('resize', function () {
                if (window.innerWidth > 700) setSidebarOpen(false);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') setSidebarOpen(false);
            });
        })();
    </script>
</body>
</html>
