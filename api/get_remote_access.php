<?php
require_once __DIR__ . '/../db_config.php';

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Helper functions
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

// Remote Access Protocol Definitions
$remote_protocols = [
    'SSH' => [
        'ports' => [22],
        'services' => ['SSH'],
        'risk' => 'medium',
        'icon' => 'Terminal',
        'color' => 'primary'
    ],
    'Telnet' => [
        'ports' => [23],
        'services' => ['TELNET'],
        'risk' => 'critical',
        'icon' => 'Keyboard',
        'color' => 'danger'
    ],
    'RDP' => [
        'ports' => [3389],
        'services' => ['MS-WBT-SERVER', 'RDP'],
        'risk' => 'high',
        'icon' => 'Monitor',
        'color' => 'warning'
    ],
    'VNC' => [
        'ports' => [5900, 5901, 5902, 5903],
        'services' => ['VNC'],
        'risk' => 'high',
        'icon' => 'MonitorPlay',
        'color' => 'warning'
    ],
    'TightVNC' => [
        'ports' => [5800, 5801],
        'services' => ['TIGHTVNC'],
        'risk' => 'high',
        'icon' => 'Minimize',
        'color' => 'warning'
    ],
    'TeamViewer' => [
        'ports' => [5938],
        'services' => ['TEAMVIEWER'],
        'risk' => 'medium',
        'icon' => 'Users',
        'color' => 'info'
    ],
    'AnyDesk' => [
        'ports' => [7070],
        'services' => ['ANYDESK'],
        'risk' => 'medium',
        'icon' => 'Link',
        'color' => 'info'
    ],
    'SFTP' => [
        'ports' => [115],
        'services' => ['SFTP'],
        'risk' => 'low',
        'icon' => 'Folder',
        'color' => 'success'
    ],
    'FTP' => [
        'ports' => [20, 21],
        'services' => ['FTP'],
        'risk' => 'high',
        'icon' => 'FileUp',
        'color' => 'warning'
    ],
    'WinRM' => [
        'ports' => [5985, 5986],
        'services' => ['WINRM'],
        'risk' => 'medium',
        'icon' => 'AppWindow',
        'color' => 'info'
    ],
    'X11' => [
        'ports' => [6000, 6001, 6002],
        'services' => ['X11'],
        'risk' => 'medium',
        'icon' => 'Maximize',
        'color' => 'info'
    ]
];

// DB connection
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Parameters
$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';

$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

// Build port list for query
$all_ports = [];
foreach ($remote_protocols as $proto => $details) {
    $all_ports = array_merge($all_ports, $details['ports']);
}
$all_ports = array_unique($all_ports);
$port_conditions = implode(' OR ', array_map(fn($p) => "message LIKE '%dstport=$p%'", $all_ports));

