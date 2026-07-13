<?php
require_once __DIR__ . '/../db_config.php';
// ss/bruteforce.php - Brute Force Detection (Tactical HUD visual pass, backend unchanged)
// Uses 2-phase SQL aggregation to avoid 504 timeout on 30d range

set_time_limit(300);

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
}

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

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'min ago';
    if ($diff < 86400) return floor($diff / 3600) . 'hr ago';
    return floor($diff / 86400) . 'd ago';
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
    echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>';
    exit;
}

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';

$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$has_firewall = false;
$firewall_ip = '';
$fw_result = mysqli_query($con, "SELECT firewall_ip FROM response_config WHERE is_active = 1 LIMIT 1");
if ($fw_result && $fw_row = mysqli_fetch_assoc($fw_result)) {
    $has_firewall = true;
    $firewall_ip = $fw_row['firewall_ip'];
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

// PHASE 1: aggregation
$agg_query = "
    SELECT source_ip, COUNT(*) AS attempt_count
    FROM (
        SELECT source_ip, message FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
        UNION ALL
        SELECT source_ip, message FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
    ) AS combined
    GROUP BY source_ip
    HAVING attempt_count >= 3
    ORDER BY attempt_count DESC
    LIMIT 200
";

$agg_result = mysqli_query($con, $agg_query);
if (!$agg_result) {
    echo '<div class="alert alert-danger">Query error (phase 1): ' . mysqli_error($con) . '</div>';
    mysqli_close($con);
    exit;
}

$ip_counts = [];
while ($row = mysqli_fetch_assoc($agg_result)) {
    $ip_counts[$row['source_ip']] = (int)$row['attempt_count'];
}

$timeline_query = "
    SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt
    FROM (
        SELECT received_at FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
        UNION ALL
        SELECT received_at FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
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

$breakdown_query = "
    SELECT message
    FROM (
        SELECT message, received_at FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
        UNION ALL
        SELECT message, received_at FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
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

// PHASE 2: detail for top 50 IPs
$brute_force_attacks = [];
$failed_attempts_detail = [];
$top_ip_list = array_keys(array_slice($ip_counts, 0, 50, true));

if (!empty($top_ip_list)) {
    $ip_placeholders = implode(',', array_map(fn($ip) => "'" . mysqli_real_escape_string($con, $ip) . "'", $top_ip_list));

    $detail_query = "
        SELECT message, received_at, source_ip
        FROM (
            SELECT message, received_at, source_ip FROM syslog_entries
            WHERE received_at BETWEEN '$start' AND '$end' AND source_ip IN ($ip_placeholders) AND $auth_filter
            UNION ALL
            SELECT message, received_at, source_ip FROM syslog_entries_archive
            WHERE received_at BETWEEN '$start' AND '$end' AND source_ip IN ($ip_placeholders) AND $auth_filter
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

usort($brute_force_attacks, fn($a, $b) => strtotime($b['last_attempt']) <=> strtotime($a['last_attempt']));

$top_attackers = [];
foreach ($ip_counts as $ip => $count) {
    $top_attackers[] = ['ip' => $ip, 'count' => $count];
}
$top_attackers = array_slice($top_attackers, 0, 10);

ksort($timeline);
$timeline_labels = array_keys($timeline);
$timeline_values = array_values($timeline);

$total_failed_sql = "
    SELECT COUNT(*) AS total
    FROM (
        SELECT 1 FROM syslog_entries WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
        UNION ALL
        SELECT 1 FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $auth_filter $device_where
    ) AS combined
";
$total_res = mysqli_query($con, $total_failed_sql);
$total_failed = $total_res ? (int)mysqli_fetch_assoc($total_res)['total'] : array_sum($ip_counts);

$critical_count = count(array_filter($brute_force_attacks, fn($a) => $a['risk_level'] === 'critical'));

mysqli_close($con);
?>

<link rel="stylesheet" href="/css/pages/ss_bruteforce.css">

<?php if ($has_firewall): ?>
<div class="alert alert-info">
    <i data-lucide="shield" class="icon-lucide"></i>
    <strong>Automated Response Available:</strong> Connected to firewall <code><?= htmlspecialchars($firewall_ip) ?></code>. You can quarantine attacking IPs directly from this page.
</div>
<?php endif; ?>

<div class="threat-meter">
    <?php if ($critical_count > 0): ?>
        <span class="lvl high"><i data-lucide="triangle-alert" class="icon-lucide"></i> <?= $critical_count ?> Critical Threat<?= $critical_count > 1 ? 's' : '' ?> Detected</span>
    <?php else: ?>
        <span class="lvl clear"><i data-lucide="check-circle" class="icon-lucide"></i> No Critical Threats in Window</span>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="lock" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= (int)$total_failed ?>">0</div>
            <span class="stat-chip chip-danger">Failed Auth Attempts</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="user" class="icon-lucide -secret"></i></div>
            <div class="stat-value" data-raw="<?= count($brute_force_attacks) ?>">0</div>
            <span class="stat-chip chip-warning">Brute Force Attacks</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i data-lucide="user" class="icon-lucide s"></i></div>
            <div class="stat-value" data-raw="<?= count($ip_counts) ?>">0</div>
            <span class="stat-chip chip-info">Unique Attack Sources</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="shield" class="icon-lucide"></i></div>
            <div class="stat-value"><?= $has_firewall ? 'ACTIVE' : 'INACTIVE' ?></div>
            <span class="stat-chip <?= $has_firewall ? 'chip-success' : 'chip-neutral' ?>">Auto-Response Status</span>
        </div>
    </div>
</div>

<!-- Service and Port Breakdown -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="server" class="icon-lucide"></i> Targeted Services</h5>
            <div class="table-container" style="max-height: 300px;">
                <table class="table table-hover">
                    <thead><tr><th>Service</th><th>Failed Attempts</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($service_breakdown, 0, 10, true) as $service => $count): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($service) ?></strong></td>
                                <td><span class="badge bg-danger"><?= number_format($count) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($service_breakdown)): ?>
                            <tr><td colspan="2" class="text-center">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="door-open" class="icon-lucide"></i> Targeted Ports</h5>
            <div class="table-container" style="max-height: 300px;">
                <table class="table table-hover">
                    <thead><tr><th>Port</th><th>Service</th><th>Failed Attempts</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($port_breakdown, 0, 10, true) as $port => $count): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($port) ?></code></td>
                                <td><span class="port-badge"><?= getPortName($port) ?></span></td>
                                <td><span class="badge bg-danger"><?= number_format($count) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($port_breakdown)): ?>
                            <tr><td colspan="3" class="text-center">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="report-card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom: 4px;">
        <h5 style="border-bottom:none; padding-bottom:0; margin-bottom:0;"><i data-lucide="user" class="icon-lucide -lock"></i> Authentication Brute Force Incidents</h5>
        <div style="display:flex; gap:14px; font-family:var(--font-mono); font-size:0.72rem; color:var(--text-mid);">
            <span><span class="score-badge score-red" style="width:18px;height:18px;font-size:0.6rem;"><?= count(array_filter($brute_force_attacks, fn($a)=>$a['risk_level']==='critical')) ?></span>Critical</span>
            <span><span class="score-badge score-amber" style="width:18px;height:18px;font-size:0.6rem;"><?= count(array_filter($brute_force_attacks, fn($a)=>$a['risk_level']==='high')) ?></span>High</span>
            <span><span class="score-badge score-green" style="width:18px;height:18px;font-size:0.6rem;"><?= count($brute_force_attacks) ?></span>Total</span>
        </div>
    </div>
    <p class="text-muted small" style="font-family: var(--font-mono); font-size: 0.75rem; margin-top:0; margin-bottom:16px;">DETECTION RULE: 3+ failed attempts within a 5 minute window &middot; click a row for full detail</p>
    <div class="table-container">
        <?php if (!empty($brute_force_attacks)): ?>
            <table class="table table-hover incidents-table">
                <thead>
                    <tr>
                        <th style="width:26px;"></th>
                        <th>Severity</th>
                        <th>ID</th>
                        <th>Incident</th>
                        <th>Source</th>
                        <th>Target</th>
                        <th>Last Occurred</th>
                        <th>Attempts</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($brute_force_attacks as $i => $bf):
                        $incident_id = 'BF-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
                        $score_class = $bf['score'] >= 70 ? 'score-red' : ($bf['score'] >= 45 ? 'score-amber' : 'score-green');
                        $title = "Brute force attack from {$bf['source_ip']} — {$bf['attempt_count']} failed attempts on {$bf['port_name']} ({$bf['service']})";
                    ?>
                        <tr class="incident-row" onclick="showIncidentDetail(<?= $i ?>)">
                            <td onclick="event.stopPropagation()"><input type="checkbox"></td>
                            <td><span class="sev-pill sev-<?= $bf['risk_level'] ?>"><?= strtoupper($bf['risk_level']) ?></span></td>
                            <td><code><?= $incident_id ?></code></td>
                            <td style="max-width:280px;"><small><?= htmlspecialchars($title) ?></small></td>
                            <td>
                                <span class="score-badge <?= $score_class ?>"><?= $bf['score'] ?></span>
                                <a href="javascript:void(0)" onclick="event.stopPropagation(); drillDownIP('<?= htmlspecialchars($bf['source_ip']) ?>')" class="clickable-ip"><?= htmlspecialchars($bf['source_ip']) ?></a>
                            </td>
                            <td><code><?= htmlspecialchars($bf['target_ip']) ?></code></td>
                            <td><small><?= timeAgo($bf['last_attempt']) ?></small></td>
                            <td><span class="badge bg-danger"><?= number_format($bf['attempt_count']) ?></span></td>
                            <td onclick="event.stopPropagation()">
                                <?php if ($has_firewall): ?>
                                    <button class="btn btn-danger btn-sm" onclick="quarantineIP('<?= htmlspecialchars($bf['source_ip']) ?>', '<?= htmlspecialchars($firewall_ip) ?>', 'Brute force attack - <?= $bf['attempt_count'] ?> attempts on <?= htmlspecialchars($bf['port_name']) ?>')">
                                        <i data-lucide="ban" class="icon-lucide"></i>
                                    </button>
                                <?php else: ?>
                                    <a href="../responder.php?ip=<?= urlencode($bf['source_ip']) ?>" class="btn btn-outline-warning btn-sm" target="_blank" onclick="event.stopPropagation()">
                                        <i data-lucide="settings" class="icon-lucide"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-success"><i data-lucide="check-circle" class="icon-lucide"></i> No brute force attempts detected</div>
        <?php endif; ?>
    </div>
</div>

<script>
// Incident payload for the click-through detail modal (reuses the parent page's #drillDownModal)
const bruteForceIncidents = <?= json_encode(array_map(function($bf, $i) use ($has_firewall, $firewall_ip) {
    return [
        'id' => 'BF-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
        'source_ip' => $bf['source_ip'],
        'target_ip' => $bf['target_ip'],
        'port' => $bf['port'],
        'port_name' => $bf['port_name'],
        'service' => $bf['service'],
        'attempt_count' => $bf['attempt_count'],
        'time_window' => $bf['time_window'],
        'first_attempt' => date('M d, H:i:s', strtotime($bf['first_attempt'])),
        'last_attempt' => date('M d, H:i:s', strtotime($bf['last_attempt'])),
        'usernames' => $bf['usernames'],
        'risk_level' => $bf['risk_level'],
        'score' => $bf['score'],
        'has_firewall' => $has_firewall,
        'firewall_ip' => $firewall_ip,
    ];
}, $brute_force_attacks, array_keys($brute_force_attacks))) ?>;

function showIncidentDetail(idx) {
    const bf = bruteForceIncidents[idx];
    if (!bf) return;
    const modal = new bootstrap.Modal(document.getElementById('drillDownModal'));
    document.getElementById('drillDownModalLabel').innerHTML =
        `<i data-lucide="user" class="icon-lucide -lock"></i> ${bf.id} &middot; Brute Force Attack`;

    const usernamesRow = bf.usernames.length
        ? `<div class="detail-row"><span><i data-lucide="user" class="icon-lucide"></i> Usernames tried: ${bf.usernames.map(u => escapeHtml(u)).join(', ')}</span></div>`
        : '';

    const actionHtml = bf.has_firewall
        ? `<div class="quarantine-btn">
                <button class="btn btn-danger btn-sm" onclick="quarantineIP('${bf.source_ip}', '${bf.firewall_ip}', 'Brute force attack - ${bf.attempt_count} attempts on ${bf.port_name}')"><i data-lucide="ban" class="icon-lucide"></i> Quarantine This IP</button>
                <button class="btn btn-warning btn-sm" onclick="window.open('../responder.php?ip=${encodeURIComponent(bf.source_ip)}', '_blank')"><i data-lucide="external-link-alt" class="icon-lucide"></i> Manual Block</button>
           </div>`
        : `<div class="quarantine-btn">
                <a href="../responder.php?ip=${encodeURIComponent(bf.source_ip)}" class="btn btn-outline-warning btn-sm" target="_blank"><i data-lucide="settings" class="icon-lucide"></i> Configure Firewall to Block</a>
           </div>`;

    document.getElementById('drillDownContent').innerHTML = `
        <div class="attack-detail risk-${bf.risk_level}">
            <div class="attack-header">
                <span><i data-lucide="triangle-alert" class="icon-lucide"></i> ${bf.id}</span>
                <span class="badge bg-${bf.risk_level === 'critical' ? 'danger' : 'warning'}">${bf.risk_level.toUpperCase()} &middot; SCORE ${bf.score}</span>
            </div>
            <div class="attack-body">
                <div class="detail-row">
                    <span><i data-lucide="user" class="icon-lucide -secret"></i> Attacker: <code>${bf.source_ip}</code></span>
                    <span><i data-lucide="bullseye" class="icon-lucide"></i> Target: <code>${bf.target_ip}</code></span>
                </div>
                <div class="detail-row">
                    <span><i data-lucide="hashtag" class="icon-lucide"></i> Failed Attempts: <strong>${bf.attempt_count}</strong></span>
                    <span><i data-lucide="door-closed" class="icon-lucide"></i> Service: <strong>${bf.service}</strong></span>
                </div>
                <div class="detail-row">
                    <span><i data-lucide="hourglass-half" class="icon-lucide"></i> Time Window: ${bf.time_window}</span>
                    <span><i data-lucide="clock" class="icon-lucide"></i> First: ${bf.first_attempt}</span>
                </div>
                <div class="detail-row">
                    <span><i data-lucide="network" class="icon-lucide"></i> Port: <code>${bf.port}</code> <span class="port-badge">${bf.port_name}</span></span>
                    <span><i data-lucide="clock" class="icon-lucide"></i> Last: ${bf.last_attempt}</span>
                </div>
                ${usernamesRow}
                ${actionHtml}
            </div>
        </div>
    `;
    modal.show();
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
window.showIncidentDetail = showIncidentDetail;
</script>

<div class="row g-3 mt-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="map-marker-alt" class="icon-lucide"></i> Top Attacking IPs</h5>
            <div class="table-container">
                <table class="table table-hover">
                    <thead><tr><th>Rank</th><th>IP Address</th><th>Total Attempts</th><th>Risk Level</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($top_attackers as $rank => $att): ?>
                            <tr>
                                <td><?= $rank + 1 ?></td>
                                <td><code><?= htmlspecialchars($att['ip']) ?></code></td>
                                <td><?= number_format($att['count']) ?></td>
                                <td><span class="badge bg-<?= $att['count'] > 10 ? 'danger' : 'warning' ?>">
                                    <?= $att['count'] > 10 ? 'HIGH' : 'MEDIUM' ?>
                                </span></td>
                                <td>
                                    <?php if ($has_firewall): ?>
                                        <button class="btn btn-danger btn-sm" onclick="quarantineIP('<?= htmlspecialchars($att['ip']) ?>', '<?= htmlspecialchars($firewall_ip) ?>', 'Multiple failed login attempts')">
                                            <i data-lucide="ban" class="icon-lucide"></i>
                                        </button>
                                    <?php else: ?>
                                        <a href="../responder.php?ip=<?= urlencode($att['ip']) ?>" class="btn btn-outline-warning btn-sm" target="_blank">
                                            <i data-lucide="settings" class="icon-lucide"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($top_attackers)): ?>
                            <tr><td colspan="5" class="text-center">No attackers detected</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="line-chart" class="icon-lucide"></i> Attack Timeline</h5>
            <div class="chart-container">
                <canvas id="bruteForceTimeline"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
const timelineData = {
    labels: <?= json_encode($timeline_labels) ?>,
    values: <?= json_encode($timeline_values) ?>
};

createChart('bruteForceTimeline', {
    type: 'line',
    data: {
        labels: timelineData.labels,
        datasets: [{
            label: 'Failed Attempts',
            data: timelineData.values,
            borderColor: '#ff4d5e',
            backgroundColor: 'rgba(255,77,94,0.10)',
            pointBackgroundColor: '#ff4d5e',
            pointRadius: timelineData.values.length <= 3 ? 4 : 0,
            pointHoverRadius: 5,
            borderWidth: 2,
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
            x: { grid: { display: false } }
        }
    }
});

async function quarantineIP(ip, firewallIP, reason) {
    if (!confirm(`Are you sure you want to quarantine ${ip} on firewall ${firewallIP}?\n\nReason: ${reason}`)) return;
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" class="icon-lucide fa-spin"></i> Blocking...';
    try {
        const formData = new FormData();
        formData.append('block_ip', '1');
        formData.append('target_ip', ip);
        formData.append('firewall_ip', firewallIP);
        formData.append('reason', reason);
        const response = await fetch('../responder.php', { method: 'POST', body: formData });
        const text = await response.text();
        if (text.includes('blocked successfully')) {
            btn.innerHTML = '<i data-lucide="check" class="icon-lucide"></i> Blocked';
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-success');
            alert(`IP ${ip} has been successfully quarantined!`);
        } else {
            throw new Error('Failed to block IP');
        }
    } catch (error) {
        console.error('Error:', error);
        alert(`Failed to quarantine IP: ${error.message}`);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}
</script>
