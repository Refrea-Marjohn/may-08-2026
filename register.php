<?php
require_once 'config.php';
$error = '';
$success = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - DepEd Provident Loan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        
        .split-container {
            display: flex;
            width: 100%;
            height: 100vh;
            overflow: hidden;
        }
        
        .left-side {
            width: 60%;
            background: url('loginbg.jpg') center/cover;
            /* background-attachment: fixed; */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 3rem;
            position: relative;
            overflow: hidden;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
        }
        
        .left-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
        }
        
        .left-content {
            text-align: center;
            z-index: 1;
            max-width: 600px;
        }
        
        .logo-container {
            margin-bottom: 2rem;
        }
        
        .logo {
            width: 120px;
            height: 120px;
            object-fit: contain;
            border-radius: 50%;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            background: white;
            padding: 10px;
        }
        
        .left-side h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }
        
        .left-side p {
            font-size: 1.2rem;
            line-height: 1.6;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .benefits-list {
            text-align: left;
            margin-top: 2rem;
        }
        
        .benefit-item {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .benefit-icon {
            margin-right: 1rem;
            font-size: 1.5rem;
        }
        
        .right-side {
            width: 40%;
            background: white;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem;
            margin-left: 60%;
            height: 100vh;
            overflow-y: auto;
        }
        
        .form-container {
            width: 100%;
            max-width: 450px;
            position: relative;
        }

        .back-button {
            position: static;
            background: rgba(139, 0, 0, 0.08);
            border: 1px solid rgba(139, 0, 0, 0.25);
            color: #8b0000;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            gap: 0.45rem;
            transition: all 0.3s;
            cursor: pointer;
            margin-bottom: 1rem;
            width: fit-content;
        }

        .back-button:hover {
            background: rgba(139, 0, 0, 0.2);
            border-color: #8b0000;
            transform: translateX(-2px);
        }
        
        .form-container h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        .section-title {
            font-size: 1.1rem;
            color: #8b0000;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #8b0000;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        label .required {
            color: #e74c3c;
            margin-left: 2px;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="date"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #8b0000;
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 3px;
            font-style: italic;
        }
        
        input.password-weak {
            border-color: #e74c3c;
        }
        
        input.password-medium {
            border-color: #f39c12;
        }
        
        input.password-strong {
            border-color: #27ae60;
        }
        
        .password-container {
            position: relative;
        }

        .password-container input[type="password"] {
            padding-right: 40px;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 16px;
            user-select: none;
            transition: all 0.3s;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            padding: 0;
        }
        
        .password-toggle:hover {
            color: #8b0000;
        }
        
        .password-toggle.showing {
            color: #8b0000;
        }
        
        .password-strength {
            margin-top: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .password-strength-bar {
            flex: 1;
            height: 6px;
            background: #eee;
            border-radius: 999px;
            overflow: hidden;
        }

        .password-strength-bar-fill {
            height: 100%;
            width: 0%;
            border-radius: inherit;
            transition: width 0.25s ease, background-color 0.25s ease;
            background: #e74c3c;
        }
        
        .strength-weak {
            color: #e74c3c;
        }
        
        .strength-medium {
            color: #f39c12;
        }
        
        .strength-strong {
            color: #27ae60;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .error {
            color: #e74c3c;
            background: #fdf2f2;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .success {
            color: #27ae60;
            background: #f2fdf5;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        
        .login-link a {
            color: #8b0000;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }

        /* OTP Modal – professional, consistent styling */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal-box {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.14), 0 0 0 1px rgba(0, 0, 0, 0.04);
            max-width: 440px;
            width: 100%;
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%);
            padding: 1.5rem 1.75rem;
            text-align: center;
        }
        .modal-logo {
            width: 56px;
            height: 56px;
            object-fit: contain;
            border-radius: 50%;
            background: #fff;
            padding: 6px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
            margin-bottom: 0.6rem;
        }
        .modal-header-title {
            color: #fff;
            font-size: 1.05rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            margin: 0;
        }
        .modal-body {
            padding: 1.75rem 2rem 2rem;
        }
        .modal-box h3 {
            color: #0f172a;
            margin: 0 0 0.35rem;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .otp-step-label {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #8b0000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.75rem;
        }
        .modal-msg {
            display: none;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .modal-msg.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .modal-msg.success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .otp-hint {
            margin-bottom: 1.25rem;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.55;
        }
        .otp-hint strong {
            color: #334155;
        }
        .otp-back {
            margin-top: 1.25rem;
            text-align: center;
        }
        .otp-back a {
            color: #8b0000;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .otp-back a:hover { text-decoration: underline; }
        #otpInput {
            font-size: 1.5rem;
            letter-spacing: 0.5rem;
            text-align: center;
            padding: 14px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #otpInput:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1);
        }
        .modal-box .btn {
            width: 100%;
            padding: 12px 20px;
            font-weight: 600;
            border-radius: 10px;
        }
        .modal-success-state {
            text-align: center;
            padding: 1rem 0;
        }
        .modal-success-state .success-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            background: #dcfce7;
            color: #16a34a;
            font-size: 2rem;
            font-weight: 700;
            border-radius: 50%;
        }
        .modal-success-state h3 {
            color: #15803d;
            margin-bottom: 0.35rem;
            font-size: 1.2rem;
        }
        .modal-success-state p:last-child {
            color: #64748b;
            font-size: 0.9rem;
        }

        /* Form validation / server errors — centered modal (visible kahit naka-scroll sa baba) */
        .reg-alert-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 10001;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .reg-alert-overlay.show {
            display: flex;
        }
        .reg-alert-box {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.14), 0 0 0 1px rgba(0, 0, 0, 0.04);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }
        .reg-alert-box .reg-alert-header {
            background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%);
            padding: 1.25rem 1.5rem;
            text-align: center;
        }
        .reg-alert-box .reg-alert-header .modal-logo {
            margin-bottom: 0.5rem;
        }
        .reg-alert-title {
            color: #fff;
            font-size: 1.05rem;
            font-weight: 600;
            margin: 0;
        }
        .reg-alert-body {
            padding: 1.5rem 1.75rem 1.75rem;
            text-align: center;
        }
        .reg-alert-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }
        .reg-alert-message {
            color: #334155;
            font-size: 0.95rem;
            line-height: 1.55;
            margin: 0 0 1.25rem;
        }
        .reg-alert-box .btn {
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
            display: block;
        }
        
        @media (max-width: 768px) {
            .split-container {
                flex-direction: column;
                height: auto;
            }
            
            .left-side, .right-side {
                width: 100%;
                position: relative;
                height: auto;
                margin-left: 0;
            }
            
            .left-side {
                min-height: 40vh;
                padding: 2rem;
            }
            
            .left-side h1 {
                font-size: 2rem;
            }
            
            .left-side p {
                font-size: 1rem;
            }
            
            .right-side {
                padding: 1rem;
                overflow-y: visible;
            }
            
            .form-container {
                max-width: 100%;
            }

            .back-button {
                position: static;
                margin-bottom: 1rem;
                width: fit-content;
            }
            
            .form-container h2 {
                font-size: 20px;
                margin-bottom: 15px;
            }
            .modal-box {
                max-width: 100%;
            }
            .modal-body {
                padding: 1.25rem 1.25rem 1.5rem;
            }
            #otpInput {
                font-size: 1.25rem;
                letter-spacing: 0.4rem;
            }
            .reg-alert-box {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="split-container">
        <div class="left-side">
            <div class="left-content">
                <div class="logo-container">
                    <img src="SDO.jpg" alt="DepEd Provident Loan Logo" class="logo">
                </div>
                <h1>Join DepEd Provident Loan</h1>
                <p>Create an account to access the DepEd Provident Loan System. Apply for loans, monitor application status, and manage your loan records.</p>
            </div>
        </div>
        
        <div class="right-side">
            <div class="form-container">
                <a href="index.php" class="back-button">
                    <span class="back-icon">←</span>
                    Back to Landing Page
                </a>
                <h2>Create Account</h2>
        
        <div id="msgBox" style="display: none;" aria-live="polite"></div>

        <!-- Step 1: Registration form -->
        <form id="regForm" method="POST" action="">
            <!-- Basic Information Section -->
            <div class="section-title">Basic Information</div>
            
            <div class="form-group">
                <label for="username">Username <span class="required">*</span></label>
                <input type="text" id="username" name="username" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <input type="email" id="email" name="email" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <div class="password-container">
                    <input type="password" id="password" name="password" required pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number, one uppercase and lowercase letter, and at least 8 characters">
                    <button type="button" class="password-toggle" data-target="password" onclick="togglePassword(this)" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
                <div class="form-hint">
                    Minimum 8 characters. Sample: <strong>Password_123</strong>
                </div>
                <div class="password-strength">
                    <div class="password-strength-bar">
                        <div id="passwordStrengthBarFill" class="password-strength-bar-fill"></div>
                    </div>
                    <span id="passwordStrengthText"></span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                <div class="password-container">
                    <input type="password" id="confirm_password" name="confirm_password" required pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number, one uppercase and lowercase letter, and at least 8 characters">
                    <button type="button" class="password-toggle" data-target="confirm_password" onclick="togglePassword(this)" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <!-- Personal Information Section -->
            <div class="section-title" style="margin-top: 2rem;">Personal Information</div>
            
            <div class="form-group">
                <label for="deped_id">Employee Deped No. <span class="required">*</span></label>
                <input
                    type="text"
                    id="deped_id"
                    name="deped_id"
                    value="<?php echo isset($deped_id) ? htmlspecialchars($deped_id) : ''; ?>"
                    required
                    placeholder="1234567"
                    pattern="[0-9]{7}"
                    maxlength="7"
                    oninput="formatDepedId(this)"
                    title="Employee Deped No. must be exactly 7 digits"
                >
                <div class="form-hint">7 digits only</div>
            </div>
            
            <div class="form-group">
                <label for="contact_number">Contact Number <span class="required">*</span></label>
                <input
                    type="text"
                    id="contact_number"
                    name="contact_number"
                    value="<?php echo isset($contact_number) ? htmlspecialchars($contact_number) : ''; ?>"
                    required
                    placeholder="09XXXXXXXXX"
                    maxlength="11"
                    pattern="09[0-9]{9}"
                    title="Contact number must be 11 digits and start with 09 (e.g. 09XXXXXXXXX)."
                    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);"
                >
                <div class="form-hint">Exactly 11 digits, starting with 09.</div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name <span class="required">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo isset($first_name) ? htmlspecialchars($first_name) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?php echo isset($middle_name) ? htmlspecialchars($middle_name) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="surname">Last Name <span class="required">*</span></label>
                    <input type="text" id="surname" name="surname" value="<?php echo isset($surname) ? htmlspecialchars($surname) : ''; ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="birth_date">Birth Date <span class="required">*</span></label>
                <input type="date" id="birth_date" name="birth_date" value="<?php echo isset($birth_date) ? htmlspecialchars($birth_date) : ''; ?>" required>
                <div class="form-hint">dd/mm/yyyy</div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="gender">Gender <span class="required">*</span></label>
                    <select id="gender" name="gender" required>
                        <option value="">Select Gender</option>
                        <option value="Male" <?php echo (isset($gender) && $gender == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (isset($gender) && $gender == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (isset($gender) && $gender == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="civil_status">Civil Status <span class="required">*</span></label>
                    <select id="civil_status" name="civil_status" required>
                        <option value="">Select Status</option>
                        <option value="Single" <?php echo (isset($civil_status) && $civil_status == 'Single') ? 'selected' : ''; ?>>Single</option>
                        <option value="Married" <?php echo (isset($civil_status) && $civil_status == 'Married') ? 'selected' : ''; ?>>Married</option>
                        <option value="Widowed" <?php echo (isset($civil_status) && $civil_status == 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                        <option value="Separated" <?php echo (isset($civil_status) && $civil_status == 'Separated') ? 'selected' : ''; ?>>Separated</option>
                    </select>
                </div>
            </div>
            
            <div class="section-title" style="margin-top: 2rem;">Present Address</div>

            <div class="form-group">
                <label for="house_unit">House / Unit Number <span class="required">*</span></label>
                <input
                    type="text"
                    id="house_unit"
                    name="house_unit"
                    value="<?php echo isset($house_unit) ? htmlspecialchars($house_unit) : ''; ?>"
                    required
                    placeholder="e.g. Blk 5 Lot 12, Unit 3B"
                >
            </div>

            <div class="form-group">
                <label for="street_name">Street Name <span class="required">*</span></label>
                <input
                    type="text"
                    id="street_name"
                    name="street_name"
                    value="<?php echo isset($street_name) ? htmlspecialchars($street_name) : ''; ?>"
                    required
                    placeholder="e.g. Mabini Street"
                >
            </div>

            <div class="form-group">
                <label for="barangay">Barangay <span class="required">*</span></label>
                <input
                    type="text"
                    id="barangay"
                    name="barangay"
                    value="<?php echo isset($barangay) ? htmlspecialchars($barangay) : ''; ?>"
                    required
                    placeholder="e.g. Brgy. Sala"
                >
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="city_municipality">City / Municipality <span class="required">*</span></label>
                    <input
                        type="text"
                        id="city_municipality"
                        name="city_municipality"
                        value="<?php echo isset($city_municipality) ? htmlspecialchars($city_municipality) : ''; ?>"
                        required
                        placeholder="e.g. Cabuyao"
                    >
                </div>
                <div class="form-group">
                    <label for="province">Province <span class="required">*</span></label>
                    <input
                        type="text"
                        id="province"
                        name="province"
                        value="<?php echo isset($province) ? htmlspecialchars($province) : 'Laguna'; ?>"
                        required
                        placeholder="e.g. Laguna"
                    >
                </div>
                <div class="form-group">
                    <label for="postal_code">Postal / ZIP Code <span class="required">*</span></label>
                    <input
                        type="text"
                        id="postal_code"
                        name="postal_code"
                        value="<?php echo isset($postal_code) ? htmlspecialchars($postal_code) : '4025'; ?>"
                        required
                        placeholder="e.g. 4025"
                    >
                </div>
            </div>
            
            <button type="submit" class="btn" id="btnSendOtp">Send OTP to Email</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
            </div>
        </div>
    </div>

    <!-- Registration errors (validation / duplicate user / network) — modal so visible when scrolled -->
    <div id="regAlertModal" class="reg-alert-overlay" role="dialog" aria-modal="true" aria-labelledby="regAlertTitle">
        <div class="reg-alert-box" onclick="event.stopPropagation()">
            <div class="reg-alert-header">
                <img src="SDO.jpg" alt="" class="modal-logo">
                <p class="reg-alert-title" id="regAlertTitle">Please fix this</p>
            </div>
            <div class="reg-alert-body">
                <div class="reg-alert-icon" aria-hidden="true"><i class="fas fa-exclamation-circle"></i></div>
                <p id="regAlertMessage" class="reg-alert-message"></p>
                <button type="button" class="btn" id="regAlertOk">OK</button>
            </div>
        </div>
    </div>

    <!-- OTP Modal - professional look with logo -->
    <div id="otpModal" class="modal-overlay">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <img src="SDO.jpg" alt="DepEd Provident Loan" class="modal-logo">
                <p class="modal-header-title">DepEd Provident Loan</p>
            </div>
            <div class="modal-body">
                <div id="modalMsgBox" class="modal-msg"></div>
                <div id="otpModalForm">
                    <span class="otp-step-label">Email verification</span>
                    <h3>Verify your email</h3>
                    <p class="otp-hint">We sent a 6-digit code to <strong id="otpEmail"></strong>. Enter it below to complete registration.</p>
                    <div class="form-group">
                        <label for="otpInput">OTP Code <span class="required">*</span></label>
                        <input type="text" id="otpInput" name="otp" maxlength="6" placeholder="000000" pattern="[0-9]{6}" autocomplete="one-time-code">
                    </div>
                    <button type="button" class="btn" id="btnVerifyOtp">Verify & Create Account</button>
                    <p class="otp-back"><a href="#" id="backToForm">← Back to form</a></p>
                </div>
                <div id="otpModalSuccess" class="modal-success-state" style="display: none;">
                    <div class="success-icon">✓</div>
                    <h3>Account created!</h3>
                    <p>Redirecting to login...</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showMsg(text, isError) {
            var box = document.getElementById('msgBox');
            box.className = isError ? 'error' : 'success';
            box.textContent = text;
            box.style.display = text ? 'block' : 'none';
        }

        var regAlertModal = document.getElementById('regAlertModal');
        var regAlertMessageEl = document.getElementById('regAlertMessage');

        function closeRegAlertModal() {
            if (!regAlertModal) return;
            regAlertModal.classList.remove('show');
            regAlertMessageEl.textContent = '';
        }

        function showRegAlertModal(message) {
            if (!regAlertModal || !regAlertMessageEl) return;
            regAlertMessageEl.textContent = message || 'Something went wrong.';
            regAlertModal.classList.add('show');
            var okBtn = document.getElementById('regAlertOk');
            if (okBtn) {
                setTimeout(function() { okBtn.focus(); }, 50);
            }
        }

        function showModalMsg(text, isError) {
            var box = document.getElementById('modalMsgBox');
            box.className = 'modal-msg ' + (isError ? 'error' : 'success');
            box.textContent = text;
            box.style.display = text ? 'block' : 'none';
        }

        function togglePassword(button) {
            const fieldId = button.getAttribute('data-target');
            const passwordField = document.getElementById(fieldId);
            const icon = button.querySelector('i');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                button.classList.add('showing');
                button.setAttribute('aria-label', 'Hide password');
                if (icon) {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            } else {
                passwordField.type = 'password';
                button.classList.remove('showing');
                button.setAttribute('aria-label', 'Show password');
                if (icon) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }
        
        function checkPasswordStrength(password) {
            var strength = 0;
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            return strength;
        }

        // Employee Deped No.: 7 digits only, no dash
        function formatDepedId(input) {
            input.value = input.value.replace(/\D/g, '').slice(0, 7);
        }
        
        function updatePasswordStrength(password, fieldId) {
            var passwordField = document.getElementById(fieldId);
            var strength = checkPasswordStrength(password);
            passwordField.classList.remove('password-weak', 'password-medium', 'password-strong');
            var strengthTextEl = document.getElementById('passwordStrengthText');
            var strengthBarFill = document.getElementById('passwordStrengthBarFill');

            if (password.length === 0) {
                if (strengthTextEl) {
                    strengthTextEl.textContent = '';
                    strengthTextEl.classList.remove('strength-weak', 'strength-medium', 'strength-strong');
                }
                if (strengthBarFill) {
                    strengthBarFill.style.width = '0%';
                    strengthBarFill.style.backgroundColor = '#e74c3c';
                }
                return;
            }

            var label = '';
            var labelClass = '';

            if (strength <= 2) {
                passwordField.classList.add('password-weak');
                label = 'Weak password';
                labelClass = 'strength-weak';
            } else if (strength <= 4) {
                passwordField.classList.add('password-medium');
                label = 'Medium strength password';
                labelClass = 'strength-medium';
            } else {
                passwordField.classList.add('password-strong');
                label = 'Strong password';
                labelClass = 'strength-strong';
            }

            if (strengthTextEl && fieldId === 'password') {
                strengthTextEl.textContent = label;
                strengthTextEl.classList.remove('strength-weak', 'strength-medium', 'strength-strong');
                strengthTextEl.classList.add(labelClass);
            }

            if (strengthBarFill && fieldId === 'password') {
                var width = '33%';
                var color = '#e74c3c';
                if (strength <= 2) {
                    width = '33%';
                    color = '#e74c3c';
                } else if (strength <= 4) {
                    width = '66%';
                    color = '#f39c12';
                } else {
                    width = '100%';
                    color = '#27ae60';
                }
                strengthBarFill.style.width = width;
                strengthBarFill.style.backgroundColor = color;
            }
        }
        
        document.getElementById('password').addEventListener('input', function() {
            updatePasswordStrength(this.value, 'password');
        });
        document.getElementById('confirm_password').addEventListener('input', function() {
            updatePasswordStrength(this.value, 'confirm_password');
        });

        document.getElementById('regAlertOk').addEventListener('click', function() {
            closeRegAlertModal();
        });
        if (regAlertModal) {
            regAlertModal.addEventListener('click', function(e) {
                if (e.target === regAlertModal) {
                    closeRegAlertModal();
                }
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && regAlertModal && regAlertModal.classList.contains('show')) {
                closeRegAlertModal();
            }
        });

        // Step 1: Send OTP
        document.getElementById('regForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('btnSendOtp');
            showMsg('', false);
            var formData = new FormData(this);
            var pw = (formData.get('password') || '').toString();
            var cpw = (formData.get('confirm_password') || '').toString();
            if (pw !== cpw) {
                showRegAlertModal('Passwords do not match.');
                return;
            }
            if (pw.length < 8) {
                showRegAlertModal('Password must be at least 8 characters long.');
                return;
            }
            if (!/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}$/.test(pw)) {
                showRegAlertModal('Password must contain at least one uppercase, one lowercase, and one number.');
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Sending...';
            var xhr = new XMLHttpRequest();
            xhr.withCredentials = true;
            xhr.open('POST', 'register_send_otp.php');
            xhr.onload = function() {
                btn.disabled = false;
                btn.textContent = 'Send OTP to Email';
                var res;
                try { res = JSON.parse(xhr.responseText); } catch (err) {
                    showRegAlertModal('Invalid response from server. Please try again.');
                    return;
                }
                if (res.success) {
                    document.getElementById('otpEmail').textContent = formData.get('email');
                    document.getElementById('otpInput').value = '';
                    document.getElementById('otpModalForm').style.display = 'block';
                    document.getElementById('otpModalSuccess').style.display = 'none';
                    showModalMsg(res.message || '', false);
                    document.getElementById('otpModal').classList.add('show');
                    document.getElementById('otpInput').focus();
                } else {
                    showRegAlertModal(res.message || 'Failed to send OTP.');
                }
            };
            xhr.onerror = function() {
                btn.disabled = false;
                btn.textContent = 'Send OTP to Email';
                showRegAlertModal('Network error. Try again.');
            };
            xhr.send(formData);
        });

        // Step 2: Verify OTP (messages show inside modal)
        document.getElementById('btnVerifyOtp').addEventListener('click', function() {
            var otp = document.getElementById('otpInput').value.trim().replace(/\D/g, '');
            if (otp.length !== 6) {
                showModalMsg('Please enter the 6-digit OTP.', true);
                return;
            }
            var btn = document.getElementById('btnVerifyOtp');
            btn.disabled = true;
            btn.textContent = 'Verifying...';
            showModalMsg('', false);
            var formData = new FormData();
            formData.append('otp', otp);
            formData.append('email', document.getElementById('otpEmail').textContent.trim());
            var xhr = new XMLHttpRequest();
            xhr.withCredentials = true;
            xhr.open('POST', 'register_verify_otp.php');
            xhr.onload = function() {
                btn.disabled = false;
                btn.textContent = 'Verify & Create Account';
                var res;
                var raw = xhr.responseText || '';
                try { res = JSON.parse(raw); } catch (err) {
                    var preview = raw.substring(0, 80).replace(/\s+/g, ' ');
                    showModalMsg('Server error. Please try again.' + (preview ? ' (' + preview + '…)' : ''), true);
                    return;
                }
                if (res.success) {
                    showModalMsg('', false);
                    document.getElementById('otpModalForm').style.display = 'none';
                    document.getElementById('otpModalSuccess').style.display = 'block';
                    if (res.redirect) {
                        setTimeout(function() { window.location.href = res.redirect; }, 1000);
                    }
                } else {
                    showModalMsg(res.message || 'Verification failed.', true);
                }
            };
            xhr.onerror = function() {
                btn.disabled = false;
                btn.textContent = 'Verify & Create Account';
                showModalMsg('Network error. Try again.', true);
            };
            xhr.send(formData);
        });

        document.getElementById('backToForm').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('otpModal').classList.remove('show');
            document.getElementById('otpModalForm').style.display = 'block';
            document.getElementById('otpModalSuccess').style.display = 'none';
            showModalMsg('', false);
            showMsg('', false);
        });

        document.getElementById('otpModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
                document.getElementById('otpModalForm').style.display = 'block';
                document.getElementById('otpModalSuccess').style.display = 'none';
                showModalMsg('', false);
                showMsg('', false);
            }
        });
    </script>
</body>
</html>
