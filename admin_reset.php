<?php
require_once 'config.php';

// Admin credentials to set
$new_email = 'sdoofcabuyao@gmail.com';
$new_username = 'sdoofcabuyao';
$new_password_plain = 'sdoofcabuyao';
$new_full_name = 'SDO Cabuyao';

$new_password_hash = password_hash($new_password_plain, PASSWORD_DEFAULT);

// Find an existing admin
$admin = null;
$result = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($result && $result->num_rows > 0) {
    $admin = $result->fetch_assoc();
}

if ($admin) {
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, full_name = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $new_username, $new_email, $new_password_hash, $new_full_name, $admin['id']);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        log_audit(
            $conn,
            'UPDATE',
            'Admin account reset/update executed.',
            'Admin Reset',
            "User #{$admin['id']}",
            $admin['id'],
            $new_full_name,
            'admin'
        );
        echo "Admin account updated.\n";
    } else {
        echo "Failed to update admin account.\n";
    }
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, 'admin')");
    $stmt->bind_param("ssss", $new_username, $new_email, $new_password_hash, $new_full_name);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $new_admin_id = $conn->insert_id;
        log_audit(
            $conn,
            'CREATE',
            'Admin account created via reset script.',
            'Admin Reset',
            $new_admin_id ? "User #{$new_admin_id}" : $new_full_name,
            $new_admin_id,
            $new_full_name,
            'admin'
        );
        echo "Admin account created.\n";
    } else {
        echo "Failed to create admin account.\n";
    }
}
