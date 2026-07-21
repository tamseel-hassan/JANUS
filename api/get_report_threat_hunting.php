<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_threat_hunting.php

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
$search = trim($_GET['q'] ?? '');
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$notable_filter = "(
    message LIKE '%virus%' OR message LIKE '%malware%' OR message LIKE '%botnet%'
    OR message LIKE '%exploit%' OR message LIKE '%ips%' OR message LIKE '%attack%'
    OR message LIKE '%blocked%' OR message LIKE '%denied%' OR message LIKE '%c2%'
    OR message LIKE '%anomaly%' OR message LIKE '%suspicious%'
)";

$search_where = $search ? " AND message LIKE '%" . mysqli_real_escape_string($con, $search) . "%'" : '';

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $notable_filter $device_where $search_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 150);

$events = [];
$severity_counts = ['critical' => 0, 'high' => 0, 'medium' => 0];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $lower = strtolower($row['message']);
        if (strpos($lower, 'virus') !== false || strpos($lower, 'malware') !== false || strpos($lower, 'botnet') !== false || strpos($lower, 'c2') !== false) {
            $sev = 'critical';
        } elseif (strpos($lower, 'exploit') !== false || strpos($lower, 'attack') !== false || strpos($lower, 'ips') !== false) {
            $sev = 'high';
        } else {
            $sev = 'medium';
        }
        $severity_counts[$sev]++;
        $events[] = [
            'time' => $row['received_at'],
            'source' => $row['source_ip'],
            'severity' => $sev,
            'action' => $p['action'] ?? 'n/a',
            'summary' => $p['msg'] ?? mb_substr($row['message'], 0, 140),
        ];
    }
}

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;
$archive_query = $has_archive ? "UNION ALL SELECT 1 FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $notable_filter $device_where $search_where" : "";

$total_notable_sql = "
    SELECT COUNT(*) AS total FROM (
        SELECT 1 FROM syslog_entries WHERE received_at BETWEEN '$start' AND '$end' AND $notable_filter $device_where $search_where
        $archive_query
    ) AS combined
";
$total_res = mysqli_query($con, $total_notable_sql);
$total_notable = $total_res ? (int)mysqli_fetch_assoc($total_res)['total'] : count($events);

mysqli_close($con);

echo json_encode([
    'total_notable' => $total_notable,
    'severity_counts' => $severity_counts,
    'events' => $events
]);
?>
