<?php
require_once 'config.php';

$error = '';
$success = isset($_GET['reset']) && $_GET['reset'] === '1';
$forgot_err = isset($_GET['forgot_err']) && $_GET['forgot_err'] === '1';

// Remember me: pre-fill username and checkbox from cookie when loading login page
$remember_username = '';
$remember_checked = false;
if (isset($_COOKIE['remember_username']) && is_string($_COOKIE['remember_username'])) {
    $remember_username = htmlspecialchars($_COOKIE['remember_username'], ENT_QUOTES, 'UTF-8');
    $remember_checked = true;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (empty($username) || empty($password)) {
        $error = "Username and password are required";
    } else {
        // Check user credentials (include user_status for inactive check)
        $sql = "SELECT id, username, email, password, full_name, role, deped_id, COALESCE(NULLIF(user_status,''), 'active') AS user_status FROM users WHERE username = ? OR email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $status = strtolower($user['user_status'] ?? 'active');
                if ($status === 'inactive' || $status === 'suspended') {
                    $error = 'Account is inactive or suspended. Please contact the administrator.';
                    $stmt->close();
                } else {
                // Update last login and set active (in case they were auto-inactive)
                $uid = (int) $user['id'];
                $up = $conn->prepare("UPDATE users SET last_login_at = NOW(), user_status = 'active' WHERE id = ?");
                $up->bind_param('i', $uid);
                $up->execute();
                $up->close();

                // Run daily job: mark users inactive if no login in 4 weeks (once per 24h)
                require_once __DIR__ . '/cron_inactive_users.php';

                // Remember me: save username in cookie for 30 days if checkbox checked
                $remember = !empty($_POST['remember']);
                if ($remember) {
                    $cookie_value = $user['username'];
                    setcookie('remember_username', $cookie_value, time() + (30 * 24 * 60 * 60), '/', '', false, true); // 30 days, httpOnly
                } else {
                    setcookie('remember_username', '', time() - 3600, '/', '', false, true); // delete cookie
                }

                // Password is correct, start session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['deped_id'] = $user['deped_id'] ?? null;
                $_SESSION['role'] = $user['role'] ?? ($user['username'] === 'admin' ? 'admin' : 'borrower');

                log_audit(
                    $conn,
                    'LOGIN',
                    'User logged in successfully.',
                    'Login',
                    null,
                    $user['id'],
                    $user['full_name'],
                    $_SESSION['role']
                );
                
                // Redirect based on user role
                if (($user['role'] ?? '') === 'admin' || $user['username'] === 'admin') {
                    header("Location: admin_dashboard.php");
                } elseif (user_is_accountant_role($user['role'] ?? null)) {
                    header("Location: accountant_dashboard.php");
                } else {
                    header("Location: borrower_dashboard.php");
                }
                exit();
                }
            } else {
                $error = "Invalid username or password";
                log_audit(
                    $conn,
                    'LOGIN',
                    "Failed login attempt for {$username}.",
                    'Login',
                    null,
                    null,
                    $username ?: 'Guest',
                    'guest'
                );
            }
        } else {
            $error = "Invalid username or password";
            log_audit(
                $conn,
                'LOGIN',
                "Failed login attempt for {$username}.",
                'Login',
                null,
                null,
                $username ?: 'Guest',
                'guest'
            );
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
    <title>Login - DepEd Provident Loan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
        }
        
        .split-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }
        
        .left-side {
            width: 60%;
            background: url('loginbg.jpg') center/cover;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 3rem;
            position: relative;
            overflow: hidden;
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
        
        .feature-list {
            text-align: left;
            margin-top: 2rem;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .feature-icon {
            margin-right: 1rem;
            font-size: 1.5rem;
        }
        
        .right-side {
            width: 40%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .form-container {
            width: 100%;
            max-width: 420px;
            position: relative;
        }
        
        .back-button {
            position: absolute;
            top: -50px;
            left: 0;
            background: rgba(139, 0, 0, 0.08);
            border: 1px solid rgba(139, 0, 0, 0.25);
            color: #8b0000;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            gap: 0.45rem;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .back-button:hover {
            background: rgba(139, 0, 0, 0.2);
            border-color: #8b0000;
            transform: translateX(-2px);
        }
        
        .form-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 2.75rem 2.75rem 2.5rem;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
            border: 1px solid #f0f0f0;
        }
        
        .form-container h2 {
            text-align: center;
            color: #333;
            margin-bottom: 0.6rem;
            font-size: 32px;
        }

        .form-subtitle {
            text-align: center;
            color: #6b7280;
            margin-bottom: 2.25rem;
            font-size: 1rem;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 26px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .remember-me input[type="checkbox"] {
            width: auto;
            margin: 0;
            padding: 0;
        }
        
        .remember-me label {
            margin: 0;
            font-size: 14px;
            cursor: pointer;
        }
        
        .forgot-password {
            color: #8b0000;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s;
        }
        
        .forgot-password:hover {
            color: #dc143c;
            text-decoration: underline;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa0a6;
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 14px 14px 14px 42px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: #fff;
        }

        .input-wrapper.password-toggle-wrapper input {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #9aa0a6;
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle.active {
            color: #8b0000;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.12);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            box-shadow: 0 10px 25px rgba(139, 0, 0, 0.2);
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
        
        .register-link {
            text-align: center;
            margin-top: 26px;
            color: #666;
        }
        
        .register-link a {
            color: #8b0000;
            text-decoration: none;
            font-weight: 600;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .split-container {
                flex-direction: column;
            }
            
            .left-side, .right-side {
                width: 100%;
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

            .back-button {
                position: static;
                margin-bottom: 1rem;
                width: fit-content;
            }

            .form-card {
                padding: 2rem 1.75rem 1.9rem;
            }
        }

        /* Forgot Password Modal – professional OTP styling */
        .fp-modal-overlay {
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
        .fp-modal-overlay.show { display: flex; }
        .fp-modal-box {
            position: relative;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.14), 0 0 0 1px rgba(0, 0, 0, 0.04);
            max-width: 440px;
            width: 100%;
            overflow: hidden;
        }
        .fp-modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 3;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            line-height: 1;
            transition: background 0.2s ease, transform 0.15s ease;
        }
        .fp-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .fp-modal-close:focus-visible {
            outline: 2px solid #fff;
            outline-offset: 2px;
        }
        .fp-modal-close:active {
            transform: scale(0.96);
        }
        .fp-modal-header {
            background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%);
            padding: 1.5rem 1.75rem;
            text-align: center;
        }
        .fp-modal-logo {
            width: 56px; height: 56px;
            object-fit: contain; border-radius: 50%;
            background: #fff; padding: 6px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
            margin-bottom: 0.6rem;
        }
        .fp-modal-header-title { color: #fff; font-size: 1.05rem; font-weight: 600; margin: 0; }
        .fp-modal-body { padding: 1.75rem 2rem 2rem; }
        .fp-modal-body h3 { color: #0f172a; margin: 0 0 0.35rem; font-size: 1.2rem; font-weight: 600; }
        .fp-step-label {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #8b0000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.75rem;
        }
        .fp-modal-msg {
            display: none;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
        }
        .fp-modal-msg.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .fp-modal-msg.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .fp-step-hint { color: #64748b; font-size: 0.9rem; margin-bottom: 1rem; line-height: 1.55; }
        .fp-step-hint strong { color: #334155; }
        .fp-modal-body .form-group { margin-bottom: 1rem; }
        .fp-modal-body .form-group label { display: block; margin-bottom: 6px; color: #334155; font-weight: 500; font-size: 0.9rem; }
        .fp-modal-body .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .fp-modal-body .form-group input:focus {
            outline: none;
            border-color: #8b0000;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1);
        }
        .fp-modal-body #fpOtpInput {
            font-size: 1.4rem;
            letter-spacing: 0.5rem;
            text-align: center;
            padding: 14px 16px;
        }
        .fp-modal-body .btn { margin-top: 0.35rem; padding: 12px 20px; font-weight: 600; border-radius: 10px; }
        .fp-back-link { text-align: center; margin-top: 1.25rem; }
        .fp-back-link a { color: #8b0000; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
        .fp-back-link a:hover { text-decoration: underline; }
        .fp-step { display: none; }
        .fp-step.active { display: block; }
    </style>
</head>
<body>
    <div class="split-container">
        <div class="left-side">
            <div class="left-content">
                <div class="logo-container">
                    <img src="SDO.jpg" alt="DepEd Provident Loan Logo" class="logo">
                </div>
                <h1>DepEd Provident Loan</h1>
                <p>Official platform for DepEd employees to access provident loan services, manage loan accounts, and track application status.</p>
            </div>
        </div>
        
        <div class="right-side">
            <div class="form-container">
                <a href="index.php" class="back-button">
                    <span class="back-icon">←</span>
                    Back to Landing Page
                </a>
                <div class="form-card">
                    <h2>Login</h2>
                    <div class="form-subtitle">Sign in to access your loan dashboard.</div>
        
                <?php if (!empty($error)): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="success" style="color:#166534;background:#f0fdf4;padding:10px;border-radius:6px;margin-bottom:1rem;border:1px solid #bbf7d0;">Password updated. You can now log in.</div>
                <?php endif; ?>
                <?php if ($forgot_err): ?>
                    <div class="error" style="margin-bottom:1rem;">Password reset failed. Please try again from Forgot password.</div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <div class="input-wrapper">
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                            <input type="text" id="username" name="username" value="<?php echo $remember_username; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper password-toggle-wrapper">
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                            <input type="password" id="password" name="password" required>
                            <button type="button" class="password-toggle" data-target="password" aria-label="Show password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-options">
                        <div class="remember-me">
                            <input type="checkbox" id="remember" name="remember"<?php echo $remember_checked ? ' checked' : ''; ?>>
                            <label for="remember">Remember me</label>
                        </div>
                        <a href="#" class="forgot-password" id="openForgotModal">Forgot password?</a>
                    </div>
                    
                    <button type="submit" class="btn">Login</button>
                </form>
                
                <div class="register-link">
                    Don't have an account? <a href="register.php">Register here</a>
                </div>
            </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" class="fp-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="fpModalTitle">
        <div class="fp-modal-box" onclick="event.stopPropagation()">
            <button type="button" class="fp-modal-close" id="fpModalClose" aria-label="Close and return to login" title="Close">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
            <div class="fp-modal-header">
                <img src="SDO.jpg" alt="SDO Cabuyao City" class="fp-modal-logo">
                <p class="fp-modal-header-title" id="fpModalTitle">Reset Password</p>
            </div>
            <div class="fp-modal-body">
                <div id="fpModalMsg" class="fp-modal-msg"></div>

                <div id="fpStep1" class="fp-step active">
                    <span class="fp-step-label">Step 1 of 3</span>
                    <h3>Enter your email</h3>
                    <p class="fp-step-hint">We’ll send a 6-digit code to this email to verify your identity.</p>
                    <div class="form-group">
                        <label for="fpEmail">Email address</label>
                        <input type="email" id="fpEmail" placeholder="you@example.com" required>
                    </div>
                    <button type="button" class="btn" id="fpBtnSendOtp">Send OTP</button>
                </div>

                <div id="fpStep2" class="fp-step">
                    <span class="fp-step-label">Step 2 of 3</span>
                    <h3>Enter the code</h3>
                    <p class="fp-step-hint">We sent a 6-digit code to <strong id="fpEmailDisplay"></strong>. Enter it below.</p>
                    <div class="form-group">
                        <label for="fpOtpInput">OTP Code</label>
                        <input type="text" id="fpOtpInput" maxlength="6" placeholder="000000" autocomplete="one-time-code">
                    </div>
                    <button type="button" class="btn" id="fpBtnVerify">Verify & continue</button>
                    <p class="fp-back-link"><a href="#" id="fpBackToStep1">← Use a different email</a></p>
                </div>

                <div id="fpStep3" class="fp-step">
                    <span class="fp-step-label">Step 3 of 3</span>
                    <h3>Set new password</h3>
                    <p class="fp-step-hint">Enter your new password (at least 8 characters, with uppercase, lowercase, and a number).</p>
                    <form id="fpFormPassword" method="post" action="forgot_password_reset.php">
                        <div class="form-group">
                            <label for="fpPassword">New password</label>
                            <input type="password" id="fpPassword" name="password" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label for="fpConfirmPassword">Confirm new password</label>
                            <input type="password" id="fpConfirmPassword" name="confirm_password" required minlength="8">
                        </div>
                        <button type="submit" class="btn">Reset password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.password-toggle').forEach(function(button) {
            button.addEventListener('click', function() {
                var targetId = button.getAttribute('data-target');
                var input = document.getElementById(targetId);
                if (!input) return;
                var icon = button.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    button.classList.add('active');
                    button.setAttribute('aria-label', 'Hide password');
                    if (icon) {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                } else {
                    input.type = 'password';
                    button.classList.remove('active');
                    button.setAttribute('aria-label', 'Show password');
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            });
        });

        (function() {
            var fpModal = document.getElementById('forgotModal');
            var fpEmail = document.getElementById('fpEmail');
            var fpOtpInput = document.getElementById('fpOtpInput');
            var fpEmailDisplay = document.getElementById('fpEmailDisplay');
            var fpStep1 = document.getElementById('fpStep1');
            var fpStep2 = document.getElementById('fpStep2');
            var fpStep3 = document.getElementById('fpStep3');
            var fpModalMsg = document.getElementById('fpModalMsg');
            var currentFpEmail = '';

            function closeForgotModal() {
                fpModal.classList.remove('show');
                showFpStep('fpStep1');
                showFpMsg('');
                fpEmail.value = '';
                fpOtpInput.value = '';
                var p = document.getElementById('fpPassword');
                var c = document.getElementById('fpConfirmPassword');
                if (p) p.value = '';
                if (c) c.value = '';
                currentFpEmail = '';
                var sendBtn = document.getElementById('fpBtnSendOtp');
                var verifyBtn = document.getElementById('fpBtnVerify');
                if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'Send OTP'; }
                if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify & continue'; }
            }

            function showFpMsg(text, isError) {
                fpModalMsg.className = 'fp-modal-msg ' + (isError ? 'error' : 'success');
                fpModalMsg.textContent = text || '';
                fpModalMsg.style.display = text ? 'block' : 'none';
            }

            function showFpStep(stepId) {
                fpStep1.classList.remove('active');
                fpStep2.classList.remove('active');
                fpStep3.classList.remove('active');
                document.getElementById(stepId).classList.add('active');
            }

            document.getElementById('openForgotModal').addEventListener('click', function(e) {
                e.preventDefault();
                showFpMsg('');
                showFpStep('fpStep1');
                fpEmail.value = '';
                fpOtpInput.value = '';
                fpModal.classList.add('show');
            });

            fpModal.addEventListener('click', function(e) {
                if (e.target === fpModal) {
                    closeForgotModal();
                }
            });

            document.getElementById('fpModalClose').addEventListener('click', function() {
                closeForgotModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && fpModal.classList.contains('show')) {
                    closeForgotModal();
                }
            });

            document.getElementById('fpBtnSendOtp').addEventListener('click', function() {
                var email = fpEmail.value.trim();
                if (!email) { showFpMsg('Please enter your email.', true); return; }
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Sending...';
                showFpMsg('');
                var fd = new FormData();
                fd.append('email', email);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'forgot_password_send_otp.php');
                xhr.onload = function() {
                    btn.disabled = false;
                    btn.textContent = 'Send OTP';
                    var res;
                    try { res = JSON.parse(xhr.responseText); } catch (err) { showFpMsg('Server error. Try again.', true); return; }
                    if (res.success) {
                        currentFpEmail = email;
                        fpEmailDisplay.textContent = email;
                        fpOtpInput.value = '';
                        showFpStep('fpStep2');
                        showFpMsg(res.message, false);
                    } else {
                        showFpMsg(res.message || 'Failed to send OTP.', true);
                    }
                };
                xhr.onerror = function() { btn.disabled = false; btn.textContent = 'Send OTP'; showFpMsg('Network error.', true); };
                xhr.send(fd);
            });

            document.getElementById('fpBtnVerify').addEventListener('click', function() {
                var otp = fpOtpInput.value.trim().replace(/\D/g, '');
                if (otp.length !== 6) { showFpMsg('Please enter the 6-digit code.', true); return; }
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Verifying...';
                showFpMsg('');
                var fd = new FormData();
                fd.append('email', currentFpEmail);
                fd.append('otp', otp);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'forgot_password_verify_otp.php');
                xhr.onload = function() {
                    btn.disabled = false;
                    btn.textContent = 'Verify & continue';
                    var res;
                    try { res = JSON.parse(xhr.responseText); } catch (err) { showFpMsg('Server error. Try again.', true); return; }
                    if (res.success) {
                        showFpStep('fpStep3');
                        showFpMsg(res.message, false);
                    } else {
                        showFpMsg(res.message || 'Verification failed.', true);
                    }
                };
                xhr.onerror = function() { btn.disabled = false; btn.textContent = 'Verify & continue'; showFpMsg('Network error.', true); };
                xhr.send(fd);
            });

            document.getElementById('fpBackToStep1').addEventListener('click', function(e) {
                e.preventDefault();
                showFpStep('fpStep1');
                showFpMsg('');
            });

            document.getElementById('fpFormPassword').addEventListener('submit', function(e) {
                var p = document.getElementById('fpPassword').value;
                var c = document.getElementById('fpConfirmPassword').value;
                if (p !== c) {
                    e.preventDefault();
                    showFpMsg('Passwords do not match.', true);
                    return false;
                }
                if (p.length < 8) {
                    e.preventDefault();
                    showFpMsg('Password must be at least 8 characters.', true);
                    return false;
                }
            });
        })();
    </script>
</body>
</html>
