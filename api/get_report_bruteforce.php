<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_bruteforce.php

set_time_limit(300);
session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
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

$start  = $_GET['range'] ? date('Y-m-d H:i:s', strtotime('-' . str_replace(['15m','1h','6h','24h','7d','30d'], ['15 minutes','1 hour','6 hours','24 hours','7 days','30 days'], $_GET['range']))) : date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';

$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$has_firewall = false;
$firewall_ip = '';
$fw_result = mysqli_query($con, "SELECT id FROM response_config WHERE is_active = 1 LIMIT 1");
if ($fw_result && mysqli_num_rows($fw_result) > 0) {
    $has_firewall = true;
    $firewall_ip = "192.168.1.1 (Configured)";
}

$auth_filter = "(
    message LIKE '%authentication%failed%'
    OR message LIKE '%login%failed%'
    OR message LIKE '%status=\"failure\"%'
    OR message LIKE '%reason=%failure%'
    OR (message LIKE '%Progress IPsec phase 2%' AND message LIKE '%result=\"ERROR\"%')
    OR message LIKE '%logid=\"0101039424\"%'
    OR message LIKE '%logid=\"0100032001\"%'
    OR message LIKE '%logid=\"0100032002\"%'
)";

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
ksort($timeline);

$archive_query_bd = $has_archive ? "UNION ALL SELECT message, received_at FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where" : "";
$breakdown_query = "
    SELECT message
    FROM (
        SELECT message, received_at FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
        $archive_query_bd
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 2000
";
$bd_result = mysqli_query($con, $breakdown_query);
$service_breakdown = [];
$port_breakdown = [];
if ($bd_result) {
    while ($row = mysqli_fetch_assoc($bd_result)) {
        $p = parseMessage($row['message']);
        $svc = $p['vpntunnel'] ?? $p['service'] ?? $p['proto'] ?? 'unknown';
        $port = $p['remport'] ?? $p['locport'] ?? $p['dstport'] ?? 'unknown';
        $service_breakdown[$svc] = ($service_breakdown[$svc] ?? 0) + 1;
        $port_breakdown[$port] = ($port_breakdown[$port] ?? 0) + 1;
    }
}
arsort($service_breakdown);
arsort($port_breakdown);

$brute_force_attacks = [];
$failed_attempts_detail = [];
$top_ip_list = array_keys(array_slice($ip_counts, 0, 50, true));

if (!empty($top_ip_list)) {
    $ip_placeholders = implode(',', array_map(fn($ip) => "'" . mysqli_real_escape_string($con, $ip) . "'", $top_ip_list));
    $archive_query_det = $has_archive ? "UNION ALL SELECT message, received_at, source_ip FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND source_ip IN ($ip_placeholders) AND $auth_filter" : "";
    
    $detail_query = "
        SELECT message, received_at, source_ip
        FROM (
            SELECT message, received_at, source_ip FROM syslog_entries
            WHERE received_at BETWEEN '$start' AND '$end' AND source_ip IN ($ip_placeholders) AND $auth_filter
            $archive_query_det
        ) AS combined
        ORDER BY received_at ASC
        LIMIT 5000
    ";

    $detail_result = mysqli_query($con, $detail_query);
    if ($detail_result) {
        while ($row = mysqli_fetch_assoc($detail_result)) {
            $parsed = parseMessage($row['message']);
            $srcip   = $parsed['remip'] ?? $parsed['srcip'] ?? $parsed['srcaddr'] ?? $row['source_ip'] ?? 'unknown';
            $dstip   = $parsed['locip'] ?? $parsed['dstip'] ?? $parsed['dstaddr'] ?? 'unknown';
            $user    = $parsed['user'] ?? $parsed['xauthuser'] ?? 'N/A';
            $service = $parsed['vpntunnel'] ?? $parsed['service'] ?? $parsed['proto'] ?? 'unknown';
            $port    = $parsed['remport'] ?? $parsed['locport'] ?? $parsed['dstport'] ?? 'unknown';

            if ($srcip === 'unknown' || $srcip === $dstip) continue;

            if (!isset($failed_attempts_detail[$srcip])) {
                $failed_attempts_detail[$srcip] = [
                    'source_ip' => $srcip, 'target_ip' => $dstip, 'port' => $port,
                    'service' => $service, 'attempts' => [], 'usernames' => []
                ];
            }
            $failed_attempts_detail[$srcip]['attempts'][] = $row['received_at'];
            if ($user !== 'N/A' && $user !== $srcip && !in_array($user, $failed_attempts_detail[$srcip]['usernames'])) {
                $failed_attempts_detail[$srcip]['usernames'][] = $user;
            }
        }
    }

    foreach ($failed_attempts_detail as $ip => $data) {
        $attempts = $data['attempts'];
        if (count($attempts) < 3) continue;
        sort($attempts);
        for ($i = 0; $i < count($attempts) - 2; $i++) {
            $first = strtotime($attempts[$i]);
            $third = strtotime($attempts[$i + 2]);
            $diff  = ($third - $first) / 60;
            if ($diff <= 5) {
                $total = $ip_counts[$ip] ?? count($attempts);
                $score = min(99, 40 + ($total * 2));
                $brute_force_attacks[] = [
                    'id'            => 'BF-', // Assigned in frontend
                    'source_ip'     => $data['source_ip'],
                    'target_ip'     => $data['target_ip'],
                    'port'          => $data['port'],
                    'port_name'     => getPortName($data['port']),
                    'service'       => $data['service'],
                    'attempt_count' => $total,
                    'time_window'   => round($diff, 1) . ' min',
                    'first_attempt' => $attempts[0],
                    'last_attempt'  => end($attempts),
                    'usernames'     => $data['usernames'],
                    'risk_level'    => $total > 10 ? 'critical' : 'high',
                    'score'         => $score
                ];
                break;
            }
        }
    }
}

usort($brute_force_attacks, function($a, $b) { return strtotime($b['last_attempt']) <=> strtotime($a['last_attempt']); });

foreach ($brute_force_attacks as $i => &$bf) {
    $bf['id'] = 'BF-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
}

$top_attackers = [];
foreach ($ip_counts as $ip => $count) {
    $top_attackers[] = ['ip' => $ip, 'count' => $count];
}
$top_attackers = array_slice($top_attackers, 0, 10);

$archive_query_tot = $has_archive ? "UNION ALL SELECT 1 FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where" : "";
$total_failed_sql = "
    SELECT COUNT(*) AS total
    FROM (
        SELECT 1 FROM syslog_entries WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
        $archive_query_tot
    ) AS combined
";
$total_res = mysqli_query($con, $total_failed_sql);
$total_failed = $total_res ? (int)mysqli_fetch_assoc($total_res)['total'] : array_sum($ip_counts);

$critical_count = count(array_filter($brute_force_attacks, function($a) { return $a['risk_level'] === 'critical'; }));

mysqli_close($con);

echo json_encode([
    'total_failed' => $total_failed,
    'critical_count' => $critical_count,
    'has_firewall' => $has_firewall,
    'firewall_ip' => $firewall_ip,
    'brute_force_attacks' => $brute_force_attacks,
    'top_attackers' => $top_attackers,
    'service_breakdown' => array_slice($service_breakdown, 0, 10, true),
    'port_breakdown' => array_slice($port_breakdown, 0, 10, true),
    'timeline' => [
        'labels' => array_keys($timeline),
        'values' => array_values($timeline)
    ]
]);
?>
