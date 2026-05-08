<?php
require_once 'config.php';

// Create database if it doesn't exist
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully or already exists<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

$conn->close();

// Connect to the new database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create users table with encryption support
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'borrower', 'accountant') DEFAULT 'borrower',
    profile_photo VARCHAR(255),
    deped_id VARCHAR(50),
    contact_number VARCHAR(20),
    birth_date DATE,
    gender VARCHAR(10),
    civil_status VARCHAR(20),
    home_address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table 'users' created successfully or already exists<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// Create loans table with encryption support
$sql = "CREATE TABLE IF NOT EXISTS loans (
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
    payslip_filename VARCHAR(255) NOT NULL,
    co_maker_payslip_filename VARCHAR(255) NOT NULL,
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

if ($conn->query($sql) === TRUE) {
    echo "Table 'loans' created successfully or already exists<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// Create deductions table
$sql = "CREATE TABLE IF NOT EXISTS deductions (
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
if ($conn->query($sql) === TRUE) {
    echo "Table 'deductions' created successfully or already exists<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// Create fund ledger table
$sql = "CREATE TABLE IF NOT EXISTS fund_ledger (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    entry_type ENUM('collection', 'release', 'adjustment') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT(11) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($sql) === TRUE) {
    echo "Table 'fund_ledger' created successfully or already exists<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

$conn->close();
echo "Database setup completed!";
?>
