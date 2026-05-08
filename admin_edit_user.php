<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_sql = $conn->prepare("SELECT role, username, full_name, email, profile_photo, deped_id FROM users WHERE id = ?");
$admin_sql->bind_param("i", $admin_id);
$admin_sql->execute();
$admin = $admin_sql->get_result()->fetch_assoc();
$admin_sql->close();

if (!($admin && (($admin['role'] ?? '') === 'admin' || ($admin['username'] ?? '') === 'admin'))) {
    header("Location: borrower_dashboard.php");
    exit();
}

$profile_photo = $admin['profile_photo'] ?? '';
$profile_photo_exists = $profile_photo && file_exists(__DIR__ . '/' . $profile_photo);

$target_id = (int) ($_GET['user_id'] ?? 0);
if ($target_id <= 0) {
    header("Location: manage_users.php");
    exit();
}

$stmt = $conn->prepare("SELECT id, full_name, username, email, contact_number, deped_id, home_address, role, COALESCE(NULLIF(user_status,''), 'active') AS user_status FROM users WHERE id = ?");
$stmt->bind_param("i", $target_id);
$stmt->execute();
$target_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target_user) {
    header("Location: manage_users.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_edit_user'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $deped_id = trim($_POST['deped_id'] ?? '');
    $home_address = trim($_POST['home_address'] ?? '');
    $user_status = trim($_POST['user_status'] ?? 'active');

    if (empty($username) || empty($email) || empty($contact_number)) {
        $error = 'Username, email, and contact number are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (!preg_match('/^09\d{9}$/', $contact_number)) {
        $error = 'Contact number must be 11 digits starting with 09 (format: 09XXXXXXXXX).';
    } elseif (!empty($deped_id) && strlen(preg_replace('/\D/', '', $deped_id)) !== 7) {
        $error = 'Employee Deped No. must be exactly 7 digits.';
    } elseif (!in_array($user_status, ['active', 'inactive', 'suspended'])) {
        $user_status = 'active';
    } else {
        $deped_id_clean = preg_replace('/\D/', '', $deped_id);

        $check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check->bind_param("ssi", $username, $email, $target_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'Username or email already exists.';
        } else {
            $up = $conn->prepare("UPDATE users SET username = ?, email = ?, contact_number = ?, deped_id = ?, home_address = ?, user_status = ? WHERE id = ?");
            $up->bind_param("ssssssi", $username, $email, $contact_number, $deped_id_clean, $home_address, $user_status, $target_id);
            if ($up->execute()) {
                $_SESSION['manage_users_success'] = 'User ' . htmlspecialchars($target_user['full_name']) . ' updated successfully.';
                header('Location: manage_users.php');
                exit;
            } else {
                $error = 'Failed to update user.';
            }
            $up->close();
        }
        $check->close();
    }
    if (!empty($error)) {
        $target_user['username'] = $username;
        $target_user['email'] = $email;
        $target_user['contact_number'] = $contact_number;
        $target_user['deped_id'] = $deped_id;
        $target_user['home_address'] = $home_address;
        $target_user['user_status'] = $user_status;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Manage Users</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/shared.css">
    <script src="assets/notifications.js" defer></script>
    <script src="assets/topbar.js" defer></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; min-height: 100vh; padding: 0; }
        .navbar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }
        .nav-icons { display: flex; align-items: center; gap: 1.5rem; position: relative; }
        .page-wrap { padding: 90px 2rem 2rem; }
        .edit-container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 2rem; }
        .edit-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; }
        .edit-header h1 { font-size: 1.4rem; color: #333; }
        .edit-header a { color: #8b0000; text-decoration: none; font-size: 0.95rem; }
        .edit-header a:hover { text-decoration: underline; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.35rem; font-weight: 600; color: #555; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .btn { padding: 0.6rem 1.25rem; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 0.95rem; }
        .btn-primary { background: linear-gradient(135deg, #8b0000 0%, #dc143c 100%); color: #fff; }
        .btn-primary:hover { opacity: 0.95; }
        .btn-outline { background: #fff; color: #8b0000; border: 1px solid #8b0000; }
        .btn-outline:hover { background: #fff5f5; }
        .form-actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="welcome-message">
            <div class="welcome-block">
                <div class="welcome-title">Administrator <strong><?php echo htmlspecialchars($admin['full_name'] ?? 'Admin'); ?></strong></div>
                <div class="welcome-meta">
                    <span class="meta-pill"><i class="fas fa-id-badge"></i> Admin</span>
                    <span><i class="fas fa-user-edit"></i> Edit User</span>
                </div>
            </div>
        </div>
        <div class="nav-icons">
            <?php include 'notifications.php'; ?>
            <div class="profile-trigger" title="Profile menu" onclick="toggleProfileDropdown()">
                <div class="profile-trigger-main">
                <div class="profile-icon">
                <?php if ($profile_photo_exists): ?>
                    <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                <?php else: ?>
                    <span class="profile-initial"><?php echo strtoupper(substr(($admin['full_name'] ?? 'A'), 0, 1)); ?></span>
                <?php endif; ?>
                <div class="status-indicator"></div>
                </div>
                <span class="profile-chevron" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-user-info">
                            <div class="dropdown-user-avatar">
                                <?php if ($profile_photo_exists): ?>
                                    <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <?php echo strtoupper(substr(($admin['full_name'] ?? 'A'), 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-user-details">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($admin['full_name'] ?? 'Admin'); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($admin['email'] ?? ''); ?></div>
                                <div class="dropdown-user-email">Employee Deped No.: <?php echo htmlspecialchars($admin['deped_id'] ?? 'Not set'); ?></div>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="dropdown-item" onclick="openProfileModal('profile'); return false;">
                        <i class="fas fa-user-edit"></i>
                        Update Profile
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item logout-item">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="page-wrap">
    <div class="edit-container">
        <div class="edit-header">
            <a href="manage_users.php"><i class="fas fa-arrow-left"></i> Back to Manage Users</a>
            <h1>Edit User: <?php echo htmlspecialchars($target_user['full_name']); ?></h1>
        </div>

        <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="admin_edit_user.php?user_id=<?php echo (int)$target_id; ?>">
            <input type="hidden" name="admin_edit_user" value="1">

            <div class="form-group">
                <label for="username">Username <span style="color:red;">*</span></label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($target_user['username'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="email">Email <span style="color:red;">*</span></label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($target_user['email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="contact_number">Contact Number <span style="color:red;">*</span></label>
                <input type="tel" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($target_user['contact_number'] ?? ''); ?>" required placeholder="09123456789" maxlength="11">
            </div>

            <div class="form-group">
                <label for="deped_id">Employee Deped No.</label>
                <input type="text" id="deped_id" name="deped_id" value="<?php echo htmlspecialchars($target_user['deped_id'] ?? ''); ?>" placeholder="1234567" maxlength="7">
            </div>

            <div class="form-group">
                <label for="home_address">Home Address</label>
                <textarea id="home_address" name="home_address"><?php echo htmlspecialchars($target_user['home_address'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label for="user_status">Status</label>
                <select id="user_status" name="user_status">
                    <option value="active" <?php echo ($target_user['user_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($target_user['user_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo ($target_user['user_status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="manage_users.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
    </div>
    <script>
        document.getElementById('contact_number').addEventListener('input', function(e) {
            var v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 0 && !v.startsWith('09')) v = '09' + v.substring(2);
            e.target.value = v;
        });
        document.getElementById('deped_id').addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0, 7);
        });
    </script>

    <div id="profileModalOverlay" class="profile-modal-overlay">
        <div class="profile-modal-content">
            <iframe id="profileModalFrame" src="" title="Profile Settings"></iframe>
        </div>
    </div>

    <script>
        function openProfileModal(tab) {
            const overlay = document.getElementById('profileModalOverlay');
            const frame = document.getElementById('profileModalFrame');
            const safeTab = tab === 'password' ? 'password' : 'profile';
            frame.src = 'profile_update.php?tab=' + safeTab + '&embed=1';
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProfileModal() {
            const overlay = document.getElementById('profileModalOverlay');
            const frame = document.getElementById('profileModalFrame');
            overlay.classList.remove('active');
            document.body.style.overflow = 'auto';
            frame.src = '';
        }

        document.addEventListener('click', function(event) {
            if (event.target && event.target.id === 'profileModalOverlay') {
                closeProfileModal();
            }
        });
    </script>
</body>
</html>
