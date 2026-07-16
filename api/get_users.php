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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    if (isset($data['action'])) {
        if ($data['action'] === 'create') {
            $username = trim($data['username']);
            $password = password_hash($data['password'], PASSWORD_DEFAULT);
            $email    = trim($data['email'] ?? '');
            $role     = in_array($data['role'], array_keys($roles)) ? $data['role'] : 'analyst';
            
            if (empty($username)) {
                echo json_encode(['error' => 'Username is required!']);
                exit;
            }
            $stmt = $con->prepare("SELECT id FROM accounts WHERE username = ?");
            $stmt->bind_param("s", $username); $stmt->execute(); $stmt->store_result();
            if ($stmt->num_rows > 0) {
                echo json_encode(['error' => 'Username already exists!']);
                exit;
            }
            $stmt = $con->prepare("INSERT INTO accounts (username, password, email, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $password, $email, $role);
            if ($stmt->execute()) {
                echo json_encode(['success' => "User '{$username}' created."]);
            } else {
                echo json_encode(['error' => 'Failed to create user: '.$stmt->error]);
            }
            exit;
        }
        
        if (isset($data['id'])) {
            $id = (int)$data['id'];
            switch ($data['action']) {
                case 'reset_password':
                    $np = password_hash('newpassword123', PASSWORD_DEFAULT);
                    $s  = $con->prepare("UPDATE accounts SET password=? WHERE id=?");
                    $s->bind_param("si",$np,$id); $s->execute();
                    echo json_encode(['success' => 'Password reset to "newpassword123".']);
                    break;
                case 'change_role':
                    $nr = in_array($data['new_role'], array_keys($roles)) ? $data['new_role'] : 'analyst';
                    $s  = $con->prepare("UPDATE accounts SET role=? WHERE id=? AND username != 'admin'");
                    $s->bind_param("si",$nr,$id); $s->execute();
                    echo json_encode(['success' => 'Role updated.']);
                    break;
                case 'disable_user':
                    $s = $con->prepare("UPDATE accounts SET is_active=0 WHERE id=? AND username != 'admin'");
                    $s->bind_param("i",$id); $s->execute();
                    echo json_encode(['success' => 'User disabled.']);
                    break;
                case 'enable_user':
                    $s = $con->prepare("UPDATE accounts SET is_active=1 WHERE id=?");
                    $s->bind_param("i",$id); $s->execute();
                    echo json_encode(['success' => 'User enabled.']);
                    break;
                case 'delete':
                    $s = $con->prepare("DELETE FROM accounts WHERE id=? AND username != 'admin'");
                    $s->bind_param("i",$id);
                    if ($s->execute()) {
                        echo json_encode(['success' => 'User deleted.']);
                    } else {
                        echo json_encode(['error' => 'Failed to delete user.']);
                    }
                    break;
                default:
                    echo json_encode(['error' => 'Invalid action.']);
            }
            exit;
        }
    }
}

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
