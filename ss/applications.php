<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/applications.php - Application usage breakdown
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
session_write_close();
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;

$range = $_GET['range'] ?? '24h';
$is_large_range = ($range === '7d' || $range === '30d');

require_once __DIR__ . '/../includes/cache.php';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("applications", $start, $end, $device, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device, $device_where, $is_large_range, $has_archive) {
    if ($is_large_range) {
        $rollup_device_where = $device ? "AND (source_ip = '" . mysqli_real_escape_string($con, $device) . "' OR destination_ip = '" . mysqli_real_escape_string($con, $device) . "')" : '';
        $query = "
            SELECT log_date AS received_at, source_ip, app, action, flow_count, total_sent AS sent_bytes, total_rcvd AS rcvd_bytes
            FROM syslog_traffic_daily
            WHERE log_date BETWEEN DATE('$start') AND DATE('$end')
              $rollup_device_where
        ";
    } else {
        $archive_query = $has_archive ? "
            UNION ALL
            SELECT src_ip, dst_ip, app, action, sent_bytes, rcvd_bytes, received_at, source_ip
            FROM syslog_entries_archive
            WHERE received_at BETWEEN '$start' AND '$end'
              AND src_ip IS NOT NULL
              $device_where
        " : "";

        $query = "
            SELECT src_ip, dst_ip, app, action, sent_bytes, rcvd_bytes, received_at, source_ip
            FROM (
                SELECT src_ip, dst_ip, app, action, sent_bytes, rcvd_bytes, received_at, source_ip
                FROM syslog_entries
                WHERE received_at BETWEEN '$start' AND '$end'
                  AND src_ip IS NOT NULL
                  $device_where
                $archive_query
            ) AS combined
            ORDER BY received_at DESC
        ";
    }

    $result = mysqli_query($con, $query);
    if (!$result) return null;

    $apps = [];
    $risk_categories = [];
    $total_flows = 0;
    $total_bw = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $app = $row['app'] ?: 'Unknown';
        $cat = $row['app'] ?: 'Uncategorized';
        $risk = 'medium'; // Default or simplified risk
        $flows = isset($row['flow_count']) ? intval($row['flow_count']) : 1;
        
        $sent = intval($row['sent_bytes'] ?? 0);
        $rcvd = intval($row['rcvd_bytes'] ?? 0);
        $bw = $sent + $rcvd;

        $total_flows += $flows;
        $total_bw += $bw;

        if (!isset($apps[$app])) {
            $apps[$app] = ['name' => $app, 'category' => $cat, 'risk' => $risk, 'flows' => 0, 'bandwidth' => 0, 'sources' => []];
        }
        $apps[$app]['flows'] += $flows;
        $apps[$app]['bandwidth'] += $bw;
        
        $source_dev = $row['source_ip'] ?: ($row['src_ip'] ?? 'unknown');
        $apps[$app]['sources'][$source_dev] = true;

        $risk_categories[$risk] = ($risk_categories[$risk] ?? 0) + $flows;
    }

    uasort($apps, fn($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);

    return compact('apps', 'risk_categories', 'total_flows', 'total_bw');
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please refresh.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);

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
