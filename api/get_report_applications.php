<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_applications.php

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

function unionQuery($con, $select, $where, $order, $limit) {
    $check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    $has_archive = mysqli_num_rows($check_archive) > 0;
    
    $archive_query = $has_archive ? "
        UNION ALL
        SELECT $select
        FROM syslog_entries_archive
        WHERE $where
    " : "";
    
    $query = "
        SELECT $select
        FROM (
            SELECT $select
            FROM syslog_entries
            WHERE $where
            $archive_query
        ) AS combined
        ORDER BY $order
        LIMIT $limit
    ";
    
    return mysqli_query($con, $query);
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo json_encode(['error' => 'DB connection failed: ' . mysqli_connect_error()]);
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

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%') $device_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 10000);

$apps = [];
$risk_categories = [];
$total_flows = 0;
$total_bw = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $app = $p['app'] ?? $p['appcat'] ?? 'Unknown';
        $cat = $p['appcat'] ?? $p['app'] ?? 'Uncategorized';
        $risk = $p['apprisk'] ?? 'unknown';
        $sent = intval($p['sentbyte'] ?? 0);
        $rcvd = intval($p['rcvdbyte'] ?? 0);
        $bw = $sent + $rcvd;

        $total_flows++;
        $total_bw += $bw;

        if (!isset($apps[$app])) {
            $apps[$app] = ['name' => $app, 'category' => $cat, 'risk' => $risk, 'flows' => 0, 'bandwidth' => 0, 'sources' => []];
        }
        $apps[$app]['flows']++;
        $apps[$app]['bandwidth'] += $bw;
        $apps[$app]['sources'][$row['source_ip']] = true;

        $risk_categories[$risk] = ($risk_categories[$risk] ?? 0) + 1;
    }
}

uasort($apps, function($a, $b) { return $b['bandwidth'] <=> $a['bandwidth']; });
mysqli_close($con);

foreach ($apps as &$app) {
    $app['sources_count'] = count($app['sources']);
    unset($app['sources']);
}

$high_risk_flows = ($risk_categories['4'] ?? 0) + ($risk_categories['5'] ?? 0) + ($risk_categories['high'] ?? 0) + ($risk_categories['critical'] ?? 0);

echo json_encode([
    'total_apps' => count($apps),
    'total_flows' => $total_flows,
    'total_bw' => $total_bw,
    'high_risk_flows' => $high_risk_flows,
    'apps' => array_values(array_slice($apps, 0, 20)),
    'risk_categories' => $risk_categories
]);
?>
