<?php
require_once __DIR__ . '/../db_config.php';
// api/get_report_asset_discovery.php

session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

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

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "' $device_where";

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;
$archive_query = $has_archive ? "UNION ALL SELECT source_ip, received_at FROM syslog_entries_archive WHERE $where" : "";

$agg_query = "
    SELECT source_ip, COUNT(*) AS events, MIN(received_at) AS first_seen, MAX(received_at) AS last_seen
    FROM (
        SELECT source_ip, received_at FROM syslog_entries WHERE $where
        $archive_query
    ) AS combined
    GROUP BY source_ip
    ORDER BY events DESC
    LIMIT 300
";
$result = mysqli_query($con, $agg_query);

$assets = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $assets[] = [
            'source_ip' => $row['source_ip'],
            'events' => (int)$row['events'],
            'first_seen' => $row['first_seen'],
            'last_seen' => $row['last_seen']
        ];
    }
}

// Sample one message per asset (cheap heuristic) to guess a device role
$roles = [];
$archive_query_msg = $has_archive ? "UNION ALL SELECT source_ip, message, received_at FROM syslog_entries_archive WHERE $where" : "";
$sample_query = "
    SELECT source_ip, message FROM (
        SELECT source_ip, message, received_at FROM syslog_entries WHERE $where
        $archive_query_msg
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 3000
";
$sample_result = mysqli_query($con, $sample_query);
if ($sample_result) {
    while ($row = mysqli_fetch_assoc($sample_result)) {
        if (isset($roles[$row['source_ip']])) continue;
        $m = strtolower($row['message']);
        if (strpos($m, 'type=traffic') !== false) $roles[$row['source_ip']] = 'Firewall/Gateway';
        elseif (strpos($m, 'vpntunnel') !== false) $roles[$row['source_ip']] = 'VPN Endpoint';
        elseif (strpos($m, 'cfgpath') !== false) $roles[$row['source_ip']] = 'Managed Device';
        else $roles[$row['source_ip']] = 'Unclassified';
    }
}

mysqli_close($con);

// Apply roles to assets and calculate stats
$new_last_24h = 0;
$unclassified_count = 0;
$firewall_count = 0;

$twenty_four_hours_ago = strtotime('-24 hours');

foreach ($assets as &$a) {
    $role = $roles[$a['source_ip']] ?? 'Unclassified';
    $a['role'] = $role;
    
    if (strtotime($a['first_seen']) > $twenty_four_hours_ago) {
        $new_last_24h++;
    }
}
unset($a);

foreach ($roles as $ip => $r) {
    if ($r === 'Unclassified') $unclassified_count++;
    if ($r === 'Firewall/Gateway') $firewall_count++;
}

echo json_encode([
    'assets' => array_slice($assets, 0, 100),
    'total_assets' => count($assets),
    'new_last_24h' => $new_last_24h,
    'unclassified_count' => $unclassified_count,
    'firewall_count' => $firewall_count
]);
?>
