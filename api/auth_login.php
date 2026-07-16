<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = trim($body['password'] ?? '');

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$stmt = $con->prepare('SELECT id, password, role, username FROM accounts WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
    $stmt->close();
    $con->close();
    exit;
}

$stmt->bind_result($id, $hashed_password, $role, $db_username);
$stmt->fetch();

if (!password_verify($password, $hashed_password)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
    $stmt->close();
    $con->close();
    exit;
}

// Success — regenerate session ID and set session variables
session_regenerate_id(true);
$_SESSION['loggedin'] = TRUE;
$_SESSION['name']     = $db_username;
$_SESSION['id']       = $id;
$_SESSION['role']     = $role ?? 'analyst';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmt->close();
$con->close();

echo json_encode([
    'success' => true,
    'user' => [
        'id'       => $id,
        'username' => $db_username,
        'role'     => $role ?? 'analyst',
    ]
]);
