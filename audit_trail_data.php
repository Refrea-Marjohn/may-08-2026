<?php
define('AUDIT_LOGGING_DISABLED', true);
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_admin = ($user['role'] ?? '') === 'admin' || ($user['username'] ?? '') === 'admin';
if (!$is_admin) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$q = trim($_GET['q'] ?? '');
$actor = trim($_GET['actor'] ?? '');
$role = trim($_GET['role'] ?? '');
$action = trim($_GET['action'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$sort = $_GET['sort'] ?? 'date_desc';
$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1) $limit = 1;
if ($limit > 500) $limit = 500;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sort_map = [
    'date_desc' => 'created_at DESC',
    'date_asc' => 'created_at ASC',
    'actor_asc' => 'actor_name ASC',
    'actor_desc' => 'actor_name DESC',
    'action_asc' => 'action_type ASC',
    'action_desc' => 'action_type DESC'
];
$order_by = $sort_map[$sort] ?? $sort_map['date_desc'];

$conditions = [];
$params = [];
$types = '';

if ($q !== '') {
    $like = '%' . $q . '%';
    $conditions[] = "(actor_name LIKE ? OR action_type LIKE ? OR page_name LIKE ? OR description LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

if ($actor !== '') {
    $like_actor = '%' . $actor . '%';
    $conditions[] = "actor_name LIKE ?";
    $params[] = $like_actor;
    $types .= 's';
}

if ($role !== '' && $role !== 'all') {
    $conditions[] = "user_role = ?";
    $params[] = $role;
    $types .= 's';
}

if ($action !== '' && $action !== 'all') {
    $conditions[] = "action_type = ?";
    $params[] = strtoupper($action);
    $types .= 's';
}

if ($from !== '') {
    $from_dt = DateTime::createFromFormat('Y-m-d', $from);
    if ($from_dt) {
        $conditions[] = "created_at >= ?";
        $params[] = $from_dt->format('Y-m-d 00:00:00');
        $types .= 's';
    }
}

if ($to !== '') {
    $to_dt = DateTime::createFromFormat('Y-m-d', $to);
    if ($to_dt) {
        $conditions[] = "created_at <= ?";
        $params[] = $to_dt->format('Y-m-d 23:59:59');
        $types .= 's';
    }
}

$filter_params = $params;
$filter_types = $types;

$sql = "SELECT audit_logs.id, audit_logs.actor_name, audit_logs.user_role, audit_logs.action_type, audit_logs.page_name, audit_logs.description, audit_logs.created_at, users.username AS actor_username, users.email AS actor_email FROM audit_logs LEFT JOIN users ON users.id = audit_logs.actor_id";
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(' AND ', $conditions);
}
$sql .= " ORDER BY {$order_by} LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query failed']);
    exit;
}

$types .= 'ii';
$params[] = $limit;
$params[] = $offset;
$bind_params = [];
$bind_params[] = $types;
foreach ($params as $key => $value) {
    $bind_params[] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bind_params);
$stmt->execute();
$result = $stmt->get_result();
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();

$count_sql = "SELECT COUNT(*) AS total FROM audit_logs";
if (!empty($conditions)) {
    $count_sql .= " WHERE " . implode(' AND ', $conditions);
}
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    if (!empty($conditions)) {
        $count_params = [];
        $count_params[] = $filter_types;
        foreach ($filter_params as $key => $value) {
            $count_params[] = &$filter_params[$key];
        }
        call_user_func_array([$count_stmt, 'bind_param'], $count_params);
    }
    $count_stmt->execute();
    $total_row = $count_stmt->get_result()->fetch_assoc();
    $total = (int)($total_row['total'] ?? 0);
    $count_stmt->close();
} else {
    $total = 0;
}

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'server_time' => date('Y-m-d H:i:s'),
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total
    ]
]);
