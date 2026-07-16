<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Error']);
    exit;
}

$devices = [];
$res = mysqli_query($con, "SELECT DISTINCT source_ip FROM syslog_entries ORDER BY source_ip");
while ($r = mysqli_fetch_assoc($res)) {
    $devices[] = $r['source_ip'];
}

echo json_encode([
    'devices' => $devices
]);
