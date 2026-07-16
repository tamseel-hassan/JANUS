<?php
require_once __DIR__ . '/../db_config.php';
// ss/remote_access.php - Remote Access Protocol Monitor
// Tracks SSH, Telnet, RDP, VNC, and other remote access protocols

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
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

function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . ['B','KB','MB','GB','TB'][$i];
}

// Remote Access Protocol Definitions
$remote_protocols = [
    'SSH' => [
        'ports' => [22],
        'services' => ['SSH'],
        'risk' => 'medium',
        'icon' => 'fa-terminal',
        'color' => 'primary'
    ],
    'Telnet' => [
        'ports' => [23],
        'services' => ['TELNET'],
        'risk' => 'critical',
        'icon' => 'fa-keyboard',
        'color' => 'danger'
    ],
    'RDP' => [
        'ports' => [3389],
        'services' => ['MS-WBT-SERVER', 'RDP'],
        'risk' => 'high',
        'icon' => 'fa-desktop',
        'color' => 'warning'
    ],
    'VNC' => [
        'ports' => [5900, 5901, 5902, 5903],
        'services' => ['VNC'],
        'risk' => 'high',
        'icon' => 'fa-tv',
        'color' => 'warning'
    ],
    'TightVNC' => [
        'ports' => [5800, 5801],
        'services' => ['TIGHTVNC'],
        'risk' => 'high',
        'icon' => 'fa-compress',
        'color' => 'warning'
    ],
    'TeamViewer' => [
        'ports' => [5938],
        'services' => ['TEAMVIEWER'],
        'risk' => 'medium',
        'icon' => 'fa-users',
        'color' => 'info'
    ],
    'AnyDesk' => [
        'ports' => [7070],
        'services' => ['ANYDESK'],
        'risk' => 'medium',
        'icon' => 'fa-link',
        'color' => 'info'
    ],
    'SFTP' => [
        'ports' => [115],
        'services' => ['SFTP'],
        'risk' => 'low',
        'icon' => 'fa-folder',
        'color' => 'success'
    ],
    'FTP' => [
        'ports' => [20, 21],
        'services' => ['FTP'],
        'risk' => 'high',
        'icon' => 'fa-file-export',
        'color' => 'warning'
    ],
    'WinRM' => [
        'ports' => [5985, 5986],
        'services' => ['WINRM'],
        'risk' => 'medium',
        'icon' => 'fa-windows',
        'color' => 'info'
    ],
    'X11' => [
        'ports' => [6000, 6001, 6002],
        'services' => ['X11'],
        'risk' => 'medium',
        'icon' => 'fa-window-maximize',
        'color' => 'info'
    ]
];

