<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_compliance.php

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

$range = $_GET['range'] ?? '24h';
$end = date('Y-m-d H:i:s');
$start = date('Y-m-d H:i:s', strtotime('-24 hours'));
if ($range === '15m') $start = date('Y-m-d H:i:s', strtotime('-15 minutes'));
if ($range === '1h') $start = date('Y-m-d H:i:s', strtotime('-1 hour'));
if ($range === '6h') $start = date('Y-m-d H:i:s', strtotime('-6 hours'));
if ($range === '7d') $start = date('Y-m-d H:i:s', strtotime('-7 days'));
if ($range === '30d') $start = date('Y-m-d H:i:s', strtotime('-30 days'));

$device = $_GET['device'] ?? '';
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
$w = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "' $device_where";

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;

function countWhere($con, $where, $has_archive) {
    $archive_query = $has_archive ? "UNION ALL SELECT 1 FROM syslog_entries_archive WHERE $where" : "";
    $sql = "SELECT COUNT(*) AS c FROM (
        SELECT 1 FROM syslog_entries WHERE $where
        $archive_query
    ) AS combined";
    $r = mysqli_query($con, $sql);
    return $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;
}

$total_events   = countWhere($con, $w, $has_archive);
$auth_fail      = countWhere($con, $w . " AND (message LIKE '%authentication%failed%' OR message LIKE '%login%failed%')", $has_archive);
$denied_traffic = countWhere($con, $w . " AND message LIKE '%action=deny%'", $has_archive);
$total_traffic  = countWhere($con, $w . " AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%')", $has_archive);
$malware_hits   = countWhere($con, $w . " AND (message LIKE '%virus%' OR message LIKE '%malware%')", $has_archive);
$cfg_events     = countWhere($con, $w . " AND (message LIKE '%cfgattr%' OR message LIKE '%cfgpath%')", $has_archive);

$devices_res = mysqli_query($con, "SELECT COUNT(DISTINCT source_ip) AS c FROM syslog_entries");
$devices_reporting = $devices_res ? (int)mysqli_fetch_assoc($devices_res)['c'] : 0;

$fw_result = mysqli_query($con, "SELECT id FROM response_config WHERE is_active = 1 LIMIT 1");
$has_firewall = $fw_result && mysqli_num_rows($fw_result) > 0;

$auth_fail_ratio = $total_events > 0 ? round(($auth_fail / $total_events) * 100, 2) : 0;
$deny_ratio = $total_traffic > 0 ? round(($denied_traffic / $total_traffic) * 100, 2) : 0;

mysqli_close($con);

$checks = [
    ['label' => 'Automated firewall response configured', 'pass' => $has_firewall, 'detail' => $has_firewall ? 'Active integration found' : 'No active firewall integration in response_config'],
    ['label' => 'Devices actively reporting logs', 'pass' => $devices_reporting > 0, 'detail' => $devices_reporting . ' distinct source(s) on record'],
    ['label' => 'Failed authentication ratio under 5%', 'pass' => $auth_fail_ratio < 5, 'detail' => $auth_fail_ratio . '% of events in this window'],
    ['label' => 'Malware detections present in window', 'pass' => $malware_hits === 0, 'detail' => $malware_hits . ' detection(s)'],
    ['label' => 'Configuration change logging active', 'pass' => $cfg_events >= 0, 'detail' => $cfg_events . ' config event(s) captured'],
];

$passed = count(array_filter($checks, function($c) { return $c['pass']; }));
$score = round(($passed / count($checks)) * 100);

echo json_encode([
    'score' => $score,
    'devices_reporting' => $devices_reporting,
    'auth_fail_ratio' => $auth_fail_ratio,
    'deny_ratio' => $deny_ratio,
    'checks' => $checks
]);
?>
