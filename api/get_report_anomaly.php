<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_anomaly.php

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

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "' $device_where";

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;
$archive_query_agg = $has_archive ? "UNION ALL SELECT source_ip FROM syslog_entries_archive WHERE $where" : "";

$agg_query = "
    SELECT source_ip, COUNT(*) AS cnt FROM (
        SELECT source_ip FROM syslog_entries WHERE $where
        $archive_query_agg
    ) AS combined
    GROUP BY source_ip
";
$result = mysqli_query($con, $agg_query);

$counts = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[$row['source_ip']] = (int)$row['cnt'];
    }
}

$n = count($counts);
$mean = $n ? array_sum($counts) / $n : 0;
$variance = 0;
foreach ($counts as $c) { $variance += pow($c - $mean, 2); }
$stddev = $n ? sqrt($variance / $n) : 0;

$anomalies = [];
foreach ($counts as $ip => $c) {
    $z = $stddev > 0 ? round(($c - $mean) / $stddev, 2) : 0;
    if ($z >= 2) {
        $anomalies[] = ['ip' => $ip, 'count' => $c, 'z' => $z];
    }
}
usort($anomalies, function($a, $b) { return $b['z'] <=> $a['z']; });

$archive_query_tl = $has_archive ? "UNION ALL SELECT received_at FROM syslog_entries_archive WHERE $where" : "";
$timeline_query = "
    SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt FROM (
        SELECT received_at FROM syslog_entries WHERE $where
        $archive_query_tl
    ) AS combined GROUP BY hour ORDER BY hour ASC
";
$tl_result = mysqli_query($con, $timeline_query);
$timeline = [];
if ($tl_result) { 
    while ($row = mysqli_fetch_assoc($tl_result)) { 
        $timeline[$row['hour']] = (int)$row['cnt']; 
    } 
}

mysqli_close($con);

echo json_encode([
    'anomalies' => $anomalies,
    'mean' => round($mean),
    'stddev' => round($stddev),
    'analyzed_count' => $n,
    'timeline' => [
        'labels' => array_keys($timeline),
        'values' => array_values($timeline)
    ]
]);
?>