// DB connection
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>';
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
    echo '<div class="alert alert-danger">Query error: ' . mysqli_error($con) . '</div>';
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
    $sessionid = $parsed['sessionid'] ?? 'unknown';
    
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
        $risk_reason[] = 'High bandwidth (' . formatBytes($bandwidth) . ')';
    }
    
    // Long duration sessions (potential persistence)
    if ($duration > 3600) { // 1 hour
        $is_risky = true;
        $risk_reason[] = 'Long session (' . round($duration/60) . ' min)';
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

$top_sources = array_slice($source_analysis, 0, 10);
$top_destinations = array_slice($destination_analysis, 0, 10);
$longest_sessions = array_slice($sessions, 0, 10);

// Calculate summary stats
$total_protocols = count(array_filter($protocol_stats, fn($s) => $s['count'] > 0));
$critical_risk_count = count(array_filter($protocol_stats, fn($s) => $s['count'] > 0 && $s['risk'] === 'critical'));
$unauthorized_count = count($unauthorized_attempts);

ksort($timeline);

mysqli_close($con);
?>

<link rel="stylesheet" href="/css/pages/ss_remoteaccess_users.css">

<!-- HTML Output -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i data-lucide="network" class="icon-lucide"></i></div>
            <div class="stat-value"><?= number_format($total_connections) ?></div>
            <div class="stat-label">Remote Connections</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="layers" class="icon-lucide"></i></div>
            <div class="stat-value"><?= $total_protocols ?></div>
            <div class="stat-label">Active Protocols</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="triangle-alert" class="icon-lucide"></i></div>
            <div class="stat-value"><?= count($risky_connections) ?></div>
            <div class="stat-label">Risky Connections</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="ban" class="icon-lucide"></i></div>
            <div class="stat-value"><?= $unauthorized_count ?></div>
            <div class="stat-label">Unauthorized Attempts</div>
        </div>
    </div>
</div>

<?php if ($critical_risk_count > 0): ?>
<div class="alert alert-danger">
    <i data-lucide="triangle-alert" class="icon-lucide"></i>
    <strong>Security Warning:</strong> Detected <?= $critical_risk_count ?> critical-risk protocol(s) in use (Telnet, unencrypted connections). Review immediately.
</div>
<?php endif; ?>

<!-- Protocol Summary Cards -->
<div class="report-card">
    <h5><i data-lucide="shield" class="icon-lucide"></i> Remote Access Protocols Detected</h5>
    <div class="row g-3 mt-3">
        <?php foreach ($protocol_stats as $proto => $stats): ?>
            <?php if ($stats['count'] > 0): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="protocol-card <?= $stats['risk'] ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6>
                                    <i class="fas <?= $stats['icon'] ?> text-<?= $stats['color'] ?>"></i>
                                    <?= htmlspecialchars($proto) ?>
                                </h6>
                                <span class="badge bg-<?= $stats['risk'] === 'critical' ? 'danger' : ($stats['risk'] === 'high' ? 'warning' : ($stats['risk'] === 'medium' ? 'info' : 'success')) ?>">
                                    <?= strtoupper($stats['risk']) ?> RISK
                                </span>
                            </div>
                            <div class="text-end">
                                <div class="h4 mb-0"><?= number_format($stats['count']) ?></div>
                                <small class="text-muted">connections</small>
                            </div>
                        </div>
                        
                        <div class="row g-2 small mt-3">
                            <div class="col-6">
                                <i data-lucide="arrow-up" class="icon-lucide text-success"></i> Accepted: <strong><?= $stats['accepted'] ?></strong>
                            </div>
                            <div class="col-6">
                                <i data-lucide="ban" class="icon-lucide text-danger"></i> Denied: <strong><?= $stats['denied'] ?></strong>
                            </div>
                            <div class="col-6">
                                <i data-lucide="user" class="icon-lucide s"></i> Sources: <strong><?= $stats['unique_sources'] ?></strong>
                            </div>
                            <div class="col-6">
                                <i data-lucide="server" class="icon-lucide"></i> Targets: <strong><?= $stats['unique_targets'] ?></strong>
                            </div>
                            <div class="col-12">
                                <i data-lucide="database" class="icon-lucide"></i> Data: <strong><?= formatBytes($stats['bandwidth']) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        
        <?php if ($total_protocols === 0): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i data-lucide="info" class="icon-lucide"></i> No remote access protocol activity detected in this time period.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Risky Connections Alert -->
<?php if (!empty($risky_connections)): ?>
<div class="report-card">
    <h5><i data-lucide="triangle-alert" class="icon-lucide text-danger"></i> High-Risk Connections Detected</h5>
    <p class="text-muted small">Connections that require immediate attention</p>
    
    <?php foreach (array_slice($risky_connections, 0, 20) as $risk): ?>
        <div class="risk-alert">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <strong><?= htmlspecialchars($risk['protocol']) ?></strong>
                    <span class="badge bg-<?= $risk['severity'] === 'critical' ? 'danger' : 'warning' ?> ms-2">
                        <?= strtoupper($risk['severity']) ?>
                    </span>
                </div>
                <small class="text-muted"><?= date('M d, H:i:s', strtotime($risk['timestamp'])) ?></small>
            </div>
            
            <div class="connection-flow">
                <code><?= htmlspecialchars($risk['source']) ?></code>
                <i data-lucide="arrow-right" class="icon-lucide flow-arrow"></i>
                <code><?= htmlspecialchars($risk['destination']) ?>:<?= htmlspecialchars($risk['port']) ?></code>
                <span class="badge bg-<?= $risk['action'] === 'accept' ? 'success' : 'danger' ?> ms-auto">
                    <?= strtoupper($risk['action']) ?>
                </span>
            </div>
            
            <div class="small">
                <i data-lucide="alert-circle" class="icon-lucide text-danger"></i> 
                <strong>Risks:</strong> <?= implode(', ', $risk['risk_reasons']) ?>
            </div>
            
            <?php if ($risk['duration'] > 0): ?>
                <div class="small mt-1">
                    <i data-lucide="clock" class="icon-lucide"></i> Duration: <?= round($risk['duration']/60, 1) ?> minutes
                    | <i data-lucide="database" class="icon-lucide"></i> Data: <?= formatBytes($risk['bandwidth']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Charts Row -->
<div class="row g-3 mt-4">
    <div class="col-lg-8">
        <div class="report-card">
            <h5><i data-lucide="line-chart" class="icon-lucide"></i> Remote Access Activity Timeline</h5>
            <div class="chart-container">
                <canvas id="timelineChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="report-card">
            <h5><i data-lucide="chart-pie" class="icon-lucide"></i> Protocol Distribution</h5>
            <div class="chart-container">
                <canvas id="protocolChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Source & Destination Analysis -->
<div class="row g-3 mt-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="user" class="icon-lucide -secret"></i> Top Remote Access Sources</h5>
            <p class="text-muted small">Systems initiating remote connections</p>
            <div class="table-container">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Source IP</th>
                            <th>Protocols</th>
                            <th>Targets</th>
                            <th>Connections</th>
                            <th>Failed</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_sources as $src): ?>
                            <?php 
                                arsort($src['protocols']);
                                $top_proto = array_key_first($src['protocols']);
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($session['source']) ?></code></td>
                        <td><code><?= htmlspecialchars($session['destination']) ?></code></td>
                        <td><?= htmlspecialchars($session['port']) ?></td>
                        <td>
                            <strong><?= gmdate('H:i:s', $session['duration']) ?></strong>
                            <?php if ($session['duration'] > 1800): ?>
                                <i data-lucide="triangle-alert" class="icon-lucide text-warning ms-1"></i>
                            <?php endif; ?>
                        </td>
                        <td><?= formatBytes($session['bandwidth']) ?></td>
                        <td class="small">
                            <?= date('H:i', strtotime($session['started'])) ?> - 
                            <?= date('H:i', strtotime($session['ended'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Unauthorized Access Attempts -->
<?php if (!empty($unauthorized_attempts)): ?>
<div class="report-card mt-4">
    <h5><i data-lucide="ban" class="icon-lucide text-danger"></i> Unauthorized Access Attempts</h5>
    <p class="text-muted small">Blocked or failed remote access attempts</p>
    <div class="table-container" style="max-height: 400px; overflow-y: auto;">
        <table class="table table-hover table-sm">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Protocol</th>
                    <th>Source</th>
                    <th>Target</th>
                    <th>Port</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($unauthorized_attempts, 0, 50) as $attempt): ?>
                    <tr>
                        <td class="small"><?= date('H:i:s', strtotime($attempt['timestamp'])) ?></td>
                        <td>
                            <span class="badge bg-<?= $remote_protocols[$attempt['protocol']]['color'] ?>">
                                <?= htmlspecialchars($attempt['protocol']) ?>
                            </span>
                        </td>
                        <td><code><?= htmlspecialchars($attempt['source']) ?></code></td>
                        <td><code><?= htmlspecialchars($attempt['destination']) ?></code></td>
                        <td><?= htmlspecialchars($attempt['port']) ?></td>
                        <td>
                            <span class="badge bg-danger">
                                <?= strtoupper($attempt['action']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Security Recommendations -->
<div class="report-card mt-4">
    <h5><i data-lucide="lightbulb" class="icon-lucide text-warning"></i> Security Recommendations</h5>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="alert alert-warning">
                <h6><i data-lucide="shield" class="icon-lucide"></i> Best Practices</h6>
                <ul class="mb-0 small">
                    <li>Disable Telnet and use SSH instead</li>
                    <li>Implement VPN for remote access</li>
                    <li>Use multi-factor authentication</li>
                    <li>Monitor for unusual session durations</li>
                    <li>Restrict remote access by IP whitelist</li>
                </ul>
            </div>
        </div>
        <div class="col-md-6">
            <div class="alert alert-info">
                <h6><i data-lucide="alert-circle" class="icon-lucide"></i> Key Metrics to Watch</h6>
                <ul class="mb-0 small">
                    <li>Failed authentication attempts</li>
                    <li>Sessions longer than 1 hour</li>
                    <li>High bandwidth on remote protocols</li>
                    <li>Multiple targets from single source</li>
                    <li>Access from unknown IP addresses</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<script src="../js/chart.js"></script>
<script>
// Timeline chart with multiple protocols
const timelineData = <?= json_encode($timeline) ?>;
const protocols = <?= json_encode(array_keys($remote_protocols)) ?>;
const protocolColors = {
    'SSH': '#3b82f6',
    'Telnet': '#ef4444',
    'RDP': '#f59e0b',
    'VNC': '#f59e0b',
    'TightVNC': '#f59e0b',
    'TeamViewer': '#3b82f6',
    'AnyDesk': '#3b82f6',
    'SFTP': '#10b981',
    'FTP': '#f59e0b',
    'WinRM': '#3b82f6',
    'X11': '#3b82f6'
};

const datasets = protocols.map(proto => ({
    label: proto,
    data: Object.keys(timelineData).map(hour => timelineData[hour][proto] || 0),
    borderColor: protocolColors[proto],
    backgroundColor: protocolColors[proto] + '33',
    fill: false,
    tension: 0.3
}));

new Chart(document.getElementById('timelineChart'), {
    type: 'line',
    data: {
        labels: Object.keys(timelineData),
        datasets: datasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                display: true,
                position: 'bottom'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                stacked: false
            }
        }
    }
});

// Protocol distribution pie chart
const protocolStats = <?= json_encode(array_values(array_filter($protocol_stats, fn($s) => $s['count'] > 0))) ?>;
new Chart(document.getElementById('protocolChart'), {
    type: 'doughnut',
    data: {
        labels: protocolStats.map(s => s.name),
        datasets: [{
            data: protocolStats.map(s => s.count),
            backgroundColor: protocolStats.map(s => protocolColors[s.name])
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'bottom'
            }
        }
    }
});
</script>specialchars($src['ip']) ?></code></td>
                                <td>
                                    <span class="badge bg-secondary"><?= $top_proto ?></span>
                                    <?php if (count($src['protocols']) > 1): ?>
                                        <small class="text-muted">+<?= count($src['protocols']) - 1 ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= count($src['targets']) ?></td>
                                <td><?= number_format($src['total_connections']) ?></td>
                                <td>
                                    <?php if ($src['denied_count'] > 0): ?>
                                        <span class="badge bg-danger"><?= $src['denied_count'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatBytes($src['total_bandwidth']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($top_sources)): ?>
                            <tr><td colspan="6" class="text-center">No source data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="server" class="icon-lucide"></i> Top Remote Access Targets</h5>
            <p class="text-muted small">Systems being accessed remotely</p>
            <div class="table-container">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Target IP</th>
                            <th>Protocols</th>
                            <th>Sources</th>
                            <th>Ports</th>
                            <th>Connections</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_destinations as $dst): ?>
                            <?php 
                                arsort($dst['protocols']);
                                $top_proto = array_key_first($dst['protocols']);
                                $ports = array_keys($dst['ports_accessed']);
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($dst['ip']) ?></code></td>
                                <td>
                                    <span class="badge bg-secondary"><?= $top_proto ?></span>
                                    <?php if (count($dst['protocols']) > 1): ?>
                                        <small class="text-muted">+<?= count($dst['protocols']) - 1 ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= count($dst['sources']) ?></td>
                                <td><small><?= implode(',', array_slice($ports, 0, 3)) ?></small></td>
                                <td><?= number_format($dst['total_connections']) ?></td>
                                <td><?= formatBytes($dst['total_bandwidth']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($top_destinations)): ?>
                            <tr><td colspan="6" class="text-center">No destination data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Longest Sessions -->
<?php if (!empty($longest_sessions)): ?>
<div class="report-card mt-4">
    <h5><i data-lucide="hourglass-half" class="icon-lucide"></i> Longest Active Sessions</h5>
    <p class="text-muted small">Extended remote access sessions that may indicate persistence or data exfiltration</p>
    <div class="table-container">
        <table class="table table-hover table-sm">
            <thead>
                <tr>
                    <th>Protocol</th>
                    <th>Source</th>
                    <th>Destination</th>
                    <th>Port</th>
                    <th>Duration</th>
                    <th>Data Transferred</th>
                    <th>Session Period</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($longest_sessions as $session): ?>
                    <tr>
                        <td>
                            <span class="badge bg-<?= $remote_protocols[$session['protocol']]['color'] ?>">
                                <?= htmlspecialchars($session['protocol']) ?>
                            </span>
                        </td>
                        <td><code><?= html
