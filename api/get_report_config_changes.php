<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_config_changes.php

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

$cfg_filter = "(
    message LIKE '%cfgattr%' OR message LIKE '%cfgpath%' OR message LIKE '%admin login%'
    OR message LIKE '%added user%' OR message LIKE '%user added%' OR message LIKE '%config%'
    OR message LIKE '%logid=\"010003%'
)";

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $cfg_filter $device_where";

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
    LIMIT 500
";

$result = mysqli_query($con, $query);

$changes = [];
$by_admin = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $admin = $p['user'] ?? $p['admin'] ?? 'system';
        $path = $p['cfgpath'] ?? $p['cfgobj'] ?? 'n/a';
        $attr = $p['cfgattr'] ?? '';
        $msg = $p['msg'] ?? mb_substr($row['message'], 0, 130);

        $changes[] = [
            'time' => $row['received_at'],
            'admin' => $admin,
            'source' => $row['source_ip'],
            'path' => $path,
            'attr' => $attr,
            'summary' => $msg
        ];
        $by_admin[$admin] = ($by_admin[$admin] ?? 0) + 1;
    }
}
arsort($by_admin);
mysqli_close($con);

echo json_encode([
    'changes' => array_slice($changes, 0, 100), // Front-end will paginate or slice to 40
    'by_admin' => $by_admin,
    'total_changes' => count($changes)
]);
?>
