<?php
require_once 'config.php';
require_once 'includes/office_school_options.php';

// Ensure co_maker_email column exists (one-time migration)
$col_check = $conn->query("SHOW COLUMNS FROM loans LIKE 'co_maker_email'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE loans ADD COLUMN co_maker_email VARCHAR(150) NOT NULL DEFAULT ''");
}

// Ensure borrower/co-maker additional fields exist (one-time migration)
$columns_to_ensure = [
    "borrower_date_of_birth" => "ALTER TABLE loans ADD COLUMN borrower_date_of_birth DATE NULL",
    "borrower_years_of_service" => "ALTER TABLE loans ADD COLUMN borrower_years_of_service INT NULL",
    "borrower_id_front_filename" => "ALTER TABLE loans ADD COLUMN borrower_id_front_filename VARCHAR(255) NOT NULL DEFAULT ''",
    "borrower_id_back_filename" => "ALTER TABLE loans ADD COLUMN borrower_id_back_filename VARCHAR(255) NOT NULL DEFAULT ''",
    "co_maker_date_of_birth" => "ALTER TABLE loans ADD COLUMN co_maker_date_of_birth DATE NULL",
    "co_maker_years_of_service" => "ALTER TABLE loans ADD COLUMN co_maker_years_of_service INT NULL",
    "co_maker_id_front_filename" => "ALTER TABLE loans ADD COLUMN co_maker_id_front_filename VARCHAR(255) NOT NULL DEFAULT ''",
    "co_maker_id_back_filename" => "ALTER TABLE loans ADD COLUMN co_maker_id_back_filename VARCHAR(255) NOT NULL DEFAULT ''",
];
foreach ($columns_to_ensure as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM loans LIKE '{$col}'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($ddl);
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$user_sql = "SELECT username, email, full_name, created_at, profile_photo, deped_id, home_address FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$stmt->close();
$profile_photo = $user_data['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);
$home_address = $user_data['home_address'] ?? '';

$borrower_surname = '';
$borrower_first_name = '';
$borrower_mi = '';
$full_name_raw = trim((string) ($user_data['full_name'] ?? ''));
if ($full_name_raw !== '') {
    if (strpos($full_name_raw, ',') !== false) {
        $name_parts = explode(',', $full_name_raw, 2);
        $borrower_surname = trim((string) ($name_parts[0] ?? ''));
        $given = trim((string) ($name_parts[1] ?? ''));
        $given_parts = preg_split('/\s+/', $given);
        $borrower_first_name = trim((string) ($given_parts[0] ?? ''));
        $middle = trim((string) ($given_parts[1] ?? ''));
        $borrower_mi = $middle !== '' ? strtoupper(substr($middle, 0, 1)) : '';
    } else {
        $parts = preg_split('/\s+/', $full_name_raw);
        $borrower_first_name = trim((string) ($parts[0] ?? ''));
        $last = trim((string) (count($parts) > 0 ? $parts[count($parts) - 1] : ''));
        if (count($parts) >= 3) {
            $middle = trim((string) ($parts[1] ?? ''));
            $borrower_mi = $middle !== '' ? strtoupper(substr($middle, 0, 1)) : '';
        }
        $borrower_surname = $last;
    }
}

// Check for rejected loans to pre-populate form and show reviewer comment (admin or accountant)
$rejected_loan = null;
$admin_comment = '';
$reviewed_by_name = '';
$reviewed_by_role = '';

$rejected_loan_sql = "SELECT * FROM loans 
                      WHERE user_id = ? AND status = 'rejected' 
                      ORDER BY COALESCE(reviewed_at, application_date) DESC 
                      LIMIT 1";
$stmt = $conn->prepare($rejected_loan_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rejected_result = $stmt->get_result();
if ($rejected_result->num_rows > 0) {
    $rejected_loan = $rejected_result->fetch_assoc();
    $admin_comment = $rejected_loan['admin_comment'] ?? '';
    $reviewed_by_name = $rejected_loan['reviewed_by_name'] ?? '';
    $reviewed_by_role = $rejected_loan['reviewed_by_role'] ?? '';
    
    // Parse co-maker full name into parts
    $co_maker_full_name = $rejected_loan['co_maker_full_name'] ?? '';
    $co_maker_parts = explode(', ', $co_maker_full_name, 2);
    $co_maker_last_name = $co_maker_parts[0] ?? '';
    $co_maker_first_middle = $co_maker_parts[1] ?? '';
    $co_maker_name_parts = explode(' ', $co_maker_first_middle, 2);
    $co_maker_first_name = $co_maker_name_parts[0] ?? '';
    $co_maker_middle_name = $co_maker_name_parts[1] ?? '';
}
$stmt->close();

// Handle form submission
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $loan_amount = trim($_POST['loan_amount']);
    $loan_purpose = trim($_POST['loan_purpose']);
    $loan_term = trim($_POST['loan_term']);
    $net_pay = trim($_POST['net_pay']);
    $school_assignment = trim($_POST['school_assignment']);
    $position = trim($_POST['position']);
    $salary_grade = trim($_POST['salary_grade']);
    $employment_status = trim($_POST['employment_status']);
    $borrower_date_of_birth = trim($_POST['borrower_date_of_birth'] ?? '');
    $borrower_years_of_service = trim($_POST['borrower_years_of_service'] ?? '');
    $co_maker_first_name = trim($_POST['co_maker_first_name']);
    $co_maker_middle_name = trim($_POST['co_maker_middle_name']);
    $co_maker_last_name = trim($_POST['co_maker_last_name']);
    $co_maker_full_name = trim($co_maker_first_name . ', ' . $co_maker_middle_name . ' ' . $co_maker_last_name);
    $co_maker_email = trim($_POST['co_maker_email'] ?? '');
    $co_maker_position = trim($_POST['co_maker_position']);
    $co_maker_school_assignment = trim($_POST['co_maker_school_assignment']);
    $co_maker_net_pay = trim($_POST['co_maker_net_pay']);
    $co_maker_employment_status = trim($_POST['co_maker_employment_status']);
    $co_maker_date_of_birth = trim($_POST['co_maker_date_of_birth'] ?? '');
    $co_maker_years_of_service = trim($_POST['co_maker_years_of_service'] ?? '');
    $payslip_filename = '';
    $co_maker_payslip_filename = '';
    $borrower_id_front_filename = '';
    $borrower_id_back_filename = '';
    $co_maker_id_front_filename = '';
    $co_maker_id_back_filename = '';
    
    // 30% re-apply: check pending vs approved with payment progress
    $pending_count = 0;
    $previous_loan_id_for_insert = null;
    $offset_amount_for_insert = null;
    $pending_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM loans WHERE user_id = ? AND status = 'pending'");
    if ($pending_stmt) {
        $pending_stmt->bind_param("i", $user_id);
        $pending_stmt->execute();
        $pending_count = (int) ($pending_stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $pending_stmt->close();
    }
    $approved_loan = null;
    $approved_stmt = $conn->prepare("SELECT l.id, l.total_amount, COALESCE(SUM(d.amount), 0) AS total_paid FROM loans l LEFT JOIN deductions d ON d.loan_id = l.id WHERE l.user_id = ? AND l.status = 'approved' GROUP BY l.id LIMIT 1");
    if ($approved_stmt) {
        $approved_stmt->bind_param("i", $user_id);
        $approved_stmt->execute();
        $res = $approved_stmt->get_result();
        if ($res && $res->num_rows > 0) $approved_loan = $res->fetch_assoc();
        $approved_stmt->close();
    }
    $block_due_to_30 = false;
    if ($approved_loan) {
        $total_amt = (float) ($approved_loan['total_amount'] ?? 0);
        $total_pd = (float) ($approved_loan['total_paid'] ?? 0);
        $pct = $total_amt > 0 ? ($total_pd / $total_amt) * 100 : 0;
        if ($pct < 30) {
            $block_due_to_30 = true;
        } else {
            $previous_loan_id_for_insert = (int) $approved_loan['id'];
            $offset_amount_for_insert = $total_amt - $total_pd;
        }
    }

    // Validation
    if (empty($loan_amount) || empty($loan_purpose) || empty($loan_term) || empty($net_pay) ||
        empty($school_assignment) || empty($position) || empty($salary_grade) || empty($employment_status) ||
        empty($borrower_date_of_birth) || $borrower_years_of_service === '' ||
        empty($co_maker_first_name) || empty($co_maker_middle_name) || empty($co_maker_last_name) ||
        empty($co_maker_email) || empty($co_maker_position) || empty($co_maker_school_assignment) ||
        empty($co_maker_net_pay) || empty($co_maker_employment_status) ||
        empty($co_maker_date_of_birth) || $co_maker_years_of_service === '') {
        $error = "All fields are required";
    } elseif (!filter_var($co_maker_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid co-maker email address";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $borrower_date_of_birth)) {
        $error = "Please enter a valid borrower date of birth";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $co_maker_date_of_birth)) {
        $error = "Please enter a valid co-maker date of birth";
    } elseif (!is_numeric($borrower_years_of_service) || (int) $borrower_years_of_service < 0) {
        $error = "Borrower years of service must be 0 or greater";
    } elseif (!is_numeric($co_maker_years_of_service) || (int) $co_maker_years_of_service < 0) {
        $error = "Co-maker years of service must be 0 or greater";
    } elseif ($pending_count > 0) {
        $error = "You already have a pending loan application. Only one application at a time is allowed.";
    } elseif ($block_due_to_30) {
        $error = "You need to pay at least 30% of your current loan before you can apply for another loan.";
    } elseif ($previous_loan_id_for_insert !== null && $offset_amount_for_insert !== null && (float) $loan_amount < (float) $offset_amount_for_insert) {
        $error = "Your new loan amount must be at least ₱" . number_format((float) $offset_amount_for_insert, 2) . " (your remaining balance) so it can cover your current loan.";
    } elseif (!is_numeric($loan_amount) || $loan_amount < 1000 || $loan_amount > 100000) {
        $error = "Loan amount must be between ₱1,000 and ₱100,000";
    } elseif (!is_numeric($net_pay) || $net_pay <= 0) {
        $error = "Net pay must be greater than ₱0";
    } elseif (!is_numeric($co_maker_net_pay) || $co_maker_net_pay <= 0) {
        $error = "Co-maker net pay must be greater than ₱0";
    } elseif (!in_array($loan_term, [6, 12, 18, 24, 30, 36, 42, 48, 54, 60])) {
        $error = "Invalid loan term selected";
    } else {
        // Only block if user still has a PENDING application (rejected applicants may re-apply)
        $feb_pending_check = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE user_id = ? AND status = 'pending' AND MONTH(application_date) = 2 AND YEAR(application_date) = YEAR(CURDATE())");
        $feb_pending_check->bind_param("i", $user_id);
        $feb_pending_check->execute();
        $feb_pending_count = $feb_pending_check->get_result()->fetch_assoc()['count'] ?? 0;
        $feb_pending_check->close();
            
        if ($feb_pending_count > 0) {
            $error = "You have a pending loan application from February that is still being processed. Please wait for it to be reviewed before submitting a new application.";
        } else {
            // Check if user has a rejected loan with existing files (use latest rejection by review time)
            $rejected_loan_check = $conn->prepare("SELECT payslip_filename, co_maker_payslip_filename,
                                                          borrower_id_front_filename, borrower_id_back_filename,
                                                          co_maker_id_front_filename, co_maker_id_back_filename
                                                   FROM loans 
                                                   WHERE user_id = ? AND status = 'rejected' 
                                                   ORDER BY COALESCE(reviewed_at, application_date) DESC 
                                                   LIMIT 1");
            $rejected_loan_check->bind_param("i", $user_id);
            $rejected_loan_check->execute();
            $existing_files = $rejected_loan_check->get_result()->fetch_assoc();
            $rejected_loan_check->close();
            
            // Upload validation - allow existing files from rejected loans only when resubmitting after reject (not when re-apply after 30% paid)
            $allow_existing_payslips = ($previous_loan_id_for_insert === null);
            $has_borrower_payslip = !empty($_FILES['payslip_file']['name']) && $_FILES['payslip_file']['error'] === UPLOAD_ERR_OK;
            $has_existing_borrower_payslip = $allow_existing_payslips && $existing_files && !empty($existing_files['payslip_filename']);
            
            $has_co_maker_payslip = !empty($_FILES['co_maker_payslip_file']['name']) && $_FILES['co_maker_payslip_file']['error'] === UPLOAD_ERR_OK;
            $has_existing_co_maker_payslip = $allow_existing_payslips && $existing_files && !empty($existing_files['co_maker_payslip_filename']);

            $has_borrower_id_front = !empty($_FILES['borrower_id_front']['name']) && $_FILES['borrower_id_front']['error'] === UPLOAD_ERR_OK;
            $has_borrower_id_back = !empty($_FILES['borrower_id_back']['name']) && $_FILES['borrower_id_back']['error'] === UPLOAD_ERR_OK;
            $has_existing_borrower_id_front = $allow_existing_payslips && $existing_files && !empty($existing_files['borrower_id_front_filename']);
            $has_existing_borrower_id_back = $allow_existing_payslips && $existing_files && !empty($existing_files['borrower_id_back_filename']);

            $has_co_maker_id_front = !empty($_FILES['co_maker_id_front']['name']) && $_FILES['co_maker_id_front']['error'] === UPLOAD_ERR_OK;
            $has_co_maker_id_back = !empty($_FILES['co_maker_id_back']['name']) && $_FILES['co_maker_id_back']['error'] === UPLOAD_ERR_OK;
            $has_existing_co_maker_id_front = $allow_existing_payslips && $existing_files && !empty($existing_files['co_maker_id_front_filename']);
            $has_existing_co_maker_id_back = $allow_existing_payslips && $existing_files && !empty($existing_files['co_maker_id_back_filename']);
            
            if (!$has_borrower_payslip && !$has_existing_borrower_payslip) {
                $error = "Borrower payslip file is required";
            } elseif (!$has_co_maker_payslip && !$has_existing_co_maker_payslip) {
                $error = "Co-maker payslip file is required";
            } elseif (!$has_borrower_id_front && !$has_existing_borrower_id_front) {
                $error = "Borrower front ID is required";
            } elseif (!$has_borrower_id_back && !$has_existing_borrower_id_back) {
                $error = "Borrower back ID is required";
            } elseif (!$has_co_maker_id_front && !$has_existing_co_maker_id_front) {
                $error = "Co-maker front ID is required";
            } elseif (!$has_co_maker_id_back && !$has_existing_co_maker_id_back) {
                $error = "Co-maker back ID is required";
            } else {
            $upload_dir = defined('PAYSLIP_UPLOAD_DIR') ? PAYSLIP_UPLOAD_DIR : (__DIR__ . '/storage_private/payslips');
            $id_upload_dir = defined('ID_UPLOAD_DIR') ? ID_UPLOAD_DIR : (__DIR__ . '/storage_private/ids');

            $ensure_upload_dir = function ($dir, $label) {
                if (!is_dir($dir)) {
                    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                        error_log("Upload setup failed for {$label}. Cannot create directory: {$dir}");
                        return "Server storage is not ready for {$label} uploads. Please contact support.";
                    }
                }
                if (!is_writable($dir)) {
                    error_log("Upload setup failed for {$label}. Directory is not writable: {$dir}");
                    return "Server storage is not writable for {$label} uploads. Please contact support.";
                }
                return null;
            };

            $upload_dir_error = $ensure_upload_dir($upload_dir, 'payslip');
            if ($upload_dir_error) {
                $error = $upload_dir_error;
            }
            if (!$error) {
                $id_dir_error = $ensure_upload_dir($id_upload_dir, 'ID');
                if ($id_dir_error) {
                    $error = $id_dir_error;
                }
            }

            $allowed_mimes = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
            ];

            $payslip_file = $_FILES['payslip_file'];
            $co_maker_payslip_file = $_FILES['co_maker_payslip_file'];

            $validate_upload = function ($file, $label) use ($allowed_mimes, $upload_dir, $user_id) {
                if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    return ["Upload session expired for {$label} payslip. Please reselect the file and try again.", null];
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    return [$label . " payslip must be 5MB or less", null];
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                if (!isset($allowed_mimes[$mime])) {
                    return [$label . " payslip must be PDF, JPG, or PNG", null];
                }
                $filename = $label . '_payslip_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$mime];
                $target_path = $upload_dir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                    $php_upload_err = isset($file['error']) ? (int) $file['error'] : -1;
                    error_log("move_uploaded_file failed for {$label} payslip. target={$target_path}; php_upload_error={$php_upload_err}");
                    return ["Failed to upload " . strtolower($label) . " payslip", null];
                }
                return [null, $filename];
            };

            $validate_id_upload = function ($file, $label, $side) use ($allowed_mimes, $id_upload_dir, $user_id) {
                if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    return ["Upload session expired for {$label} {$side} ID. Please reselect the file and try again.", null];
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    return [$label . " " . $side . " ID must be 5MB or less", null];
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                if (!isset($allowed_mimes[$mime])) {
                    return [$label . " " . $side . " ID must be PDF, JPG, or PNG", null];
                }
                $filename = $label . '_id_' . $side . '_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$mime];
                $target_path = $id_upload_dir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                    $php_upload_err = isset($file['error']) ? (int) $file['error'] : -1;
                    error_log("move_uploaded_file failed for {$label} {$side} ID. target={$target_path}; php_upload_error={$php_upload_err}");
                    return ["Failed to upload " . strtolower($label) . " " . $side . " ID", null];
                }
                return [null, $filename];
            };

            // Process borrower payslip
            if ($has_borrower_payslip) {
                [$upload_error, $payslip_filename] = $validate_upload($payslip_file, 'borrower');
                if ($upload_error) {
                    $error = $upload_error;
                }
            } else {
                // Use existing payslip from rejected loan
                $payslip_filename = $existing_files['payslip_filename'] ?? '';
            }
            
            // Process co-maker payslip
            if (!$error) {
                if ($has_co_maker_payslip) {
                    [$upload_error, $co_maker_payslip_filename] = $validate_upload($co_maker_payslip_file, 'co_maker');
                    if ($upload_error) {
                        $error = $upload_error;
                    }
                } else {
                    // Use existing co-maker payslip from rejected loan
                    $co_maker_payslip_filename = $existing_files['co_maker_payslip_filename'] ?? '';
                }
            }

            // Process borrower IDs
            if (!$error) {
                if ($has_borrower_id_front) {
                    [$upload_error, $borrower_id_front_filename] = $validate_id_upload($_FILES['borrower_id_front'], 'borrower', 'front');
                    if ($upload_error) $error = $upload_error;
                } else {
                    $borrower_id_front_filename = $existing_files['borrower_id_front_filename'] ?? '';
                }
            }
            if (!$error) {
                if ($has_borrower_id_back) {
                    [$upload_error, $borrower_id_back_filename] = $validate_id_upload($_FILES['borrower_id_back'], 'borrower', 'back');
                    if ($upload_error) $error = $upload_error;
                } else {
                    $borrower_id_back_filename = $existing_files['borrower_id_back_filename'] ?? '';
                }
            }

            // Process co-maker IDs
            if (!$error) {
                if ($has_co_maker_id_front) {
                    [$upload_error, $co_maker_id_front_filename] = $validate_id_upload($_FILES['co_maker_id_front'], 'co_maker', 'front');
                    if ($upload_error) $error = $upload_error;
                } else {
                    $co_maker_id_front_filename = $existing_files['co_maker_id_front_filename'] ?? '';
                }
            }
            if (!$error) {
                if ($has_co_maker_id_back) {
                    [$upload_error, $co_maker_id_back_filename] = $validate_id_upload($_FILES['co_maker_id_back'], 'co_maker', 'back');
                    if ($upload_error) $error = $upload_error;
                } else {
                    $co_maker_id_back_filename = $existing_files['co_maker_id_back_filename'] ?? '';
                }
            }
            } // end else (both payslips satisfied)
        }
    }
}

    if (!empty($error) || $_SERVER["REQUEST_METHOD"] !== "POST") {
        // Skip insert if validation/upload failed or not a POST request
    } else {
        // Database connection
        require_once 'config.php';
        
        $loan_amount = (float) ($loan_amount ?? 0);
        $loan_term = (int) ($loan_term ?? 12);
        if ($loan_term < 1) {
            $loan_term = 12;
        }
        // Calculate loan details
        $annualInterestRate = 0.06; // 6% per annum
        $monthlyInterestRate = $annualInterestRate / 12; // 0.5% per month
        $monthlyPayment = $loan_amount * ($monthlyInterestRate * pow(1 + $monthlyInterestRate, $loan_term)) / (pow(1 + $monthlyInterestRate, $loan_term) - 1);
        $totalAmount = $monthlyPayment * $loan_term;
        $totalInterest = $totalAmount - $loan_amount;
        
        // Insert loan application with encryption
        // Get current month and determine disbursement month
        $current_month = date('n'); // Current month (1-12)
        $current_year = date('Y'); // Current year
        
        // Calculate disbursement month and year
        if ($current_month == 12) {
            // If December, disburse in January of next year
            $disbursement_month = 1;
            $disbursement_year = $current_year + 1;
        } elseif ($current_month == 2) {
            // If February, disburse in March of current year
            $disbursement_month = 3;
            $disbursement_year = $current_year;
        } else {
            // Otherwise, disburse in next month of current year
            $disbursement_month = $current_month + 1;
            $disbursement_year = $current_year;
        }
        
        $has_previous = $previous_loan_id_for_insert !== null && $offset_amount_for_insert !== null;
        $sql = "INSERT INTO loans (
            user_id,
            loan_amount,
            loan_purpose,
            loan_term,
            net_pay,
            school_assignment,
            position,
            salary_grade,
            employment_status,
            borrower_date_of_birth,
            borrower_years_of_service,
            borrower_id_front_filename,
            borrower_id_back_filename,
            co_maker_full_name,
            co_maker_email,
            co_maker_position,
            co_maker_school_assignment,
            co_maker_net_pay,
            co_maker_employment_status,
            co_maker_date_of_birth,
            co_maker_years_of_service,
            co_maker_id_front_filename,
            co_maker_id_back_filename,
            payslip_filename,
            co_maker_payslip_filename,
            monthly_payment,
            total_amount,
            total_interest,
            application_date" . ($has_previous ? ",\n            previous_loan_id,\n            offset_amount" : "") . "
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($has_previous ? ", ?, ?" : "") . ")";
        $stmt = $conn->prepare($sql);
        
        // Create variables for bind_param (required for reference passing)
        $uid = $_SESSION['user_id'];
        $lamt = (float)$loan_amount;
        $lp = $loan_purpose;
        $lt = (int)$loan_term;
        $np = (float)$net_pay;
        $sa = $school_assignment;
        $pos = $position;
        $sg = $salary_grade;
        $es = $employment_status;
        $bdob = $borrower_date_of_birth;
        $byos = (int) $borrower_years_of_service;
        $bidf = $borrower_id_front_filename;
        $bidb = $borrower_id_back_filename;
        $co_maker_full_name = trim($co_maker_last_name . ', ' . $co_maker_first_name . ' ' . $co_maker_middle_name);
        $cmn = $co_maker_full_name;
        $cme = $co_maker_email;
        $cmp = $co_maker_position;
        $cmsa = $co_maker_school_assignment;
        $cmnp = (float)$co_maker_net_pay;
        $cmes = $co_maker_employment_status;
        $cmdob = $co_maker_date_of_birth;
        $cmyos = (int) $co_maker_years_of_service;
        $cmidf = $co_maker_id_front_filename;
        $cmidb = $co_maker_id_back_filename;
        $pf = $payslip_filename;
        $cmpf = $co_maker_payslip_filename;
        $mp = (float)$monthlyPayment;
        $ta = (float)$totalAmount;
        $ti = (float)$totalInterest;
        $app_date = date('Y-m-d'); // Current date for application

        // Types must match INSERT column order exactly (29 base + 2 when re-applying after 30% paid).
        $loanInsertTypes29 = 'idsid' . str_repeat('s', 5) . 'i' . 'ss' . 'ssss' . 'dssi' . 'ssss' . 'ddd' . 's';
        
        if ($has_previous) {
            $prev_id = $previous_loan_id_for_insert;
            $off_amt = (float) $offset_amount_for_insert;
            $stmt->bind_param(
                $loanInsertTypes29 . 'id',
                $uid,
                $lamt,
                $lp,
                $lt,
                $np,
                $sa,
                $pos,
                $sg,
                $es,
                $bdob,
                $byos,
                $bidf,
                $bidb,
                $cmn,
                $cme,
                $cmp,
                $cmsa,
                $cmnp,
                $cmes,
                $cmdob,
                $cmyos,
                $cmidf,
                $cmidb,
                $pf,
                $cmpf,
                $mp,
                $ta,
                $ti,
                $app_date,
                $prev_id,
                $off_amt
            );
        } else {
            $stmt->bind_param(
                $loanInsertTypes29,
                $uid,
                $lamt,
                $lp,
                $lt,
                $np,
                $sa,
                $pos,
                $sg,
                $es,
                $bdob,
                $byos,
                $bidf,
                $bidb,
                $cmn,
                $cme,
                $cmp,
                $cmsa,
                $cmnp,
                $cmes,
                $cmdob,
                $cmyos,
                $cmidf,
                $cmidb,
                $pf,
                $cmpf,
                $mp,
                $ta,
                $ti,
                $app_date
            );
        }
        
        if ($stmt->execute()) {
            $loan_id = $conn->insert_id;
            $disbursement_display = date('F j, Y', mktime(0, 0, 0, $disbursement_month, 1)); // Display the actual disbursement date
            $success = "Loan application submitted successfully! Your loan will be disbursed on {$disbursement_display}. We will review your application within 3-5 business days.";
            if ($has_previous && $offset_amount_for_insert > 0) {
                $success .= " Your remaining balance of ₱" . number_format((float) $offset_amount_for_insert, 2) . " will be deducted from this loan; you will receive the difference when approved.";
            }
            $target_label = $loan_id ? "Loan Application #{$loan_id}" : 'Loan Application';
            log_audit(
                $conn,
                'SUBMIT',
                "Submitted a loan application for ₱" . number_format($lamt, 2) . ".",
                'Apply Loan',
                $target_label
            );
            
            // Notify co-maker by email that they were used as co-maker
            if (!empty($cme)) {
                if (file_exists(__DIR__ . '/config_email.php')) {
                    require_once __DIR__ . '/config_email.php';
                    require_once __DIR__ . '/mail_helper.php';
                    try {
                        $borrower_name = $user_data['full_name'] ?? 'Borrower';
                        $app_date_formatted = date('F j, Y', strtotime($app_date));
                        sendCoMakerNotificationEmail($cme, $cmn, $borrower_name, $lamt, $app_date_formatted, (string) $lp);
                    } catch (Exception $e) {
                        $success .= ' (Co-maker could not be notified by email: ' . $e->getMessage() . ')';
                    }
                }
            }

            // If co-maker has an account in the system (matched by email), also create an in-app notification
            if (!empty($cme) && filter_var($cme, FILTER_VALIDATE_EMAIL)) {
                $cm_user_stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
                if ($cm_user_stmt) {
                    $cm_user_stmt->bind_param("s", $cme);
                    $cm_user_stmt->execute();
                    $cm_user = $cm_user_stmt->get_result()->fetch_assoc();
                    $cm_user_stmt->close();
                    if ($cm_user && !empty($cm_user['id'])) {
                        $cm_uid = (int) $cm_user['id'];
                        $cm_title = 'Co-Maker Used on Loan Application';
                        $cm_msg = ($user_data['full_name'] ?? 'A borrower') . " listed you as co-maker.\n"
                            . "Loan Purpose: " . (string) $lp . "\n"
                            . "Loan Amount: ₱" . number_format((float) $lamt, 2) . ".";
                        $cm_notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                        if ($cm_notif) {
                            $cm_type = 'info';
                            $cm_notif->bind_param("isss", $cm_uid, $cm_title, $cm_msg, $cm_type);
                            $cm_notif->execute();
                            $cm_notif->close();
                        }
                    }
                }
            }
            
            // Clear form fields
            $loan_amount = $loan_purpose = $loan_term = $net_pay = $school_assignment = $position = $salary_grade = $employment_status = '';
            $borrower_date_of_birth = $borrower_years_of_service = '';
            $co_maker_first_name = $co_maker_middle_name = $co_maker_last_name = $co_maker_email = '';
            $co_maker_position = $co_maker_school_assignment = $co_maker_net_pay = $co_maker_employment_status = '';
            $co_maker_date_of_birth = $co_maker_years_of_service = '';
            $payslip_filename = '';
            $co_maker_payslip_filename = '';
            $borrower_id_front_filename = $borrower_id_back_filename = '';
            $co_maker_id_front_filename = $co_maker_id_back_filename = '';
        } else {
            $error = "Error submitting application. Please try again.";
        }
        
        $stmt->close();
    }

