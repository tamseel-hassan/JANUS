<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_bandwidth.php

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

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%') $device_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 10000);

$per_device = [];
$sent_timeline = [];
$rcvd_timeline = [];
$total_sent = 0;
$total_rcvd = 0;
$peak_hour = null;
$peak_value = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $sent = intval($p['sentbyte'] ?? 0);
        $rcvd = intval($p['rcvdbyte'] ?? 0);
        $total_sent += $sent;
        $total_rcvd += $rcvd;

        $dev = $row['source_ip'];
        if (!isset($per_device[$dev])) $per_device[$dev] = ['ip' => $dev, 'sent' => 0, 'rcvd' => 0, 'flows' => 0];
        $per_device[$dev]['sent'] += $sent;
        $per_device[$dev]['rcvd'] += $rcvd;
        $per_device[$dev]['flows']++;

        if ($range === '15m' || $range === '1h') {
            $hour = date('H:i', strtotime($row['received_at']));
        } else if ($range === '6h' || $range === '24h') {
            $hour = date('Y-m-d H:00', strtotime($row['received_at']));
        } else {
            $hour = date('Y-m-d', strtotime($row['received_at']));
        }

        $sent_timeline[$hour] = ($sent_timeline[$hour] ?? 0) + $sent;
        $rcvd_timeline[$hour] = ($rcvd_timeline[$hour] ?? 0) + $rcvd;
        $hour_total = $sent_timeline[$hour] + $rcvd_timeline[$hour];
        if ($hour_total > $peak_value) { $peak_value = $hour_total; $peak_hour = $hour; }
    }
}

uasort($per_device, function($a, $b) { return ($b['sent'] + $b['rcvd']) <=> ($a['sent'] + $a['rcvd']); });
ksort($sent_timeline);
ksort($rcvd_timeline);
mysqli_close($con);

echo json_encode([
    'total_sent' => $total_sent,
    'total_rcvd' => $total_rcvd,
    'peak_hour' => $peak_hour,
    'peak_value' => $peak_value,
    'active_devices' => count($per_device),
    'per_device' => array_values(array_slice($per_device, 0, 15)),
    'timeline' => [
        'labels' => array_keys($sent_timeline),
        'sent' => array_values($sent_timeline),
        'rcvd' => array_values($rcvd_timeline)
    ]
]);
?>
