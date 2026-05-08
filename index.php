<?php
require_once 'config.php';
require_once 'config_email.php';
require_once 'mail_helper.php';

$lookup_error = '';
$lookup_info  = '';
$lookup_data  = null;
$waiting_otp  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deped_id_raw = trim($_POST['deped_id'] ?? '');
    $deped_id     = preg_replace('/\D/', '', $deped_id_raw); // normalize to digits only
    $email    = trim($_POST['email'] ?? '');
    $otp_raw  = trim($_POST['otp'] ?? '');
    $otp      = preg_replace('/\D/', '', $otp_raw);
    $force_resend = isset($_POST['force_resend']) && $_POST['force_resend'] === '1';

    if ($deped_id_raw === '' || $email === '') {
        $lookup_error = 'Please enter both Employee Deped No. and email.';
    } elseif (strlen($deped_id) !== 7) {
        $lookup_error = 'Employee Deped No. must be exactly 7 digits.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $lookup_error = 'Please enter a valid email address.';
    } elseif ($otp !== '') {
        // Step 2: Verify OTP then show balance
        if (
            empty($_SESSION['balance_otp_hash']) ||
            empty($_SESSION['balance_otp_expires']) ||
            empty($_SESSION['balance_otp_deped_id']) ||
            empty($_SESSION['balance_otp_email'])
        ) {
            $lookup_error = 'OTP session expired. Please request a new code.';
        } elseif (time() > $_SESSION['balance_otp_expires']) {
            $lookup_error = 'OTP has expired. Please request a new code.';
            unset($_SESSION['balance_otp_hash'], $_SESSION['balance_otp_expires'], $_SESSION['balance_otp_deped_id'], $_SESSION['balance_otp_email']);
        } elseif (
            $_SESSION['balance_otp_deped_id'] !== $deped_id ||
            $_SESSION['balance_otp_email'] !== $email
        ) {
            $lookup_error = 'Employee Deped No. or email was changed. Please start again.';
        } elseif (!password_verify($otp, $_SESSION['balance_otp_hash'])) {
            $lookup_error = 'Invalid OTP. Please check the code and try again.';
            $waiting_otp  = true;
        } else {
            // OTP valid: clear session and load balance
            unset($_SESSION['balance_otp_hash'], $_SESSION['balance_otp_expires'], $_SESSION['balance_otp_deped_id'], $_SESSION['balance_otp_email']);

            $sql = "SELECT 
                        u.id,
                        u.full_name,
                        u.deped_id,
                        u.email,
                        COALESCE(SUM(COALESCE(l.total_amount, l.loan_amount)), 0) AS total_loan_amount,
                        COALESCE(SUM(COALESCE(d.total_paid, 0)), 0) AS total_paid,
                        COALESCE(SUM(COALESCE(l.monthly_payment, 0)), 0) AS total_monthly
                    FROM users u
                    LEFT JOIN loans l 
                        ON l.user_id = u.id 
                        AND l.status = 'approved'
                    LEFT JOIN (
                        SELECT loan_id, SUM(amount) AS total_paid
                        FROM deductions
                        GROUP BY loan_id
                    ) d ON d.loan_id = l.id
                    WHERE REPLACE(TRIM(u.deped_id), '-', '') = ? AND u.email = ?
                    GROUP BY u.id, u.full_name, u.deped_id, u.email
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $deped_id, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc();
                $total_loan = (float) $row['total_loan_amount'];
                $total_paid = (float) $row['total_paid'];
                $remaining  = max(0, $total_loan - $total_paid);
                $percent_paid = $total_loan > 0 ? ($total_paid / $total_loan) * 100 : 0;
                $can_apply_again = $total_loan > 0 && $percent_paid >= 30;
                $lookup_data = [
                    'full_name'       => $row['full_name'],
                    'total_loan'      => $total_loan,
                    'total_paid'      => $total_paid,
                    'remaining'       => $remaining,
                    'monthly'         => (float) $row['total_monthly'],
                    'percent_paid'    => round($percent_paid, 1),
                    'can_apply_again' => $can_apply_again
                ];
            } else {
                $lookup_error = 'No matching account found. Please check your Employee Deped No. and email.';
            }
            $stmt->close();
        }
    } else {
        // Step 1: Generate and send OTP (no summary yet)
        $sql = "SELECT id FROM users WHERE REPLACE(TRIM(deped_id), '-', '') = ? AND email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $deped_id, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result || $result->num_rows !== 1) {
            $lookup_error = 'No matching account found. Please check your Employee Deped No. and email.';
        } else {
            // Check if there is still a valid OTP in session for this Employee Deped No. + email
            $has_active_otp =
                !empty($_SESSION['balance_otp_hash']) &&
                !empty($_SESSION['balance_otp_expires']) &&
                time() <= $_SESSION['balance_otp_expires'] &&
                !empty($_SESSION['balance_otp_deped_id']) &&
                !empty($_SESSION['balance_otp_email']) &&
                $_SESSION['balance_otp_deped_id'] === $deped_id &&
                $_SESSION['balance_otp_email'] === $email;

            if ($has_active_otp && !$force_resend) {
                // Do NOT send a new email yet – just remind the user
                $lookup_info = 'You already requested a verification code for this Employee Deped No. and email. Please check your inbox (and spam) and enter the 6-digit code below. If you still want a new code, click "Resend Code".';
                $waiting_otp = true;
            } else {
                if (empty(MAIL_SMTP_PASS) || strpos(MAIL_FROM_EMAIL, 'your-gmail') !== false) {
                    $lookup_error = 'Email (Gmail) is not configured. Please contact the office to verify your balance.';
                } else {
                    $otp_code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_hash  = password_hash($otp_code, PASSWORD_DEFAULT);
                    $expires   = time() + (10 * 60); // 10 minutes

                    $_SESSION['balance_otp_hash']      = $otp_hash;
                    $_SESSION['balance_otp_expires']   = $expires;
                    $_SESSION['balance_otp_deped_id']  = $deped_id;
                    $_SESSION['balance_otp_email']     = $email;

                    try {
                        sendBalanceCheckerOtpEmail($email, $otp_code);
                        $lookup_info = $force_resend
                            ? 'We sent a new 6-digit verification code to ' . htmlspecialchars($email) . '. Please use the latest code you received.'
                            : 'We sent a 6-digit verification code to ' . htmlspecialchars($email) . '. Enter it below to view your loan summary.';
                        $waiting_otp = true;
                    } catch (Exception $e) {
                        $lookup_error = 'Failed to send OTP: ' . $e->getMessage();
                    }
                }
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DepEd Loan System - Home</title>
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
        
        .navbar {
            background: rgba(139, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            transition: opacity 0.3s;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }
        
        .nav-links a:hover {
            opacity: 0.8;
        }
        
        .btn-nav {
            background: rgba(139, 0, 0, 0.8);
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-nav:hover {
            background: rgba(139, 0, 0, 1);
            transform: translateY(-2px);
        }
        
        .hero {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 0 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="80" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="10" cy="50" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="90" cy="50" r="1" fill="rgba(255,255,255,0.1)"/></svg>') repeat;
            opacity: 0.3;
        }
        
        .hero-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        
        .hero-text h1 {
            font-size: 3.5rem;
            color: white;
            margin-bottom: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            line-height: 1.15;
        }
        
        .hero-text p {
            font-size: 1.08rem;
            color: rgba(255, 255, 255, 0.94);
            margin-bottom: 2rem;
            line-height: 1.75;
            max-width: 690px;
            font-weight: 400;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn-primary {
            background: white;
            color: #8b0000;
            padding: 15px 30px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: transform 0.2s;
            display: inline-block;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: transparent;
            color: white;
            padding: 15px 30px;
            border: 2px solid white;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .btn-secondary:hover {
            background: white;
            color: #8b0000;
        }
        
        .hero-image {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .hero-img {
            width: 100%;
            max-width: 500px;
            height: auto;
            max-height: 75vh;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
        }
        
        .loan-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: white;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }
        
        .features {
            padding: 5rem 2rem;
            background: #f8f9fa;
        }

        .balance-checker {
            padding: 2rem 2rem;
            background: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .checker-container {
            max-width: 900px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 2rem;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .balance-checker::before,
        .balance-checker::after {
            content: '';
            position: absolute;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(139, 0, 0, 0.22) 0%, rgba(139, 0, 0, 0) 70%);
            pointer-events: none;
        }

        .balance-checker::before {
            top: -180px;
            left: -180px;
        }

        .balance-checker::after {
            bottom: -200px;
            right: -200px;
        }

        .balance-checker .bg-dots {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.6;
            background-image: radial-gradient(rgba(139, 0, 0, 0.3) 1.2px, transparent 1.2px);
            background-size: 20px 20px;
            background-position: 10px 10px;
        }

        .balance-checker .bg-wave {
            position: absolute;
            left: 0;
            right: 0;
            bottom: -20px;
            height: 140px;
            background: radial-gradient(120% 60% at 50% 100%, rgba(139, 0, 0, 0.22) 0%, rgba(139, 0, 0, 0) 70%);
            pointer-events: none;
        }

        .balance-checker .bg-stripe {
            position: absolute;
            top: 18px;
            right: 14%;
            width: 220px;
            height: 8px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(139, 0, 0, 0.4), rgba(139, 0, 0, 0));
            pointer-events: none;
        }

        .balance-checker .bg-stripe.secondary {
            top: 38px;
            right: 8%;
            width: 140px;
            height: 6px;
            background: linear-gradient(90deg, rgba(139, 0, 0, 0.28), rgba(139, 0, 0, 0));
        }

        .checker-card {
            background: #fff5f5;
            border: 1px solid #f2dcdc;
            border-radius: 16px;
            padding: 1.35rem 1.5rem;
            box-shadow: 0 10px 30px rgba(139, 0, 0, 0.08);
        }

        .checker-card h2 {
            color: #8b0000;
            margin-bottom: 0.35rem;
        }

        .checker-card p {
            color: #6b7280;
            margin-bottom: 0.85rem;
        }

        .checker-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #374151;
        }

        .checker-form input {
            width: 100%;
            padding: 0.55rem 0.85rem;
            border-radius: 10px;
            border: 1.5px solid #e5e7eb;
            margin-bottom: 0.65rem;
            font-size: 0.95rem;
        }

        .checker-form input:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.12);
        }

        .checker-btn {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 0.6rem 1rem;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: #fff;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
        }

        .checker-note {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #6b7280;
        }

        .checker-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 0.5rem 0.75rem;
            border-radius: 10px;
            margin-bottom: 0.65rem;
            font-size: 0.9rem;
        }

        .checker-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 0.5rem 0.75rem;
            border-radius: 10px;
            margin-bottom: 0.65rem;
            font-size: 0.9rem;
        }

        .checker-result {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 2rem;
        }

        .result-title {
            font-size: 1.2rem;
            color: #111827;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .result-name {
            font-weight: 600;
            color: #8b0000;
        }

        .result-percent {
            display: inline-flex;
            align-items: baseline;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
            border: 1px solid rgba(139, 0, 0, 0.2);
            border-radius: 10px;
        }

        .result-percent-label {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 600;
        }

        .result-percent-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: #8b0000;
        }

        .result-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }

        .result-item {
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }

        .result-label {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 0.4rem;
        }

        .result-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: #111827;
        }
        
        .apply-again-msg {
            margin-top: 1.25rem;
            padding: 0.9rem 1rem;
            background: #f0fdf4;
            border-left: 4px solid #16a34a;
            border-radius: 0 6px 6px 0;
            font-size: 0.95rem;
            color: #166534;
            line-height: 1.45;
        }
        
        .features-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .features h2 {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #333;
        }
        
        .features p {
            text-align: center;
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 3rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 24px;
            color: white;
        }
        
        .feature-card h3 {
            color: #333;
            margin-bottom: 1rem;
        }
        
        .feature-card p {
            color: #666;
            line-height: 1.6;
        }
        
        .cta {
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            padding: 5rem 2rem;
            text-align: center;
            color: white;
            display: none;
        }
        
        .cta h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .cta p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .footer {
            background: black;
            color: white;
            padding: 3rem 2rem;
            text-align: center;
        }
        
        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .footer-links a {
            color: white;
            text-decoration: none;
            opacity: 0.8;
            transition: opacity 0.3s;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }
        
        .footer-links a:hover {
            opacity: 1;
        }
        
        @media (max-width: 768px) {
            .hero-content {
                grid-template-columns: 1fr;
                gap: 2rem;
                text-align: center;
            }
            
            .hero-text h1 {
                font-size: 2.5rem;
            }

            .hero-text p {
                font-size: 1rem;
                line-height: 1.65;
                max-width: 100%;
            }
            
            .hero-buttons {
                justify-content: center;
            }
            
            .nav-links {
                gap: 1rem;
            }
            
            .loan-stats {
                grid-template-columns: 1fr;
            }

            .checker-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">SDO Cabuyao City.</div>
            <div class="nav-links">
                <a href="login.php" class="btn-nav">Login</a>
                <a href="register.php" class="btn-nav">Register</a>
            </div>
        </div>
    </nav>
    
    <section class="hero">
        <div class="hero-content">
            <div class="hero-text">
                <h1>DepEd Provident Loan Management System</h1>
                <p>This official platform of Schools Division Office Cabuyao City provides secure, transparent, and accountable financial assistance services for eligible Department of Education personnel, including loan application processing, status tracking, and balance monitoring.</p>
                <div class="hero-buttons">
                    <a href="register.php" class="btn-primary">Get Started</a>
                    <a href="#features" class="btn-secondary">Learn More</a>
                </div>
            </div>
            
            <div class="hero-image">
                <img src="SDO.jpg" alt="DepEd Loan System" class="hero-img">
            </div>
        </div>
    </section>

    <section class="balance-checker" id="balance-checker">
        <div class="bg-dots"></div>
        <div class="bg-wave"></div>
        <div class="bg-stripe"></div>
        <div class="bg-stripe secondary"></div>
        <div class="checker-container">
            <div class="checker-card">
                <h2>Loan Balance Checker</h2>
                <p>Enter your Employee Deped No. and email to see your remaining balance and total payments.</p>
                <?php if (!empty($lookup_error)): ?>
                    <div class="checker-error"><?php echo htmlspecialchars($lookup_error); ?></div>
                <?php endif; ?>
                <?php if (!empty($lookup_info)): ?>
                    <div class="checker-info"><?php echo $lookup_info; ?></div>
                <?php endif; ?>
                <form class="checker-form" method="POST" action="index.php">
                    <input type="hidden" id="force_resend" name="force_resend" value="0">
                    <label for="deped_id">Employee Deped No.</label>
                    <input
                        type="text"
                        id="deped_id"
                        name="deped_id"
                        placeholder="1234567"
                        required
                        value="<?php echo (isset($deped_id_raw) && $deped_id_raw !== '') ? htmlspecialchars($deped_id) : ''; ?>"
                        pattern="[0-9]{7}"
                        maxlength="7"
                        oninput="formatDepedId(this)"
                        title="Employee Deped No. must be exactly 7 digits"
                    >

                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        required
                        value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                    >

                    <?php if ($waiting_otp): ?>
                        <label for="otp">OTP Code</label>
                        <input
                            type="text"
                            id="otp"
                            name="otp"
                            placeholder="Enter 6-digit code"
                            maxlength="6"
                            pattern="[0-9]{6}"
                            required
                        >
                        <button
                            type="submit"
                            name="force_resend"
                            value="1"
                            class="checker-btn"
                            style="margin-top:0.25rem;background:transparent;color:#8b0000;border:1px solid #fca5a5;"
                            onclick="return confirm('You already requested a code. Do you want to send a new verification code to your email?');"
                        >
                            Resend Code
                        </button>
                    <?php endif; ?>

                    <button type="submit" class="checker-btn" style="<?php echo $waiting_otp ? 'margin-top: 1rem;' : ''; ?>">
                        <?php echo $waiting_otp ? 'Verify OTP & Show Balance' : 'Send OTP & Check Balance'; ?>
                    </button>
                    <div class="checker-note">
                        For your security, we will send a one-time code to your email before showing your loan summary.
                    </div>
                </form>
            </div>

            <div class="checker-result">
                <div class="result-title">Your Loan Summary</div>
                <?php if ($lookup_data): ?>
                    <div>Account: <span class="result-name"><?php echo htmlspecialchars($lookup_data['full_name']); ?></span></div>
                    <div class="result-percent">
                        <span class="result-percent-label">Paid</span>
                        <span class="result-percent-value"><?php echo number_format($lookup_data['percent_paid'] ?? 0, 1); ?>%</span>
                    </div>
                    <div class="result-grid">
                        <div class="result-item">
                            <div class="result-label">Total Loan</div>
                            <div class="result-value">₱<?php echo number_format($lookup_data['total_loan'], 2); ?></div>
                        </div>
                        <div class="result-item">
                            <div class="result-label">Total Paid</div>
                            <div class="result-value">₱<?php echo number_format($lookup_data['total_paid'], 2); ?></div>
                        </div>
                        <div class="result-item">
                            <div class="result-label">Remaining Balance</div>
                            <div class="result-value">₱<?php echo number_format($lookup_data['remaining'], 2); ?></div>
                        </div>
                        <div class="result-item">
                            <div class="result-label">Monthly Deduction</div>
                            <div class="result-value">₱<?php echo number_format($lookup_data['monthly'], 2); ?></div>
                        </div>
                    </div>
                    <?php if (!empty($lookup_data['can_apply_again'])): ?>
                        <div class="apply-again-msg">
                            You have paid at least 30% of your loan. You may apply for another loan—log in to the system to submit a new application.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="color:#6b7280;">No results yet. Please enter your Employee Deped No. and email.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <script>
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var section = document.getElementById('balance-checker');
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        <?php endif; ?>
    </script>
    
    <section class="features" id="features">
        <div class="features-container">
            <h2>Why Choose DepEd Loan System?</h2>
            <p>We offer comprehensive loan solutions tailored to meet the unique needs of DepEd employees</p>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">⚡</div>
                    <h3>Structured Loan Processing</h3>
                    <p>Applications follow the DepEd Provident Loan process, with release expected within seven (7) working days after submiting requirements.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">💰</div>
                    <h3>Responsible Loan Terms</h3>
                    <p>Benefit from fair interest rates and structured repayment options aligned with the financial capacity of Department of Education personnel.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🔒</div>
                    <h3>Secure & Confidential</h3>
                    <p>Your information is protected with bank-level security. All transactions are encrypted and confidential.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h3>Online Access</h3>
                    <p>Manage your loans anytime, anywhere through our secure online portal and mobile-friendly interface.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h3>Exclusive Benefits</h3>
                    <p>Special loan programs and benefits exclusively available to DepEd employees.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🤝</div>
                    <h3>Dedicated Support</h3>
                    <p>Our team of loan specialists is ready to assist you throughout your loan journey.</p>
                </div>
            </div>
        </div>
    </section>
    
    <footer class="footer">
        <div class="footer-content">
            <div style="margin-bottom: 2rem;">
                <h3 style="margin-bottom: 1rem; color: white;">SDO Cabuyao City.</h3>
                <p style="max-width: 600px; margin: 0 auto; line-height: 1.6;">
                    Official loan services portal of the Schools Division Office Cabuyao City for
                    DepEd personnel, committed to secure processing, transparent
                    transactions, and responsive client support.
                </p>
            </div>
            <div style="display: flex; justify-content: center; gap: 2rem; margin-bottom: 2rem; flex-wrap: wrap;">
                <div>
                    <h4 style="color: white; margin-bottom: 0.5rem;">Contact Info</h4>
                    <p style="font-size: 0.9rem;">Email: support@depedloan.gov.ph</p>
                    <p style="font-size: 0.9rem;">Hotline: (02) 1234-5678</p>
                </div>
                <div>
                    <h4 style="color: white; margin-bottom: 0.5rem;">Office Hours</h4>
                    <p style="font-size: 0.9rem;">Monday - Friday: 8:00 AM - 5:00 PM</p>
                    <p style="font-size: 0.9rem;">Saturday, Sunday & holidays: Closed</p>
                </div>
                <div>
                    <h4 style="color: white; margin-bottom: 0.5rem;">Quick Links</h4>
                    <p style="font-size: 0.9rem;">Loan Calculator</p>
                    <p style="font-size: 0.9rem;">Requirements</p>
                </div>
            </div>
            <div style="border-top: 1px solid rgba(255, 255, 255, 0.2); padding-top: 1rem; margin-top: 1rem;">
                <p>&copy; <?php echo date('Y'); ?> DepEd Provident Loan Management System | Schools Division Office Cabuyao City</p>
                <p style="font-size: 0.85rem; opacity: 0.8; margin-top: 0.5rem;">
                    In accordance with DepEd policies and applicable government regulations | Data Privacy Act of 2012 (RA 10173) Compliant
                </p>
            </div>
        </div>
    </footer>

    <script>
        function formatDepedId(input) {
            // Allow only digits, max 7
            let value = input.value.replace(/\D/g, '').slice(0, 7);
            input.value = value;
        }
    </script>
</body>
</html>
