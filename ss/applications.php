<?php
require_once __DIR__ . '/../db_config.php';
// ss/applications.php - Application usage breakdown
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%') $device_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 10000);

$apps = [];
$risk_categories = [];
$total_flows = 0;
$total_bw = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $app = $p['app'] ?? $p['appcat'] ?? 'Unknown';
        $cat = $p['appcat'] ?? $p['app'] ?? 'Uncategorized';
        $risk = $p['apprisk'] ?? 'unknown';
        $sent = intval($p['sentbyte'] ?? 0);
        $rcvd = intval($p['rcvdbyte'] ?? 0);
        $bw = $sent + $rcvd;

        $total_flows++;
        $total_bw += $bw;

        if (!isset($apps[$app])) {
            $apps[$app] = ['name' => $app, 'category' => $cat, 'risk' => $risk, 'flows' => 0, 'bandwidth' => 0, 'sources' => []];
        }
        $apps[$app]['flows']++;
        $apps[$app]['bandwidth'] += $bw;
        $apps[$app]['sources'][$row['source_ip']] = true;

        $risk_categories[$risk] = ($risk_categories[$risk] ?? 0) + 1;
    }
}

uasort($apps, fn($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);
mysqli_close($con);

$high_risk_flows = ($risk_categories['4'] ?? 0) + ($risk_categories['5'] ?? 0) + ($risk_categories['high'] ?? 0) + ($risk_categories['critical'] ?? 0);
?>

<link rel="stylesheet" href="/css/pages/ss_applications.css">

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="layers" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= count($apps) ?>">0</div>
            <span class="stat-chip chip-info">Distinct Applications</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i data-lucide="arrow-right-left" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $total_flows ?>">0</div>
            <span class="stat-chip chip-info">Total Flows</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i data-lucide="network" class="icon-lucide"></i></div>
            <div class="stat-value"><?= formatBytes($total_bw) ?></div>
            <span class="stat-chip chip-success">Total Bandwidth</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="radiation" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $high_risk_flows ?>">0</div>
            <span class="stat-chip chip-danger">High-Risk App Flows</span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="report-card">
            <h5><i data-lucide="chart-bar" class="icon-lucide"></i> Top Applications by Bandwidth</h5>
            <div class="chart-container">
                <canvas id="appBandwidthChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="report-card">
            <h5><i data-lucide="shield" class="icon-lucide"></i> Application Risk Distribution</h5>
            <div class="chart-container">
                <canvas id="appRiskChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i data-lucide="list" class="icon-lucide"></i> Application Inventory</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>Application</th><th>Category</th><th>Flows</th><th>Bandwidth</th><th>Unique Sources</th><th>Risk</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($apps, 0, 20) as $app): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($app['name']) ?></strong></td>
                        <td><small><?= htmlspecialchars($app['category']) ?></small></td>
                        <td><?= number_format($app['flows']) ?></td>
                        <td><?= formatBytes($app['bandwidth']) ?></td>
                        <td><?= count($app['sources']) ?></td>
                        <td><span class="risk-tag" style="background:rgba(255,176,32,0.12); color:var(--amber);"><?= htmlspecialchars($app['risk']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($apps)): ?>
                    <tr><td colspan="6" class="text-center">No application data in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const appLabels = <?= json_encode(array_slice(array_column($apps, 'name'), 0, 10)) ?>;
const appBandwidth = <?= json_encode(array_slice(array_column($apps, 'bandwidth'), 0, 10)) ?>;

createChart('appBandwidthChart', {
    type: 'bar',
    data: { labels: appLabels, datasets: [{ label: 'Bandwidth', data: appBandwidth, backgroundColor: '#29d3ee', borderRadius: 2, maxBarThickness: 30 }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: 'rgba(255,255,255,0.05)' } }, y: { grid: { display: false } } } }
});

const riskData = <?= json_encode($risk_categories) ?>;
createChart('appRiskChart', {
    type: 'doughnut',
    data: {
        labels: Object.keys(riskData),
        datasets: [{ data: Object.values(riskData), backgroundColor: ['#2be8a4','#ffb020','#ff8a3d','#ff4d5e','#9d8cff','#56626f'], borderColor: '#10151d', borderWidth: 2 }]
    },
    options: { responsive: true, maintainAspectRatio: false, cutout: '65%' }
});
</script>
