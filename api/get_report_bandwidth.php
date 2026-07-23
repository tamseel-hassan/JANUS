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
$cache_key = get_bucketed_cache_key("api_get_report_bandwidth", $start, $end, $device, $range);

$response = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device, $range) {
    $device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
    $is_large_range = ($range === '7d' || $range === '30d');

    $check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    $has_archive = mysqli_num_rows($check_archive) > 0;

    if ($is_large_range) {
        $rollup_device_where = $device ? "AND (source_ip = '" . mysqli_real_escape_string($con, $device) . "' OR destination_ip = '" . mysqli_real_escape_string($con, $device) . "')" : '';
        $query = "
            SELECT log_date AS received_at, source_ip, app, action, flow_count, total_sent AS sent_bytes, total_rcvd AS rcvd_bytes
            FROM syslog_traffic_daily
            WHERE log_date BETWEEN DATE('$start') AND DATE('$end')
              $rollup_device_where
        ";
    } else {
        $archive_query = $has_archive ? "
            UNION ALL
            SELECT src_ip, dst_ip, app, action, sent_bytes, rcvd_bytes, received_at, source_ip
            FROM syslog_entries_archive
            WHERE received_at BETWEEN '$start' AND '$end'
              AND src_ip IS NOT NULL
              $device_where
        " : "";

        $query = "
            SELECT src_ip, dst_ip, app, action, sent_bytes, rcvd_bytes, received_at, source_ip
            FROM (
                SELECT src_ip, dst_ip, app, action, sent_bytes, rcvd_bytes, received_at, source_ip
                FROM syslog_entries
                WHERE received_at BETWEEN '$start' AND '$end'
                  AND src_ip IS NOT NULL
                  $device_where
                $archive_query
            ) AS combined
            ORDER BY received_at DESC
            LIMIT 50000
        ";
    }

    $result = mysqli_query($con, $query);
    if (!$result) return null;

    $per_device = [];
    $sent_timeline = [];
    $rcvd_timeline = [];
    $total_sent = 0;
    $total_rcvd = 0;
    $peak_hour = null;
    $peak_value = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $flows = isset($row['flow_count']) ? intval($row['flow_count']) : 1;
        $sent = intval($row['sent_bytes'] ?? 0);
        $rcvd = intval($row['rcvd_bytes'] ?? 0);
        $total_sent += $sent;
        $total_rcvd += $rcvd;

        $dev = $row['source_ip'] ?: ($row['src_ip'] ?? 'unknown');
        if (!isset($per_device[$dev])) {
            $per_device[$dev] = ['device' => $dev, 'sent' => 0, 'rcvd' => 0, 'flows' => 0];
        }
        $per_device[$dev]['sent'] += $sent;
        $per_device[$dev]['rcvd'] += $rcvd;
        $per_device[$dev]['flows'] += $flows;

        $hour = date('Y-m-d H:00', strtotime($row['received_at']));
        $sent_timeline[$hour] = ($sent_timeline[$hour] ?? 0) + $sent;
        $rcvd_timeline[$hour] = ($rcvd_timeline[$hour] ?? 0) + $rcvd;

        $total_hour_bw = $sent_timeline[$hour] + $rcvd_timeline[$hour];
        if ($total_hour_bw > $peak_value) {
            $peak_value = $total_hour_bw;
            $peak_hour = $hour;
        }
    }

    ksort($sent_timeline);
    ksort($rcvd_timeline);

    return [
        'total_sent' => $total_sent,
        'total_rcvd' => $total_rcvd,
        'total_bandwidth' => $total_sent + $total_rcvd,
        'peak_hour' => $peak_hour ?? 'N/A',
        'peak_value' => $peak_value,
        'per_device' => array_values($per_device),
        'timeline' => [
            'labels' => array_keys($sent_timeline),
            'sent' => array_values($sent_timeline),
            'rcvd' => array_values($rcvd_timeline)
        ]
    ];
});

echo json_encode($response ?? [
    'total_sent' => 0, 'total_rcvd' => 0, 'total_bandwidth' => 0,
    'peak_hour' => 'N/A', 'peak_value' => 0, 'per_device' => [],
    'timeline' => ['labels' => [], 'sent' => [], 'rcvd' => []]
]);

mysqli_close($con);
?>
