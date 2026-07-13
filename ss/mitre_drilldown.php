<?php
require_once __DIR__ . '/../db_config.php';
// ss/mitre_drilldown.php - Detailed MITRE ATT&CK Technique Analysis
// FIXED: Theme-aware nav-tabs and tables (no black blocks)

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
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

$mitre_rules = [
    'T1110' => [
        'name' => 'Brute Force',
        'tactic' => 'Credential Access',
        'patterns' => ['authentication.*failed', 'login.*failed', 'status=\"failure\"', 'reason=failure', 'logid="0100032001"', 'logid="0100032002"'],
        'severity' => 'high',
        'description' => 'Multiple failed authentication attempts detected indicating potential credential stuffing or brute force attack',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1110/',
        'recommendations' => ['Implement account lockout policies', 'Enable multi-factor authentication', 'Monitor for unusual login patterns', 'Block source IPs after threshold failures']
    ],
    'T1499' => [
        'name' => 'Endpoint Denial of Service',
        'tactic' => 'Impact',
        'patterns' => ['sentbyte > 500000', 'rcvdbyte > 500000'],
        'severity' => 'critical',
        'description' => 'High volume traffic detected that may indicate endpoint DoS attack attempting to exhaust system resources',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1499/',
        'recommendations' => ['Implement rate limiting', 'Configure DDoS protection', 'Enable traffic shaping', 'Monitor resource utilization']
    ],
    'T1498' => [
        'name' => 'Network Denial of Service',
        'tactic' => 'Impact',
        'patterns' => ['service=\"DNS\".*action=\"deny\"', 'flood', 'attack=\"DoS\"'],
        'severity' => 'critical',
        'description' => 'Network-level denial of service attack detected attempting to disrupt network availability',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1498/',
        'recommendations' => ['Enable anti-DDoS features', 'Configure flood protection', 'Implement traffic filtering', 'Contact ISP for upstream filtering']
    ],
    'T1590' => [
        'name' => 'Gather Victim Network Information',
        'tactic' => 'Reconnaissance',
        'patterns' => ['service=\"DNS\"'],
        'severity' => 'medium',
        'description' => 'Reconnaissance activity via DNS queries attempting to map network infrastructure',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1590/',
        'recommendations' => ['Monitor DNS query patterns', 'Implement DNS RPZ (Response Policy Zones)', 'Block known reconnaissance tools', 'Rate limit DNS queries']
    ],
    'T1595' => [
        'name' => 'Active Scanning',
        'tactic' => 'Reconnaissance',
        'patterns' => ['dstport=22', 'dstport=23', 'dstport=3389', 'dstport=445'],
        'severity' => 'medium',
        'description' => 'Port scanning activity detected attempting to discover open services and vulnerabilities',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1595/',
        'recommendations' => ['Enable IPS/IDS', 'Configure port scan detection', 'Block automated scanners', 'Hide service banners']
    ],
    'T1046' => [
        'name' => 'Network Service Discovery',
        'tactic' => 'Discovery',
        'patterns' => ['action=\"deny\".*service=', 'action=\"deny\".*dstport='],
        'severity' => 'medium',
        'description' => 'Network service enumeration attempts to identify active services and potential attack vectors',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1046/',
        'recommendations' => ['Minimize exposed services', 'Use service-specific firewalls', 'Enable network segmentation', 'Monitor denied connection patterns']
    ],
    'T1548' => [
        'name' => 'Abuse Elevation Control Mechanism',
        'tactic' => 'Privilege Escalation',
        'patterns' => ['cfgpath=\"firewall.policy\"', 'logdesc=\"Attribute configured\".*policy'],
        'severity' => 'high',
        'description' => 'Unauthorized policy modifications detected that may indicate privilege escalation attempts',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1548/',
        'recommendations' => ['Enable change auditing', 'Restrict administrative access', 'Implement approval workflows', 'Monitor for unauthorized changes']
    ],
    'T1021' => [
        'name' => 'Remote Services',
        'tactic' => 'Lateral Movement',
        'patterns' => ['dstport=22', 'dstport=3389', 'service=\"SSH\"', 'service=\"RDP\"'],
        'severity' => 'high',
        'description' => 'Remote service access detected that may indicate lateral movement within the network',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1021/',
        'recommendations' => ['Implement network segmentation', 'Require VPN for remote access', 'Enable MFA on remote services', 'Monitor for unusual access patterns']
    ],
    'T1570' => [
        'name' => 'Lateral Tool Transfer',
        'tactic' => 'Lateral Movement',
        'patterns' => ['service=\"SMB\"', 'dstport=445', 'service=\"FTP\"', 'dstport=21'],
        'severity' => 'high',
        'description' => 'File transfer activity detected that may indicate tool staging for lateral movement',
        'mitre_url' => 'https://attack.mitre.org/techniques/T1570/',
        'recommendations' => ['Monitor file transfer protocols', 'Restrict SMB/FTP access', 'Implement file integrity monitoring', 'Scan transferred files']
    ]
];

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo '<div class="alert alert-danger">DB connection failed</div>';
    exit;
}

