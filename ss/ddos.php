<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/ddos.php - Volumetric flood / DDoS detection
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

require_once __DIR__ . '/../includes/cache.php';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("ddos", $start, $end, $device, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device_where, $has_archive) {
    $archive_query = $has_archive ? "
        UNION ALL
        SELECT source_ip, received_at FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
    " : "";

    $timeline_archive_query = $has_archive ? "
        UNION ALL
        SELECT received_at FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
    " : "";

    $flood_query = "
        SELECT source_ip, DATE_FORMAT(received_at, '%Y-%m-%d %H:%i:00') AS minute_bucket, COUNT(*) AS cnt
        FROM (
            SELECT source_ip, received_at FROM syslog_entries 
            WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
            $archive_query
        ) AS combined
        GROUP BY source_ip, minute_bucket
        HAVING cnt >= 100
        ORDER BY cnt DESC
        LIMIT 200
    ";
    
    $result = mysqli_query($con, $flood_query);
    $floods = [];
    $total_flood_events = 0;
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $floods[] = $row;
            $total_flood_events += (int)$row['cnt'];
        }
    }

    $affected_sources = count(array_unique(array_column($floods, 'source_ip')));

    $timeline_query = "
        SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt
        FROM (
            SELECT received_at FROM syslog_entries 
            WHERE received_at BETWEEN '$start' AND '$end' AND src_ip IS NOT NULL $device_where
            $timeline_archive_query
        ) AS combined GROUP BY hour ORDER BY hour ASC
    ";
    
    $tl_result = mysqli_query($con, $timeline_query);
    $timeline = [];
    if ($tl_result) {
        while ($row = mysqli_fetch_assoc($tl_result)) {
            $timeline[$row['hour']] = (int)$row['cnt'];
        }
    }

    return compact('floods', 'total_flood_events', 'affected_sources', 'timeline');
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please refresh.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);

mysqli_close($con);
?>

<div class="alert alert-info"><i class="fas fa-info-circle"></i> Flood threshold: <strong>100+ events from one source within a single minute</strong>. Adjust the threshold in <code>ss/ddos.php</code> to match your baseline traffic levels.</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i class="fas fa-radiation"></i></div>
            <div class="stat-value" data-raw="<?= count($floods) ?>">0</div>
            <span class="stat-chip chip-danger">Flood Windows Detected</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-server"></i></div>
            <div class="stat-value" data-raw="<?= $affected_sources ?>">0</div>
            <span class="stat-chip chip-warning">Sources Involved</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-hashtag"></i></div>
            <div class="stat-value" data-raw="<?= $total_flood_events ?>">0</div>
            <span class="stat-chip chip-info">Events in Flood Windows</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-<?= empty($floods) ? 'success' : 'danger' ?>"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-value"><?= empty($floods) ? 'CLEAR' : 'ACTIVE' ?></div>
            <span class="stat-chip <?= empty($floods) ? 'chip-success' : 'chip-danger' ?>">DDoS Status</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-chart-line"></i> Total Traffic Volume</h5>
    <div class="chart-container">
        <canvas id="ddosTimelineChart"></canvas>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-radiation"></i> Detected Flood Windows</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>Source</th><th>Minute</th><th>Events/min</th><th>Severity</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($floods as $f):
                    $sev = $f['cnt'] >= 500 ? 'critical' : 'high';
                ?>
                    <tr>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($f['source_ip']) ?>')" class="clickable-ip"><?= htmlspecialchars($f['source_ip']) ?></a></td>
                        <td><small><?= htmlspecialchars($f['minute_bucket']) ?></small></td>
                        <td><span class="badge bg-danger"><?= number_format($f['cnt']) ?></span></td>
                        <td><span class="sev-pill sev-<?= $sev ?>"><?= strtoupper($sev) ?></span></td>
                        <td><a href="../responder.php?ip=<?= urlencode($f['source_ip']) ?>" class="btn btn-outline-warning btn-sm" target="_blank"><i class="fas fa-cog"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($floods)): ?>
                    <tr><td colspan="5" class="text-center">No volumetric flood windows detected</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
createChart('ddosTimelineChart', {
    type: 'line',
    data: {
        labels: <?= json_encode(array_keys($timeline)) ?>,
        datasets: [{ label: 'Events', data: <?= json_encode(array_values($timeline)) ?>, borderColor: '#ff4d5e', backgroundColor: 'rgba(255,77,94,0.10)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }, x: { grid: { display: false } } } }
});
</script>
