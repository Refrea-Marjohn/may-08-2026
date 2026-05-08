<?php
/**
 * Send OTP email via Gmail SMTP using PHPMailer.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function appendOfficialContactFooterToHtml(string $html): string
{
    $contactEmail = defined('MAIL_FROM_EMAIL') ? (string) MAIL_FROM_EMAIL : '';
    $contactEmailEsc = htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8');
    $footerHtml = '
    <div style="margin-top: 18px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
        <p style="margin: 0; font-size: 12px; color: #6b7280; line-height: 1.6; text-align: center;">
            For inquiries and assistance, please contact the<br>
            <strong>DepEd Provident Loan Unit, SDO Cabuyao City</strong><br>
            Email: <a href="mailto:' . $contactEmailEsc . '" style="color: #8b0000; text-decoration: none;">' . $contactEmailEsc . '</a>
        </p>
    </div>';

    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $footerHtml . "\n</body>", $html, 1) ?? ($html . $footerHtml);
    }
    return $html . $footerHtml;
}

function appendOfficialContactFooterToText(string $text): string
{
    $contactEmail = defined('MAIL_FROM_EMAIL') ? (string) MAIL_FROM_EMAIL : '';
    $footerText = "\n\nFor inquiries and assistance, please contact the DepEd Provident Loan Unit, SDO Cabuyao City.\n"
        . "Email: " . $contactEmail;
    return rtrim($text) . $footerText;
}

function applyOfficialMailFooter(PHPMailer $mail): void
{
    $mail->Body = appendOfficialContactFooterToHtml((string) $mail->Body);
    $mail->AltBody = appendOfficialContactFooterToText((string) $mail->AltBody);
}

function sendOtpEmail($toEmail, $otpCode) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your verification code – DepEd Loan System';
        $otpEscaped = htmlspecialchars($otpCode);
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Email Verification</p>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello,</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">You requested a verification code to complete your registration. Use the code below:</p>
                            <!-- OTP Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td align="center" style="background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 20px 24px;">
                                        <p style="margin: 0; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $otpEscaped . '</p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af;">If you did not request this code, you can safely ignore this email.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Email Verification\n\nYour verification code is: " . $otpCode . "\n\nThis code expires in 10 minutes. Do not share it with anyone.\n\nIf you did not request this, please ignore this email.";

        applyOfficialMailFooter($mail);
        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Send OTP email for Loan Balance Checker (landing page).
 */
function sendBalanceCheckerOtpEmail($toEmail, $otpCode) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Loan Balance Check – Verification Code';
        $otpEscaped = htmlspecialchars($otpCode);
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Loan Balance Check</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello,</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">You requested a verification code to view your loan summary. Use the code below:</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td align="center" style="background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 20px 24px;">
                                        <p style="margin: 0; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $otpEscaped . '</p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af;">If you did not request this code, you can safely ignore this email.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Loan Balance Check\n\nYour verification code is: " . $otpCode . "\n\nUse this code to view your loan summary. This code expires in 10 minutes. Do not share it with anyone.\n\nIf you did not request this, please ignore this email.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Send OTP email for password reset (same style, different copy).
 */
function sendPasswordResetOtpEmail($toEmail, $otpCode) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Password reset code – SDO Cabuyao City';
        $otpEscaped = htmlspecialchars($otpCode);
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">SDO Cabuyao City</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Password Reset</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello,</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">You requested to reset your password. Use the code below to continue:</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td align="center" style="background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 20px 24px;">
                                        <p style="margin: 0; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $otpEscaped . '</p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af;">If you did not request a password reset, you can safely ignore this email.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">SDO Cabuyao City &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "SDO Cabuyao City - Password Reset\n\nYour reset code is: " . $otpCode . "\n\nThis code expires in 10 minutes. Do not share it with anyone.\n\nIf you did not request this, please ignore this email.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Send accountant account credentials (username + temporary password) to the new accountant's email.
 * Called when admin creates an accountant account in Manage Users.
 */
