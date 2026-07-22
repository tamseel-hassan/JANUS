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

$logs = [];
$res = mysqli_query($con, "
    SELECT se.*, ss.appliance_type AS source_type
    FROM syslog_entries se
    LEFT JOIN syslog_sources ss ON se.source_id = ss.id
    ORDER BY se.received_at DESC
    LIMIT 200
");

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $r['message'] = mb_convert_encoding($r['message'], 'UTF-8', 'UTF-8');
        $logs[] = $r;
    }
}

echo json_encode(['logs' => $logs]);
