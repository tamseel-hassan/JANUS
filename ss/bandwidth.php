<?php
require_once __DIR__ . '/../db_config.php';
// ss/bandwidth.php - Bandwidth utilization by device and time
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

$per_device = [];
$sent_timeline = [];
$rcvd_timeline = [];
$total_sent = 0;
$total_rcvd = 0;
$peak_hour = null;
$peak_value = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $sent = intval($p['sentbyte'] ?? 0);
        $rcvd = intval($p['rcvdbyte'] ?? 0);
        $total_sent += $sent;
        $total_rcvd += $rcvd;

        $dev = $row['source_ip'];
        if (!isset($per_device[$dev])) $per_device[$dev] = ['ip' => $dev, 'sent' => 0, 'rcvd' => 0, 'flows' => 0];
        $per_device[$dev]['sent'] += $sent;
        $per_device[$dev]['rcvd'] += $rcvd;
        $per_device[$dev]['flows']++;

        $hour = date('Y-m-d H:00', strtotime($row['received_at']));
        $sent_timeline[$hour] = ($sent_timeline[$hour] ?? 0) + $sent;
        $rcvd_timeline[$hour] = ($rcvd_timeline[$hour] ?? 0) + $rcvd;
        $hour_total = $sent_timeline[$hour] + $rcvd_timeline[$hour];
        if ($hour_total > $peak_value) { $peak_value = $hour_total; $peak_hour = $hour; }
    }
}

uasort($per_device, fn($a, $b) => ($b['sent'] + $b['rcvd']) <=> ($a['sent'] + $a['rcvd']));
ksort($sent_timeline);
ksort($rcvd_timeline);
mysqli_close($con);
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i class="fas fa-arrow-up"></i></div>
            <div class="stat-value"><?= formatBytes($total_sent) ?></div>
            <span class="stat-chip chip-success">Total Sent</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-arrow-down"></i></div>
            <div class="stat-value"><?= formatBytes($total_rcvd) ?></div>
            <span class="stat-chip chip-info">Total Received</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-bolt"></i></div>
            <div class="stat-value"><?= $peak_hour ? formatBytes($peak_value) : '—' ?></div>
            <span class="stat-chip chip-warning">Peak Hour Volume</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-server"></i></div>
            <div class="stat-value" data-raw="<?= count($per_device) ?>">0</div>
            <span class="stat-chip chip-info">Active Devices</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-chart-area"></i> Sent vs Received Over Time<?= $peak_hour ? ' &middot; peak at ' . date('M d, H:i', strtotime($peak_hour)) : '' ?></h5>
    <div class="chart-container" style="height:320px;">
        <canvas id="bwStackedChart"></canvas>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-server"></i> Bandwidth by Device</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>Rank</th><th>Device / IP</th><th>Flows</th><th>Sent</th><th>Received</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach (array_values(array_slice($per_device, 0, 15)) as $rank => $d):
                    $tot = $d['sent'] + $d['rcvd'];
                    $max_tot = !empty($per_device) ? (reset($per_device)['sent'] + reset($per_device)['rcvd']) : 1;
                    $pct = $max_tot > 0 ? round(($tot / $max_tot) * 100) : 0;
                ?>
                    <tr>
                        <td><?= $rank + 1 ?></td>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($d['ip']) ?>')" class="clickable-ip"><i class="fas fa-search-plus"></i><?= htmlspecialchars($d['ip']) ?></a></td>
                        <td><?= number_format($d['flows']) ?></td>
                        <td><?= formatBytes($d['sent']) ?></td>
                        <td><?= formatBytes($d['rcvd']) ?></td>
                        <td>
                            <?= formatBytes($tot) ?>
                            <span class="mini-bar-track" style="background:var(--inset); border:1px solid var(--line); border-radius:2px; height:5px; width:80px; display:inline-block; vertical-align:middle; margin-left:8px; overflow:hidden;">
                                <span style="display:block; height:100%; width:<?= $pct ?>%; background:var(--cyan);"></span>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($per_device)): ?>
                    <tr><td colspan="6" class="text-center">No bandwidth data in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
createChart('bwStackedChart', {
    type: 'line',
    data: {
        labels: <?= json_encode(array_keys($sent_timeline)) ?>,
        datasets: [
            { label: 'Sent', data: <?= json_encode(array_values($sent_timeline)) ?>, borderColor: '#2be8a4', backgroundColor: 'rgba(43,232,164,0.12)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
            { label: 'Received', data: <?= json_encode(array_values($rcvd_timeline)) ?>, borderColor: '#29d3ee', backgroundColor: 'rgba(41,211,238,0.12)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true, labels: { boxWidth: 10 } } },
        scales: { y: { beginAtZero: true, stacked: true, grid: { color: 'rgba(255,255,255,0.05)' } }, x: { stacked: true, grid: { display: false } } }
    }
});
</script>
