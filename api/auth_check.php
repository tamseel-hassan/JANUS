<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== TRUE) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$role = $_SESSION['role'] ?? 'analyst';
$username = $_SESSION['name'] ?? '';
$id = $_SESSION['id'] ?? 0;

if ($con) {
    $stmt = $con->prepare('SELECT username, role FROM accounts WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($username, $role);
    $stmt->fetch();
    $stmt->close();
    $con->close();
}

echo json_encode([
    'authenticated' => true,
    'user' => [
        'id'       => (int)$id,
        'username' => $username,
        'role'     => $role,
    ]
]);
