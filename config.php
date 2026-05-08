<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'provident_loan_system');

// File storage configuration (deployment-friendly)
// You may override these in server environment variables:
// APP_STORAGE_ROOT, PAYSLIP_UPLOAD_DIR, ID_UPLOAD_DIR, RECEIPT_UPLOAD_DIR
if (!defined('APP_STORAGE_ROOT')) {
    $storage_root = getenv('APP_STORAGE_ROOT');
    if (!$storage_root) {
        $storage_root = __DIR__ . '/storage_private';
    }
    define('APP_STORAGE_ROOT', rtrim($storage_root, "/\\"));
}
if (!defined('PAYSLIP_UPLOAD_DIR')) {
    $payslip_dir = getenv('PAYSLIP_UPLOAD_DIR') ?: (APP_STORAGE_ROOT . '/payslips');
    define('PAYSLIP_UPLOAD_DIR', rtrim($payslip_dir, "/\\"));
}
if (!defined('ID_UPLOAD_DIR')) {
    $id_dir = getenv('ID_UPLOAD_DIR') ?: (APP_STORAGE_ROOT . '/ids');
    define('ID_UPLOAD_DIR', rtrim($id_dir, "/\\"));
}
if (!defined('RECEIPT_UPLOAD_DIR')) {
    $receipt_dir = getenv('RECEIPT_UPLOAD_DIR') ?: (APP_STORAGE_ROOT . '/receipts');
    define('RECEIPT_UPLOAD_DIR', rtrim($receipt_dir, "/\\"));
}

// Start session
session_start();

// Keep PHP/MySQL timestamps consistent across local and deployment.
// Deployment servers often default to UTC, which causes reviewed_at/released_at drift.
$app_timezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
date_default_timezone_set($app_timezone);

// Performance flags
if (!defined('AUDIT_LOG_VIEWS')) {
    define('AUDIT_LOG_VIEWS', false);
}
if (!defined('SCHEMA_CHECK_TTL_SECONDS')) {
    define('SCHEMA_CHECK_TTL_SECONDS', 21600); // 6 hours
}

// Create database connection with port 3307
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '');

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === TRUE) {
    // Database created or already exists
} else {
    // Handle error silently for now
}

// Select the database
$conn->select_db(DB_NAME);

// Align MySQL session timezone with application timezone.
// Use fixed offset fallback to avoid requiring timezone tables in shared hosting.
$conn->query("SET time_zone = '+08:00'");

// Skip heavy schema checks on most requests
$schema_check_at = $_SESSION['schema_checked_at'] ?? 0;
$should_check_schema = (time() - (int) $schema_check_at) > SCHEMA_CHECK_TTL_SECONDS;

// Create users table if it doesn't exist
$create_table_sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'borrower', 'accountant') DEFAULT 'borrower',
    deped_id VARCHAR(50),
    contact_number VARCHAR(20),
    birth_date DATE,
    gender VARCHAR(10),
    civil_status VARCHAR(20),
    home_address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($create_table_sql);

if ($should_check_schema) {
    // Add registration columns to users if missing (for old DBs from SQL dump)
    $user_extra_columns = [
        'first_name'     => 'VARCHAR(50)',
        'middle_name'    => 'VARCHAR(50)',
        'surname'        => 'VARCHAR(50)',
        'deped_id'       => 'VARCHAR(50)',
        'contact_number' => 'VARCHAR(20)',
        'birth_date'     => 'DATE',
        'gender'         => 'VARCHAR(10)',
        'civil_status'   => 'VARCHAR(20)',
        'home_address'   => 'TEXT',
        'last_login_at'  => 'TIMESTAMP NULL DEFAULT NULL'
    ];
    foreach ($user_extra_columns as $col => $type) {
        $check_column_sql = "SHOW COLUMNS FROM users LIKE '$col'";
        $result = $conn->query($check_column_sql);
        if ($result && $result->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col $type NULL");
        }
    }

    // Check if role column exists, if not, add it
    $check_column_sql = "SHOW COLUMNS FROM users LIKE 'role'";
    $result = $conn->query($check_column_sql);
    if ($result->num_rows == 0) {
        $alter_sql = "ALTER TABLE users ADD COLUMN role ENUM('admin', 'borrower', 'accountant') DEFAULT 'borrower'";
        $conn->query($alter_sql);
    } else {
        $role_type = $result->fetch_assoc()['Type'] ?? '';
        if (strpos($role_type, "'accountant'") === false && strpos($role_type, "'accounting'") !== false) {
            $conn->query("ALTER TABLE users MODIFY role ENUM('admin', 'borrower', 'accounting', 'accountant') DEFAULT 'borrower'");
            $conn->query("UPDATE users SET role = 'accountant' WHERE role = 'accounting'");
            $conn->query("ALTER TABLE users MODIFY role ENUM('admin', 'borrower', 'accountant') DEFAULT 'borrower'");
        } else {
            $conn->query("ALTER TABLE users MODIFY role ENUM('admin', 'borrower', 'accountant') DEFAULT 'borrower'");
            $conn->query("UPDATE users SET role = 'accountant' WHERE role = 'accounting'");
        }
    }
}

