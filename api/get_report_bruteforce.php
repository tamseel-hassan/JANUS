<?php
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/cache.php';

set_time_limit(300);
session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();

header('Content-Type: application/json');

function getPortName($port) {
    $port_map = [
        '20' => 'FTP-DATA', '21' => 'FTP', '22' => 'SSH', '23' => 'Telnet',
        '25' => 'SMTP', '53' => 'DNS', '80' => 'HTTP', '110' => 'POP3',
        '143' => 'IMAP', '161' => 'SNMP', '389' => 'LDAP', '443' => 'HTTPS',
        '445' => 'SMB', '500' => 'IKE/IPsec', '636' => 'LDAPS', '993' => 'IMAPS',
        '995' => 'POP3S', '1433' => 'MS-SQL', '1521' => 'Oracle', '3306' => 'MySQL',
        '3389' => 'RDP', '4500' => 'NAT-T/IPsec', '5432' => 'PostgreSQL',
        '5900' => 'VNC', '8080' => 'HTTP-ALT', '8443' => 'HTTPS-ALT'
    ];
    return $port_map[$port] ?? "Port-$port";
}

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
$cache_key = get_bucketed_cache_key("api_get_report_bruteforce", $start, $end, $device, $range);

$response = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device) {
    $device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
    $auth_filter = "is_auth_failure = 1";

    $has_firewall = false;
    $firewall_ip = '';
    $fw_result = mysqli_query($con, "SELECT id FROM response_config WHERE is_active = 1 LIMIT 1");
    if ($fw_result && mysqli_num_rows($fw_result) > 0) {
        $has_firewall = true;
        $firewall_ip = "192.168.1.1 (Configured)";
    }

    $check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    $has_archive = mysqli_num_rows($check_archive) > 0;
    $archive_query_agg = $has_archive ? "UNION ALL SELECT source_ip, message FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where" : "";

    $agg_query = "
        SELECT source_ip, COUNT(*) AS attempt_count
        FROM (
            SELECT source_ip, message FROM syslog_entries
            WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
            $archive_query_agg
        ) AS combined
        GROUP BY source_ip
        HAVING attempt_count >= 3
        ORDER BY attempt_count DESC
        LIMIT 200
    ";

    $agg_result = mysqli_query($con, $agg_query);
    $ip_counts = [];
    if ($agg_result) {
        while ($row = mysqli_fetch_assoc($agg_result)) {
            $ip_counts[$row['source_ip']] = (int)$row['attempt_count'];
        }
    }

    $archive_query_tl = $has_archive ? "UNION ALL SELECT received_at FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where" : "";
    $timeline_query = "
        SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt
        FROM (
            SELECT received_at FROM syslog_entries
            WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
            $archive_query_tl
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

    $top_attackers = [];
    $total_attacks = array_sum($ip_counts);

    foreach ($ip_counts as $ip => $attempts) {
        $top_attackers[] = [
            'ip' => $ip,
            'attempts' => $attempts,
            'status' => 'Detected'
        ];
    }

    return [
        'has_firewall' => $has_firewall,
        'firewall_ip' => $firewall_ip,
        'total_attacks' => $total_attacks,
        'unique_attackers' => count($ip_counts),
        'top_attackers' => array_slice($top_attackers, 0, 10),
        'timeline' => [
            'labels' => array_keys($timeline),
            'values' => array_values($timeline)
        ]
    ];
});

echo json_encode($response ?? [
    'has_firewall' => false, 'firewall_ip' => '',
    'total_attacks' => 0, 'unique_attackers' => 0,
    'top_attackers' => [], 'timeline' => ['labels' => [], 'values' => []]
]);

mysqli_close($con);
?>
