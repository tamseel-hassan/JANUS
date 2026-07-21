<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_user_activity.php

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

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND (message LIKE '%user=%' OR message LIKE '%xauthuser=%') $device_where";

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
    LIMIT 8000
";

$result = mysqli_query($con, $query);

$users = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $user = $p['user'] ?? $p['xauthuser'] ?? null;
        if (!$user || $user === 'N/A') continue;
        
        if (!isset($users[$user])) {
            $users[$user] = ['user' => $user, 'events' => 0, 'ips' => [], 'failed' => 0, 'last' => $row['received_at']];
        }
        $users[$user]['events']++;
        $users[$user]['ips'][$row['source_ip']] = true;
        if (stripos($row['message'], 'fail') !== false) $users[$user]['failed']++;
        if (strtotime($row['received_at']) > strtotime($users[$user]['last'])) $users[$user]['last'] = $row['received_at'];
    }
}

// Convert ips to array of keys
foreach ($users as &$u) {
    $u['ips'] = array_keys($u['ips']);
}
unset($u);

uasort($users, function($a, $b) { return $b['events'] <=> $a['events']; });

$total_failed = array_sum(array_column($users, 'failed'));
$total_events = array_sum(array_column($users, 'events'));
$max_ips = !empty($users) ? max(array_map(function($u) { return count($u['ips']); }, $users)) : 0;

mysqli_close($con);

echo json_encode([
    'users' => array_values(array_slice($users, 0, 100)), // Return top 100
    'total_events' => $total_events,
    'total_failed' => $total_failed,
    'active_users' => count($users),
    'max_ips_per_user' => $max_ips
]);
?>
