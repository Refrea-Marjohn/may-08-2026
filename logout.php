<?php
require_once 'config.php';

$actor_id = $_SESSION['user_id'] ?? null;
$actor_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Guest');
$actor_role = $_SESSION['role'] ?? 'guest';
log_audit($conn, 'LOGOUT', 'User logged out.', 'Logout', null, $actor_id, $actor_name, $actor_role);

// Destroy all session data
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