function sendAccountantCredentialsEmail($toEmail, $fullName, $username, $tempPassword) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your Accountant Account – DepEd Loan System';
        $nameEscaped = htmlspecialchars($fullName);
        $userEscaped = htmlspecialchars($username);
        $passEscaped = htmlspecialchars($tempPassword);
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Accountant Account</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello, Mr./Mrs. ' . $nameEscaped . ',</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">An administrator has created an accountant account for you. Use the credentials below to log in. Please change your password after your first login.</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 20px 24px;">
                                        <p style="margin: 0 0 12px; font-size: 13px; color: #6b7280;">Username</p>
                                        <p style="margin: 0 0 20px; font-size: 18px; font-weight: 600; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $userEscaped . '</p>
                                        <p style="margin: 0 0 12px; font-size: 13px; color: #6b7280;">Temporary password</p>
                                        <p style="margin: 0; font-size: 18px; font-weight: 600; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $passEscaped . '</p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">Log in at the DepEd Loan System and change your password for security.</p>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af;">If you did not expect this email, please contact your administrator.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Accountant Account\n\nHello, Mr./Mrs. " . $fullName . ",\n\nAn administrator has created an accountant account for you.\n\nUsername: " . $username . "\nTemporary password: " . $tempPassword . "\n\nPlease log in and change your password after your first login.\n\nIf you did not expect this email, contact your administrator.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Send borrower account credentials for newly-created on-file accounts.
 */
function sendBorrowerCredentialsEmail($toEmail, $fullName, $username, $tempPassword) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your Borrower Account – DepEd Loan System';
        $nameEscaped = htmlspecialchars($fullName);
        $userEscaped = htmlspecialchars($username);
        $passEscaped = htmlspecialchars($tempPassword);
        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Borrower Account</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello, Mr./Mrs. ' . $nameEscaped . ',</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">An administrator/accountant created your borrower account to record your existing loan. You can now log in using these credentials and update your password after first login.</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 20px 24px;">
                                        <p style="margin: 0 0 12px; font-size: 13px; color: #6b7280;">Username</p>
                                        <p style="margin: 0 0 20px; font-size: 18px; font-weight: 600; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $userEscaped . '</p>
                                        <p style="margin: 0 0 12px; font-size: 13px; color: #6b7280;">Temporary password</p>
                                        <p style="margin: 0; font-size: 18px; font-weight: 600; color: #1f2937; font-family: \'Consolas\', \'Monaco\', monospace;">' . $passEscaped . '</p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">For security, change your password right after login.</p>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af;">If you did not expect this email, please contact the DepEd Loan office.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Borrower Account\n\nHello, Mr./Mrs. " . $fullName . ",\n\nA borrower account was created for your existing loan record.\n\nUsername: " . $username . "\nTemporary password: " . $tempPassword . "\n\nPlease log in and change your password after your first login.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Notify co-maker that they were used as co-maker in a loan application.
 * Sent when a borrower submits a loan application.
 */
