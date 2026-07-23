<?php
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/cache.php';

session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();

header('Content-Type: application/json');

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$range  = $_GET['range']  ?? '24h';
$device = $_GET['device'] ?? '';
$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');

if ($range === '15m') $start = date('Y-m-d H:i:s', strtotime('-15 minutes'));
if ($range === '1h')  $start = date('Y-m-d H:i:s', strtotime('-1 hour'));
if ($range === '6h')  $start = date('Y-m-d H:i:s', strtotime('-6 hours'));
if ($range === '7d')  $start = date('Y-m-d H:i:s', strtotime('-7 days'));
if ($range === '30d') $start = date('Y-m-d H:i:s', strtotime('-30 days'));

$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("api_get_report_anomaly", $start, $end, $device, $range);

$response = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device, $range) {
    $device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
    $is_large_range = ($range === '7d' || $range === '30d');

    $check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    $has_archive = mysqli_num_rows($check_archive) > 0;

    if ($is_large_range) {
        $rollup_device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
        $agg_query = "
            SELECT source_ip, SUM(flow_count) AS cnt
            FROM syslog_traffic_daily
            WHERE log_date BETWEEN DATE('$start') AND DATE('$end')
              $rollup_device_where
            GROUP BY source_ip
        ";
    } else {
        $archive_query = $has_archive ? "
            UNION ALL
            SELECT source_ip FROM syslog_entries_archive 
            WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
        " : "";
        $agg_query = "
            SELECT source_ip, COUNT(*) AS cnt FROM (
                SELECT source_ip FROM syslog_entries 
                WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
                $archive_query
            ) AS combined
            GROUP BY source_ip
        ";
    }

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
        $z = $stddev > 0 ? ($c - $mean) / $stddev : 0;
        if ($z >= 2.0) {
            $anomalies[] = [
                'source_ip' => $ip,
                'event_count' => $c,
                'z_score' => round($z, 2),
                'status' => $z >= 3.0 ? 'Critical Anomaly' : 'High Anomaly'
            ];
        }
    }

    usort($anomalies, function($a, $b) { return $b['z_score'] <=> $a['z_score']; });

    return [
        'mean_events' => round($mean, 1),
        'stddev' => round($stddev, 1),
        'anomaly_count' => count($anomalies),
        'anomalies' => $anomalies
    ];
});

echo json_encode($response ?? [
    'mean_events' => 0, 'stddev' => 0, 'anomaly_count' => 0, 'anomalies' => []
]);

mysqli_close($con);
?>
