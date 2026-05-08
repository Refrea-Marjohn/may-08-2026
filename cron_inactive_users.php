<?php
/**
 * Auto-set user_status = 'inactive' for users who have not logged in for 28 days (4 weeks).
 * Run once per day (e.g. via cron or triggered by login.php).
 * Usage: php cron_inactive_users.php   OR  require_once 'cron_inactive_users.php'; run_inactive_users_check($conn);
 */

require_once __DIR__ . '/config.php';

$INACTIVE_INTERVAL_DAYS = 28;
$RUN_INTERVAL_SECONDS = 86400; // 24 hours
$MARKER_FILE = __DIR__ . '/data/last_inactive_run.txt';

function run_inactive_users_check($conn) {
    global $INACTIVE_INTERVAL_DAYS, $RUN_INTERVAL_SECONDS, $MARKER_FILE;

    // Ensure last_login_at column exists
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'user_status'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN user_status VARCHAR(20) NOT NULL DEFAULT 'active'");
    }

    $dir = dirname($MARKER_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $last_run = 0;
    if (file_exists($MARKER_FILE)) {
        $last_run = (int) @file_get_contents($MARKER_FILE);
    }
    if ((time() - $last_run) < $RUN_INTERVAL_SECONDS) {
        return;
    }

    // Mark inactive: no login in 28 days, exclude admin
    $stmt = $conn->prepare("
        UPDATE users
        SET user_status = 'inactive'
        WHERE last_login_at IS NOT NULL
          AND last_login_at < DATE_SUB(NOW(), INTERVAL ? DAY)
          AND COALESCE(NULLIF(user_status,''), 'active') = 'active'
          AND (role IS NULL OR role != 'admin')
          AND (username IS NULL OR username != 'admin')
    ");
    $stmt->bind_param('i', $INACTIVE_INTERVAL_DAYS);
    $stmt->execute();
    $stmt->close();

    @file_put_contents($MARKER_FILE, (string) time());
}

// When run from CLI or included after config
if (isset($conn)) {
    run_inactive_users_check($conn);
}
