<?php
require_once __DIR__ . '/../db_config.php';
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Auto-add columns if missing
$cols = [];
$r = mysqli_query($con, "SHOW COLUMNS FROM accounts");
while ($c = mysqli_fetch_assoc($r)) $cols[] = $c['Field'];
if (!in_array('role', $cols)) mysqli_query($con, "ALTER TABLE accounts ADD COLUMN role VARCHAR(20) DEFAULT 'analyst' AFTER username");
if (!in_array('email', $cols)) mysqli_query($con, "ALTER TABLE accounts ADD COLUMN email VARCHAR(255) AFTER password");
if (!in_array('is_active', $cols)) mysqli_query($con, "ALTER TABLE accounts ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER email");

$roles = [
    'admin'    => ['label' => 'Admin',    'color' => 'danger',  'desc' => 'Full access & user management'],
    'analyst'  => ['label' => 'Analyst',  'color' => 'primary', 'desc' => 'SOC/NOC analysis & incident response'],
    'operator' => ['label' => 'Operator', 'color' => 'info',    'desc' => 'Operations metrics only'],
];

// GET request for fetching users

// GET request for fetching users
$search     = trim($_GET['search'] ?? '');
$roleFilter = isset($_GET['role']) && array_key_exists($_GET['role'], $roles) ? $_GET['role'] : null;

$query = "SELECT id, username, role, email, is_active FROM accounts";
$conds = []; $params = [];
if ($search)     { $conds[] = "(username LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($roleFilter) { $conds[] = "role = ?"; $params[] = $roleFilter; }
if ($conds) $query .= " WHERE ".implode(" AND ",$conds);
$query .= " ORDER BY username";

$stmt = $con->prepare($query);
if ($params) $stmt->bind_param(str_repeat('s',count($params)),...$params);
$stmt->execute();
$users_result = $stmt->get_result();

$users = [];
while ($row = $users_result->fetch_assoc()) {
    $users[] = [
        'id' => $row['id'],
        'username' => $row['username'],
        'role' => $row['role'],
        'email' => $row['email'],
        'is_active' => (bool)$row['is_active']
    ];
}

echo json_encode(['users' => $users]);
exit;
