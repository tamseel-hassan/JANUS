<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_dns_attacks.php

session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

function parseMessage($msg) {
    $parsed = [];
    if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
            $parsed[$m[1]] = $value;
        }
    }
    return $parsed;
}

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

$dns_filter = "(message LIKE '%dstport=53%' OR message LIKE '%service=\"DNS\"%' OR message LIKE '%service=DNS%')";

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $dns_filter $device_where";

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;
$archive_query = $has_archive ? "UNION ALL SELECT message, source_ip, received_at FROM syslog_entries_archive WHERE $where" : "";

$query = "
    SELECT message, source_ip, received_at
    FROM (
        SELECT message, source_ip, received_at
        FROM syslog_entries
        WHERE $where
        $archive_query
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 10000
";

$result = mysqli_query($con, $query);

$per_source = [];
$denied = 0;
$total = 0;
$long_query_hits = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $total++;
        $src = $row['source_ip'];
        $action = strtolower($p['action'] ?? '');
        if ($action === 'deny' || $action === 'block') $denied++;

        $qname = $p['qname'] ?? '';
        $is_long = strlen($qname) > 50;
        if ($is_long) $long_query_hits++;

        if (!isset($per_source[$src])) {
            $per_source[$src] = ['ip' => $src, 'queries' => 0, 'denied' => 0, 'long_queries' => 0];
        }
        $per_source[$src]['queries']++;
        if ($action === 'deny' || $action === 'block') $per_source[$src]['denied']++;
        if ($is_long) $per_source[$src]['long_queries']++;
    }
}

uasort($per_source, function($a, $b) { return $b['queries'] <=> $a['queries']; });

mysqli_close($con);

echo json_encode([
    'clients' => array_values(array_slice($per_source, 0, 50)),
    'total_events' => $total,
    'denied' => $denied,
    'long_query_hits' => $long_query_hits,
    'unique_clients' => count($per_source)
]);
?>
