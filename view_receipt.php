<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Not logged in');
}

$stmt = $conn->prepare("SELECT role, username FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$is_admin = ($user['role'] ?? '') === 'admin' || ($user['username'] ?? '') === 'admin';
$is_accountant = user_is_accountant_role($user['role'] ?? null);
$is_borrower = !$is_admin && !$is_accountant;
if (!$user) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

$file = isset($_GET['file']) ? trim($_GET['file']) : '';
if ($file === '' || preg_match('/[^a-zA-Z0-9_\-\.]/', $file)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid file');
}

$base_candidates = [];
$primary_base = defined('RECEIPT_UPLOAD_DIR') ? RECEIPT_UPLOAD_DIR : (__DIR__ . '/storage_private/receipts');
$base_candidates[] = rtrim($primary_base, "/\\");
$base_candidates[] = __DIR__ . '/uploads/receipts'; // legacy

$path = false;
foreach ($base_candidates as $base_dir) {
    $base_real = realpath($base_dir);
    if ($base_real === false) {
        continue;
    }
    $candidate = realpath($base_real . DIRECTORY_SEPARATOR . $file);
    if ($candidate !== false && strpos($candidate, $base_real) === 0 && is_file($candidate)) {
        $path = $candidate;
        break;
    }
}
if ($path === false) {
    header('HTTP/1.1 404 Not Found');
    exit('Receipt not found');
}

// Borrowers can only view receipts that belong to their own loans.
if ($is_borrower) {
    $owner_stmt = $conn->prepare(
        "SELECT d.id
         FROM deductions d
         JOIN loans l ON l.id = d.loan_id
         WHERE d.receipt_filename = ? AND l.user_id = ?
         LIMIT 1"
    );
    if (!$owner_stmt) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('Unable to verify receipt access');
    }
    $uid = (int) $_SESSION['user_id'];
    $owner_stmt->bind_param("si", $file, $uid);
    $owner_stmt->execute();
    $owned = $owner_stmt->get_result()->fetch_assoc();
    $owner_stmt->close();
    if (!$owned) {
        header('HTTP/1.1 403 Forbidden');
        exit('Unauthorized');
    }
} elseif (!$is_admin && !$is_accountant) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf'
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

$raw = isset($_GET['raw']) && $_GET['raw'] === '1';
if ($raw) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    readfile($path);
    exit;
}

$safe_file = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
$raw_url = 'view_receipt.php?file=' . rawurlencode($file) . '&raw=1';
$safe_raw_url = htmlspecialchars($raw_url, ENT_QUOTES, 'UTF-8');
$is_pdf = $ext === 'pdf';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt Viewer</title>
    <style>
        html, body {
            width: 100%;
            height: 100%;
            margin: 0;
            background: #f1f5f9;
            font-family: "Inter", "Segoe UI", Tahoma, sans-serif;
        }
        .rv-wrap {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            box-sizing: border-box;
        }
        .rv-stage {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
            border-radius: 10px;
            background: #e2e8f0;
        }
        .rv-img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
            margin: auto;
        }
        .rv-pdf {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
    </style>
</head>
<body>
    <div class="rv-wrap" aria-label="Receipt preview for <?php echo $safe_file; ?>">
        <div class="rv-stage">
            <?php if ($is_pdf): ?>
                <iframe class="rv-pdf" src="<?php echo $safe_raw_url; ?>" title="Receipt PDF"></iframe>
            <?php else: ?>
                <img class="rv-img" src="<?php echo $safe_raw_url; ?>" alt="Receipt image">
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
