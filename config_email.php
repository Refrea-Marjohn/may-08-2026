<?php
/**
 * Gmail SMTP configuration for sending OTP emails.
 *
 * SETUP (Gmail):
 * 1. Enable 2-Step Verification on your Google Account:
 *    https://myaccount.google.com/security
 * 2. Create an App Password:
 *    Google Account → Security → 2-Step Verification → App passwords
 *    Select "Mail" and "Other (Custom name)" → name it "DepEd Loan" → Generate
 * 3. Copy the 16-character password and set it below (no spaces).
 */

define('MAIL_FROM_EMAIL', 'sdoofcabuyao@gmail.com');  // Your Gmail address
define('MAIL_FROM_NAME', 'SDO Cabuyao City');
define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USER', 'sdoofcabuyao@gmail.com');    // Same as MAIL_FROM_EMAIL
define('MAIL_SMTP_PASS', 'erkt kijo vtsk cgnv');                         // 16-char App Password (from step 2)
define('MAIL_SMTP_SECURE', 'tls');
