<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/mitre_drilldown.php - Detailed MITRE ATT&CK Technique Analysis
// FIXED: Theme-aware nav-tabs and tables (no black blocks)

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
}
session_write_close();

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

require_once __DIR__ . '/../includes/cache.php';
$range = $_GET['range'] ?? '24h';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("mitre_{$technique}", $start, $end, "", $range);

$query = "
    SELECT message, received_at, source_ip, src_ip, dst_ip, dst_port, app, service, action
    FROM (
        SELECT message, received_at, source_ip, src_ip, dst_ip, dst_port, app, service, action
        FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end'
        UNION ALL
        SELECT message, received_at, source_ip, src_ip, dst_ip, dst_port, app, service, action
        FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end'
    ) AS combined
    ORDER BY received_at DESC
";

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $query, $rule) {
    $result = mysqli_query($con, $query);
    if (!$result) return null;

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
        $srcip   = $row['src_ip'] ?: $parsed['srcip'] ?: $parsed['remip'] ?: 'N/A';
        $dstip   = $row['dst_ip'] ?: $parsed['dstip'] ?: $parsed['locip'] ?: 'N/A';
        $user    = $parsed['user'] ?? $parsed['unauthuser'] ?? 'N/A';
        $device  = $row['source_ip'];
        $action  = $row['action'] ?: $parsed['action'] ?? 'N/A';
        $service = $row['service'] ?: $parsed['service'] ?? $parsed['proto'] ?? 'N/A';
        $dstport = $row['dst_port'] ?: $parsed['dstport'] ?? 'N/A';

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

    return compact('matching_events', 'source_ips', 'target_ips', 'users', 'devices', 'timeline');
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please try again.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);

mysqli_close($con);

// Read theme from cookie for JS chart colors
$theme = $_COOKIE['theme'] ?? 'dark';
$isDark = $theme !== 'light';
?>

<style>
/* ── Core component vars – work in both dark modal and light modal ── */
.mitre-wrap {
    --m-bg:        <?= $isDark ? '#1e293b' : '#ffffff' ?>;
    --m-bg2:       <?= $isDark ? '#0f172a' : '#f8fafc' ?>;
    --m-border:    <?= $isDark ? '#334155' : '#e2e8f0' ?>;
    --m-text:      <?= $isDark ? '#f1f5f9' : '#0f172a' ?>;
    --m-muted:     <?= $isDark ? '#94a3b8' : '#64748b' ?>;
    --m-hover:     <?= $isDark ? 'rgba(0,198,255,0.07)' : '#f1f5f9' ?>;
    --m-divider:   <?= $isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)' ?>;
}

/* ── Header block ── */
.mitre-wrap .mitre-header {
    background: linear-gradient(135deg, rgba(239,68,68,.12), rgba(239,68,68,.04));
    border-left: 4px solid #ef4444;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: var(--m-text);
}

/* ── Stat boxes ── */
.mitre-wrap .mitre-stat {
    background: var(--m-bg);
    border: 1px solid var(--m-border);
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    color: var(--m-text);
}
.mitre-wrap .mitre-stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: #ef4444;
}

/* ── Event rows ── */
.mitre-wrap .event-row {
    border-bottom: 1px solid var(--m-divider);
    padding: 10px;
    color: var(--m-text);
}
.mitre-wrap .event-row:hover { background: var(--m-hover); }
.mitre-wrap .event-row small.text-muted { color: var(--m-muted) !important; }

/* ── Recommendation items ── */
.mitre-wrap .recommendation-item {
    padding: 8px 12px;
    background: rgba(16,185,129,.1);
    border-left: 3px solid #10b981;
    margin-bottom: 8px;
    border-radius: 4px;
    color: var(--m-text);
}

/* ── NAV TABS – the main fix for black blocks ── */
.mitre-wrap .nav-tabs {
    border-bottom: 1px solid var(--m-border);
}
.mitre-wrap .nav-tabs .nav-link {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px 6px 0 0;
    color: var(--m-muted);
    padding: 8px 14px;
    font-size: 0.875rem;
    transition: color .2s, background .2s;
}
.mitre-wrap .nav-tabs .nav-link:hover {
    color: var(--m-text);
    background: var(--m-hover);
    border-color: var(--m-border) var(--m-border) transparent;
}
.mitre-wrap .nav-tabs .nav-link.active {
    background: var(--m-bg);
    color: var(--m-text);
    border-color: var(--m-border) var(--m-border) var(--m-bg);
    font-weight: 600;
}

/* ── Tables ── */
.mitre-wrap .table {
    color: var(--m-text);
    border-color: var(--m-border);
}
.mitre-wrap .table th {
    background: var(--m-bg2);
    color: var(--m-muted);
    border-color: var(--m-border);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.mitre-wrap .table td {
    border-color: var(--m-border);
    vertical-align: middle;
}
.mitre-wrap .table-hover tbody tr:hover {
    background: var(--m-hover);
    color: var(--m-text);
}
/* kill Bootstrap's built-in dark table overrides inside the modal */
.mitre-wrap .table > :not(caption) > * > * {
    background-color: transparent;
}

/* ── Tab content pane background ── */
.mitre-wrap .tab-content {
    background: var(--m-bg);
    border: 1px solid var(--m-border);
    border-top: none;
    border-radius: 0 0 8px 8px;
    padding: 12px;
}

/* ── code tags ── */
.mitre-wrap code {
    color: <?= $isDark ? '#38bdf8' : '#0369a1' ?>;
    background: <?= $isDark ? 'rgba(56,189,248,.1)' : 'rgba(3,105,161,.08)' ?>;
    padding: 2px 6px;
    border-radius: 4px;
}
</style>

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
        <i class="fas fa-external-link-alt"></i> View on MITRE ATT&CK
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
    <h6 style="color:var(--m-text)"><i class="fas fa-chart-line"></i> Detection Timeline</h6>
    <canvas id="techniqueTimelineChart" style="height:200px;max-height:200px;"></canvas>
</div>
<?php endif; ?>

<!-- Recommendations -->
<div class="mb-4">
    <h6 style="color:var(--m-text)"><i class="fas fa-shield-alt"></i> Recommended Actions</h6>
    <?php foreach ($rule['recommendations'] as $rec): ?>
        <div class="recommendation-item">
            <i class="fas fa-check-circle text-success"></i> <?= htmlspecialchars($rec) ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" id="mitreTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#m-events" type="button">
            <i class="fas fa-list"></i> Recent Events
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#m-sources" type="button">
            <i class="fas fa-network-wired"></i> Source IPs
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#m-targets" type="button">
            <i class="fas fa-bullseye"></i> Target IPs
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#m-users" type="button">
            <i class="fas fa-users"></i> Users Involved
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
                                <i class="fas fa-search"></i> Investigate
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
                                <i class="fas fa-search"></i> Investigate
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
