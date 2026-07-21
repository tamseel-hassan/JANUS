<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_traffic.php

session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

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

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;

$archive_query = $has_archive ? "
        UNION ALL
        SELECT message, received_at, source_ip
        FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end'
          AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%')
          $device_where
" : "";

$query = "
    SELECT message, received_at, source_ip
    FROM (
        SELECT message, received_at, source_ip
        FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end'
          AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%')
          $device_where
        $archive_query
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 100000
";

$result = mysqli_query($con, $query);
if (!$result) {
    echo json_encode(['error' => 'Query error: ' . mysqli_error($con)]);
    mysqli_close($con);
    exit;
}

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
    $parsed = parseMessage($row['message']);
    if (!isset($parsed['srcip'], $parsed['dstip'])) continue;

    $total_flows++;
    $sent = intval($parsed['sentbyte'] ?? 0);
    $rcvd = intval($parsed['rcvdbyte'] ?? 0);
    $total_sent += $sent;
    $total_received += $rcvd;

    $src = $parsed['srcip'];
    $dst = $parsed['dstip'];
    $app = $parsed['app'] ?? $parsed['appcat'] ?? 'Unknown';
    $service = $parsed['service'] ?? $parsed['proto'] ?? 'Unknown';
    $dstport = $parsed['dstport'] ?? 'N/A';
    $srcport = $parsed['srcport'] ?? 'N/A';
    $proto = $parsed['proto'] ?? 'Unknown';
    $action = strtolower($parsed['action'] ?? 'other');
    $country = $parsed['srccountry'] ?? $parsed['dstcountry'] ?? 'Unknown';

    if (!isset($sources[$src])) {
        $sources[$src] = ['ip' => $src, 'count' => 0, 'bandwidth' => 0, 'apps' => []];
    }
    $sources[$src]['count']++;
    $sources[$src]['bandwidth'] += ($sent + $rcvd);
    $sources[$src]['apps'][$app] = ($sources[$src]['apps'][$app] ?? 0) + 1;

    if (!isset($destinations[$dst])) {
        $destinations[$dst] = ['ip' => $dst, 'count' => 0, 'bandwidth' => 0, 'services' => []];
    }
    $destinations[$dst]['count']++;
    $destinations[$dst]['bandwidth'] += ($sent + $rcvd);
    $destinations[$dst]['services'][$service] = ($destinations[$dst]['services'][$service] ?? 0) + 1;

    if ($range === '15m' || $range === '1h') {
        $time_key = date('H:i', strtotime($row['received_at'])); 
    } else if ($range === '6h' || $range === '24h') {
        $time_key = date('Y-m-d H:00', strtotime($row['received_at'])); 
    } else {
        $time_key = date('Y-m-d', strtotime($row['received_at'])); 
    }
    $bandwidth_timeline[$time_key] = ($bandwidth_timeline[$time_key] ?? 0) + ($sent + $rcvd);

    if ($action === 'accept') $traffic_dist['accepted']++;
    elseif ($action === 'deny') $traffic_dist['denied']++;
    elseif ($action === 'timeout') $traffic_dist['timeout']++;
    else $traffic_dist['other']++;

    if (!isset($applications[$app])) { $applications[$app] = ['name' => $app, 'count' => 0, 'bandwidth' => 0]; }
    $applications[$app]['count']++;
    $applications[$app]['bandwidth'] += ($sent + $rcvd);

    if (!isset($services[$service])) { $services[$service] = ['name' => $service, 'count' => 0, 'bandwidth' => 0]; }
    $services[$service]['count']++;
    $services[$service]['bandwidth'] += ($sent + $rcvd);

    $protocols[$proto] = ($protocols[$proto] ?? 0) + 1;
    if ($country !== 'Unknown') { $countries[$country] = ($countries[$country] ?? 0) + 1; }
}

uasort($sources, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
uasort($destinations, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
uasort($applications, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
uasort($services, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
arsort($protocols);
arsort($countries);

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

echo json_encode([
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
]);

mysqli_close($con);
?>