// Query both tables for remote access traffic
$query = "
    SELECT message, received_at, source_ip
    FROM (
        SELECT message, received_at, source_ip
        FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end'
          AND message LIKE '%type=traffic%'
          AND ($port_conditions)
          $device_where
        UNION ALL
        SELECT message, received_at, source_ip
        FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end'
          AND message LIKE '%type=traffic%'
          AND ($port_conditions)
          $device_where
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 20000
";

$result = mysqli_query($con, $query);
if (!$result) {
    echo json_encode(['error' => 'Query error']);
    mysqli_close($con);
    exit;
}

// Analytics
$total_connections = 0;
$protocol_stats = [];
$source_analysis = [];
$destination_analysis = [];
$sessions = [];
$risky_connections = [];
$timeline = [];
$unauthorized_attempts = [];

// Initialize protocol stats
foreach ($remote_protocols as $proto => $details) {
    $protocol_stats[$proto] = [
        'name' => $proto,
        'count' => 0,
        'bandwidth' => 0,
        'unique_sources' => [],
        'unique_targets' => [],
        'risk' => $details['risk'],
        'icon' => $details['icon'],
        'color' => $details['color'],
        'accepted' => 0,
        'denied' => 0
    ];
}

while ($row = mysqli_fetch_assoc($result)) {
    $parsed = parseMessage($row['message']);
    
    $srcip = $parsed['srcip'] ?? 'unknown';
    $dstip = $parsed['dstip'] ?? 'unknown';
    $dstport = $parsed['dstport'] ?? 'unknown';
    $service = $parsed['service'] ?? 'Unknown';
    $action = strtolower($parsed['action'] ?? 'unknown');
    $sent = intval($parsed['sentbyte'] ?? 0);
    $rcvd = intval($parsed['rcvdbyte'] ?? 0);
    $duration = intval($parsed['duration'] ?? 0);
    
    if ($srcip === 'unknown' || $dstip === 'unknown') continue;
    
    // Identify protocol
    $identified_protocol = null;
    foreach ($remote_protocols as $proto => $details) {
        if (in_array($dstport, $details['ports']) || in_array($service, $details['services'])) {
            $identified_protocol = $proto;
            break;
        }
    }
    
    if (!$identified_protocol) continue;
    
    $total_connections++;
    $bandwidth = $sent + $rcvd;
    
    // Update protocol stats
    $protocol_stats[$identified_protocol]['count']++;
    $protocol_stats[$identified_protocol]['bandwidth'] += $bandwidth;
    $protocol_stats[$identified_protocol]['unique_sources'][$srcip] = true;
    $protocol_stats[$identified_protocol]['unique_targets'][$dstip] = true;
    
    if ($action === 'accept') {
        $protocol_stats[$identified_protocol]['accepted']++;
    } else {
        $protocol_stats[$identified_protocol]['denied']++;
    }
    
    // Source analysis
    if (!isset($source_analysis[$srcip])) {
        $source_analysis[$srcip] = [
            'ip' => $srcip,
            'protocols' => [],
            'targets' => [],
            'total_connections' => 0,
            'total_bandwidth' => 0,
            'denied_count' => 0,
            'last_seen' => $row['received_at']
        ];
    }
    
    $source_analysis[$srcip]['protocols'][$identified_protocol] = 
        ($source_analysis[$srcip]['protocols'][$identified_protocol] ?? 0) + 1;
    $source_analysis[$srcip]['targets'][$dstip] = 
        ($source_analysis[$srcip]['targets'][$dstip] ?? 0) + 1;
    $source_analysis[$srcip]['total_connections']++;
    $source_analysis[$srcip]['total_bandwidth'] += $bandwidth;
    
    if ($action !== 'accept') {
        $source_analysis[$srcip]['denied_count']++;
    }
    
    // Destination analysis
    if (!isset($destination_analysis[$dstip])) {
        $destination_analysis[$dstip] = [
            'ip' => $dstip,
            'protocols' => [],
            'sources' => [],
            'total_connections' => 0,
            'total_bandwidth' => 0,
            'ports_accessed' => []
        ];
    }
    
    $destination_analysis[$dstip]['protocols'][$identified_protocol] = 
        ($destination_analysis[$dstip]['protocols'][$identified_protocol] ?? 0) + 1;
    $destination_analysis[$dstip]['sources'][$srcip] = true;
    $destination_analysis[$dstip]['total_connections']++;
    $destination_analysis[$dstip]['total_bandwidth'] += $bandwidth;
    $destination_analysis[$dstip]['ports_accessed'][$dstport] = true;
    
    // Track sessions
    if ($action === 'accept' && $duration > 0) {
        $sessions[] = [
            'protocol' => $identified_protocol,
            'source' => $srcip,
            'destination' => $dstip,
            'port' => $dstport,
            'duration' => $duration,
            'bandwidth' => $bandwidth,
            'started' => date('Y-m-d H:i:s', strtotime($row['received_at']) - $duration),
            'ended' => $row['received_at']
        ];
    }
    
    // Detect risky patterns
    $is_risky = false;
    $risk_reason = [];
    
    // Critical: Unencrypted protocols
    if (in_array($identified_protocol, ['Telnet', 'FTP'])) {
        $is_risky = true;
        $risk_reason[] = 'Unencrypted protocol';
    }
    
    // High bandwidth on remote access (potential data exfiltration)
    if ($bandwidth > 100 * 1024 * 1024) { // 100MB
        $is_risky = true;
        $risk_reason[] = 'High bandwidth';
    }
    
    // Long duration sessions (potential persistence)
    if ($duration > 3600) { // 1 hour
        $is_risky = true;
        $risk_reason[] = 'Long session';
    }
    
    // Failed access attempts
    if ($action !== 'accept') {
        $unauthorized_attempts[] = [
            'protocol' => $identified_protocol,
            'source' => $srcip,
            'destination' => $dstip,
            'port' => $dstport,
            'timestamp' => $row['received_at'],
            'action' => $action
        ];
        
        if ($protocol_stats[$identified_protocol]['risk'] === 'critical') {
            $is_risky = true;
            $risk_reason[] = 'Blocked critical protocol access';
        }
    }
    
    if ($is_risky) {
        $risky_connections[] = [
            'protocol' => $identified_protocol,
            'source' => $srcip,
            'destination' => $dstip,
            'port' => $dstport,
            'action' => $action,
            'bandwidth' => $bandwidth,
            'duration' => $duration,
            'timestamp' => $row['received_at'],
            'risk_reasons' => $risk_reason,
            'severity' => $protocol_stats[$identified_protocol]['risk']
        ];
    }
    
    // Timeline (hourly)
    $hour = date('Y-m-d H:00', strtotime($row['received_at']));
    if (!isset($timeline[$hour])) {
        $timeline[$hour] = [];
        foreach (array_keys($remote_protocols) as $p) {
            $timeline[$hour][$p] = 0;
        }
    }
    $timeline[$hour][$identified_protocol]++;
}

// Convert unique counts
foreach ($protocol_stats as &$stat) {
    $stat['unique_sources'] = count($stat['unique_sources']);
    $stat['unique_targets'] = count($stat['unique_targets']);
}

// Sort arrays
uasort($protocol_stats, fn($a, $b) => $b['count'] <=> $a['count']);
uasort($source_analysis, fn($a, $b) => $b['total_connections'] <=> $a['total_connections']);
uasort($destination_analysis, fn($a, $b) => $b['total_connections'] <=> $a['total_connections']);
usort($sessions, fn($a, $b) => $b['duration'] <=> $a['duration']);
usort($risky_connections, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));