function sendCoMakerNotificationEmail($toEmail, $coMakerName, $borrowerName, $loanAmount, $applicationDate, $loanPurpose = '') {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Co-Maker Notification – DepEd Loan System';
        $coMakerEsc = htmlspecialchars($coMakerName);
        $borrowerEsc = htmlspecialchars($borrowerName);
        $amountEsc = htmlspecialchars('₱' . number_format((float)$loanAmount, 2));
        $dateEsc = htmlspecialchars($applicationDate);
        $purposeEsc = $loanPurpose !== '' ? nl2br(htmlspecialchars($loanPurpose)) : '<em>Not provided.</em>';
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Co-Maker Notification</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello, Mr./Mrs. ' . $coMakerEsc . ',</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">This is to inform you that you have been listed as <strong>co-maker</strong> in a loan application submitted through the DepEd Loan System.</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px; background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 20px 24px;">
                                <tr><td style="font-size: 13px; color: #6b7280;">Borrower</td></tr>
                                <tr><td style="font-size: 18px; font-weight: 600; color: #1f2937;">' . $borrowerEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Loan Purpose</td></tr>
                                <tr><td style="font-size: 15px; color: #1f2937; line-height: 1.6;">' . $purposeEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Loan Amount</td></tr>
                                <tr><td style="font-size: 18px; font-weight: 600; color: #1f2937;">' . $amountEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Application Date</td></tr>
                                <tr><td style="font-size: 16px; color: #1f2937;">' . $dateEsc . '</td></tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">If you did not agree to be a co-maker for this application, please contact the borrower or the DepEd Loan System administrator.</p>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af;">This is an automated notification. Please do not reply to this email.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; RA 11032 Ease of Doing Business &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Co-Maker Notification (RA 11032 Ease of Doing Business)\n\nHello, Mr./Mrs. " . $coMakerName . ",\n\nYou have been listed as co-maker in a loan application.\n\nBorrower: " . $borrowerName . "\nLoan Purpose: " . $loanPurpose . "\nLoan Amount: " . number_format((float)$loanAmount, 2) . "\nApplication Date: " . $applicationDate . "\n\nIf you did not agree to this, please contact the borrower or the administrator.\n\nRA 11032 Ease of Doing Business. This is an automated message. Please do not reply.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Notify co-maker that the loan they were listed on was APPROVED.
 */
function sendCoMakerLoanApprovedEmail($toEmail, $coMakerName, $borrowerName, $loanAmount, $loanId, $loanPurpose = '') {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Loan Approved (Co-Maker Copy) – DepEd Loan System';

        $coMakerEsc = htmlspecialchars($coMakerName);
        $borrowerEsc = htmlspecialchars($borrowerName);
        $amountEsc = htmlspecialchars('₱' . number_format((float) $loanAmount, 2));
        $purposeEsc = $loanPurpose !== '' ? nl2br(htmlspecialchars($loanPurpose)) : '<em>Not provided.</em>';

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #155724 0%, #1e7e34 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Loan Approved (Co-Maker Copy)</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello, Mr./Mrs. ' . $coMakerEsc . ',</p>
                            <p style="margin: 0 0 18px; font-size: 15px; color: #4b5563; line-height: 1.6;">
                                The loan application where you are listed as <strong>co-maker</strong> has been <strong style="color:#155724;">approved</strong>.
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 18px; background-color: #f8fafc; border: 2px dashed #155724; border-radius: 8px; padding: 18px 22px;">
                                <tr><td style="font-size: 13px; color: #6b7280;">Borrower</td></tr>
                                <tr><td style="font-size: 18px; font-weight: 700; color: #1f2937;">' . $borrowerEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Loan Purpose</td></tr>
                                <tr><td style="font-size: 15px; color: #1f2937; line-height: 1.6;">' . $purposeEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Loan Amount</td></tr>
                                <tr><td style="font-size: 18px; font-weight: 700; color: #1f2937;">' . $amountEsc . '</td></tr>
                            </table>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af; line-height: 1.5;">This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; RA 11032 Ease of Doing Business &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        $mail->AltBody = "DepEd Loan System - Loan Approved (Co-Maker Copy) - RA 11032 Ease of Doing Business\n\nHello, Mr./Mrs. " . $coMakerName . ",\n\nThe loan application where you are listed as co-maker has been approved.\n\nBorrower: " . $borrowerName . "\nLoan Purpose: " . $loanPurpose . "\nLoan Amount: " . number_format((float) $loanAmount, 2) . "\n\nRA 11032 Ease of Doing Business. Do not reply to this email.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Notify co-maker that the loan they were listed on was REJECTED.
 */
function sendCoMakerLoanRejectedEmail($toEmail, $coMakerName, $borrowerName, $loanAmount, $loanId, $adminComment, $loanPurpose = '') {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Loan Rejected (Co-Maker Copy) – DepEd Loan System';

        $coMakerEsc = htmlspecialchars($coMakerName);
        $borrowerEsc = htmlspecialchars($borrowerName);
        $amountEsc = htmlspecialchars('₱' . number_format((float) $loanAmount, 2));
        $purposeEsc = $loanPurpose !== '' ? nl2br(htmlspecialchars($loanPurpose)) : '<em>Not provided.</em>';
        $commentEsc = $adminComment !== '' ? nl2br(htmlspecialchars($adminComment)) : '<em>No comment provided.</em>';

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500;">Loan Rejected (Co-Maker Copy)</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151; line-height: 1.6;">Hello, Mr./Mrs. ' . $coMakerEsc . ',</p>
                            <p style="margin: 0 0 18px; font-size: 15px; color: #4b5563; line-height: 1.6;">
                                The loan application where you are listed as <strong>co-maker</strong> was <strong style="color:#8b0000;">rejected</strong>.
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 16px; background-color: #f8fafc; border: 2px dashed #8b0000; border-radius: 8px; padding: 18px 22px;">
                                <tr><td style="font-size: 13px; color: #6b7280;">Borrower</td></tr>
                                <tr><td style="font-size: 18px; font-weight: 700; color: #1f2937;">' . $borrowerEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Loan Purpose</td></tr>
                                <tr><td style="font-size: 15px; color: #1f2937; line-height: 1.6;">' . $purposeEsc . '</td></tr>
                                <tr><td style="font-size: 13px; color: #6b7280; padding-top: 12px;">Loan Amount</td></tr>
                                <tr><td style="font-size: 18px; font-weight: 700; color: #1f2937;">' . $amountEsc . '</td></tr>
                            </table>
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #1f2937;">Reviewer comment:</p>
                            <div style="background-color: #fef2f2; border-left: 4px solid #8b0000; padding: 12px 16px; margin-bottom: 16px; color: #374151; line-height: 1.55;">' . $commentEsc . '</div>
                            <p style="margin: 0; font-size: 13px; color: #9ca3af; line-height: 1.5;">This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">DepEd Loan System &middot; RA 11032 Ease of Doing Business &middot; This is an automated message. Please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        $mail->AltBody = "DepEd Loan System - Loan Rejected (Co-Maker Copy) - RA 11032 Ease of Doing Business\n\nHello, Mr./Mrs. " . $coMakerName . ",\n\nThe loan application where you are listed as co-maker was rejected.\n\nBorrower: " . $borrowerName . "\nLoan Purpose: " . $loanPurpose . "\nLoan Amount: " . number_format((float) $loanAmount, 2) . "\nReviewer comment: " . $adminComment . "\n\nRA 11032 Ease of Doing Business. Do not reply to this email.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Notify borrower that their loan has been APPROVED. Includes requirements they need to bring.
 */
function sendLoanApprovedEmail($toEmail, $borrowerName, $loanAmount, $loanId, $requirementsHtml = null) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $defaultRequirements = '
        <p style="margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #1f2937;">Requirements should be in two (2) copies:</p>
        <ul style="margin: 0 0 16px 0; padding-left: 20px; color: #374151; line-height: 1.8;">
            <li>Provident Fund Application Form (download: <a href="' . (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/download_provident_form.php') : 'download_provident_form.php') . '" target="_blank" style="color: #8b0000;">Click here to download the form</a>)</li>
            <li>Letter Request addressed to SDS (attach Pictures/ Registration Form/ Bills, etc.)</li>
            <li>Original Payslip (Latest month available at Cash Unit)</li>
            <li>Photocopy of Latest Payslip (Co-borrower only; should have monthly net pay of Php 5,000.00 after initial computation of loan amortization)</li>
            <li>Photocopy of Employee Deped No. or any valid government ID with Certificate of Employment from HR (with three (3) specimen signatures)</li>
            <li>Photocopy of Co-borrowers\' Employee Deped No. or any valid government ID (with three (3) specimen signatures)</li>
        </ul>
        <p style="margin: 0 0 8px; font-size: 14px; color: #6b7280; line-height: 1.5;">Please proceed to the office to submit these requirements for processing. Loan release is expected <strong>within seven (7) working days</strong>, subject to complete documentary compliance and standard verification procedures.</p>
        <p style="margin: 0; font-size: 14px; color: #6b7280;"><strong>Office hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM.</p>';
    $reqBlock = $requirementsHtml !== null && $requirementsHtml !== '' ? $requirementsHtml : $defaultRequirements;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Loan Approved – DepEd Loan System';
        $nameEsc = htmlspecialchars($borrowerName);
        $amountEsc = htmlspecialchars('₱' . number_format((float)$loanAmount, 2));
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #155724 0%, #1e7e34 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9);">Loan Approved</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151;">Hello, Mr./Mrs. ' . $nameEsc . ',</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">Your loan application has been <strong style="color: #155724;">approved</strong> in the system. Loan amount: <strong>' . $amountEsc . '</strong>.</p>
                            <p style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #1f2937;">Please go to the office to submit the following requirements for processing:</p>
                            ' . $reqBlock . '
                            <p style="margin: 16px 0 0; font-size: 13px; color: #9ca3af;">This is an automated message. For questions, contact your school/division office.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center;">DepEd Loan System &middot; RA 11032 Ease of Doing Business &middot; Do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Loan Approved (RA 11032 Ease of Doing Business)\n\nHello, Mr./Mrs. " . $borrowerName . ",\n\nYour loan application has been approved in the system. Loan amount: " . number_format((float)$loanAmount, 2) . ".\n\nRequirements should be in two (2) copies:\n1. Provident Fund Application Form (download from the loan system website when you log in)\n2. Letter Request addressed to SDS (attach Pictures/ Registration Form/ Bills, etc.)\n3. Original Payslip (Latest month available at Cash Unit)\n4. Photocopy of Latest Payslip (Co-borrower only; monthly net pay of Php 5,000.00 after initial computation)\n5. Photocopy of Employee Deped No. or any valid government ID with Certificate of Employment from HR (with three (3) specimen signatures)\n6. Photocopy of Co-borrowers' Employee Deped No. or any valid government ID (with three (3) specimen signatures)\n\nPlease proceed to the office to submit these requirements for processing. Loan release is expected within seven (7) working days, subject to complete documentary compliance and standard verification procedures.\n\nOffice hours: Monday – Friday, 8:00 AM – 5:00 PM.\n\nRA 11032 Ease of Doing Business. Do not reply to this email.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Notify borrower that their loan has been REJECTED. Includes admin comment (reason).
 */
function sendLoanRejectedEmail($toEmail, $borrowerName, $loanAmount, $loanId, $adminComment) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Loan Application Not Approved – DepEd Loan System';
        $nameEsc = htmlspecialchars($borrowerName);
        $amountEsc = htmlspecialchars('₱' . number_format((float)$loanAmount, 2));
        $commentEsc = $adminComment !== '' ? nl2br(htmlspecialchars($adminComment)) : '<em>No comment provided.</em>';
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9);">Loan Application Update</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151;">Hello, Mr./Mrs. ' . $nameEsc . ',</p>
                            <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.6;">Your loan application (amount: ' . $amountEsc . ') was <strong style="color: #8b0000;">not approved</strong>.</p>
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #1f2937;">Reason / comment from reviewer:</p>
                            <div style="background-color: #fef2f2; border-left: 4px solid #8b0000; padding: 12px 16px; margin-bottom: 16px; color: #374151;">' . $commentEsc . '</div>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">You may re-apply after addressing the points above. Log in to the DepEd Loan System to submit a new application if eligible.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center;">DepEd Loan System &middot; RA 11032 Ease of Doing Business &middot; Do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Loan Not Approved (RA 11032 Ease of Doing Business)\n\nHello, Mr./Mrs. " . $borrowerName . ",\n\nYour loan application (amount: " . number_format((float)$loanAmount, 2) . ") was not approved.\n\nReason: " . $adminComment . "\n\nYou may re-apply after addressing the points above. Log in to the system to submit a new application.\n\nRA 11032 Ease of Doing Business. Do not reply to this email.";

        applyOfficialMailFooter($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}

/**
 * Notify borrower that their loan has been RELEASED.
 */
function sendLoanReleasedEmail($toEmail, $borrowerName, $loanAmount, $loanId) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('Composer dependencies not installed. Run: composer install');
    }
    require __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Loan Released – DepEd Loan System';
        $nameEsc = htmlspecialchars($borrowerName);
        $amountEsc = htmlspecialchars('₱' . number_format((float)$loanAmount, 2));
        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: hidden;">
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b0000 0%, #a52a2a 100%); padding: 28px 32px; text-align: center;">
                            <p style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">DepEd Loan System</p>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.9);">Loan Released</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 28px;">
                            <p style="margin: 0 0 20px; font-size: 16px; color: #374151;">Hello, Mr./Mrs. ' . $nameEsc . ',</p>
                            <p style="margin: 0 0 14px; font-size: 15px; color: #4b5563; line-height: 1.6;">
                                Your loan application has been <strong style="color:#155724;">released</strong>.
                            </p>
                            <p style="margin: 0 0 18px; font-size: 15px; color: #4b5563; line-height: 1.6;">
                                Released amount: <strong>' . $amountEsc . '</strong>.
                            </p>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">
                                Please log in to your account to view your updated loan status and repayment details.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center;">DepEd Loan System &middot; RA 11032 Ease of Doing Business &middot; Do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        $mail->AltBody = "DepEd Loan System - Loan Released (RA 11032 Ease of Doing Business)\n\nHello, Mr./Mrs. " . $borrowerName . ",\n\nYour loan application has been released.\nReleased amount: " . number_format((float)$loanAmount, 2) . ".\n\nPlease log in to your account to view your updated loan status and repayment details.\n\nRA 11032 Ease of Doing Business. Do not reply to this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }
}
