<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_security_analysis.php

session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

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

$tactic_rules = [
    ['match' => ['authentication failed', 'login failed', 'brute'],           'tactic' => 'Credential Access',   'id' => 'TA0006', 'technique' => 'T1110 Brute Force'],
    ['match' => ['ips', 'exploit', 'attack'],                                  'tactic' => 'Initial Access',      'id' => 'TA0001', 'technique' => 'T1190 Exploit Public-Facing App'],
    ['match' => ['virus', 'malware', 'botnet'],                                'tactic' => 'Execution',           'id' => 'TA0002', 'technique' => 'T1204 User Execution'],
    ['match' => ['dos', 'flood', 'ddos'],                                      'tactic' => 'Impact',              'id' => 'TA0040', 'technique' => 'T1498 Network DoS'],
    ['match' => ['admin', 'cfgattr', 'cfgpath', 'config'],                     'tactic' => 'Persistence',         'id' => 'TA0003', 'technique' => 'T1098 Account Manipulation'],
    ['match' => ['scan', 'recon'],                                             'tactic' => 'Reconnaissance',      'id' => 'TA0043', 'technique' => 'T1595 Active Scanning'],
    ['match' => ['tunnel', 'vpn', 'ipsec'],                                    'tactic' => 'Command and Control',  'id' => 'TA0011', 'technique' => 'T1572 Protocol Tunneling'],
];

$where_parts = [];
foreach ($tactic_rules as $rule) {
    foreach ($rule['match'] as $kw) {
        $where_parts[] = "message LIKE '%" . mysqli_real_escape_string($con, $kw) . "%'";
    }
}
$keyword_where = '(' . implode(' OR ', $where_parts) . ')';

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $keyword_where $device_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 8000);

$techniques = [];
$tactic_counts = [];
$total_events = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $msg_lower = strtolower($row['message']);
        foreach ($tactic_rules as $rule) {
            foreach ($rule['match'] as $kw) {
                if (strpos($msg_lower, $kw) !== false) {
                    $key = $rule['id'];
                    if (!isset($techniques[$key])) {
                        $techniques[$key] = ['id' => $rule['id'], 'technique' => $rule['technique'], 'tactic' => $rule['tactic'], 'count' => 0, 'sources' => []];
                    }
                    $techniques[$key]['count']++;
                    $techniques[$key]['sources'][$row['source_ip']] = true;
                    $tactic_counts[$rule['tactic']] = ($tactic_counts[$rule['tactic']] ?? 0) + 1;
                    $total_events++;
                    break 2; // break 2 levels out (from both inner loops)
                }
            }
        }
    }
}

uasort($techniques, function($a, $b) { return $b['count'] <=> $a['count']; });
arsort($tactic_counts);
mysqli_close($con);

foreach ($techniques as &$t) {
    $t['sources'] = count($t['sources']);
}

echo json_encode([
    'total_events' => $total_events,
    'techniques_count' => count($techniques),
    'tactics_count' => count($tactic_counts),
    'techniques' => array_values($techniques),
    'tactic_counts' => $tactic_counts
]);
?>