// Fetch existing pending/approved application for display
$existing_application = null;
$existing_stmt = $conn->prepare(
    "SELECT id, loan_amount, loan_purpose, loan_term, net_pay, monthly_payment, total_amount, school_assignment, position, salary_grade,
            employment_status, borrower_date_of_birth, borrower_years_of_service, borrower_id_front_filename, borrower_id_back_filename,
            co_maker_full_name, co_maker_email, co_maker_position, co_maker_school_assignment,
            co_maker_net_pay, co_maker_employment_status, co_maker_date_of_birth, co_maker_years_of_service, co_maker_id_front_filename, co_maker_id_back_filename,
            status, application_date, reviewed_at, released_at, reviewed_by_name
     FROM loans
     WHERE user_id = ? AND status IN ('pending', 'approved')
     ORDER BY application_date DESC
     LIMIT 1"
);
$existing_stmt->bind_param("i", $user_id);
$existing_stmt->execute();
$existing_application = $existing_stmt->get_result()->fetch_assoc();
$existing_stmt->close();

// For re-apply: check if user has ANY approved loan with 30%+ paid (so we hide rejection UI even when they have a new PENDING application)
$can_reapply_30 = false;
$reapply_remaining_balance = 0.0;
$approved_30_stmt = $conn->prepare(
    "SELECT l.id, l.total_amount, l.application_date, COALESCE(SUM(d.amount), 0) AS total_paid
     FROM loans l
     LEFT JOIN deductions d ON d.loan_id = l.id
     WHERE l.user_id = ? AND l.status = 'approved'
     GROUP BY l.id, l.application_date
     ORDER BY l.application_date DESC
     LIMIT 1"
);
$approved_30_stmt->bind_param("i", $user_id);
$approved_30_stmt->execute();
$approved_30_row = $approved_30_stmt->get_result()->fetch_assoc();
$approved_30_stmt->close();
if ($approved_30_row) {
    $ex_total = (float) ($approved_30_row['total_amount'] ?? 0);
    $ex_paid = (float) ($approved_30_row['total_paid'] ?? 0);
    if ($ex_total > 0) {
        $pct = ($ex_paid / $ex_total) * 100;
        if ($pct >= 30) {
            $can_reapply_30 = true;
            $reapply_remaining_balance = $ex_total - $ex_paid;
        }
    }
}
// Show rejection UI during fill-up so borrower sees why they were rejected BEFORE submitting again. Hide when they already have a pending (so we don't show "Rejected" + "For Review" together).
$rejected_is_newer_than_approved = $rejected_loan && $approved_30_row && isset($rejected_loan['application_date'], $approved_30_row['application_date']) && (strtotime($rejected_loan['application_date']) > strtotime($approved_30_row['application_date']));
// Hide "Previous Application Rejected" when current application is approved (avoid confusion)
$show_rejection_section = $rejected_loan && !empty($admin_comment) && (!$can_reapply_30 || $rejected_is_newer_than_approved) && (!$existing_application || ((string)($existing_application['status'] ?? '') !== 'pending' && (string)($existing_application['status'] ?? '') !== 'approved'));
$prefill_from_rejected = $rejected_loan && (!$can_reapply_30 || $rejected_is_newer_than_approved);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Loan - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        .navbar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 192px; /* 80% of 250px */
            right: 0;
            z-index: 1000;
        }
        
        .welcome-message {
            font-size: 1.2rem;
            color: #333;
        }
        
        .welcome-message strong {
            color: #8b0000;
        }
        
        .nav-icons {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
        }
        
        .icon-button {
            position: relative;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: color 0.3s;
        }
        
        .icon-button:hover {
            color: #8b0000;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        
        .container {
            display: flex;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
        }
        
        .sidebar {
            width: 240px;
            background: rgba(179, 0, 0, 0.9);
            backdrop-filter: blur(10px);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            padding-top: 0.25rem;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow: hidden;
            z-index: 999;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            padding: 1.5rem 1.25rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .sidebar-logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .sidebar-title {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.85rem;
            letter-spacing: 0.02em;
        }
        
        .sidebar-menu {
            list-style: none;
            flex: 1;
            padding: 0.5rem 0.5rem 1rem;
            overflow: hidden;
        }
        
        .sidebar-item {
            margin-bottom: 0.1rem;
        }
        
        .sidebar-item.logout {
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1rem 0;
            text-align: center;
        }
        
        .sidebar-item.logout .sidebar-link {
            justify-content: center;
        }
        
        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.65rem 1rem;
            margin: 0.2rem 0.5rem;
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 0;
            border-radius: 12px;
            gap: 0.85rem;
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .sidebar-link:hover {
            background: rgba(255, 255, 255, 0.14);
            color: white;
        }
        
        .sidebar-link.active {
            background: rgba(255, 255, 255, 0.22);
            color: white;
            font-weight: 600;
        }
        
        .sidebar-icon {
            margin-right: 0;
            font-size: 1.1rem;
            width: 26px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 192px; /* 80% of 250px */
            margin-top: 20px;
        }
        
        .content-section {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 2.5rem;
            color: #333;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            padding-bottom: 1rem;
            font-weight: 700;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            border-radius: 2px;
            box-shadow: 0 2px 10px rgba(139, 0, 0, 0.3);
        }
        
        .loan-form {
            width: 100%;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        /* Key fields highlight (theme: light maroon) */
        .form-group.field-key {
            padding: 0.75rem 0.85rem 0.9rem;
            border-radius: 12px;
            border: 1px solid rgba(139, 0, 0, 0.12);
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.06) 0%, rgba(220, 20, 60, 0.04) 100%);
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
            text-align: left;
        }

        .form-group.field-key label {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            background: rgba(139, 0, 0, 0.10);
            color: #8b0000;
            border: 1px solid rgba(139, 0, 0, 0.18);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
            text-align: left;
            box-sizing: border-box;
        }

        .form-group.field-key input,
        .form-group.field-key select,
        .form-group.field-key textarea {
            border: 1.5px solid #4a0404;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.92);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #8b0000;
        }

        .form-group.field-key input:focus,
        .form-group.field-key select:focus,
        .form-group.field-key textarea:focus {
            border-color: #3a0303;
            box-shadow: 0 0 0 3px rgba(74, 4, 4, 0.18);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s;
            width: 100%;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
            border: 1px solid #f5c6cb;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
            border: 1px solid #c3e6cb;
        }
        
        .loan-info {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .loan-info h3 {
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
        }
        
        .info-item:hover {
            transform: translateY(-2px);
        }
        
        .info-item i {
            font-size: 1.5rem;
            color: #8b0000;
            width: 30px;
            text-align: center;
        }
        
        .info-item strong {
            color: #333;
            display: block;
            margin-bottom: 0.3rem;
        }
        
        .info-item p {
            color: #666;
            margin: 0;
            font-size: 0.9rem;
        }
        
        .requirements-section {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        .requirements-header {
            background: #6c757d;
            color: white;
            padding: 1.25rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .requirements-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .requirements-header h3 i {
            color: #ffffff;
            font-size: 1.1rem;
        }
        
        .requirements-content {
            padding: 1.5rem;
        }
        
        .requirements-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (max-width: 1024px) {
            .requirements-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .requirements-list {
                grid-template-columns: 1fr;
            }
        }
        
        .requirement-item {
            display: flex;
            align-items: flex-start;
            gap: 0.875rem;
            padding: 1.25rem;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .requirement-item:hover {
            background: #ffffff;
            border-color: #6c757d;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }
        
        .requirement-item i {
            color: #495057;
            font-size: 1.25rem;
            min-width: 20px;
            text-align: center;
            margin-top: 0.1rem;
        }
        
        .requirement-content {
            flex: 1;
        }
        
        .requirement-item .requirement-title {
            color: #212529;
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 0.375rem;
            display: block;
        }
        
        .requirement-item .requirement-desc {
            color: #6c757d;
            font-size: 0.875rem;
            line-height: 1.4;
            margin: 0;
        }
        
        .form-container {
            background: white;
            padding: 0.7rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .application-status-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .application-status-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .application-status-header h3 i {
            color: #8b0000;
        }

        .review-banner {
            border-radius: 14px;
            padding: 1rem 1.35rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
            font-weight: 500;
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .review-banner.for-review {
            background: linear-gradient(135deg, #f0f4ff 0%, #e8eeff 100%);
            color: #1e3a5f;
        }
        .review-banner.for-review i {
            color: #4f6bf5;
            font-size: 1.2rem;
        }
        .review-banner.approved {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #065f46;
        }
        .review-banner.approved i {
            color: #059669;
            font-size: 1.2rem;
        }

        .review-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .review-item {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem 1.1rem;
            font-size: 0.925rem;
            color: #334155;
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .review-item:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }
        .review-item.highlight {
            background: linear-gradient(135deg, #fffbf5 0%, #fff7ed 100%);
            border-color: #fed7aa;
        }
        .review-item.highlight .review-value {
            font-weight: 700;
            color: #c2410c;
        }
        .review-item strong {
            display: block;
            font-size: 0.7rem;
            color: #8b0000;
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }
        .review-item .review-value {
            display: block;
            font-weight: 600;
            color: #1e293b;
        }
        .review-item.review-item-full {
            grid-column: 1 / -1;
            padding: 1.1rem 1.25rem;
        }
        .review-item.review-item-full .review-value {
            line-height: 1.5;
            margin-top: 0.25rem;
        }
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-badge.for-review {
            background: #e0e7ff;
            color: #4338ca;
        }
        .status-badge.approved {
            background: #d1fae5;
            color: #047857;
        }
        
        .form-group {
            margin-bottom: 0.25rem;
            text-align: left;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 0.25rem;
            text-align: left;
        }
        
        .form-row .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-row.three-cols {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
        }
        
        .form-container h3 {
            color: #333;
            margin-bottom: 0.35rem;
            font-size: 1.05rem;
        }
        
        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: #666;
            font-size: 0.72rem;
        }

        .file-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: center;
            margin-top: 0.5rem;
        }

        .file-preview-btn {
            background: #ffffff;
            color: #8b0000;
            border: 1px solid #eadfe2;
            padding: 0.25rem 0.6rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
        }

        .file-preview-btn:hover {
            background: #fff5f5;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
        }

        .form-group input[type="file"] {
            width: 100%;
            padding: 0.28rem;
            border: 1px dashed #d6dbe2;
            border-radius: 8px;
            background: #fafbfc;
        }

        .form-group.field-key input[type="file"] {
            border: 1.5px solid #4a0404;
            background: #ffffff;
        }
        
        .loan-computation h4 {
            color: #495057;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .loan-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-template-rows: auto auto;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .summary-item label {
            font-weight: 600;
            color: #495057;
            margin: 0;
        }
        
        .summary-item span {
            font-weight: 600;
            color: #212529;
        }
        
        .loan-computation {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
            margin: 0.25rem 0 0.75rem 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        @media (max-width: 640px) {
            .loan-summary {
                grid-template-columns: 1fr;
            }
        }
        
        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            padding: 0.25rem;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        
        .schedule-item {
            background: white;
            padding: 0.5rem;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            text-align: center;
            transition: all 0.2s ease;
        }
        
        .schedule-item:hover {
            border-color: #8b0000;
            box-shadow: 0 2px 6px rgba(139, 0, 0, 0.1);
        }
        
        .schedule-month {
            font-weight: 600;
            color: #495057;
            font-size: 0.8rem;
            margin-bottom: 0.2rem;
        }
        
        .schedule-amount {
            color: #8b0000;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 16px 30px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(139, 0, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            flex: 0 0 auto;
            width: 280px;
            min-height: 52px;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(139, 0, 0, 0.4);
            background: linear-gradient(135deg, #a52a2a 0%, #e74c3c 100%);
        }
        
        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 2px solid #e9ecef;
            justify-content: flex-start;
        }
        
        .reset-btn {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            border: none;
            padding: 16px 30px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            flex: 0 0 auto;
            width: 280px;
            min-height: 52px;
        }
        
        .reset-btn:hover {
            background: linear-gradient(135deg, #5a6268 0%, #343a40 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        
        /* Net Pay Advice Modal */
        .netpay-advice-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .netpay-advice-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.5);
        }
        .netpay-advice-content {
            position: relative;
            background: #fff;
            border-radius: 12px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            border: 1px solid #e9ecef;
        }
        .netpay-advice-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e9ecef;
            background: #fff8f0;
        }
        .netpay-advice-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #856404;
        }
        .netpay-advice-header h3 i {
            margin-right: 0.5rem;
        }
        .netpay-advice-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            color: #666;
            padding: 0 0.25rem;
        }
        .netpay-advice-close:hover {
            color: #333;
        }
        .netpay-advice-body {
            padding: 1.25rem;
        }
        .netpay-advice-remaining {
            margin-bottom: 1rem;
            padding: 0.6rem 0.9rem;
            background: #fff3f3;
            border-radius: 6px;
            border: 1px solid #f5c6cb;
        }
        .netpay-advice-remaining-label {
            margin-right: 0.5rem;
            color: #333;
        }
        .netpay-advice-remaining #netPayAdviceRemainingAmount {
            color: #b02a37;
            font-size: 1.1rem;
        }
        .netpay-advice-intro {
            margin: 0 0 1rem;
            color: #333;
        }
        .netpay-advice-followup {
            margin-top: 0.5rem;
        }
        .netpay-advice-options {
            margin: 0 0 0.5rem;
            font-weight: 600;
        }
        .netpay-advice-list {
            margin: 0;
            padding-left: 1.25rem;
        }
        .netpay-advice-list li {
            margin-bottom: 0.5rem;
        }
        .netpay-advice-tip {
            margin: 1rem 0 0;
            padding: 0.75rem 1rem;
            background: #f0f7ff;
            border-left: 4px solid #8b0000;
            border-radius: 4px;
            font-size: 0.95rem;
        }
        .netpay-advice-tip i {
            color: #856404;
            margin-right: 0.35rem;
        }
        .netpay-advice-bottom {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }
        .netpay-advice-bottom p {
            margin: 0;
            font-size: 0.95rem;
            color: #495057;
            line-height: 1.5;
        }
        .netpay-advice-divider {
            margin: 1.25rem 0;
            border-top: 1px solid #dee2e6;
        }
        .netpay-advice-subtitle {
            margin: 0 0 0.75rem;
            font-size: 1rem;
            color: #8b0000;
        }
        
        @media (max-width: 768px) {
            .navbar {
                left: 0;
                padding: 1rem;
            }
            
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
                order: 2;
            }
            
            .main-content {
                margin-left: 0;
                order: 1;
            }
            
            .container {
                flex-direction: column;
            }
            
            .form-row.three-cols {
                grid-template-columns: 1fr;
            }
            
            .welcome-message {
                font-size: 1rem;
            }
        }

        /* Payslip Preview Modal (Apply Loan) */
        .payslip-preview-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 4000;
        }
        .payslip-preview-overlay.active {
            display: flex;
        }
        .payslip-preview-modal {
            background: white;
            border-radius: 16px;
            width: min(95vw, 1200px);
            max-height: 95vh;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .payslip-preview-modal .payslip-modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .payslip-preview-modal .payslip-modal-header h3 {
            margin: 0;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .payslip-preview-modal .payslip-modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .payslip-preview-modal .payslip-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        .payslip-preview-modal .payslip-modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }
        .payslip-preview-modal .payslip-preview-image {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            object-fit: contain;
        }
        .payslip-preview-modal .payslip-preview-iframe {
            width: 100%;
            height: 70vh;
            border: none;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        /* Step-by-Step Process Styles */
        .loan-process-guide {
            background: #ffffff;
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 2px solid #e5e7eb;
            position: relative;
            overflow: hidden;
        }

        .loan-process-guide::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #8b0000, #dc143c, #ff6b6b, #dc143c, #8b0000);
            background-size: 200% 100%;
            animation: processGradient 3s linear infinite;
        }

        @keyframes processGradient {
            0% { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }

        .process-header {
            text-align: center;
            margin-bottom: 1.25rem;
        }

        .process-header h3 {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 0.35rem;
            line-height: 1.2;
        }

        .process-header p {
            color: #64748b;
            font-size: 1.2rem;
            margin: 0;
        }

        .process-steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            position: relative;
            align-items: stretch;
        }

        .process-step {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            position: relative;
            border: 2px solid #a52a2a;
            display: flex;
            flex-direction: column;
            min-height: 320px;
        }

        .process-step:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 0, 0, 0.15);
            border-color: #8b0000;
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 6px rgba(139, 0, 0, 0.25);
            animation: stepPulse 2s ease-in-out infinite;
        }

        .step-number-inline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #1e293b;
            background: none;
            border: none;
            padding: 0 0.2rem;
            line-height: 1;
            font-size: 1.08rem;
        }

        @keyframes stepPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .step-title {
            font-size: 1.38rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            line-height: 1.28;
        }

        .step-title i {
            color: #dc143c;
            font-size: 1.12rem;
        }

        .step-description {
            color: #64748b;
            font-size: 1.14rem;
            line-height: 1.55;
            margin-bottom: 0.75rem;
        }

        .step-details {
            background: #f8f9fa;
            padding: 0.7rem 0.75rem;
            border-radius: 6px;
            font-size: 1rem;
            line-height: 1.5;
            color: #334155;
            border-left: 2px solid #8b0000;
            margin-top: 0.55rem;
        }
        /* Make steps 1,2,4,5 use space better; keep step 3 as-is */
        .process-step:not(.approved) .step-details {
            font-size: 1.16rem;
            line-height: 1.68;
            padding: 0.85rem 0.9rem;
        }

        .step-details strong {
            color: #8b0000;
            font-weight: 600;
        }

        .process-step.approved {
            border-color: #a52a2a;
            background: #ffffff;
        }

        .process-step.rejected {
            border-color: #a52a2a;
            background: #ffffff;
        }

        .process-step.office {
            border-color: #a52a2a;
            background: #ffffff;
        }

        @media (max-width: 1024px) {
            .process-steps {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .process-step { min-height: 0; }
        }

        @media (max-width: 768px) {
            .process-steps {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .loan-process-guide {
                padding: 1rem;
            }
            
            .process-header h3 {
                font-size: 1.5rem;
            }
            
            .process-step {
                padding: 0.9rem;
            }

            .step-title { font-size: 1.2rem; }
            .step-description { font-size: 1rem; }
            .step-details { font-size: 0.94rem; }
        }

        /* Success Modal Styles */
        .success-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: modalFadeIn 0.3s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .success-modal {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 22px;
            padding: 2.75rem 3rem;
            max-width: 640px;
            width: 95%;
            box-shadow: 0 25px 55px rgba(139, 0, 0, 0.18);
            border: 2px solid #e2e8f0;
            position: relative;
            animation: modalSlideIn 0.4s ease-out;
        }

        @keyframes modalSlideIn {
            from { 
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .success-modal::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #8b0000, #dc143c, #ff6b6b, #dc143c, #8b0000);
            background-size: 200% 100%;
            animation: modalGradient 3s linear infinite;
            border-radius: 20px 20px 0 0;
        }

        @keyframes modalGradient {
            0% { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 50%, #86efac 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            box-shadow: 0 8px 25px rgba(34, 185, 94, 0.25);
            animation: iconPulse 2s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .success-icon i {
            font-size: 2.5rem;
            color: #166534;
        }

        .success-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            text-align: center;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .success-message {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.6;
            text-align: center;
            margin-bottom: 2rem;
        }

        .success-details {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border-left: 3px solid #dc143c;
        }

        .success-details p {
            margin: 0.5rem 0;
            color: #475569;
            font-size: 0.9rem;
        }

        .success-details strong {
            color: #8b0000;
            font-weight: 600;
        }

        .success-close-btn {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            padding: 0.875rem 2rem;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: block;
            margin: 0 auto;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(139, 0, 0, 0.3);
        }

        .success-close-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 0, 0, 0.4);
        }

        @media (max-width: 768px) {
            .success-modal {
                padding: 2rem;
                margin: 1rem;
                max-width: 100%;
                width: 100%;
            }
            
            .success-icon {
                width: 60px;
                height: 60px;
            }
            
            .success-icon i {
                font-size: 2rem;
            }
            
            .success-title {
                font-size: 1.25rem;
            }
        }

        /* ===== App shell (match borrower pages) ===== */
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

            .content-section {
                padding: 0.95rem;
                overflow: hidden;
            }

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

        @media (max-width: 768px) {
            .provident-form-two-col {
                grid-template-columns: 1fr !important;
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
                <div class="welcome-title">Welcome back, <strong><?php echo htmlspecialchars($user_data['full_name']); ?></strong>! 👋</div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> Borrower</span>
                    <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-file-signature"></i> Apply for Loan</span>
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
                    <span class="profile-initial"><?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?></span>
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
                                    <?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($user_data['full_name']); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($user_data['email']); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($user_data['deped_id'] ?? 'Not set'); ?></div>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="dropdown-item" onclick="openProfileModal('profile'); return false;">
                        <i class="fas fa-user-edit"></i>
                        Update Profile
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
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
                <div class="sidebar-logo">
                    <img src="SDO.jpg" alt="DepEd Loan System Logo">
                </div>
                <div class="sidebar-title">DepEd Loan System</div>
            </div>
            
            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="borrower_dashboard.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-home"></i></span>
                        Borrower Dashboard
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="my_loans.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-credit-card"></i></span>
                        My Loans
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="apply_loan.php" class="sidebar-link active">
                        <span class="sidebar-icon"><i class="fas fa-plus-circle"></i></span>
                        Apply for Loan
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="loan_history.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-history"></i></span>
                        Loan History
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="support.php" class="sidebar-link">
                        <span class="sidebar-icon"><i class="fas fa-headset"></i></span>
                        Support
                    </a>
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
                    <div class="sidebar-user-role">Borrower</div>
                </div>
            </div>
            
        </aside>
        
        <main class="main-content">
            <!-- Step-by-Step Process Guide Section -->
            <div class="content-section">
                <div class="loan-process-guide">
                    <div class="process-header">
                        <h3><i class="fas fa-route"></i> Loan Application Process Guide</h3>
                        <p>Understand the complete loan application process from start to finish</p>
                    </div>
                    
                    <div class="process-steps">
                        <div class="process-step">
                            <div class="step-title">
                                <span class="step-number-inline">1.</span>
                                <i class="fas fa-edit"></i>
                                Fill Out Application Form
                            </div>
                            <div class="step-description">
                                Complete the loan application form with your personal information, loan details, and co-maker information.
                            </div>
                            <div class="step-details">
                                <strong>Required:</strong> Personal details, loan amount, purpose, employment info, and co-maker details with payslips
                            </div>
                        </div>
                        
                        <div class="process-step">
                            <div class="step-title">
                                <span class="step-number-inline">2.</span>
                                <i class="fas fa-hourglass-half"></i>
                                Wait for Review & Decision
                            </div>
                            <div class="step-description">
                                Your application will be reviewed by the admin or accountant. This typically takes 1-3 business days.
                            </div>
                            <div class="step-details">
                                <strong>Outcomes:</strong> <span style="color: #22c55e;">✓ Approved</span> or <span style="color: #ef4444;">✗ Rejected</span>
                            </div>
                        </div>
                        
                        <div class="process-step approved">
                            <div class="step-title">
                                <span class="step-number-inline">3.</span>
                                <i class="fas fa-check-circle"></i>
                                If Approved: Visit Office
                            </div>
                            <div class="step-description">
                                Bring your requirements (in two (2) copies) to the office to complete the loan release process.
                            </div>
                            <div class="step-details">
                                <strong>Requirements (2 copies):</strong> Provident Fund Application Form, Letter Request to SDS, Original Payslip, Photocopy of Latest Payslip (Co-borrower only), Copy of DEPED ID(if none yet, Copy of any Government ID with 3 specimens signatures, along with COE from HR), Co-borrowers' ID (3 specimen signatures)<br>
                                <strong>Office Hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM
                            </div>
                        </div>
                        
                        <div class="process-step rejected">
                            <div class="step-title">
                                <span class="step-number-inline">4.</span>
                                <i class="fas fa-times-circle"></i>
                                If Rejected: Review & Reapply
                            </div>
                            <div class="step-description">
                                Check the reviewer's comment for the reason of rejection, make corrections, and submit a new application.
                            </div>
                            <div class="step-details">
                                <strong>Common Reasons:</strong> Incomplete documents, insufficient income, or eligibility issues<br>
                                <strong>Action:</strong> Fix the issues and submit a new application
                            </div>
                        </div>
                        
                        <div class="process-step office">
                            <div class="step-title">
                                <span class="step-number-inline">5.</span>
                                <i class="fas fa-hand-holding-usd"></i>
                                Loan Release & Payments
                            </div>
                            <div class="step-description">
                                Once requirements are verified, your loan will be released and payments will be deducted automatically.
                            </div>
                            <div class="step-details">
                                <strong>Payment Schedule:</strong> Every 15th & 30th of the month via payroll deduction<br>
                                <strong>Monitoring:</strong> Check your dashboard for payment status and remaining balance
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Application Form Section -->
            <div class="content-section">
                <h2 class="section-title">Apply for Loan</h2>
                
                <?php if (!empty($error)): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
<div class="success-modal-overlay" id="successModal">
    <div class="success-modal">
        <div class="success-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h2 class="success-title">Application Submitted Successfully!</h2>
        <div class="success-message">
            <?php 
            $success_message = $success;
            // Extract disbursement date and other details
            if (preg_match('/Your loan will be disbursed on ([^.]+)\./', $success_message, $matches)) {
                $disbursement_date = $matches[1];
                $remaining_message = str_replace($matches[0], '', $success_message);
            } else {
                $disbursement_date = 'Processing';
                $remaining_message = $success_message;
            }
            ?>
            <div class="success-details">
                <p><strong>⏱️ Processing Time:</strong> Within working hours</p>
                <p><strong>📋 Status:</strong> Under Review</p>
            </div>
            <p>Loan application submitted successfully! We will review your application within working hours. Once approved, please proceed to our office to submit your physical requirements (in two (2) copies—see the checklist below) for loan disbursement.</p>
        </div>
        <button class="success-close-btn" onclick="closeSuccessModal()">
            <i class="fas fa-thumbs-up"></i> Got it, Thank You!
        </button>
    </div>
</div>

<script>
function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.style.animation = 'modalFadeOut 0.3s ease-out';
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
}

// Auto-close after 10 seconds
setTimeout(() => {
    const modal = document.getElementById('successModal');
    if (modal && modal.style.display !== 'none') {
        closeSuccessModal();
    }
}, 10000);

// Add fade out animation
const style = document.createElement('style');
style.textContent = `
    @keyframes modalFadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
`;
document.head.appendChild(style);
</script>
<?php endif; ?>
                
                <?php if ($show_rejection_section): ?>
                    <div class="rejection-section" style="background: #f8d7da; border: 2px solid #f5c6cb; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 4px 12px rgba(220, 53, 69, 0.15);">
                        <div style="display: flex; align-items: center; margin-bottom: 1rem;">
                            <div style="background: #dc3545; color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <h3 style="color: #721c24; margin: 0; font-size: 1.3rem;">Previous Application Rejected</h3>
                                <p style="color: #721c24; margin: 0.25rem 0 0 0; font-size: 0.95rem;">Your previous loan application was rejected. Please review the reviewer's comment below and make the necessary corrections:</p>
                            </div>
                        </div>
                        <div class="rejection-comment" style="background: white; padding: 1rem; border-radius: 8px; margin-top: 0.5rem; border-left: 4px solid #dc3545; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div style="font-size: 1rem; line-height: 1.5; color: #333;">
                                <?php echo htmlspecialchars($admin_comment); ?>
                            </div>
                            <?php if (!empty($reviewed_by_name)): ?>
                                <div style="margin-top: 0.75rem; font-size: 0.9rem; color: #6c757d; font-style: italic; border-top: 1px solid #eee; padding-top: 0.5rem;">
                                    — <?php echo htmlspecialchars($reviewed_by_name); ?>
                                    <?php if (!empty($reviewed_by_role)): ?>
                                        (<?php echo htmlspecialchars(ucfirst($reviewed_by_role)); ?>)
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 1rem; padding: 1rem; background: #e8f5e8; border-radius: 8px; border-left: 4px solid #28a745;">
                            <div style="display: flex; align-items: center;">
                                <i class="fas fa-info-circle" style="color: #28a745; margin-right: 0.75rem; font-size: 1.1rem;"></i>
                                <div style="color: #155724; font-size: 0.9rem; line-height: 1.4;">
                                    <strong>Your previous information has been pre-filled below.</strong> Please review and update as needed, then resubmit your application.
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="form-container">
                    <?php
                    $existing_status = $existing_application ? strtolower($existing_application['status'] ?? '') : '';
                    $existing_released = $existing_application && !empty($existing_application['released_at']);
                    $show_status_block = $existing_application && ($existing_status === 'pending' || ($existing_status === 'approved' && !$can_reapply_30));
                    ?>
                    <?php if ($show_status_block): ?>
                        <?php
                            if ($existing_status === 'approved') {
                                $status_label = $existing_released ? 'Released' : 'Approved';
                            } else {
                                $status_label = 'For Review';
                            }
                            $applied_at = !empty($existing_application['application_date']) ? date('M d, Y', strtotime($existing_application['application_date'])) : 'N/A';
                            $reviewed_at = !empty($existing_application['reviewed_at']) ? date('M d, Y', strtotime($existing_application['reviewed_at'])) : 'N/A';
                            $released_at = $existing_released ? date('M d, Y', strtotime($existing_application['released_at'])) : '—';
                            $approved_by = $existing_application['reviewed_by_name'] ?? 'N/A';
                            $monthly_payment = isset($existing_application['monthly_payment']) && $existing_application['monthly_payment'] !== null && $existing_application['monthly_payment'] !== ''
                                ? (float) $existing_application['monthly_payment']
                                : null;
                            if ($monthly_payment === null) {
                                $lamt = (float) ($existing_application['loan_amount'] ?? 0);
                                $term = (int) ($existing_application['loan_term'] ?? 1);
                                $monthly_payment = $term > 0 ? round($lamt / $term, 2) : 0;
                            }
                        ?>
                        <div class="application-status-header">
                            <h3><i class="fas fa-hourglass-half"></i> Application Status</h3>
                        </div>
                        <div class="review-banner <?php echo $existing_status === 'approved' ? 'approved' : 'for-review'; ?>">
                            <i class="fas fa-<?php echo $existing_status === 'approved' ? 'check-circle' : 'info-circle'; ?>"></i>
                            <?php if ($existing_status === 'approved'): ?>
                                <?php if ($existing_released): ?>
                                    Your loan is <strong><?php echo htmlspecialchars($status_label); ?></strong>. You can view and track it under <strong>My Loans</strong>.
                                <?php else: ?>
                                    Your application is <strong><?php echo htmlspecialchars($status_label); ?></strong>. To release your loan, please go to the office and bring the requirements below.
                                <?php endif; ?>
                            <?php else: ?>
                                Your application is currently <strong><?php echo htmlspecialchars($status_label); ?></strong>. We are reviewing your details.
                            <?php endif; ?>
                        </div>
                        <?php if ($existing_status === 'approved' && !$existing_released): ?>
                        <div class="office-requirements-box" style="background: #fff8e6; border: 2px solid #f0c14b; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
                            <h4 style="margin: 0 0 0.75rem 0; color: #856404; font-size: 1.1rem;"><i class="fas fa-building"></i> Pumunta sa office para ma-release ang loan</h4>
                            <p style="margin: 0 0 0.75rem 0; color: #333; font-size: 0.95rem;"><strong>Requirements should be in two (2) copies.</strong> Dalhin ang mga sumusunod sa SDO / designated office:</p>
                            <ul style="margin: 0; padding-left: 1.5rem; color: #333; font-size: 0.95rem; line-height: 1.7;">
                                <li>Provident Fund Application Form (<a href="#" onclick="openProvidentFormModal(event)" style="color: #8b0000;">Download form here</a>)</li>
                                <li>Letter Request addressed to SDS (attach Pictures/ Registration Form/ Bills, etc.)</li>
                                <li>Original Payslip (Latest month available at Cash Unit)</li>
                                <li>Photocopy of Latest Payslip (Co-borrower only; monthly net pay of Php 5,000.00 after initial computation of loan amortization)</li>
                                <li>Photocopy of Employee Deped No. or any valid government ID with Certificate of Employment from HR (with three (3) specimen signatures)</li>
                                <li>Photocopy of Co-borrowers' Employee Deped No. or any valid government ID (with three (3) specimen signatures)</li>
                            </ul>
                            <p style="margin: 0.75rem 0 0 0; font-size: 0.9rem; color: #856404;"><strong>Office hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM. Kapag na-process na at na-release ang loan, makikita mo ito sa <strong>My Loans</strong> bilang active.</p>
                        </div>
                        <?php endif; ?>
                        <div class="review-grid">
                            <div class="review-item"><strong>Status</strong><span class="status-badge <?php echo $existing_status === 'approved' ? 'approved' : 'for-review'; ?>"><?php echo htmlspecialchars($status_label); ?></span></div>
                            <div class="review-item"><strong>Loan Amount</strong><span class="review-value">₱<?php echo number_format((float) $existing_application['loan_amount'], 2); ?></span></div>
                            <div class="review-item"><strong>Loan Term</strong><span class="review-value"><?php echo (int) ($existing_application['loan_term'] ?? 0); ?> months</span></div>
                            <div class="review-item highlight"><strong>Monthly Payment</strong><span class="review-value">₱<?php echo number_format($monthly_payment, 2); ?></span></div>
                            <div class="review-item"><strong>Net Pay</strong><span class="review-value">₱<?php echo number_format((float) $existing_application['net_pay'], 2); ?></span></div>
                            <div class="review-item"><strong>Office/School Assignment</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['school_assignment'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Position</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['position'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Salary Grade</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['salary_grade'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Employment Status</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['employment_status'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Co-Maker Name</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['co_maker_full_name'] ?? ''); ?></span></div>
                            <?php if (!empty($existing_application['co_maker_email'])): ?>
                            <div class="review-item"><strong>Co-Maker Email</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['co_maker_email']); ?></span></div>
                            <?php endif; ?>
                            <div class="review-item"><strong>Co-Maker Position</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['co_maker_position'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Co-Maker Office/School Assignment</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['co_maker_school_assignment'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Co-Maker Net Pay</strong><span class="review-value">₱<?php echo number_format((float) $existing_application['co_maker_net_pay'], 2); ?></span></div>
                            <div class="review-item"><strong>Co-Maker Status</strong><span class="review-value"><?php echo htmlspecialchars($existing_application['co_maker_employment_status'] ?? ''); ?></span></div>
                            <div class="review-item"><strong>Applied Date</strong><span class="review-value"><?php echo $applied_at; ?></span></div>
                            <div class="review-item"><strong>Reviewed Date</strong><span class="review-value"><?php echo $reviewed_at; ?></span></div>
                            <div class="review-item"><strong>Released Date</strong><span class="review-value"><?php echo $released_at; ?></span></div>
                            <div class="review-item"><strong>Approved By</strong><span class="review-value"><?php echo htmlspecialchars($approved_by); ?></span></div>
                            <div class="review-item review-item-full"><strong>Loan Purpose</strong><span class="review-value"><?php echo nl2br(htmlspecialchars($existing_application['loan_purpose'] ?? '')); ?></span></div>
                        </div>
                    <?php else: ?>
                        <?php if ($can_reapply_30 && $reapply_remaining_balance > 0): ?>
                        <div class="reapply-notice" style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 2px solid #81c784; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                            <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                <i class="fas fa-info-circle" style="color: #2e7d32; font-size: 1.25rem; margin-top: 0.1rem;"></i>
                                <div>
                                    <strong style="color: #1b5e20;">You can apply for another loan.</strong>
                                    <p style="margin: 0.35rem 0 0 0; color: #33691e; font-size: 0.95rem;">Your remaining balance of <strong>₱<?php echo number_format($reapply_remaining_balance, 2); ?></strong> will be deducted from your new loan amount. The amount you receive will be (new loan amount minus remaining balance).</p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <h3><i class="fas fa-edit"></i> Application Form</h3>
                        
                        <!-- Loan Computation Section -->
                        <div id="loan-computation" class="loan-computation" style="display: none;">
                            <h4><i class="fas fa-calculator"></i> Loan Computation</h4>
                            <div class="loan-summary">
                                <div class="summary-item">
                                    <label>Principal Amount:</label>
                                    <span id="principal-display">₱0.00</span>
                                </div>
                                <div class="summary-item">
                                    <label>Monthly Payment:</label>
                                    <span id="monthly-payment-display">₱0.00</span>
                                </div>
                                <div class="summary-item">
                                    <label>Total Amount:</label>
                                    <span id="total-amount-display">₱0.00</span>
                                </div>
                                <div class="summary-item">
                                    <label>Total Interest:</label>
                                    <span id="total-interest-display">₱0.00</span>
                                </div>
                                <div class="summary-item" id="remaining-net-pay-wrap" style="display: none;">
                                    <label>Remaining Net Pay (Borrower):</label>
                                    <span id="remaining-net-pay-display">—</span>
                                </div>
                                <div class="summary-item" id="co-maker-remaining-net-pay-wrap" style="display: none;">
                                    <label>Remaining Net Pay (Co-Maker):</label>
                                    <span id="co-maker-remaining-net-pay-display">—</span>
                                </div>
                            </div>
                            
                            <h5><i class="fas fa-calendar-alt"></i> Payment Schedule</h5>
                            <div id="schedule-grid" class="schedule-grid">
                                <!-- Payment schedule will be generated here -->
                            </div>
                        </div>
                        
                        <form class="loan-form" method="POST" action="" enctype="multipart/form-data">
                        <h4 style="margin-bottom: 1rem; color: #8b0000;"><i class="fas fa-user"></i> Borrower Information</h4>
                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="loan_amount"><i class="fas fa-peso-sign"></i> Loan Amount (₱)</label>
                                <input type="number" id="loan_amount" name="loan_amount" min="1000" max="100000" step="1" inputmode="decimal" required value="<?php echo isset($loan_amount) ? htmlspecialchars($loan_amount) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['loan_amount']) : ''); ?>" title="Loan amount must be from ₱1,000 to ₱100,000">
                                <small>Minimum ₱1,000 / Maximum ₱100,000</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="loan_term"><i class="fas fa-calendar-alt"></i> Loan Term (months)</label>
                                <select id="loan_term" name="loan_term" required>
                                    <option value="">Select Payment Term</option>
                                    <option value="6" <?php echo (isset($loan_term) && $loan_term == '6') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '6') ? 'selected' : ''; ?>>6 months</option>
                                    <option value="12" <?php echo (isset($loan_term) && $loan_term == '12') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '12') ? 'selected' : ''; ?>>12 months</option>
                                    <option value="18" <?php echo (isset($loan_term) && $loan_term == '18') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '18') ? 'selected' : ''; ?>>18 months</option>
                                    <option value="24" <?php echo (isset($loan_term) && $loan_term == '24') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '24') ? 'selected' : ''; ?>>24 months</option>
                                    <option value="30" <?php echo (isset($loan_term) && $loan_term == '30') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '30') ? 'selected' : ''; ?>>30 months</option>
                                    <option value="36" <?php echo (isset($loan_term) && $loan_term == '36') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '36') ? 'selected' : ''; ?>>36 months</option>
                                    <option value="42" <?php echo (isset($loan_term) && $loan_term == '42') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '42') ? 'selected' : ''; ?>>42 months</option>
                                    <option value="48" <?php echo (isset($loan_term) && $loan_term == '48') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '48') ? 'selected' : ''; ?>>48 months</option>
                                    <option value="54" <?php echo (isset($loan_term) && $loan_term == '54') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '54') ? 'selected' : ''; ?>>54 months</option>
                                    <option value="60" <?php echo (isset($loan_term) && $loan_term == '60') || ($prefill_from_rejected && $rejected_loan['loan_term'] == '60') ? 'selected' : ''; ?>>60 months (5 years)</option>
                                </select>
                                <small>6–60 months</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="net_pay"><i class="fas fa-money-bill-wave"></i> Net Pay (₱)</label>
                                <input type="number" id="net_pay" name="net_pay" min="1" step="1" required value="<?php echo isset($net_pay) ? htmlspecialchars($net_pay) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['net_pay']) : ''); ?>">
                                <small>Enter your net pay</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="school_assignment"><i class="fas fa-school"></i> Office/School Assignment *</label>
                                <select id="school_assignment" name="school_assignment" required>
                                    <option value="">Select office or school</option>
                                    <?php
                                    $current_school = isset($school_assignment) ? $school_assignment : ($prefill_from_rejected ? ($rejected_loan['school_assignment'] ?? '') : '');
                                    foreach ($office_school_options as $group => $items): ?>
                                        <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                            <?php foreach ($items as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($current_school === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <small>Select your assigned office or school</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="position"><i class="fas fa-user-tie"></i> Position *</label>
                                <?php $current_position = isset($position) ? $position : ($prefill_from_rejected ? ($rejected_loan['position'] ?? '') : ''); ?>
                                <input type="text" id="position" name="position" required placeholder="Enter your position" value="<?php echo htmlspecialchars($current_position); ?>">
                                <small>Enter your current position</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="salary_grade"><i class="fas fa-chart-line"></i> Salary Grade *</label>
                                <select id="salary_grade" name="salary_grade" required>
                                    <option value="">Select Salary Grade</option>
                                    <?php
                                    $salary_grades = range(1, 33);
                                    foreach ($salary_grades as $grade) {
                                        $selected = (isset($salary_grade) && $salary_grade == $grade) || ($prefill_from_rejected && $rejected_loan['salary_grade'] == $grade) ? 'selected' : '';
                                        echo "<option value='$grade' $selected>Grade $grade</option>";
                                    }
                                    ?>
                                </select>
                                <small>Select your salary grade</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="employment_status"><i class="fas fa-user-check"></i> Employment Status *</label>
                                <select id="employment_status" name="employment_status" required>
                                    <option value="">Select Employment Status</option>
                                    <option value="Permanent" <?php echo (isset($employment_status) && $employment_status == 'Permanent') || ($prefill_from_rejected && $rejected_loan['employment_status'] == 'Permanent') ? 'selected' : ''; ?>>Permanent</option>
                                    <option value="Contractual" <?php echo (isset($employment_status) && $employment_status == 'Contractual') || ($prefill_from_rejected && $rejected_loan['employment_status'] == 'Contractual') ? 'selected' : ''; ?>>Contractual</option>
                                    <option value="Substitute" <?php echo (isset($employment_status) && $employment_status == 'Substitute') || ($prefill_from_rejected && $rejected_loan['employment_status'] == 'Substitute') ? 'selected' : ''; ?>>Substitute</option>
                                    <option value="Provisional" <?php echo (isset($employment_status) && $employment_status == 'Provisional') || ($prefill_from_rejected && $rejected_loan['employment_status'] == 'Provisional') ? 'selected' : ''; ?>>Provisional</option>
                                    <option value="Probationary" <?php echo (isset($employment_status) && $employment_status == 'Probationary') || ($prefill_from_rejected && $rejected_loan['employment_status'] == 'Probationary') ? 'selected' : ''; ?>>Probationary</option>
                                </select>
                                <small>Select your current employment status</small>
                            </div>

                            <div class="form-group field-key">
                                <label for="borrower_date_of_birth"><i class="fas fa-cake-candles"></i> Date of Birth *</label>
                                <input type="date" id="borrower_date_of_birth" name="borrower_date_of_birth" required value="<?php echo isset($borrower_date_of_birth) ? htmlspecialchars($borrower_date_of_birth) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['borrower_date_of_birth'] ?? '') : ''); ?>">
                                <small>Borrower's date of birth</small>
                            </div>

                            <div class="form-group field-key">
                                <label for="borrower_years_of_service"><i class="fas fa-briefcase"></i> Years of Service *</label>
                                <input type="number" id="borrower_years_of_service" name="borrower_years_of_service" min="0" step="1" required value="<?php echo isset($borrower_years_of_service) ? htmlspecialchars($borrower_years_of_service) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['borrower_years_of_service'] ?? '') : ''); ?>">
                                <small>Number of years in service</small>
                            </div>

                            <div class="form-group field-key">
                                <label for="payslip_file"><i class="fas fa-file-upload"></i> Latest Payslip (PDF/JPG/PNG)</label>
                                <input type="file" id="payslip_file" name="payslip_file" accept=".pdf,.jpg,.jpeg,.png" <?php echo $prefill_from_rejected && !empty($rejected_loan['payslip_filename']) ? '' : 'required'; ?>>
                                <small>Upload latest payslip (PDF, JPG, PNG) up to 5MB.</small>
                                <?php if ($prefill_from_rejected && !empty($rejected_loan['payslip_filename'])): ?>
                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e8; border-radius: 4px; font-size: 0.9rem; color: #155724;">
                                        <i class="fas fa-check-circle"></i> Previous payslip file already uploaded. You can upload a new one or keep the existing.
                                    </div>
                                <?php endif; ?>
                                <div class="file-actions">
                                    <button type="button" id="payslip_preview_btn" class="file-preview-btn" style="display: none;" onclick="previewPayslip('payslip_file')">
                                        <i class="fas fa-eye"></i> View Selected Payslip
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="borrower_id_front"><i class="fas fa-id-card"></i> ID (Front) *</label>
                                <input type="file" id="borrower_id_front" name="borrower_id_front" accept=".pdf,.jpg,.jpeg,.png" <?php echo $prefill_from_rejected && !empty($rejected_loan['borrower_id_front_filename']) ? '' : 'required'; ?>>
                                <small>Upload front ID (PDF, JPG, PNG) up to 5MB.</small>
                                <?php if ($prefill_from_rejected && !empty($rejected_loan['borrower_id_front_filename'])): ?>
                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e8; border-radius: 4px; font-size: 0.9rem; color: #155724;">
                                        <i class="fas fa-check-circle"></i> Previous borrower front ID already uploaded. You can upload a new one or keep the existing.
                                    </div>
                                <?php endif; ?>
                                <div class="file-actions">
                                    <button type="button" id="borrower_id_front_preview_btn" class="file-preview-btn" style="display: none;" onclick="previewPayslip('borrower_id_front')">
                                        <i class="fas fa-eye"></i> View Selected Front ID
                                    </button>
                                </div>
                            </div>

                            <div class="form-group field-key">
                                <label for="borrower_id_back"><i class="fas fa-id-card"></i> ID (Back) *</label>
                                <input type="file" id="borrower_id_back" name="borrower_id_back" accept=".pdf,.jpg,.jpeg,.png" <?php echo $prefill_from_rejected && !empty($rejected_loan['borrower_id_back_filename']) ? '' : 'required'; ?>>
                                <small>Upload back ID (PDF, JPG, PNG) up to 5MB.</small>
                                <?php if ($prefill_from_rejected && !empty($rejected_loan['borrower_id_back_filename'])): ?>
                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e8; border-radius: 4px; font-size: 0.9rem; color: #155724;">
                                        <i class="fas fa-check-circle"></i> Previous borrower back ID already uploaded. You can upload a new one or keep the existing.
                                    </div>
                                <?php endif; ?>
                                <div class="file-actions">
                                    <button type="button" id="borrower_id_back_preview_btn" class="file-preview-btn" style="display: none;" onclick="previewPayslip('borrower_id_back')">
                                        <i class="fas fa-eye"></i> View Selected Back ID
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group field-key" style="flex: 1 1 100%;">
                                <label for="loan_purpose"><i class="fas fa-comment-alt"></i> Loan Purpose</label>
                                <textarea id="loan_purpose" name="loan_purpose" required placeholder="Please describe the purpose of your loan in detail..."><?php echo isset($loan_purpose) ? htmlspecialchars($loan_purpose) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['loan_purpose']) : ''); ?></textarea>
                                <small>Provide a clear description of how you plan to use the loan</small>
                            </div>
                        </div>

                        <h4 style="margin: 2rem 0 1rem; color: #8b0000;"><i class="fas fa-user-friends"></i> Co-Maker Information</h4>
                        <div class="form-row three-cols">
                            <div class="form-group field-key">
                                <label for="co_maker_last_name"><i class="fas fa-user"></i> Last Name *</label>
                                <input type="text" id="co_maker_last_name" name="co_maker_last_name" required value="<?php echo isset($_POST['co_maker_last_name']) ? htmlspecialchars($_POST['co_maker_last_name']) : ($prefill_from_rejected ? htmlspecialchars($co_maker_last_name ?? '') : ''); ?>">
                                <small>Enter co-maker last name</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="co_maker_first_name"><i class="fas fa-user"></i> First Name *</label>
                                <input type="text" id="co_maker_first_name" name="co_maker_first_name" required value="<?php echo isset($_POST['co_maker_first_name']) ? htmlspecialchars($_POST['co_maker_first_name']) : ($prefill_from_rejected ? htmlspecialchars($co_maker_first_name ?? '') : ''); ?>">
                                <small>Enter co-maker first name</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="co_maker_middle_name"><i class="fas fa-user"></i> Middle Name *</label>
                                <input type="text" id="co_maker_middle_name" name="co_maker_middle_name" required value="<?php echo isset($_POST['co_maker_middle_name']) ? htmlspecialchars($_POST['co_maker_middle_name']) : ($prefill_from_rejected ? htmlspecialchars($co_maker_middle_name ?? '') : ''); ?>">
                                <small>Enter co-maker middle name</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="co_maker_email"><i class="fas fa-envelope"></i> Co-Maker Email *</label>
                                <input type="email" id="co_maker_email" name="co_maker_email" required placeholder="Enter co-maker email address" value="<?php echo isset($co_maker_email) ? htmlspecialchars($co_maker_email) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['co_maker_email'] ?? '') : ''); ?>">
                                <small>Email for co-maker notification</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="co_maker_position"><i class="fas fa-user-tie"></i> Position *</label>
                                <?php $current_co_position = isset($co_maker_position) ? $co_maker_position : ($prefill_from_rejected ? ($rejected_loan['co_maker_position'] ?? '') : ''); ?>
                                <input type="text" id="co_maker_position" name="co_maker_position" required placeholder="Enter co-maker position" value="<?php echo htmlspecialchars($current_co_position); ?>">
                                <small>Enter co-maker position</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="co_maker_school_assignment"><i class="fas fa-school"></i> Co-Maker Office/School Assignment *</label>
                                <select id="co_maker_school_assignment" name="co_maker_school_assignment" required>
                                    <option value="">Select office or school</option>
                                    <?php
                                    $current_co_school = isset($co_maker_school_assignment) ? $co_maker_school_assignment : ($prefill_from_rejected ? ($rejected_loan['co_maker_school_assignment'] ?? '') : '');
                                    foreach ($office_school_options as $group => $items): ?>
                                        <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                            <?php foreach ($items as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($current_co_school === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <small>Co-maker office/school</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="co_maker_date_of_birth"><i class="fas fa-cake-candles"></i> Co-Maker Date of Birth *</label>
                                <input type="date" id="co_maker_date_of_birth" name="co_maker_date_of_birth" required value="<?php echo isset($co_maker_date_of_birth) ? htmlspecialchars($co_maker_date_of_birth) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['co_maker_date_of_birth'] ?? '') : ''); ?>">
                                <small>Co-maker date of birth</small>
                            </div>

                            <div class="form-group field-key">
                                <label for="co_maker_years_of_service"><i class="fas fa-briefcase"></i> Co-Maker Years of Service *</label>
                                <input type="number" id="co_maker_years_of_service" name="co_maker_years_of_service" min="0" step="1" required value="<?php echo isset($co_maker_years_of_service) ? htmlspecialchars($co_maker_years_of_service) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['co_maker_years_of_service'] ?? '') : ''); ?>">
                                <small>Number of years in service</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="co_maker_net_pay"><i class="fas fa-money-bill-wave"></i> Net Pay (₱) *</label>
                                <input type="number" id="co_maker_net_pay" name="co_maker_net_pay" min="1" step="1" required value="<?php echo isset($co_maker_net_pay) ? htmlspecialchars($co_maker_net_pay) : ($prefill_from_rejected ? htmlspecialchars($rejected_loan['co_maker_net_pay']) : ''); ?>">
                                <small>Enter co-maker net pay</small>
                            </div>
                            
                            <div class="form-group field-key">
                                <label for="co_maker_employment_status"><i class="fas fa-user-check"></i> Employment Status *</label>
                                <select id="co_maker_employment_status" name="co_maker_employment_status" required>
                                    <option value="">Select Employment Status</option>
                                    <option value="Permanent" <?php echo (isset($co_maker_employment_status) && $co_maker_employment_status == 'Permanent') || ($prefill_from_rejected && $rejected_loan['co_maker_employment_status'] == 'Permanent') ? 'selected' : ''; ?>>Permanent</option>
                                    <option value="Contractual" <?php echo (isset($co_maker_employment_status) && $co_maker_employment_status == 'Contractual') || ($prefill_from_rejected && $rejected_loan['co_maker_employment_status'] == 'Contractual') ? 'selected' : ''; ?>>Contractual</option>
                                    <option value="Substitute" <?php echo (isset($co_maker_employment_status) && $co_maker_employment_status == 'Substitute') || ($prefill_from_rejected && $rejected_loan['co_maker_employment_status'] == 'Substitute') ? 'selected' : ''; ?>>Substitute</option>
                                    <option value="Provisional" <?php echo (isset($co_maker_employment_status) && $co_maker_employment_status == 'Provisional') || ($prefill_from_rejected && $rejected_loan['co_maker_employment_status'] == 'Provisional') ? 'selected' : ''; ?>>Provisional</option>
                                    <option value="Probationary" <?php echo (isset($co_maker_employment_status) && $co_maker_employment_status == 'Probationary') || ($prefill_from_rejected && $rejected_loan['co_maker_employment_status'] == 'Probationary') ? 'selected' : ''; ?>>Probationary</option>
                                </select>
                                <small>Select co-maker employment status</small>
                            </div>
                            <div class="form-group field-key">
                                <label for="co_maker_payslip_file"><i class="fas fa-file-upload"></i> Co-Maker Payslip (PDF/JPG/PNG)</label>
                                <input type="file" id="co_maker_payslip_file" name="co_maker_payslip_file" accept=".pdf,.jpg,.jpeg,.png" <?php echo $prefill_from_rejected && !empty($rejected_loan['co_maker_payslip_filename']) ? '' : 'required'; ?>>
                                <small>Upload co-maker payslip (PDF, JPG, PNG) up to 5MB.</small>
                                <?php if ($prefill_from_rejected && !empty($rejected_loan['co_maker_payslip_filename'])): ?>
                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e8; border-radius: 4px; font-size: 0.9rem; color: #155724;">
                                        <i class="fas fa-check-circle"></i> Previous co-maker payslip file already uploaded. You can upload a new one or keep the existing.
                                    </div>
                                <?php endif; ?>
                                <div class="file-actions">
                                    <button type="button" id="co_maker_payslip_preview_btn" class="file-preview-btn" style="display: none;" onclick="previewPayslip('co_maker_payslip_file')">
                                        <i class="fas fa-eye"></i> View Selected Payslip
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group field-key">
                                <label for="co_maker_id_front"><i class="fas fa-id-card"></i> Co-Maker ID (Front) *</label>
                                <input type="file" id="co_maker_id_front" name="co_maker_id_front" accept=".pdf,.jpg,.jpeg,.png" <?php echo $prefill_from_rejected && !empty($rejected_loan['co_maker_id_front_filename']) ? '' : 'required'; ?>>
                                <small>Upload co-maker front ID (PDF, JPG, PNG) up to 5MB.</small>
                                <?php if ($prefill_from_rejected && !empty($rejected_loan['co_maker_id_front_filename'])): ?>
                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e8; border-radius: 4px; font-size: 0.9rem; color: #155724;">
                                        <i class="fas fa-check-circle"></i> Previous co-maker front ID already uploaded. You can upload a new one or keep the existing.
                                    </div>
                                <?php endif; ?>
                                <div class="file-actions">
                                    <button type="button" id="co_maker_id_front_preview_btn" class="file-preview-btn" style="display: none;" onclick="previewPayslip('co_maker_id_front')">
                                        <i class="fas fa-eye"></i> View Selected Front ID
                                    </button>
                                </div>
                            </div>

                            <div class="form-group field-key">
                                <label for="co_maker_id_back"><i class="fas fa-id-card"></i> Co-Maker ID (Back) *</label>
                                <input type="file" id="co_maker_id_back" name="co_maker_id_back" accept=".pdf,.jpg,.jpeg,.png" <?php echo $prefill_from_rejected && !empty($rejected_loan['co_maker_id_back_filename']) ? '' : 'required'; ?>>
                                <small>Upload co-maker back ID (PDF, JPG, PNG) up to 5MB.</small>
                                <?php if ($prefill_from_rejected && !empty($rejected_loan['co_maker_id_back_filename'])): ?>
                                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e8; border-radius: 4px; font-size: 0.9rem; color: #155724;">
                                        <i class="fas fa-check-circle"></i> Previous co-maker back ID already uploaded. You can upload a new one or keep the existing.
                                    </div>
                                <?php endif; ?>
                                <div class="file-actions">
                                    <button type="button" id="co_maker_id_back_preview_btn" class="file-preview-btn" style="display: none;" onclick="previewPayslip('co_maker_id_back')">
                                        <i class="fas fa-eye"></i> View Selected Back ID
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="submit-btn">
                                <i class="fas fa-paper-plane"></i> Submit Application
                            </button>
                            <button type="button" class="reset-btn" onclick="resetForm()">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Loan Information Section -->
                <div class="loan-info">
                    <h3><i class="fas fa-info-circle"></i> Loan Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <i class="fas fa-piggy-bank"></i>
                            <div>
                                <strong>Interest Rate</strong>
                                <p>6% per annum</p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-calendar-check"></i>
                            <div>
                                <strong>Payment Terms</strong>
                                <p>6 to 60 months (up to 5 years)</p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-money-bill-wave"></i>
                            <div>
                                <strong>Maximum Loan</strong>
                                <p>₱100,000</p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Processing Time</strong>
                                <p>7 working days</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Requirements Section -->
                <div class="requirements-section">
                    <div class="requirements-header">
                        <h3><i class="fas fa-file-contract"></i> Requirements — should be in two (2) copies</h3>
                    </div>
                    <div class="requirements-content">
                        <div class="requirements-list">
                            <div class="requirement-item">
                                <i class="fas fa-file-download"></i>
                                <div class="requirement-content">
                                    <strong class="requirement-title">Provident Fund Application Form</strong>
                                    <p class="requirement-desc">Please click the link below to download the form.<br><a href="#" onclick="openProvidentFormModal(event)" style="color: #8b0000;"><i class="fas fa-file-download"></i> Download Provident Fund Application Form</a></p>
                                </div>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-envelope"></i>
                                <div class="requirement-content">
                                    <strong class="requirement-title">Letter Request addressed to SDS</strong>
                                    <p class="requirement-desc">Attach Pictures/ Registration Form/ Bills, etc.</p>
                                </div>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-money-bill-wave"></i>
                                <div class="requirement-content">
                                    <strong class="requirement-title">Original Payslip</strong>
                                    <p class="requirement-desc">Latest month available at Cash Unit</p>
                                </div>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-copy"></i>
                                <div class="requirement-content">
                                    <strong class="requirement-title">Photocopy of Latest Payslip</strong>
                                    <p class="requirement-desc">Co-borrower only; should have monthly net pay of Php 5,000.00 after initial computation of loan amortization</p>
                                </div>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-id-card"></i>
                                <div class="requirement-content">
                                    <strong class="requirement-title">Photocopy of Employee Deped No. or any valid government ID</strong>
                                    <p class="requirement-desc">With Certificate of Employment from HR (with three (3) specimen signatures)</p>
                                </div>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-users"></i>
                                <div class="requirement-content">
                                    <strong class="requirement-title">Photocopy of Co-borrowers' Employee Deped No. or any valid government ID</strong>
                                    <p class="requirement-desc">With three (3) specimen signatures</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Net Pay Advice Modal (remaining net pay below ₱5,000) -->
    <div id="netPayAdviceModal" class="netpay-advice-modal" role="dialog" aria-labelledby="netPayAdviceTitle" aria-modal="true" style="display: none;">
        <div class="netpay-advice-backdrop" id="netPayAdviceBackdrop"></div>
        <div class="netpay-advice-content">
            <div class="netpay-advice-header">
                <h3 id="netPayAdviceTitle"><i class="fas fa-exclamation-triangle"></i> Loan amount & term advisory</h3>
                <button type="button" class="netpay-advice-close" id="netPayAdviceClose" aria-label="Close">&times;</button>
            </div>
            <div class="netpay-advice-body">
                <div id="netPayAdviceBorrowerSection">
                    <div class="netpay-advice-remaining" id="netPayAdviceRemainingWrap">
                        <span class="netpay-advice-remaining-label">Remaining Net Pay (Borrower):</span>
                        <strong id="netPayAdviceRemainingAmount">—</strong>
                    </div>
                    <p class="netpay-advice-intro" id="netPayAdviceIntro">Based on your Net Pay, the selected loan amount and term will reduce your remaining net pay below ₱5,000.</p>
                    <p id="netPayAdviceNetPayTooLow" class="netpay-advice-intro" style="display: none;">Your net pay must be above ₱5,000 to meet the minimum remaining net pay requirement.</p>
                    <p id="netPayAdviceNetPayTooLowFollowUp" class="netpay-advice-intro netpay-advice-followup" style="display: none;"></p>
                    <p class="netpay-advice-options" id="netPayAdviceOptionsLabel">You may:</p>
                    <ul class="netpay-advice-list">
                        <li id="netPayAdviceMaxLoan">Borrow up to <strong id="netPayAdviceMaxLoanAmount">—</strong> under the selected term, or</li>
                        <li id="netPayAdviceMinTerm">Extend your payment term to <strong id="netPayAdviceMinTermMonths">—</strong> months to meet the minimum remaining net pay requirement.</li>
                    </ul>
                    <p class="netpay-advice-tip" id="netPayAdviceTipNormal"><i class="fas fa-lightbulb"></i> <strong>Advice:</strong> Kung gusto mong ganito ang payment term, bawasan ang loan amount. Kung gusto mong ganito ang loan amount, dagdagan ang term.</p>
                    <div class="netpay-advice-bottom" id="netPayAdviceBottom">
                        <p id="netPayAdviceBottomText"></p>
                    </div>
                </div>
                <!-- Co-Maker section (same ₱5,000 remaining rule) -->
                <div class="netpay-advice-divider" id="netPayAdviceCoMakerDivider" style="display: none;"></div>
                <div id="netPayAdviceCoMakerSection" style="display: none;">
                    <h4 class="netpay-advice-subtitle"><i class="fas fa-user-friends"></i> Co-Maker</h4>
                    <div class="netpay-advice-remaining" id="netPayAdviceCoMakerRemainingWrap">
                        <span class="netpay-advice-remaining-label">Co-Maker Remaining Net Pay:</span>
                        <strong id="netPayAdviceCoMakerRemainingAmount">—</strong>
                    </div>
                    <p class="netpay-advice-intro" id="netPayAdviceCoMakerIntro" style="display: none;">Based on the co-maker's Net Pay, the selected loan would leave the co-maker's remaining net pay below ₱5,000.</p>
                    <p id="netPayAdviceCoMakerTooLow" class="netpay-advice-intro" style="display: none;">Co-maker's net pay must be above ₱5,000 to meet the minimum remaining net pay requirement.</p>
                    <p id="netPayAdviceCoMakerTooLowFollowUp" class="netpay-advice-intro netpay-advice-followup" style="display: none;"></p>
                    <p class="netpay-advice-options" id="netPayAdviceCoMakerOptionsLabel" style="display: none;">You may:</p>
                    <ul class="netpay-advice-list" id="netPayAdviceCoMakerList" style="display: none;">
                        <li>Borrow up to <strong id="netPayAdviceCoMakerMaxLoanAmount">—</strong> under the selected term, or</li>
                        <li>Extend your payment term to <strong id="netPayAdviceCoMakerMinTermMonths">—</strong> months for the co-maker to meet the minimum remaining net pay.</li>
                    </ul>
                    <p class="netpay-advice-tip" id="netPayAdviceCoMakerTip" style="display: none;"><i class="fas fa-lightbulb"></i> <strong>Advice:</strong> Kung gusto mong ganito ang term, bawasan ang loan amount; kung gusto ang loan amount, dagdagan ang term para ma-meet din ng co-maker ang ₱5,000 remaining.</p>
                    <div class="netpay-advice-bottom" id="netPayAdviceCoMakerBottom" style="display: none;">
                        <p id="netPayAdviceCoMakerBottomText"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileIcon = document.querySelector('.profile-trigger');
            const dropdown = document.getElementById('profileDropdown');
            
            if (!profileIcon.contains(event.target)) {
                dropdown.classList.remove('active');
            }
        });
        
        // Reset form function
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                document.querySelector('.loan-form').reset();
                document.getElementById('loan-computation').style.display = 'none';
            }
        }
        
        const MIN_REMAINING_NET_PAY = 5000;
        const MAX_LOAN_AMOUNT = 100000;
        const MAX_LOAN_TERM = 60;
        const MIN_LOAN_TERM = 6;

        /** Clamp so amount never exceeds max (typing or spinner can bypass HTML max in some browsers). */
        function clampLoanAmountInput() {
            const el = document.getElementById('loan_amount');
            if (!el) return;
            const raw = el.value.trim();
            if (raw === '' || raw === '-') return;
            const v = parseFloat(raw);
            if (!Number.isFinite(v)) return;
            if (v > MAX_LOAN_AMOUNT) {
                el.value = String(MAX_LOAN_AMOUNT);
            }
        }

        // Loan computation function (real-time; includes Borrower & Co-Maker Remaining Net Pay validation)
        function computeLoan() {
            const loanAmount = parseFloat(document.getElementById('loan_amount').value) || 0;
            const loanTerm = parseInt(document.getElementById('loan_term').value) || 0;
            const netPay = parseFloat(document.getElementById('net_pay').value) || 0;
            const coMakerNetPayEl = document.getElementById('co_maker_net_pay');
            const coMakerNetPay = coMakerNetPayEl ? (parseFloat(coMakerNetPayEl.value) || 0) : 0;
            
            if (loanAmount > 0 && loanTerm > 0) {
                const annualInterestRate = 0.06; // 6% per annum
                const monthlyInterestRate = annualInterestRate / 12; // 0.5% per month
                
                // Compute using amortization formula (proper loan calculation)
                const monthlyPayment = loanAmount * (monthlyInterestRate * Math.pow(1 + monthlyInterestRate, loanTerm)) / (Math.pow(1 + monthlyInterestRate, loanTerm) - 1);
                const totalAmount = monthlyPayment * loanTerm;
                const totalInterest = totalAmount - loanAmount;
                
                // For validation: Monthly Amortization = Loan Amount ÷ Payment Term (simple)
                const monthlyAmortization = loanAmount / loanTerm;
                const remainingNetPay = netPay > 0 ? netPay - monthlyAmortization : 0;
                const coMakerRemaining = coMakerNetPay > 0 ? coMakerNetPay - monthlyAmortization : 0;
                
                // Update display
                document.getElementById('principal-display').textContent = '₱' + loanAmount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('monthly-payment-display').textContent = '₱' + monthlyPayment.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('total-amount-display').textContent = '₱' + totalAmount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('total-interest-display').textContent = '₱' + totalInterest.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Remaining Net Pay – Borrower (show when net pay is entered)
                const remainingWrap = document.getElementById('remaining-net-pay-wrap');
                const remainingDisplay = document.getElementById('remaining-net-pay-display');
                if (netPay > 0) {
                    remainingWrap.style.display = '';
                    remainingDisplay.textContent = '₱' + remainingNetPay.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    remainingDisplay.style.color = remainingNetPay < MIN_REMAINING_NET_PAY ? '#b02a37' : '';
                } else {
                    remainingWrap.style.display = 'none';
                }
                
                // Remaining Net Pay – Co-Maker (show when co-maker net pay is entered)
                const cmRemainingWrap = document.getElementById('co-maker-remaining-net-pay-wrap');
                const cmRemainingDisplay = document.getElementById('co-maker-remaining-net-pay-display');
                if (coMakerNetPay > 0 && cmRemainingWrap) {
                    cmRemainingWrap.style.display = '';
                    cmRemainingDisplay.textContent = '₱' + coMakerRemaining.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    cmRemainingDisplay.style.color = coMakerRemaining < MIN_REMAINING_NET_PAY ? '#b02a37' : '';
                } else if (cmRemainingWrap) {
                    cmRemainingWrap.style.display = 'none';
                }
                
                // Generate payment schedule
                generatePaymentSchedule(loanTerm, monthlyPayment);
                
                // Show computation section
                document.getElementById('loan-computation').style.display = 'block';
                
                // Advisory: show modal if Borrower and/or Co-Maker remaining net pay < ₱5,000
                const borrowerIssue = netPay > 0 && remainingNetPay < MIN_REMAINING_NET_PAY;
                const coMakerIssue = coMakerNetPay > 0 && coMakerRemaining < MIN_REMAINING_NET_PAY;
                if (borrowerIssue || coMakerIssue) {
                    showNetPayAdviceModal(netPay, loanAmount, loanTerm, remainingNetPay, coMakerNetPay, coMakerRemaining);
                } else {
                    hideNetPayAdviceModal();
                }
            } else {
                document.getElementById('loan-computation').style.display = 'none';
                document.getElementById('remaining-net-pay-wrap').style.display = 'none';
                const cmWrap = document.getElementById('co-maker-remaining-net-pay-wrap');
                if (cmWrap) cmWrap.style.display = 'none';
                hideNetPayAdviceModal();
            }
        }
        
        function showNetPayAdviceModal(netPay, loanAmount, loanTerm, remainingNetPay, coMakerNetPay, coMakerRemaining) {
            coMakerNetPay = coMakerNetPay != null ? coMakerNetPay : 0;
            coMakerRemaining = coMakerRemaining != null ? coMakerRemaining : 0;
            const fmt = (n) => '₱' + (n != null ? Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—');
            const borrowerIssue = netPay > 0 && remainingNetPay < MIN_REMAINING_NET_PAY;
            const coMakerIssue = coMakerNetPay > 0 && coMakerRemaining < MIN_REMAINING_NET_PAY;
            
            // Borrower section
            const borrowerSection = document.getElementById('netPayAdviceBorrowerSection');
            borrowerSection.style.display = borrowerIssue ? 'block' : 'none';
            if (borrowerIssue) {
                const maxMonthlyPayment = netPay - MIN_REMAINING_NET_PAY;
                document.getElementById('netPayAdviceRemainingAmount').textContent = fmt(remainingNetPay);
                const introEl = document.getElementById('netPayAdviceIntro');
                const tooLowEl = document.getElementById('netPayAdviceNetPayTooLow');
                const tooLowFollowUp = document.getElementById('netPayAdviceNetPayTooLowFollowUp');
                const optionsLabel = document.getElementById('netPayAdviceOptionsLabel');
                const tipNormal = document.getElementById('netPayAdviceTipNormal');
                const bottomWrap = document.getElementById('netPayAdviceBottom');
                const bottomText = document.getElementById('netPayAdviceBottomText');
                if (maxMonthlyPayment <= 0) {
                    introEl.style.display = 'none';
                    tooLowEl.style.display = 'block';
                    tooLowFollowUp.style.display = 'block';
                    tooLowFollowUp.innerHTML = 'Base sa iyong net pay na <strong>' + fmt(netPay) + '</strong>, hindi pa na-meet ang minimum na ₱5,000. Kailangan tumaas ang iyong net pay bago makapag-apply nang pasok sa requirement, o mag-apply para sa mas maliit na loan at mas mahabang term kapag eligible ka na.';
                    optionsLabel.style.display = 'none';
                    document.getElementById('netPayAdviceMaxLoan').style.display = 'none';
                    document.getElementById('netPayAdviceMinTerm').style.display = 'none';
                    tipNormal.style.display = 'none';
                    bottomWrap.style.display = 'none';
                } else {
                    introEl.style.display = 'block';
                    tooLowEl.style.display = 'none';
                    tooLowFollowUp.style.display = 'none';
                    tooLowFollowUp.innerHTML = '';
                    optionsLabel.style.display = 'block';
                    const maxLoanAmount = Math.min(maxMonthlyPayment * loanTerm, MAX_LOAN_AMOUNT);
                    const minTerm = Math.min(Math.ceil(loanAmount / maxMonthlyPayment), MAX_LOAN_TERM);
                    document.getElementById('netPayAdviceMinTermMonths').textContent = minTerm < MIN_LOAN_TERM ? (MIN_LOAN_TERM + ' (minimum)') : String(minTerm);
                    document.getElementById('netPayAdviceMaxLoanAmount').textContent = fmt(maxLoanAmount);
                    document.getElementById('netPayAdviceMaxLoan').style.display = '';
                    document.getElementById('netPayAdviceMinTerm').style.display = '';
                    tipNormal.style.display = 'block';
                    bottomWrap.style.display = 'block';
                    bottomText.innerHTML = 'Base sa iyong net pay na <strong>' + fmt(netPay) + '</strong>, malapit na ang remaining mo sa ₱5,000. Sundin ang mga option sa itaas: bawasan ang loan amount para sa napiling term, o dagdagan ang term para sa napiling loan amount, para ma-meet ang minimum remaining net pay.';
                }
            }
            
            // Co-Maker section (same ₱5,000 rule)
            const cmSection = document.getElementById('netPayAdviceCoMakerSection');
            const cmDivider = document.getElementById('netPayAdviceCoMakerDivider');
            cmSection.style.display = coMakerIssue ? 'block' : 'none';
            cmDivider.style.display = (borrowerIssue && coMakerIssue) ? 'block' : 'none';
            if (coMakerIssue) {
                const cmMaxMonthly = coMakerNetPay - MIN_REMAINING_NET_PAY;
                document.getElementById('netPayAdviceCoMakerRemainingAmount').textContent = fmt(coMakerRemaining);
                document.getElementById('netPayAdviceCoMakerIntro').style.display = 'none';
                document.getElementById('netPayAdviceCoMakerTooLow').style.display = 'none';
                document.getElementById('netPayAdviceCoMakerTooLowFollowUp').style.display = 'none';
                document.getElementById('netPayAdviceCoMakerOptionsLabel').style.display = 'none';
                document.getElementById('netPayAdviceCoMakerList').style.display = 'none';
                document.getElementById('netPayAdviceCoMakerTip').style.display = 'none';
                document.getElementById('netPayAdviceCoMakerBottom').style.display = 'none';
                if (cmMaxMonthly <= 0) {
                    document.getElementById('netPayAdviceCoMakerTooLow').style.display = 'block';
                    document.getElementById('netPayAdviceCoMakerTooLowFollowUp').style.display = 'block';
                    document.getElementById('netPayAdviceCoMakerTooLowFollowUp').innerHTML = 'Base sa co-maker net pay na <strong>' + fmt(coMakerNetPay) + '</strong>, hindi pa na-meet ang minimum na ₱5,000. Kailangan tumaas ang co-maker net pay o magbawas ng loan amount / dagdagan ang term kapag eligible na.';
                } else {
                    document.getElementById('netPayAdviceCoMakerIntro').style.display = 'block';
                    document.getElementById('netPayAdviceCoMakerOptionsLabel').style.display = 'block';
                    document.getElementById('netPayAdviceCoMakerList').style.display = 'block';
                    const cmMaxLoan = Math.min(cmMaxMonthly * loanTerm, MAX_LOAN_AMOUNT);
                    const cmMinTerm = Math.min(Math.ceil(loanAmount / cmMaxMonthly), MAX_LOAN_TERM);
                    document.getElementById('netPayAdviceCoMakerMaxLoanAmount').textContent = fmt(cmMaxLoan);
                    document.getElementById('netPayAdviceCoMakerMinTermMonths').textContent = cmMinTerm < MIN_LOAN_TERM ? (MIN_LOAN_TERM + ' (minimum)') : String(cmMinTerm);
                    document.getElementById('netPayAdviceCoMakerTip').style.display = 'block';
                    document.getElementById('netPayAdviceCoMakerBottom').style.display = 'block';
                    document.getElementById('netPayAdviceCoMakerBottomText').innerHTML = 'Base sa co-maker net pay na <strong>' + fmt(coMakerNetPay) + '</strong>, sundin ang option sa itaas: bawasan ang loan amount o dagdagan ang term para ma-meet din ng co-maker ang minimum remaining net pay na ₱5,000.';
                }
            }
            
            document.getElementById('netPayAdviceModal').style.display = 'flex';
        }
        
        function hideNetPayAdviceModal() {
            document.getElementById('netPayAdviceModal').style.display = 'none';
        }
        
        // Generate payment schedule (first payment = next month after application)
        function generatePaymentSchedule(loanTerm, monthlyPayment) {
            const scheduleGrid = document.getElementById('schedule-grid');
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                          'July', 'August', 'September', 'October', 'November', 'December'];
            const currentDate = new Date();
            let currentMonth = currentDate.getMonth();
            let currentYear = currentDate.getFullYear();
            // First payment starts next month (e.g. apply in Feb → first payment March)
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            
            let scheduleHTML = '';
            
            for (let i = 1; i <= loanTerm; i++) {
                const monthName = months[currentMonth];
                const displayText = i === 1 ? `${monthName} ${currentYear}` : monthName;
                
                scheduleHTML += `
                    <div class="schedule-item">
                        <div class="schedule-month">${displayText}</div>
                        <div class="schedule-amount">₱${monthlyPayment.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                    </div>
                `;
                
                // Move to next month
                currentMonth++;
                if (currentMonth > 11) {
                    currentMonth = 0;
                    currentYear++;
                }
            }
            
            scheduleGrid.innerHTML = scheduleHTML;
        }
        
        // Add event listeners for real-time computation (Loan Amount, Payment Term, Net Pay)
        document.addEventListener('DOMContentLoaded', function() {
            const loanAmountInput = document.getElementById('loan_amount');
            const loanTermSelect = document.getElementById('loan_term');
            const netPayInput = document.getElementById('net_pay');

            if (loanAmountInput) {
                const v0 = parseFloat(loanAmountInput.value);
                if (Number.isFinite(v0) && v0 > MAX_LOAN_AMOUNT) {
                    loanAmountInput.value = String(MAX_LOAN_AMOUNT);
                }
                loanAmountInput.addEventListener('input', function() {
                    clampLoanAmountInput();
                    computeLoan();
                });
                loanAmountInput.addEventListener('wheel', function(e) {
                    if (document.activeElement === this) {
                        e.preventDefault();
                    }
                }, { passive: false });
            }
            loanTermSelect.addEventListener('change', computeLoan);
            if (netPayInput) netPayInput.addEventListener('input', computeLoan);
            const coMakerNetPayInput = document.getElementById('co_maker_net_pay');
            if (coMakerNetPayInput) coMakerNetPayInput.addEventListener('input', computeLoan);
            
            // Net Pay Advice Modal: close on button or backdrop click
            document.getElementById('netPayAdviceClose').addEventListener('click', hideNetPayAdviceModal);
            document.getElementById('netPayAdviceBackdrop').addEventListener('click', hideNetPayAdviceModal);

            // Payslip Preview Modal: close button and overlay click
            document.getElementById('payslipPreviewClose').addEventListener('click', closePayslipPreviewModal);
            document.getElementById('payslipPreviewOverlay').addEventListener('click', function(e) {
                if (e.target.id === 'payslipPreviewOverlay') closePayslipPreviewModal();
            });
            
            // Compute on page load if values are pre-filled
            computeLoan();
        });

        var filePreviewButtonByInput = {
            payslip_file: 'payslip_preview_btn',
            co_maker_payslip_file: 'co_maker_payslip_preview_btn',
            borrower_id_front: 'borrower_id_front_preview_btn',
            borrower_id_back: 'borrower_id_back_preview_btn',
            co_maker_id_front: 'co_maker_id_front_preview_btn',
            co_maker_id_back: 'co_maker_id_back_preview_btn'
        };

        function updatePayslipPreviewButton(inputId) {
            const input = document.getElementById(inputId);
            const buttonId = filePreviewButtonByInput[inputId];
            const button = buttonId ? document.getElementById(buttonId) : null;
            if (input && button) {
                button.style.display = input.files && input.files.length ? 'inline-flex' : 'none';
            }
        }

        let currentPayslipObjectUrl = null;

        function previewPayslip(inputId) {
            const input = document.getElementById(inputId);
            if (!input || !input.files || !input.files.length) {
                return;
            }
            const file = input.files[0];
            const fileUrl = URL.createObjectURL(file);
            if (currentPayslipObjectUrl) {
                URL.revokeObjectURL(currentPayslipObjectUrl);
            }
            currentPayslipObjectUrl = fileUrl;

            var previewTitles = {
                payslip_file: 'Payslip Preview',
                co_maker_payslip_file: 'Co-Maker Payslip Preview',
                borrower_id_front: 'ID (Front) Preview',
                borrower_id_back: 'ID (Back) Preview',
                co_maker_id_front: 'Co-Maker ID (Front) Preview',
                co_maker_id_back: 'Co-Maker ID (Back) Preview'
            };
            const overlay = document.getElementById('payslipPreviewOverlay');
            const bodyEl = document.getElementById('payslipPreviewBody');
            const titleEl = document.getElementById('payslipPreviewTitle');
            const modalTitle = previewTitles[inputId] || 'File Preview';
            titleEl.textContent = modalTitle;

            const isPdf = (file.type || '').toLowerCase() === 'application/pdf';
            if (isPdf) {
                bodyEl.innerHTML = '<iframe src="' + fileUrl + '" class="payslip-preview-iframe" title="' + modalTitle.replace(/"/g, '&quot;') + '"></iframe>';
            } else {
                bodyEl.innerHTML = '<img src="' + fileUrl + '" alt="' + modalTitle.replace(/"/g, '&quot;') + '" class="payslip-preview-image">';
            }

            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePayslipPreviewModal() {
            const overlay = document.getElementById('payslipPreviewOverlay');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            if (currentPayslipObjectUrl) {
                URL.revokeObjectURL(currentPayslipObjectUrl);
                currentPayslipObjectUrl = null;
            }
            const bodyEl = document.getElementById('payslipPreviewBody');
            bodyEl.innerHTML = '';
        }

        ['payslip_file', 'co_maker_payslip_file', 'borrower_id_front', 'borrower_id_back', 'co_maker_id_front', 'co_maker_id_back'].forEach(function(fid) {
            var fe = document.getElementById(fid);
            if (fe) {
                fe.addEventListener('change', function() {
                    updatePayslipPreviewButton(fid);
                });
            }
        });
    </script>

    <!-- Payslip Preview Modal (selected file) -->
    <div id="payslipPreviewOverlay" class="payslip-preview-overlay" aria-hidden="true">
        <div class="payslip-preview-modal" role="dialog" aria-modal="true" onclick="event.stopPropagation()">
            <div class="payslip-modal-header">
                <h3><i class="fas fa-file-image"></i> <span id="payslipPreviewTitle">Payslip Preview</span></h3>
                <button type="button" class="payslip-modal-close" id="payslipPreviewClose" aria-label="Close">×</button>
            </div>
            <div class="payslip-modal-body" id="payslipPreviewBody"></div>
        </div>
    </div>

    <div id="providentFormModal" style="display:none; position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:2100; align-items:center; justify-content:center; padding:1rem;">
        <div style="width:100%; max-width:1100px; background:#fff; border-radius:12px; box-shadow:0 16px 35px rgba(0,0,0,0.22); overflow:hidden; max-height: calc(100vh - 2rem); display:flex; flex-direction:column;">
            <div style="display:flex; align-items:center; justify-content:space-between; background:#8b0000; color:#fff; padding:0.85rem 1rem;">
                <h3 style="margin:0; font-size:1rem;"><i class="fas fa-file-contract"></i> Provident Form Details</h3>
                <button type="button" onclick="closeProvidentFormModal()" style="background:none; border:none; color:#fff; font-size:1.4rem; line-height:1; cursor:pointer;">&times;</button>
            </div>
            <form id="providentPdfForm" action="download_provident_form.php" method="get" target="_blank" style="padding:1rem; overflow-y:auto; flex:1;" onsubmit="closeProvidentFormModal()">
                <div class="provident-form-two-col" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div style="border:1px solid #e5e7eb; border-radius:10px; padding:0.9rem;">
                        <h4 style="margin:0 0 0.8rem; color:#8b0000; font-size:0.95rem;">Borrower Information</h4>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-bottom:0.65rem;">
                            <div>
                                <label for="providentLoanAmount" style="display:block; font-weight:600; margin-bottom:0.35rem;">Loan Amount</label>
                                <input type="number" id="providentLoanAmount" name="loan_amount" min="1" step="0.01" required placeholder="Enter loan amount" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerEmploymentStatus" style="display:block; font-weight:600; margin-bottom:0.35rem;">Employment Status</label>
                                <select id="providentBorrowerEmploymentStatus" name="borrower_employment_status" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                                    <option value="">Select Employment Status</option>
                                    <option value="Permanent">Permanent</option>
                                    <option value="Contractual">Contractual</option>
                                    <option value="Substitute">Substitute</option>
                                    <option value="Provisional">Provisional</option>
                                    <option value="Probationary">Probationary</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1.3fr 1.3fr 0.6fr; gap:0.6rem; margin-bottom:0.65rem;">
                            <div>
                                <label for="providentBorrowerSurname" style="display:block; font-weight:600; margin-bottom:0.35rem;">Surname</label>
                                <input type="text" id="providentBorrowerSurname" name="borrower_surname" value="<?php echo htmlspecialchars($borrower_surname ?? ''); ?>" placeholder="Surname" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerFirstName" style="display:block; font-weight:600; margin-bottom:0.35rem;">First Name</label>
                                <input type="text" id="providentBorrowerFirstName" name="borrower_first_name" value="<?php echo htmlspecialchars($borrower_first_name ?? ''); ?>" placeholder="First name" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerMI" style="display:block; font-weight:600; margin-bottom:0.35rem;">M.I.</label>
                                <input type="text" id="providentBorrowerMI" name="borrower_mi" value="<?php echo htmlspecialchars($borrower_mi ?? ''); ?>" placeholder="M.I." maxlength="3" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-bottom:0.65rem;">
                            <div>
                                <label for="providentBorrowerPosition" style="display:block; font-weight:600; margin-bottom:0.35rem;">Position</label>
                                <input type="text" id="providentBorrowerPosition" name="borrower_position" placeholder="Position" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerEmployeeNo" style="display:block; font-weight:600; margin-bottom:0.35rem;">Employee No.</label>
                                <input type="text" id="providentBorrowerEmployeeNo" name="borrower_employee_no" placeholder="7-digit employee no." inputmode="numeric" maxlength="7" pattern="[0-9]{7}" title="Employee No. must be exactly 7 digits." oninput="enforceDigitsOnly(this, 7)" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr; gap:0.6rem;">
                            <div>
                                <label for="providentBorrowerOffice" style="display:block; font-weight:600; margin-bottom:0.35rem;">Office</label>
                                <input type="text" id="providentBorrowerOffice" name="borrower_office" placeholder="Office" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentBorrowerSchoolUnit" style="display:block; font-weight:600; margin-bottom:0.35rem;">School/Unit</label>
                                <select id="providentBorrowerSchoolUnit" name="borrower_school_unit" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                                    <option value="">Select School/Unit</option>
                                    <?php foreach ($office_school_options as $group => $items): ?>
                                        <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                            <?php foreach ($items as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="providentBorrowerService" style="display:block; font-weight:600; margin-bottom:0.35rem;">Service</label>
                                <input type="text" id="providentBorrowerService" name="borrower_service" placeholder="Service" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentBorrowerBirthDate" style="display:block; font-weight:600; margin-bottom:0.35rem;">Date of Birth</label>
                                <input type="date" id="providentBorrowerBirthDate" name="borrower_birth_date" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerAge" style="display:block; font-weight:600; margin-bottom:0.35rem;">Age</label>
                                <input type="text" id="providentBorrowerAge" name="borrower_age" placeholder="Age" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentBorrowerMonthlySalary" style="display:block; font-weight:600; margin-bottom:0.35rem;">Monthly Salary</label>
                                <input type="text" id="providentBorrowerMonthlySalary" name="borrower_monthly_salary" placeholder="Monthly salary" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerOfficeTel" style="display:block; font-weight:600; margin-bottom:0.35rem;">Office Tel. No.</label>
                                <input type="text" id="providentBorrowerOfficeTel" name="borrower_office_tel_no" placeholder="Office telephone no." style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentBorrowerYearsInService" style="display:block; font-weight:600; margin-bottom:0.35rem;">Years in Service</label>
                                <input type="text" id="providentBorrowerYearsInService" name="borrower_years_in_service" placeholder="Years in service" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentBorrowerMobileNo" style="display:block; font-weight:600; margin-bottom:0.35rem;">Mobile No.</label>
                                <input type="text" id="providentBorrowerMobileNo" name="borrower_mobile_no" placeholder="09XXXXXXXXX" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="Mobile No. must be exactly 11 digits." oninput="enforceDigitsOnly(this, 11)" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                        <div style="margin-top:0.65rem;">
                            <label for="providentBorrowerHomeAddress" style="display:block; font-weight:600; margin-bottom:0.35rem;">Present Address</label>
                            <input type="text" id="providentBorrowerHomeAddress" name="borrower_home_address" placeholder="Present address" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                        </div>
                    </div>
                    <div style="border:1px solid #e5e7eb; border-radius:10px; padding:0.9rem;">
                        <h4 style="margin:0 0 0.8rem; color:#8b0000; font-size:0.95rem;">Co-Maker Information</h4>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-bottom:0.65rem;">
                            <div>
                                <label for="providentCoMakerEmploymentStatus" style="display:block; font-weight:600; margin-bottom:0.35rem;">Employment Status</label>
                                <select id="providentCoMakerEmploymentStatus" name="co_maker_employment_status" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                                    <option value="">Select Employment Status</option>
                                    <option value="Permanent">Permanent</option>
                                    <option value="Contractual">Contractual</option>
                                    <option value="Substitute">Substitute</option>
                                    <option value="Provisional">Provisional</option>
                                    <option value="Probationary">Probationary</option>
                                </select>
                            </div>
                            <div>
                                <label for="providentCoMakerMobileNo" style="display:block; font-weight:600; margin-bottom:0.35rem;">Mobile No.</label>
                                <input type="text" id="providentCoMakerMobileNo" name="co_maker_mobile_no" placeholder="09XXXXXXXXX" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="Mobile No. must be exactly 11 digits." oninput="enforceDigitsOnly(this, 11)" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1.3fr 1.3fr 0.6fr; gap:0.6rem; margin-bottom:0.65rem;">
                            <div>
                                <label for="providentCoMakerSurname" style="display:block; font-weight:600; margin-bottom:0.35rem;">Surname</label>
                                <input type="text" id="providentCoMakerSurname" name="co_maker_surname" placeholder="Surname" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentCoMakerFirstName" style="display:block; font-weight:600; margin-bottom:0.35rem;">First Name</label>
                                <input type="text" id="providentCoMakerFirstName" name="co_maker_first_name" placeholder="First name" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentCoMakerMI" style="display:block; font-weight:600; margin-bottom:0.35rem;">M.I.</label>
                                <input type="text" id="providentCoMakerMI" name="co_maker_mi" placeholder="M.I." maxlength="3" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-bottom:0.65rem;">
                            <div>
                                <label for="providentCoMakerPosition" style="display:block; font-weight:600; margin-bottom:0.35rem;">Position</label>
                                <input type="text" id="providentCoMakerPosition" name="co_maker_position" placeholder="Position" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentCoMakerEmployeeNo" style="display:block; font-weight:600; margin-bottom:0.35rem;">Employee No.</label>
                                <input type="text" id="providentCoMakerEmployeeNo" name="co_maker_employee_no" placeholder="7-digit employee no." inputmode="numeric" maxlength="7" pattern="[0-9]{7}" title="Employee No. must be exactly 7 digits." oninput="enforceDigitsOnly(this, 7)" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr; gap:0.6rem;">
                            <div>
                                <label for="providentCoMakerOffice" style="display:block; font-weight:600; margin-bottom:0.35rem;">Office</label>
                                <input type="text" id="providentCoMakerOffice" name="co_maker_office" placeholder="Office" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentCoMakerBirthDate" style="display:block; font-weight:600; margin-bottom:0.35rem;">Date of Birth</label>
                                <input type="date" id="providentCoMakerBirthDate" name="co_maker_birth_date" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentCoMakerAge" style="display:block; font-weight:600; margin-bottom:0.35rem;">Age</label>
                                <input type="text" id="providentCoMakerAge" name="co_maker_age" placeholder="Age" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentCoMakerMonthlySalary" style="display:block; font-weight:600; margin-bottom:0.35rem;">Monthly Salary</label>
                                <input type="text" id="providentCoMakerMonthlySalary" name="co_maker_monthly_salary" placeholder="Monthly salary" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentCoMakerOfficeTel" style="display:block; font-weight:600; margin-bottom:0.35rem;">Office Tel. No.</label>
                                <input type="text" id="providentCoMakerOfficeTel" name="co_maker_office_tel_no" placeholder="Office telephone no." style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.65rem;">
                            <div>
                                <label for="providentCoMakerYearsInService" style="display:block; font-weight:600; margin-bottom:0.35rem;">Years in Service</label>
                                <input type="text" id="providentCoMakerYearsInService" name="co_maker_years_in_service" placeholder="Years in service" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                            <div>
                                <label for="providentCoMakerHomeAddress" style="display:block; font-weight:600; margin-bottom:0.35rem;">Present Address</label>
                                <input type="text" id="providentCoMakerHomeAddress" name="co_maker_home_address" placeholder="Present address" style="width:100%; padding:0.55rem 0.65rem; border:1px solid #d1d5db; border-radius:8px;">
                            </div>
                        </div>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:0.8rem;">
                    <button type="button" onclick="closeProvidentFormModal()" style="border:none; border-radius:8px; padding:0.5rem 0.9rem; background:#e5e7eb; color:#111827; cursor:pointer;">Cancel</button>
                    <button type="submit" style="border:none; border-radius:8px; padding:0.5rem 0.95rem; background:#8b0000; color:#fff; cursor:pointer;"><i class="fas fa-file-download"></i> Download PDF</button>
                </div>
            </form>
        </div>
    </div>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <script>
        function enforceDigitsOnly(input, maxLength) {
            const digits = (input.value || '').replace(/\D/g, '').slice(0, maxLength);
            if (input.value !== digits) {
                input.value = digits;
            }
        }

        function openProvidentFormModal(event) {
            if (event) event.preventDefault();
            const modal = document.getElementById('providentFormModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeProvidentFormModal() {
            const modal = document.getElementById('providentFormModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

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

        function closeProfileModal() {
            const overlay = document.getElementById('profileModalOverlay');
            const frame = document.getElementById('profileModalFrame');
            overlay.classList.remove('active', 'change-password-modal');
            document.body.style.overflow = 'auto';
            frame.src = '';
        }

        document.addEventListener('click', function(event) {
            if (event.target && event.target.id === 'profileModalOverlay') {
                closeProfileModal();
            }
        });

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