$top_sources = array_slice(array_values($source_analysis), 0, 10);
$top_destinations = array_slice(array_values($destination_analysis), 0, 10);
$longest_sessions = array_slice($sessions, 0, 10);

// For objects to convert cleanly to arrays
$protocol_stats_arr = array_values($protocol_stats);

// Calculate summary stats
$total_protocols = count(array_filter($protocol_stats_arr, fn($s) => $s['count'] > 0));
$critical_risk_count = count(array_filter($protocol_stats_arr, fn($s) => $s['count'] > 0 && $s['risk'] === 'critical'));
$unauthorized_count = count($unauthorized_attempts);

ksort($timeline);

mysqli_close($con);

echo json_encode([
    'summary' => [
        'total_connections' => $total_connections,
        'total_protocols' => $total_protocols,
        'critical_risk_count' => $critical_risk_count,
        'unauthorized_count' => $unauthorized_count
    ],
    'protocol_stats' => $protocol_stats_arr,
    'top_sources' => $top_sources,
    'top_destinations' => $top_destinations,
    'longest_sessions' => $longest_sessions,
    'risky_connections' => $risky_connections,
    'unauthorized_attempts' => $unauthorized_attempts,
    'timeline' => $timeline,
    'remote_protocols' => array_keys($remote_protocols)
]);
