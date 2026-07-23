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
$cache_key = get_bucketed_cache_key("api_get_report_ddos", $start, $end, $device, $range);

$response = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device) {
    $device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

    $check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    $has_archive = mysqli_num_rows($check_archive) > 0;

    $archive_query = $has_archive ? "
        UNION ALL
        SELECT source_ip, received_at FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
    " : "";

    $timeline_archive_query = $has_archive ? "
        UNION ALL
        SELECT received_at FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
    " : "";

    $flood_query = "
        SELECT source_ip, DATE_FORMAT(received_at, '%Y-%m-%d %H:%i:00') AS minute_bucket, COUNT(*) AS cnt
        FROM (
            SELECT source_ip, received_at FROM syslog_entries 
            WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
            $archive_query
        ) AS combined
        GROUP BY source_ip, minute_bucket
        HAVING cnt >= 100
        ORDER BY cnt DESC
        LIMIT 200
    ";
    
    $result = mysqli_query($con, $flood_query);
    $floods = [];
    $total_flood_events = 0;
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $floods[] = [
                'source_ip' => $row['source_ip'],
                'minute_bucket' => $row['minute_bucket'],
                'cnt' => (int)$row['cnt']
            ];
            $total_flood_events += (int)$row['cnt'];
        }
    }

    $timeline_query = "
        SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt
        FROM (
            SELECT received_at FROM syslog_entries 
            WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
            $timeline_archive_query
        ) AS combined
        GROUP BY hour
        ORDER BY hour ASC
    ";
    $tl_result = mysqli_query($con, $timeline_query);
    $timeline = [];
    if ($tl_result) {
        while ($row = mysqli_fetch_assoc($tl_result)) {
            $timeline[$row['hour']] = (int)$row['cnt'];
        }
    }

    return [
        'floods' => $floods,
        'total_flood_events' => $total_flood_events,
        'unique_sources' => count(array_unique(array_column($floods, 'source_ip'))),
        'timeline' => [
            'labels' => array_keys($timeline),
            'values' => array_values($timeline)
        ]
    ];
});

echo json_encode($response ?? [
    'floods' => [], 'total_flood_events' => 0, 'unique_sources' => 0,
    'timeline' => ['labels' => [], 'values' => []]
]);

mysqli_close($con);
?>