// Create loans table if it doesn't exist
$create_loans_sql = "CREATE TABLE IF NOT EXISTS loans (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    loan_amount DECIMAL(10,2) NOT NULL,
    loan_purpose TEXT NOT NULL,
    loan_term INT NOT NULL,
    net_pay DECIMAL(10,2) NOT NULL,
    school_assignment VARCHAR(255) NOT NULL,
    position VARCHAR(255) NOT NULL,
    salary_grade VARCHAR(10) NOT NULL,
    employment_status VARCHAR(50) NOT NULL,
    co_maker_full_name VARCHAR(150) NOT NULL,
    co_maker_position VARCHAR(150) NOT NULL,
    co_maker_school_assignment VARCHAR(255) NOT NULL,
    co_maker_net_pay DECIMAL(10,2) NOT NULL,
    co_maker_employment_status VARCHAR(50) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by_id INT(11) NULL,
    reviewed_by_role VARCHAR(50) NULL,
    reviewed_by_name VARCHAR(150) NULL,
    reviewed_at TIMESTAMP NULL,
    monthly_payment DECIMAL(10,2),
    total_amount DECIMAL(10,2),
    total_interest DECIMAL(10,2),
    released_at TIMESTAMP NULL,
    application_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($create_loans_sql);

// Create deductions table for payroll collections
$create_deductions_sql = "CREATE TABLE IF NOT EXISTS deductions (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    loan_id INT(11) NOT NULL,
    borrower_id INT(11) NOT NULL,
    deduction_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    posted_by INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
)";
$conn->query($create_deductions_sql);

// Add receipt_filename to deductions if missing (for payment receipt uploads)
if ($should_check_schema) {
    $dc = $conn->query("SHOW COLUMNS FROM deductions LIKE 'receipt_filename'");
    if ($dc && $dc->num_rows === 0) {
        $conn->query("ALTER TABLE deductions ADD COLUMN receipt_filename VARCHAR(255) NULL DEFAULT NULL");
    }
}

// Skip months: when DepEd approves "stop hulog" for a month, timeline shifts (e.g. Feb–Nov becomes Mar–Dec)
$create_skip_months_sql = "CREATE TABLE IF NOT EXISTS loan_skip_months (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    loan_id INT(11) NOT NULL,
    skip_ym CHAR(7) NOT NULL COMMENT 'Year-month YYYY-MM',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11) NULL,
    UNIQUE KEY uk_loan_skip (loan_id, skip_ym),
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
)";
$conn->query($create_skip_months_sql);

// Create fund ledger table for fund health reporting
$create_fund_ledger_sql = "CREATE TABLE IF NOT EXISTS fund_ledger (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    entry_type ENUM('collection', 'release', 'adjustment') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT(11) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($create_fund_ledger_sql);

// Table for OTP verification fallback (when session is lost)
$create_reg_pending_sql = "CREATE TABLE IF NOT EXISTS registration_pending (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at INT(11) NOT NULL,
    reg_data TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
)";
$conn->query($create_reg_pending_sql);

// Table for forgot-password OTP
$create_pwd_reset_sql = "CREATE TABLE IF NOT EXISTS password_reset_pending (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
)";
$conn->query($create_pwd_reset_sql);

