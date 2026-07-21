<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_endpoint_activity.php

session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$check_tables = mysqli_query($con, "SHOW TABLES LIKE 'endpoints'");
if (!$check_tables || mysqli_num_rows($check_tables) === 0) {
    echo json_encode([
        'error_type' => 'missing_table',
        'error' => 'Endpoint tables not found yet. Run sql/endpoint_schema.sql against your database, deploy agent/janus_agent.ps1 to a Windows client via Scheduled Task, and this page will populate automatically.'
    ]);
    mysqli_close($con);
    exit;
}

$endpoints_res = mysqli_query($con, "SELECT * FROM endpoints ORDER BY last_seen DESC");
$endpoints = [];
while ($row = mysqli_fetch_assoc($endpoints_res)) {
    $endpoints[] = [
        'id' => $row['id'],
        'hostname' => $row['hostname'],
        'ip_address' => $row['ip_address'],
        'os_version' => $row['os_version'],
        'agent_version' => $row['agent_version'],
        'last_seen' => $row['last_seen']
    ];
}

$online_cutoff = date('Y-m-d H:i:s', strtotime('-10 minutes'));
$online_count = count(array_filter($endpoints, function($e) use ($online_cutoff) {
    return $e['last_seen'] >= $online_cutoff;
}));

$total_apps_res = mysqli_query($con, "SELECT COUNT(DISTINCT app_name) AS c FROM endpoint_apps");
$total_apps = $total_apps_res ? (int)mysqli_fetch_assoc($total_apps_res)['c'] : 0;

$rdp_24h_res = mysqli_query($con, "SELECT COUNT(*) AS c FROM endpoint_rdp_sessions WHERE event_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$rdp_24h = $rdp_24h_res ? (int)mysqli_fetch_assoc($rdp_24h_res)['c'] : 0;

mysqli_close($con);

echo json_encode([
    'endpoints' => $endpoints,
    'total_endpoints' => count($endpoints),
    'online_count' => $online_count,
    'total_apps' => $total_apps,
    'rdp_24h' => $rdp_24h,
    'online_cutoff' => $online_cutoff
]);
?>
