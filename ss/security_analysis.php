<?php
require_once __DIR__ . '/../db_config.php';
// ss/security_analysis.php - Heuristic MITRE ATT&CK tactic mapping
// NOTE: Fortinet/syslog messages don't natively carry MITRE technique IDs.
// This maps common log keywords to a best-guess tactic/technique so the
// page is useful today; tune the $tactic_rules table to your real log samples.
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
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
                    break 2;
                }
            }
        }
    }
}

uasort($techniques, fn($a, $b) => $b['count'] <=> $a['count']);
arsort($tactic_counts);
mysqli_close($con);
?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="shield-virus" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $total_events ?>">0</div>
            <span class="stat-chip chip-danger">Classified Events</span>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="crosshair" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= count($techniques) ?>">0</div>
            <span class="stat-chip chip-warning">Techniques Observed</span>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="sitemap" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= count($tactic_counts) ?>">0</div>
            <span class="stat-chip chip-info">Tactics Observed</span>
        </div>
    </div>
</div>

<div class="alert alert-info"><i data-lucide="info" class="icon-lucide"></i> Technique mapping is keyword-heuristic (built from message content, not native MITRE tagging). Tune the rules in <code>ss/security_analysis.php</code> to match your device's real log fields for higher fidelity.</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="chart-pie" class="icon-lucide"></i> Events by Tactic</h5>
            <div class="chart-container">
                <canvas id="tacticChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="list" class="icon-lucide -ol"></i> Top Techniques</h5>
            <div class="chart-container">
                <canvas id="techniqueChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i data-lucide="shield" class="icon-lucide"></i> Technique Detail</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>ID</th><th>Technique</th><th>Tactic</th><th>Events</th><th>Sources</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($techniques as $t): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($t['id']) ?></code></td>
                        <td><?= htmlspecialchars($t['technique']) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($t['tactic']) ?></span></td>
                        <td><?= number_format($t['count']) ?></td>
                        <td><?= count($t['sources']) ?></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="drillDownTechnique('<?= htmlspecialchars($t['id']) ?>', '<?= htmlspecialchars($t['technique']) ?>')"><i data-lucide="zoom-in" class="icon-lucide"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($techniques)): ?>
                    <tr><td colspan="6" class="text-center">No mapped technique activity in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const tacticData = <?= json_encode($tactic_counts) ?>;
createChart('tacticChart', {
    type: 'doughnut',
    data: { labels: Object.keys(tacticData), datasets: [{ data: Object.values(tacticData), backgroundColor: ['#ff4d5e','#ffb020','#29d3ee','#2be8a4','#9d8cff','#ff8a3d','#56626f'], borderColor: '#10151d', borderWidth: 2 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '60%' }
});

const techLabels = <?= json_encode(array_slice(array_column($techniques, 'technique'), 0, 8)) ?>;
const techCounts = <?= json_encode(array_slice(array_column($techniques, 'count'), 0, 8)) ?>;
createChart('techniqueChart', {
    type: 'bar',
    data: { labels: techLabels, datasets: [{ label: 'Events', data: techCounts, backgroundColor: '#ffb020', borderRadius: 2, maxBarThickness: 28 }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: 'rgba(255,255,255,0.05)' } }, y: { grid: { display: false } } } }
});
</script>