// Add new loan columns if missing (migration for existing DBs)
$loan_columns = [
    'net_pay' => "ALTER TABLE loans ADD COLUMN net_pay DECIMAL(10,2) NOT NULL",
    'co_maker_full_name' => "ALTER TABLE loans ADD COLUMN co_maker_full_name VARCHAR(150) NOT NULL",
    'co_maker_position' => "ALTER TABLE loans ADD COLUMN co_maker_position VARCHAR(150) NOT NULL",
    'co_maker_school_assignment' => "ALTER TABLE loans ADD COLUMN co_maker_school_assignment VARCHAR(255) NOT NULL",
    'co_maker_net_pay' => "ALTER TABLE loans ADD COLUMN co_maker_net_pay DECIMAL(10,2) NOT NULL",
    'co_maker_employment_status' => "ALTER TABLE loans ADD COLUMN co_maker_employment_status VARCHAR(50) NOT NULL",
    'co_maker_email' => "ALTER TABLE loans ADD COLUMN co_maker_email VARCHAR(150) NOT NULL DEFAULT ''",
    'payslip_filename' => "ALTER TABLE loans ADD COLUMN payslip_filename VARCHAR(255) NOT NULL DEFAULT ''",
    'co_maker_payslip_filename' => "ALTER TABLE loans ADD COLUMN co_maker_payslip_filename VARCHAR(255) NOT NULL DEFAULT ''",
    'reviewed_by_id' => "ALTER TABLE loans ADD COLUMN reviewed_by_id INT(11) NULL",
    'reviewed_by_role' => "ALTER TABLE loans ADD COLUMN reviewed_by_role VARCHAR(50) NULL",
    'reviewed_by_name' => "ALTER TABLE loans ADD COLUMN reviewed_by_name VARCHAR(150) NULL",
    'reviewed_at' => "ALTER TABLE loans ADD COLUMN reviewed_at TIMESTAMP NULL",
    'monthly_payment' => "ALTER TABLE loans ADD COLUMN monthly_payment DECIMAL(10,2) NULL",
    'total_amount' => "ALTER TABLE loans ADD COLUMN total_amount DECIMAL(10,2) NULL",
    'total_interest' => "ALTER TABLE loans ADD COLUMN total_interest DECIMAL(10,2) NULL",
    'released_at' => "ALTER TABLE loans ADD COLUMN released_at TIMESTAMP NULL",
    'previous_loan_id' => "ALTER TABLE loans ADD COLUMN previous_loan_id INT(11) NULL",
    'offset_amount' => "ALTER TABLE loans ADD COLUMN offset_amount DECIMAL(10,2) NULL",
    'borrower_date_of_birth' => "ALTER TABLE loans ADD COLUMN borrower_date_of_birth DATE NULL",
    'borrower_years_of_service' => "ALTER TABLE loans ADD COLUMN borrower_years_of_service INT NULL",
    'borrower_id_front_filename' => "ALTER TABLE loans ADD COLUMN borrower_id_front_filename VARCHAR(255) NOT NULL DEFAULT ''",
    'borrower_id_back_filename' => "ALTER TABLE loans ADD COLUMN borrower_id_back_filename VARCHAR(255) NOT NULL DEFAULT ''",
    'co_maker_date_of_birth' => "ALTER TABLE loans ADD COLUMN co_maker_date_of_birth DATE NULL",
    'co_maker_years_of_service' => "ALTER TABLE loans ADD COLUMN co_maker_years_of_service INT NULL",
    'co_maker_id_front_filename' => "ALTER TABLE loans ADD COLUMN co_maker_id_front_filename VARCHAR(255) NOT NULL DEFAULT ''",
    'co_maker_id_back_filename' => "ALTER TABLE loans ADD COLUMN co_maker_id_back_filename VARCHAR(255) NOT NULL DEFAULT ''",
    'is_existing_loan' => "ALTER TABLE loans ADD COLUMN is_existing_loan TINYINT(1) NOT NULL DEFAULT 0"
];

if ($should_check_schema) {
    foreach ($loan_columns as $column => $alter_sql) {
        $check_column_sql = "SHOW COLUMNS FROM loans LIKE '$column'";
        $result = $conn->query($check_column_sql);
        if ($result && $result->num_rows == 0) {
            $conn->query($alter_sql);
        }
    }

    // Relax old columns if they exist from previous schema
    $legacy_columns = ['monthly_income', 'basic_salary'];
    foreach ($legacy_columns as $column) {
        $check_column_sql = "SHOW COLUMNS FROM loans LIKE '$column'";
        $result = $conn->query($check_column_sql);
        if ($result && $result->num_rows == 1) {
            $conn->query("ALTER TABLE loans MODIFY $column DECIMAL(10,2) NULL");
        }
    }

    // Ensure loans.status enum includes 'completed' for 30% re-apply (old loan closed)
    $status_row = $conn->query("SHOW COLUMNS FROM loans WHERE Field = 'status'")->fetch_assoc();
    $status_type = $status_row['Type'] ?? '';
    if (stripos($status_type, "'completed'") === false) {
        $conn->query("ALTER TABLE loans MODIFY status ENUM('pending','approved','rejected','completed') DEFAULT 'pending'");
    }
}

// Create audit logs table if it doesn't exist
$create_audit_logs_sql = "CREATE TABLE IF NOT EXISTS audit_logs (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    actor_id INT(11) NULL,
    actor_name VARCHAR(150) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    action_type VARCHAR(20) NOT NULL,
    target VARCHAR(255) NULL,
    page_name VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_actor_id (actor_id),
    INDEX idx_action_type (action_type),
    INDEX idx_user_role (user_role),
    INDEX idx_page_name (page_name)
)";
$conn->query($create_audit_logs_sql);

