<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_remote_access.php

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

$remote_filter = "(
    message LIKE '%vpntunnel%' OR message LIKE '%sslvpn%' OR message LIKE '%ipsec%'
    OR message LIKE '%xauthuser%' OR message LIKE '%ppp%' OR message LIKE '%tunnel%'
)";

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $remote_filter $device_where";

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
    ORDER BY received_at ASC
    LIMIT 8000
";

$result = mysqli_query($con, $query);

$sessions = [];
$total_events = 0;
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $user = $p['user'] ?? $p['xauthuser'] ?? 'unknown';
        $tunnel = $p['vpntunnel'] ?? $p['tunnel'] ?? 'n/a';
        $remip = $p['remip'] ?? $row['source_ip'];
        $key = $user . '|' . $remip;

        if (!isset($sessions[$key])) {
            $sessions[$key] = ['user' => $user, 'ip' => $remip, 'tunnel' => $tunnel, 'first' => $row['received_at'], 'last' => $row['received_at'], 'events' => 0];
        }
        $sessions[$key]['last'] = $row['received_at'];
        $sessions[$key]['events']++;
        $total_events++;
    }
}

uasort($sessions, function($a, $b) { return strtotime($b['last']) <=> strtotime($a['last']); });
$unique_users = count(array_unique(array_column($sessions, 'user')));

mysqli_close($con);

echo json_encode([
    'sessions' => array_values(array_slice($sessions, 0, 100)), // Return top 100 for safety, frontend can slice further if needed
    'unique_users' => $unique_users,
    'total_events' => $total_events
]);
?>
