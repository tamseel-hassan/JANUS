<?php
require_once __DIR__ . '/../db_config.php';
// ss/anomaly.php - Statistical anomaly detection (z-score on per-source event volume)
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "' $device_where";

$agg_query = "
    SELECT source_ip, COUNT(*) AS cnt FROM (
        SELECT source_ip FROM syslog_entries WHERE $where
        UNION ALL
        SELECT source_ip FROM syslog_entries_archive WHERE $where
    ) AS combined
    GROUP BY source_ip
";
$result = mysqli_query($con, $agg_query);

$counts = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[$row['source_ip']] = (int)$row['cnt'];
    }
}

$n = count($counts);
$mean = $n ? array_sum($counts) / $n : 0;
$variance = 0;
foreach ($counts as $c) { $variance += pow($c - $mean, 2); }
$stddev = $n ? sqrt($variance / $n) : 0;

$anomalies = [];
foreach ($counts as $ip => $c) {
    $z = $stddev > 0 ? round(($c - $mean) / $stddev, 2) : 0;
    if ($z >= 2) {
        $anomalies[] = ['ip' => $ip, 'count' => $c, 'z' => $z];
    }
}
usort($anomalies, fn($a, $b) => $b['z'] <=> $a['z']);

// Hourly baseline vs current for the sparkline
$timeline_query = "
    SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt FROM (
        SELECT received_at FROM syslog_entries WHERE $where
        UNION ALL
        SELECT received_at FROM syslog_entries_archive WHERE $where
    ) AS combined GROUP BY hour ORDER BY hour ASC
";
$tl_result = mysqli_query($con, $timeline_query);
$timeline = [];
if ($tl_result) { while ($row = mysqli_fetch_assoc($tl_result)) { $timeline[$row['hour']] = (int)$row['cnt']; } }

mysqli_close($con);
?>

<div class="alert alert-info"><i class="fas fa-info-circle"></i> Anomaly = a source whose event volume this window is <strong>2 or more standard deviations</strong> above the mean across all reporting sources — a lightweight z-score outlier check, not a trained baseline model.</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value" data-raw="<?= count($anomalies) ?>">0</div>
            <span class="stat-chip chip-danger">Anomalous Sources</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-chart-bar"></i></div>
            <div class="stat-value"><?= round($mean) ?></div>
            <span class="stat-chip chip-info">Mean Events / Source</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-ruler-vertical"></i></div>
            <div class="stat-value"><?= round($stddev) ?></div>
            <span class="stat-chip chip-warning">Std. Deviation</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-server"></i></div>
            <div class="stat-value" data-raw="<?= $n ?>">0</div>
            <span class="stat-chip chip-info">Sources Analyzed</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-chart-line"></i> Total Event Volume</h5>
    <div class="chart-container">
        <canvas id="anomalyTimelineChart"></canvas>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-crosshairs"></i> Detected Anomalies</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>Source</th><th>Event Count</th><th>Z-Score</th><th>Deviation</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($anomalies as $a):
                    $score = min(99, round($a['z'] * 25));
                ?>
                    <tr>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($a['ip']) ?>')" class="clickable-ip"><?= htmlspecialchars($a['ip']) ?></a></td>
                        <td><?= number_format($a['count']) ?></td>
                        <td><span class="score-badge <?= scoreClass($score) ?>"><?= $a['z'] ?></span></td>
                        <td><span class="badge bg-<?= $a['z'] >= 3 ? 'danger' : 'warning' ?>"><?= $a['z'] >= 3 ? 'SEVERE' : 'ELEVATED' ?></span></td>
                        <td><button class="btn btn-outline-primary btn-sm" onclick="drillDownIP('<?= htmlspecialchars($a['ip']) ?>')"><i class="fas fa-search-plus"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($anomalies)): ?>
                    <tr><td colspan="5" class="text-center">No statistical outliers detected in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
createChart('anomalyTimelineChart', {
    type: 'line',
    data: {
        labels: <?= json_encode(array_keys($timeline)) ?>,
        datasets: [{ label: 'Events', data: <?= json_encode(array_values($timeline)) ?>, borderColor: '#9d8cff', backgroundColor: 'rgba(157,140,255,0.10)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }, x: { grid: { display: false } } } }
});
</script>