// Create notifications table if it doesn't exist
$create_notifications_sql = "CREATE TABLE IF NOT EXISTS notifications (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(30) DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($create_notifications_sql);

// Ensure audit log columns exist (for older installs)
$audit_columns = [
    'actor_id' => 'INT(11) NULL',
    'actor_name' => 'VARCHAR(150) NOT NULL',
    'user_role' => 'VARCHAR(50) NOT NULL',
    'action_type' => 'VARCHAR(20) NOT NULL',
    'target' => 'VARCHAR(255) NULL',
    'page_name' => 'VARCHAR(120) NOT NULL',
    'description' => 'TEXT NOT NULL',
    'ip_address' => 'VARCHAR(45) NULL',
    'user_agent' => 'VARCHAR(255) NULL',
    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
];
if ($should_check_schema) {
    foreach ($audit_columns as $col => $type) {
        $check_column_sql = "SHOW COLUMNS FROM audit_logs LIKE '$col'";
        $result = $conn->query($check_column_sql);
        if ($result && $result->num_rows == 0) {
            $conn->query("ALTER TABLE audit_logs ADD COLUMN $col $type");
        }
    }
}

if ($should_check_schema) {
    $notification_columns = [
        'user_id' => 'INT(11) NOT NULL',
        'title' => 'VARCHAR(150) NOT NULL',
        'message' => 'TEXT NOT NULL',
        'type' => "VARCHAR(30) DEFAULT 'info'",
        'is_read' => 'TINYINT(1) DEFAULT 0',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
    ];
    foreach ($notification_columns as $col => $type) {
        $check_column_sql = "SHOW COLUMNS FROM notifications LIKE '$col'";
        $result = $conn->query($check_column_sql);
        if ($result && $result->num_rows == 0) {
            $conn->query("ALTER TABLE notifications ADD COLUMN $col $type");
        }
    }
}

if (!function_exists('audit_get_client_ip')) {
    function audit_get_client_ip() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }
}

if (!function_exists('audit_format_page_name')) {
    function audit_format_page_name($script_name) {
        $base = basename($script_name, '.php');
        $label = str_replace('_', ' ', $base);
        return ucwords(trim($label));
    }
}

if (!function_exists('log_audit')) {
    function log_audit($conn, $action_type, $description, $page_name = null, $target = null, $actor_id = null, $actor_name = null, $actor_role = null) {
        if (!$conn) {
            return false;
        }
        $action_type = strtoupper(trim($action_type));
        $page_name = $page_name ?: audit_format_page_name($_SERVER['SCRIPT_NAME'] ?? 'unknown');
        $actor_id = $actor_id ?? ($_SESSION['user_id'] ?? null);
        $actor_name = $actor_name ?? ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Guest'));
        $actor_role = $actor_role ?? ($_SESSION['role'] ?? ($_SESSION['user_role'] ?? null));
        if (!$actor_role) {
            $actor_role = $actor_id ? 'user' : 'guest';
        }
        $ip_address = audit_get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_name, user_role, action_type, target, page_name, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            "issssssss",
            $actor_id,
            $actor_name,
            $actor_role,
            $action_type,
            $target,
            $page_name,
            $description,
            $ip_address,
            $user_agent
        );
        $stmt->execute();
        $stmt->close();
        return true;
    }
}

if (!defined('AUDIT_LOGGING_DISABLED')) {
    if (isset($_SESSION['user_id']) && (!isset($_SESSION['role']) || !isset($_SESSION['full_name']))) {
        $stmt = $conn->prepare("SELECT role, full_name, username FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($info) {
                $_SESSION['role'] = $info['role'] ?? ($_SESSION['username'] === 'admin' ? 'admin' : 'borrower');
                $_SESSION['full_name'] = $info['full_name'] ?? ($_SESSION['username'] ?? 'User');
                if (!isset($_SESSION['username']) && !empty($info['username'])) {
                    $_SESSION['username'] = $info['username'];
                }
            }
        }
    }
    $request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (AUDIT_LOG_VIEWS && $request_method === 'GET') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $skip_auto = in_array($script, ['audit_trail_data.php'], true);
        if (!$skip_auto) {
            $page_label = audit_format_page_name($_SERVER['SCRIPT_NAME'] ?? 'page');
            log_audit($conn, 'VIEW', 'Viewed ' . $page_label, $page_label, $page_label);
        }
    }
}

if ($should_check_schema) {
    $_SESSION['schema_checked_at'] = time();
}

if (!function_exists('user_is_accountant_role')) {
    /**
     * True for accountant staff, including legacy role value 'accounting' in some databases.
     */
    function user_is_accountant_role(?string $role): bool {
        return in_array($role ?? '', ['accountant', 'accounting'], true);
    }
}