$technique = $_GET['technique'] ?? '';
$start = $_GET['start'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end   = $_GET['end']   ?? date('Y-m-d H:i:s');

if (!$technique || !isset($mitre_rules[$technique])) {
    echo '<div class="alert alert-warning">Invalid or missing technique</div>';
    exit;
}

$rule = $mitre_rules[$technique];

$query = "
    SELECT message, received_at, source_ip
    FROM (
        SELECT message, received_at, source_ip
        FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end'
        UNION ALL
        SELECT message, received_at, source_ip
        FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end'
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 10000
";

$result = mysqli_query($con, $query);
$matching_events = [];
$source_ips = [];
$target_ips = [];
$users = [];
$devices = [];
$timeline = [];

while ($row = mysqli_fetch_assoc($result)) {
    $msg = $row['message'];
    $matched = false;
    foreach ($rule['patterns'] as $pattern) {
        if (preg_match('/' . $pattern . '/i', $msg)) { $matched = true; break; }
    }
    if (!$matched) continue;

    $parsed = parseMessage($msg);
    $srcip   = $parsed['srcip'] ?? $parsed['remip'] ?? 'N/A';
    $dstip   = $parsed['dstip'] ?? $parsed['locip'] ?? 'N/A';
    $user    = $parsed['user'] ?? $parsed['unauthuser'] ?? 'N/A';
    $device  = $parsed['devname'] ?? $row['source_ip'];
    $action  = $parsed['action'] ?? 'N/A';
    $service = $parsed['service'] ?? $parsed['proto'] ?? 'N/A';
    $dstport = $parsed['dstport'] ?? 'N/A';

    $source_ips[$srcip] = ($source_ips[$srcip] ?? 0) + 1;
    $target_ips[$dstip] = ($target_ips[$dstip] ?? 0) + 1;
    $users[$user]       = ($users[$user] ?? 0) + 1;
    $devices[$device]   = ($devices[$device] ?? 0) + 1;

    $hour = date('Y-m-d H:00', strtotime($row['received_at']));
    $timeline[$hour] = ($timeline[$hour] ?? 0) + 1;

    if (count($matching_events) < 100) {
        $matching_events[] = [
            'timestamp' => $row['received_at'],
            'srcip'     => $srcip,
            'dstip'     => $dstip,
            'user'      => $user,
            'device'    => $device,
            'action'    => $action,
            'service'   => $service,
            'dstport'   => $dstport,
            'message'   => substr($msg, 0, 200)
        ];
    }
}

arsort($source_ips);
arsort($target_ips);
arsort($users);
arsort($devices);
mysqli_close($con);

// Read theme from cookie for JS chart colors
$theme = $_COOKIE['theme'] ?? 'dark';
$isDark = $theme !== 'light';
?>

<link rel="stylesheet" href="/css/pages/ss_mitre_drilldown.css">

<div class="mitre-wrap">

<!-- Technique Header -->
<div class="mitre-header">
    <h4><?= htmlspecialchars($technique) ?>: <?= htmlspecialchars($rule['name']) ?></h4>
    <div class="mb-2">
        <span class="badge bg-<?= $rule['severity'] === 'critical' ? 'danger' : ($rule['severity'] === 'high' ? 'warning' : 'info') ?>">
            <?= strtoupper($rule['severity']) ?> SEVERITY
        </span>
        <span class="badge bg-secondary"><?= htmlspecialchars($rule['tactic']) ?></span>
    </div>
    <p class="mb-2"><?= htmlspecialchars($rule['description']) ?></p>
    <a href="<?= htmlspecialchars($rule['mitre_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
        <i data-lucide="external-link-alt" class="icon-lucide"></i> View on MITRE ATT&CK
    </a>
</div>

<!-- Statistics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="mitre-stat">
            <div class="mitre-stat-value"><?= count($matching_events) ?></div>
            <div style="color:var(--m-muted);font-size:.85rem;">Total Detections</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="mitre-stat">
            <div class="mitre-stat-value"><?= count($source_ips) ?></div>
            <div style="color:var(--m-muted);font-size:.85rem;">Unique Sources</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="mitre-stat">
            <div class="mitre-stat-value"><?= count($target_ips) ?></div>
            <div style="color:var(--m-muted);font-size:.85rem;">Unique Targets</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="mitre-stat">
            <div class="mitre-stat-value"><?= count($devices) ?></div>
            <div style="color:var(--m-muted);font-size:.85rem;">Affected Devices</div>
        </div>
    </div>
</div>

<!-- Timeline -->
<?php if (!empty($timeline)): ?>
<div class="mb-4">
    <h6 style="color:var(--m-text)"><i data-lucide="line-chart" class="icon-lucide"></i> Detection Timeline</h6>
    <canvas id="techniqueTimelineChart" style="height:200px;max-height:200px;"></canvas>
</div>
<?php endif; ?>

<!-- Recommendations -->
<div class="mb-4">
    <h6 style="color:var(--m-text)"><i data-lucide="shield" class="icon-lucide"></i> Recommended Actions</h6>
    <?php foreach ($rule['recommendations'] as $rec): ?>
        <div class="recommendation-item">
            <i data-lucide="check-circle" class="icon-lucide text-success"></i> <?= htmlspecialchars($rec) ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" id="mitreTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#m-events" type="button">
            <i data-lucide="list" class="icon-lucide"></i> Recent Events
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#m-sources" type="button">
            <i data-lucide="network" class="icon-lucide"></i> Source IPs
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#m-targets" type="button">
            <i data-lucide="bullseye" class="icon-lucide"></i> Target IPs
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#m-users" type="button">
            <i data-lucide="user" class="icon-lucide s"></i> Users Involved
        </button>
    </li>
</ul>

<div class="tab-content" id="mitreTabContent">

    <!-- Events -->
    <div class="tab-pane fade show active" id="m-events" role="tabpanel">
        <div style="max-height:400px;overflow-y:auto;">
            <?php foreach ($matching_events as $event): ?>
                <div class="event-row">
                    <div class="row">
                        <div class="col-md-2">
                            <small class="text-muted"><?= date('M d, H:i:s', strtotime($event['timestamp'])) ?></small>
                        </div>
                        <div class="col-md-3">
                            <strong>Source:</strong> <code><?= htmlspecialchars($event['srcip']) ?></code>
                        </div>
                        <div class="col-md-3">
                            <strong>Target:</strong> <code><?= htmlspecialchars($event['dstip']) ?>:<?= htmlspecialchars($event['dstport']) ?></code>
                        </div>
                        <div class="col-md-2">
                            <strong>User:</strong> <?= htmlspecialchars($event['user']) ?>
                        </div>
                        <div class="col-md-2">
                            <span class="badge bg-<?= $event['action'] === 'accept' ? 'success' : 'danger' ?>">
                                <?= strtoupper(htmlspecialchars($event['action'])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="row mt-1">
                        <div class="col-12">
                            <small><strong>Service:</strong> <?= htmlspecialchars($event['service']) ?> | <strong>Device:</strong> <?= htmlspecialchars($event['device']) ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($matching_events)): ?>
                <div class="alert alert-info mt-2">No matching events found</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Source IPs -->
    <div class="tab-pane fade" id="m-sources" role="tabpanel">
        <table class="table table-sm table-hover mt-2">
            <thead><tr><th>Source IP</th><th>Detections</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($source_ips, 0, 50, true) as $ip => $count): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($ip) ?></code></td>
                        <td><span class="badge bg-danger"><?= number_format($count) ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="parent.drillDownIP('<?= htmlspecialchars($ip) ?>')">
                                <i data-lucide="search" class="icon-lucide"></i> Investigate
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Target IPs -->
    <div class="tab-pane fade" id="m-targets" role="tabpanel">
        <table class="table table-sm table-hover mt-2">
            <thead><tr><th>Target IP</th><th>Detections</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($target_ips, 0, 50, true) as $ip => $count): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($ip) ?></code></td>
                        <td><span class="badge bg-warning"><?= number_format($count) ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="parent.drillDownIP('<?= htmlspecialchars($ip) ?>')">
                                <i data-lucide="search" class="icon-lucide"></i> Investigate
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Users -->
    <div class="tab-pane fade" id="m-users" role="tabpanel">
        <table class="table table-sm table-hover mt-2">
            <thead><tr><th>User</th><th>Associated Events</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user => $count): ?>
                    <tr>
                        <td><?= htmlspecialchars($user) ?></td>
                        <td><span class="badge bg-info"><?= number_format($count) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div><!-- /tab-content -->

</div><!-- /mitre-wrap -->

<?php if (!empty($timeline)): ?>
<script>
(function() {
    const isDark = <?= $isDark ? 'true' : 'false' ?>;
    const gridColor  = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
    const labelColor = isDark ? '#94a3b8' : '#64748b';
    new Chart(document.getElementById('techniqueTimelineChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($timeline)) ?>,
            datasets: [{
                label: 'Detections',
                data: <?= json_encode(array_values($timeline)) ?>,
                backgroundColor: 'rgba(239,68,68,0.7)',
                borderColor: '#dc2626',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: labelColor } }
            },
            scales: {
                x: { ticks: { color: labelColor }, grid: { color: gridColor } },
                y: { beginAtZero: true, ticks: { color: labelColor }, grid: { color: gridColor } }
            }
        }
    });
})();
</script>
<?php endif; ?>
