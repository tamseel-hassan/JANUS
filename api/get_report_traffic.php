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

// Helper parse
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
    echo json_encode(['error' => 'DB connection failed: ' . mysqli_connect_error()]);
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
$cache_key = get_bucketed_cache_key("api_get_report_traffic", $start, $end, $device, $range);

$response = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device, $range) {
    $device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
    $is_large_range = ($range === '7d' || $range === '30d');

    $check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    $has_archive = mysqli_num_rows($check_archive) > 0;

    if ($is_large_range) {
        $rollup_device_where = $device ? "AND (source_ip = '" . mysqli_real_escape_string($con, $device) . "' OR destination_ip = '" . mysqli_real_escape_string($con, $device) . "')" : '';
        $query = "
            SELECT log_date AS received_at, source_ip AS src_ip, destination_ip AS dst_ip, 
                   app, service, action, flow_count, total_sent AS sent_bytes, total_rcvd AS rcvd_bytes
            FROM syslog_traffic_daily
            WHERE log_date BETWEEN DATE('$start') AND DATE('$end')
              $rollup_device_where
        ";
    } else {
        $archive_query = $has_archive ? "
            UNION ALL
            SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at, message
            FROM syslog_entries_archive
            WHERE received_at BETWEEN '$start' AND '$end'
              AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%')
              $device_where
        " : "";

        $query = "
            SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at, message
            FROM (
                SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at, message
                FROM syslog_entries
                WHERE received_at BETWEEN '$start' AND '$end'
                  AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%')
                  $device_where
                $archive_query
            ) AS combined
            ORDER BY received_at DESC
            LIMIT 50000
        ";
    }

    $result = mysqli_query($con, $query);
    if (!$result) return null;

    $total_flows = 0;
    $total_sent = 0;
    $total_received = 0;
    $sources = [];
    $destinations = [];
    $bandwidth_timeline = [];
    $traffic_dist = ['accepted' => 0, 'denied' => 0, 'timeout' => 0, 'other' => 0];
    $applications = [];
    $services = [];
    $protocols = [];
    $countries = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if ($is_large_range) {
            $src = $row['src_ip'] ?? 'unknown';
            $dst = $row['dst_ip'] ?? 'unknown';
            $sent = intval($row['sent_bytes'] ?? 0);
            $rcvd = intval($row['rcvd_bytes'] ?? 0);
            $flows = intval($row['flow_count'] ?? 1);
            $app = $row['app'] ?: 'Unknown';
            $service = $row['service'] ?: 'Unknown';
            $action = strtolower($row['action'] ?? 'accept');
        } else {
            $parsed = parseMessage($row['message'] ?? '');
            $src = $row['src_ip'] ?: ($parsed['srcip'] ?? null);
            $dst = $row['dst_ip'] ?: ($parsed['dstip'] ?? null);
            if (!$src || !$dst) continue;

            $sent = intval($row['sent_bytes'] ?? $parsed['sentbyte'] ?? 0);
            $rcvd = intval($row['rcvd_bytes'] ?? $parsed['rcvdbyte'] ?? 0);
            $flows = 1;
            $app = $row['app'] ?: ($parsed['app'] ?? $parsed['appcat'] ?? 'Unknown');
            $service = $row['service'] ?: ($parsed['service'] ?? $parsed['proto'] ?? 'Unknown');
            $action = strtolower($row['action'] ?: ($parsed['action'] ?? 'other'));
        }

        $total_flows += $flows;
        $total_sent += $sent;
        $total_received += $rcvd;

        if (!isset($sources[$src])) {
            $sources[$src] = ['ip' => $src, 'count' => 0, 'bandwidth' => 0, 'apps' => []];
        }
        $sources[$src]['count'] += $flows;
        $sources[$src]['bandwidth'] += ($sent + $rcvd);
        $sources[$src]['apps'][$app] = ($sources[$src]['apps'][$app] ?? 0) + $flows;

        if (!isset($destinations[$dst])) {
            $destinations[$dst] = ['ip' => $dst, 'count' => 0, 'bandwidth' => 0, 'services' => []];
        }
        $destinations[$dst]['count'] += $flows;
        $destinations[$dst]['bandwidth'] += ($sent + $rcvd);
        $destinations[$dst]['services'][$service] = ($destinations[$dst]['services'][$service] ?? 0) + $flows;

        if ($range === '15m' || $range === '1h') {
            $time_key = date('H:i', strtotime($row['received_at']));
        } else if ($range === '6h' || $range === '24h') {
            $time_key = date('Y-m-d H:00', strtotime($row['received_at']));
        } else {
            $time_key = date('Y-m-d', strtotime($row['received_at']));
        }
        $bandwidth_timeline[$time_key] = ($bandwidth_timeline[$time_key] ?? 0) + ($sent + $rcvd);

        if ($action === 'accept' || $action === 'accepted' || $action === 'pass') $traffic_dist['accepted'] += $flows;
        elseif ($action === 'deny' || $action === 'denied' || $action === 'block' || $action === 'drop') $traffic_dist['denied'] += $flows;
        elseif ($action === 'timeout') $traffic_dist['timeout'] += $flows;
        else $traffic_dist['other'] += $flows;

        if (!isset($applications[$app])) { $applications[$app] = ['name' => $app, 'count' => 0, 'bandwidth' => 0]; }
        $applications[$app]['count'] += $flows;
        $applications[$app]['bandwidth'] += ($sent + $rcvd);
    }

    uasort($sources, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
    uasort($destinations, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
    uasort($applications, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });

    foreach ($sources as &$s) {
        arsort($s['apps']);
        $s['top_apps'] = array_slice(array_keys($s['apps']), 0, 2);
    }
    foreach ($destinations as &$d) {
        arsort($d['services']);
        $d['top_services'] = array_slice(array_keys($d['services']), 0, 2);
    }

    ksort($bandwidth_timeline);

    $total_bw_all = $total_sent + $total_received;
    $accept_pct = $total_flows > 0 ? round(($traffic_dist['accepted'] / $total_flows) * 100, 1) : 0;

    return [
        'total_flows' => $total_flows,
        'total_sent' => $total_sent,
        'total_received' => $total_received,
        'total_bw_all' => $total_bw_all,
        'accept_pct' => $accept_pct,
        'sources' => array_values(array_slice($sources, 0, 10)),
        'destinations' => array_values(array_slice($destinations, 0, 10)),
        'applications' => array_values(array_slice($applications, 0, 10)),
        'protocols' => $protocols,
        'countries' => array_slice($countries, 0, 10, true),
        'bandwidth_timeline' => [
            'labels' => array_keys($bandwidth_timeline),
            'values' => array_values($bandwidth_timeline)
        ],
        'traffic_dist' => $traffic_dist
    ];
});

echo json_encode($response ?? [
    'total_flows' => 0, 'total_sent' => 0, 'total_received' => 0, 'total_bw_all' => 0,
    'accept_pct' => 0, 'sources' => [], 'destinations' => [], 'applications' => [],
    'protocols' => [], 'countries' => [], 'bandwidth_timeline' => ['labels' => [], 'values' => []],
    'traffic_dist' => ['accepted' => 0, 'denied' => 0, 'timeout' => 0, 'other' => 0]
]);

mysqli_close($con);
?>
