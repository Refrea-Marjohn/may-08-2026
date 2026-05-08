<?php
require_once 'config.php';
require_once 'includes/office_school_options.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$user_sql = "SELECT username, email, full_name, created_at, profile_photo, deped_id FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$stmt->close();
$profile_photo = $user_data['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($loan_id === 0) {
    header("Location: loan_history.php");
    exit();
}

// Fetch loan details and verify it belongs to the current user and is rejected
$loan_sql = "SELECT * FROM loans WHERE id = ? AND user_id = ? AND status = 'rejected'";
$stmt = $conn->prepare($loan_sql);
$stmt->bind_param("ii", $loan_id, $user_id);
$stmt->execute();
$loan_result = $stmt->get_result();
$loan = $loan_result->fetch_assoc();
$stmt->close();

if (!$loan) {
    header("Location: loan_history.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $loan_amount = $_POST['loan_amount'];
    $loan_purpose = $_POST['loan_purpose'];
    $loan_term = $_POST['loan_term'];
    $net_pay = $_POST['net_pay'];
    $school_assignment = $_POST['school_assignment'];
    $position = $_POST['position'];
    $salary_grade = $_POST['salary_grade'];
    $employment_status = $_POST['employment_status'];
    $co_maker_full_name = $_POST['co_maker_full_name'];
    $co_maker_position = $_POST['co_maker_position'];
    $co_maker_school_assignment = $_POST['co_maker_school_assignment'];
    $co_maker_net_pay = $_POST['co_maker_net_pay'];
    $co_maker_employment_status = $_POST['co_maker_employment_status'];
    
    // Handle file uploads
    $payslip_filename = $loan['payslip_filename']; // Keep existing if not uploaded
    $co_maker_payslip_filename = $loan['co_maker_payslip_filename']; // Keep existing if not uploaded
    
    if (isset($_FILES['payslip']) && $_FILES['payslip']['error'] == 0) {
        $payslip_filename = uploadFile('payslip', $user_id);
    }
    
    if (isset($_FILES['co_maker_payslip']) && $_FILES['co_maker_payslip']['error'] == 0) {
        $co_maker_payslip_filename = uploadFile('co_maker_payslip', $user_id);
    }
    
    // Update loan in database
    $update_sql = "UPDATE loans SET 
        loan_amount = ?, loan_purpose = ?, loan_term = ?, net_pay = ?, 
        school_assignment = ?, position = ?, salary_grade = ?, employment_status = ?,
        co_maker_full_name = ?, co_maker_position = ?, co_maker_school_assignment = ?, 
        co_maker_net_pay = ?, co_maker_employment_status = ?, payslip_filename = ?, 
        co_maker_payslip_filename = ?, status = 'pending', admin_comment = NULL,
        reviewed_by_id = NULL, reviewed_by_role = NULL, reviewed_by_name = NULL, reviewed_at = NULL
        WHERE id = ? AND user_id = ?";
    
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("dsissssssdsdsii", 
        $loan_amount, $loan_purpose, $loan_term, $net_pay,
        $school_assignment, $position, $salary_grade, $employment_status,
        $co_maker_full_name, $co_maker_position, $co_maker_school_assignment,
        $co_maker_net_pay, $co_maker_employment_status, $payslip_filename,
        $co_maker_payslip_filename, $loan_id, $user_id
    );
    
    if ($stmt->execute()) {
        $success = "Loan application updated successfully! It has been resubmitted for review.";
        log_audit($conn, 'UPDATE', "Resubmitted rejected loan application #{$loan_id}", 'Edit Loan', "Loan #{$loan_id}");
        
        // Redirect to loan history after successful update
        header("Location: loan_history.php?success=" . urlencode($success));
        exit();
    } else {
        $error = "Error updating loan application. Please try again.";
    }
    $stmt->close();
}

function uploadFile($field_name, $user_id) {
    global $conn;
    
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] != 0) {
        return null;
    }
    
    $file = $_FILES[$field_name];
    $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_types)) {
        throw new Exception("Invalid file type. Only PDF, DOC, DOCX, JPG, JPEG, PNG files are allowed.");
    }
    
    if ($file['size'] > $max_size) {
        throw new Exception("File size too large. Maximum size is 5MB.");
    }
    
    $uploads_dir = 'uploads';
    if (!is_dir($uploads_dir)) {
        mkdir($uploads_dir, 0755, true);
    }
    
    $filename = $field_name . '_' . $user_id . '_' . time() . '.' . $file_extension;
    $filepath = $uploads_dir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filepath;
    } else {
        throw new Exception("Error uploading file.");
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Loan Application - DepEd Loan System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
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
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 192px; /* 80% of 250px */
            height: 100vh;
            background: #8b0000;
            color: white;
            padding: 2rem 1rem;
            overflow-y: auto;
            z-index: 1000;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-weight: bold;
            color: #8b0000;
        }
        
        .sidebar-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }
        
        .sidebar-menu a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .sidebar-menu i {
            margin-right: 0.75rem;
            width: 20px;
        }
        
        .main-content {
            margin-left: 192px; /* 80% of 250px */
            padding: 2rem;
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
            z-index: 999;
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
        }
        
        .icon-button {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 1.2rem;
            position: relative;
        }
        
        .profile-trigger {
            display: inline-flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            position: relative;
            cursor: pointer;
        }
        
        .profile-trigger-main {
            display: inline-flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.35rem;
            width: max-content;
        }
        
        .profile-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #8b0000;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .profile-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #495057;
            line-height: 0;
        }
        
        .profile-chevron svg {
            display: block;
        }
        
        .page-header {
            margin-top: 80px;
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #666;
            font-size: 1.1rem;
        }
        
        .form-container {
            background: white;
            border-radius: 14px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }
        
        .rejection-notice {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }
        
        .rejection-notice h3 {
            color: #721c24;
            margin-bottom: 0.5rem;
        }
        
        .rejection-comment {
            background: white;
            padding: 0.75rem;
            border-radius: 4px;
            margin-top: 0.5rem;
            border-left: 3px solid #721c24;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #8b0000;
        }
        
        .form-group .required {
            color: #dc3545;
        }
        
        .form-section {
            margin-bottom: 2rem;
        }
        
        .form-section h3 {
            color: #333;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #8b0000;
        }
        
        .file-upload {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: border-color 0.3s ease;
        }
        
        .file-upload:hover {
            border-color: #8b0000;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: #8b0000;
            color: white;
        }
        
        .btn-primary:hover {
            background: #6b0000;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .navbar {
                left: 0;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== App shell (match admin_dashboard/reports) ===== */
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
            .welcome-message { min-width: 0; flex: 1 1 auto !important; }
            .profile-chevron { display: none; }

            .sidebar {
                --mobile-sidebar-width: clamp(200px, 62vw, 240px);
                width: var(--mobile-sidebar-width) !important;
                transform: translateX(calc(-1 * var(--mobile-sidebar-width) - 12px)) !important;
                transition: transform 0.24s ease !important;
                border-radius: 0 16px 16px 0;
                box-shadow: 0 20px 42px rgba(15, 23, 42, 0.24);
                padding-top: 2.5rem;
            }

            body.sidebar-open .sidebar {
                transform: translateX(0) !important;
            }

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
                z-index: 999;
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

            .main-content {
                margin-left: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <button id="sidebarClose" class="sidebar-close" type="button" aria-label="Hide sidebar">
            <i class="fas fa-times"></i>
        </button>
        <div class="sidebar-header">
            <div class="sidebar-logo">DL</div>
            <div class="sidebar-title">DepEd Loan</div>
        </div>
        <ul class="sidebar-menu">
            <li><a href="borrower_dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="apply_loan.php"><i class="fas fa-plus-circle"></i> Apply for Loan</a></li>
            <li><a href="my_loans.php"><i class="fas fa-list"></i> My Loans</a></li>
            <li><a href="loan_history.php" class="active"><i class="fas fa-history"></i> Loan History</a></li>
            <li><a href="profile_update.php"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="support.php"><i class="fas fa-headset"></i> Support</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <nav class="navbar">
            <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Toggle menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <div class="welcome-message">
                Welcome, <strong><?php echo htmlspecialchars($user_data['full_name']); ?></strong>
            </div>
            <div class="nav-icons">
                <button class="icon-button" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                </button>
                <div class="profile-trigger" title="Profile menu" onclick="toggleProfileDropdown()">
                    <div class="profile-trigger-main">
                    <div class="profile-icon">
                    <?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?>
                    </div>
                    <span class="profile-chevron" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
                    </div>
                </div>
            </div>
        </nav>
        <button id="sidebarBackdrop" class="sidebar-backdrop" type="button" aria-label="Close menu"></button>
        
        <div class="page-header">
            <h1 class="page-title">Edit Loan Application</h1>
            <p class="page-subtitle">Update and resubmit your rejected loan application</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="form-container">
            <?php if (!empty($loan['admin_comment'])): ?>
                <div class="rejection-notice">
                    <h3><i class="fas fa-exclamation-triangle"></i> Previous Rejection Reason</h3>
                    <p>Please review the reviewer's comment below and make the necessary corrections to your application:</p>
                    <div class="rejection-comment">
                        <?php echo htmlspecialchars($loan['admin_comment']); ?>
                        <?php if (!empty($loan['reviewed_by_name'])): ?>
                            <div style="margin-top: 0.5rem; font-size: 0.9rem; color: #6c757d;">
                                — <?php echo htmlspecialchars($loan['reviewed_by_name']); ?>
                                <?php if (!empty($loan['reviewed_by_role'])): ?>
                                    (<?php echo htmlspecialchars(ucfirst($loan['reviewed_by_role'])); ?>)
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-section">
                    <h3>Loan Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="loan_amount">Loan Amount <span class="required">*</span></label>
                            <input type="number" id="loan_amount" name="loan_amount" value="<?php echo htmlspecialchars($loan['loan_amount']); ?>" required step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="loan_term">Loan Term (months) <span class="required">*</span></label>
                            <input type="number" id="loan_term" name="loan_term" value="<?php echo htmlspecialchars($loan['loan_term']); ?>" required min="1" max="60">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="loan_purpose">Loan Purpose <span class="required">*</span></label>
                        <textarea id="loan_purpose" name="loan_purpose" rows="3" required><?php echo htmlspecialchars($loan['loan_purpose']); ?></textarea>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Employment Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="net_pay">Net Monthly Pay <span class="required">*</span></label>
                            <input type="number" id="net_pay" name="net_pay" value="<?php echo htmlspecialchars($loan['net_pay']); ?>" required step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="school_assignment">Office/School Assignment <span class="required">*</span></label>
                            <select id="school_assignment" name="school_assignment" required>
                                <option value="">Select office or school</option>
                                <?php foreach ($office_school_options as $group => $items): ?>
                                    <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                        <?php foreach ($items as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($loan['school_assignment'] === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="position">Position <span class="required">*</span></label>
                            <input type="text" id="position" name="position" value="<?php echo htmlspecialchars($loan['position']); ?>" required placeholder="Enter position">
                        </div>
                        <div class="form-group">
                            <label for="salary_grade">Salary Grade <span class="required">*</span></label>
                            <input type="text" id="salary_grade" name="salary_grade" value="<?php echo htmlspecialchars($loan['salary_grade']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="employment_status">Employment Status <span class="required">*</span></label>
                            <select id="employment_status" name="employment_status" required>
                                <option value="">Select Status</option>
                                <option value="Permanent" <?php echo $loan['employment_status'] == 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                                <option value="Contractual" <?php echo $loan['employment_status'] == 'Contractual' ? 'selected' : ''; ?>>Contractual</option>
                                <option value="Substitute" <?php echo $loan['employment_status'] == 'Substitute' ? 'selected' : ''; ?>>Substitute</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Co-Maker Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="co_maker_full_name">Co-Maker Full Name <span class="required">*</span></label>
                            <input type="text" id="co_maker_full_name" name="co_maker_full_name" value="<?php echo htmlspecialchars($loan['co_maker_full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="co_maker_position">Co-Maker Position <span class="required">*</span></label>
                            <input type="text" id="co_maker_position" name="co_maker_position" value="<?php echo htmlspecialchars($loan['co_maker_position']); ?>" required placeholder="Enter co-maker position">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_school_assignment">Co-Maker Office/School Assignment <span class="required">*</span></label>
                            <select id="co_maker_school_assignment" name="co_maker_school_assignment" required>
                                <option value="">Select office or school</option>
                                <?php foreach ($office_school_options as $group => $items): ?>
                                    <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                        <?php foreach ($items as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($loan['co_maker_school_assignment'] === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="co_maker_net_pay">Co-Maker Net Monthly Pay <span class="required">*</span></label>
                            <input type="number" id="co_maker_net_pay" name="co_maker_net_pay" value="<?php echo htmlspecialchars($loan['co_maker_net_pay']); ?>" required step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="co_maker_employment_status">Co-Maker Employment Status <span class="required">*</span></label>
                            <select id="co_maker_employment_status" name="co_maker_employment_status" required>
                                <option value="">Select Status</option>
                                <option value="Permanent" <?php echo $loan['co_maker_employment_status'] == 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                                <option value="Contractual" <?php echo $loan['co_maker_employment_status'] == 'Contractual' ? 'selected' : ''; ?>>Contractual</option>
                                <option value="Substitute" <?php echo $loan['co_maker_employment_status'] == 'Substitute' ? 'selected' : ''; ?>>Substitute</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Document Uploads</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="payslip">Your Payslip</label>
                            <div class="file-upload">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #8b0000; margin-bottom: 0.5rem;"></i>
                                <p>Click to upload or drag and drop</p>
                                <p style="font-size: 0.9rem; color: #666;">PDF, DOC, DOCX, JPG, JPEG, PNG (MAX. 5MB)</p>
                                <input type="file" id="payslip" name="payslip" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display: none;">
                                <?php if (!empty($loan['payslip_filename'])): ?>
                                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #28a745;">
                                        <i class="fas fa-check-circle"></i> Current file: <?php echo basename($loan['payslip_filename']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="co_maker_payslip">Co-Maker Payslip</label>
                            <div class="file-upload">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #8b0000; margin-bottom: 0.5rem;"></i>
                                <p>Click to upload or drag and drop</p>
                                <p style="font-size: 0.9rem; color: #666;">PDF, DOC, DOCX, JPG, JPEG, PNG (MAX. 5MB)</p>
                                <input type="file" id="co_maker_payslip" name="co_maker_payslip" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display: none;">
                                <?php if (!empty($loan['co_maker_payslip_filename'])): ?>
                                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #28a745;">
                                        <i class="fas fa-check-circle"></i> Current file: <?php echo basename($loan['co_maker_payslip_filename']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="loan_history.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Resubmit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // File upload handling
        document.querySelectorAll('.file-upload').forEach(uploadArea => {
            const fileInput = uploadArea.querySelector('input[type="file"]');
            
            uploadArea.addEventListener('click', () => {
                fileInput.click();
            });
            
            fileInput.addEventListener('change', (e) => {
                const fileName = e.target.files[0]?.name;
                if (fileName) {
                    uploadArea.querySelector('p').textContent = fileName;
                    uploadArea.style.borderColor = '#28a745';
                }
            });
        });
        
        function toggleNotifications() {
            // Implementation for notifications toggle
        }
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
