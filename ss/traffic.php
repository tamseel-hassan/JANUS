<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/traffic.php - Traffic Analysis (Tactical HUD visual pass, backend unchanged)

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
}
session_write_close();

// Helper: format bytes
function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . ['B','KB','MB','GB','TB'][$i];
}

// Helper parse
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

$range = $_GET['range'] ?? '24h';
$is_large_range = ($range === '7d' || $range === '30d');

$check_archive = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
$has_archive = mysqli_num_rows($check_archive) > 0;

require_once __DIR__ . '/../includes/cache.php';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("traffic", $start, $end, $device, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device, $device_where, $is_large_range, $has_archive) {
    if ($is_large_range) {
        $rollup_device_where = $device ? "AND (source_ip = '" . mysqli_real_escape_string($con, $device) . "' OR destination_ip = '" . mysqli_real_escape_string($con, $device) . "')" : '';
        $query = "
            SELECT log_date AS received_at, source_ip AS src_ip, destination_ip AS dst_ip, 
                   app, service, action, flow_count, total_sent AS sent_bytes, total_rcvd AS rcvd_bytes
            FROM syslog_traffic_daily
            WHERE log_date BETWEEN DATE('$start') AND DATE('$end')
              $rollup_device_where
        ";
    } else {
        $archive_query = $has_archive ? "
            UNION ALL
            SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at
            FROM syslog_entries_archive
            WHERE received_at BETWEEN '$start' AND '$end'
              AND src_ip IS NOT NULL
              $device_where
        " : "";

        $query = "
            SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at
            FROM (
                SELECT src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, received_at
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
    $num_rows = $result ? mysqli_num_rows($result) : 0;
    @file_put_contents('/tmp/janus_traffic_debug.log', "Start: $start, End: $end, Query: $query, Rows: $num_rows\n", FILE_APPEND);
    if (!$result) return null;

    $total_flows = 0;
    $total_sent = 0;
    $total_received = 0;
    $sources = [];
    $destinations = [];
    $bandwidth_timeline = [];
    $traffic_dist = ['accepted' => 0, 'denied' => 0, 'timeout' => 0, 'other' => 0];
    $applications = [];
    $services = [];
    $protocols = [];
    $countries = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if (!isset($row['src_ip'], $row['dst_ip'])) continue;

        $flows = isset($row['flow_count']) ? intval($row['flow_count']) : 1;
        $total_flows += $flows;
        
        $sent = intval($row['sent_bytes'] ?? 0);
        $rcvd = intval($row['rcvd_bytes'] ?? 0);
        $total_sent += $sent;
        $total_received += $rcvd;

        $src = $row['src_ip'];
        $dst = $row['dst_ip'];
        $app = $row['app'] ?: 'Unknown';
        $service = $row['service'] ?: 'Unknown';
        $dstport = $row['dst_port'] ?? 'N/A';
        $proto = $row['service'] ?: 'Unknown';
        $action = strtolower($row['action'] ?? 'other');
        $country = 'Unknown';

        // Source tracking
        if (!isset($sources[$src])) {
            $sources[$src] = [
                'ip' => $src, 'count' => 0, 'bandwidth' => 0,
                'apps' => [], 'services' => [], 'ports' => [], 'countries' => [], 'protocols' => []
            ];
        }
        $sources[$src]['count'] += $flows;
        $sources[$src]['bandwidth'] += ($sent + $rcvd);
        $sources[$src]['apps'][$app] = ($sources[$src]['apps'][$app] ?? 0) + $flows;
        $sources[$src]['services'][$service] = ($sources[$src]['services'][$service] ?? 0) + $flows;
        $sources[$src]['ports'][$dstport] = ($sources[$src]['ports'][$dstport] ?? 0) + $flows;
        $sources[$src]['protocols'][$proto] = ($sources[$src]['protocols'][$proto] ?? 0) + $flows;

        // Destination tracking
        if (!isset($destinations[$dst])) {
            $destinations[$dst] = [
                'ip' => $dst, 'count' => 0, 'bandwidth' => 0,
                'apps' => [], 'services' => [], 'ports' => [], 'countries' => [], 'protocols' => []
            ];
        }
        $destinations[$dst]['count'] += $flows;
        $destinations[$dst]['bandwidth'] += ($sent + $rcvd);
        $destinations[$dst]['apps'][$app] = ($destinations[$dst]['apps'][$app] ?? 0) + $flows;
        $destinations[$dst]['services'][$service] = ($destinations[$dst]['services'][$service] ?? 0) + $flows;
        $destinations[$dst]['ports'][$dstport] = ($destinations[$dst]['ports'][$dstport] ?? 0) + $flows;
        $destinations[$dst]['protocols'][$proto] = ($destinations[$dst]['protocols'][$proto] ?? 0) + $flows;

        // Timeline
        if ($is_large_range) {
            $hour = date('Y-m-d', strtotime($row['received_at']));
        } else {
            $hour = date('Y-m-d H:00', strtotime($row['received_at']));
        }
        $bandwidth_timeline[$hour] = ($bandwidth_timeline[$hour] ?? 0) + ($sent + $rcvd);

        // Traffic distribution
        if ($action === 'accept' || $action === 'accepted') $traffic_dist['accepted'] += $flows;
        elseif ($action === 'deny' || $action === 'denied') $traffic_dist['denied'] += $flows;
        elseif ($action === 'timeout') $traffic_dist['timeout'] += $flows;
        else $traffic_dist['other'] += $flows;

        // Application stats
        if (!isset($applications[$app])) { $applications[$app] = ['name' => $app, 'count' => 0, 'bandwidth' => 0]; }
        $applications[$app]['count'] += $flows;
        $applications[$app]['bandwidth'] += ($sent + $rcvd);

        // Service stats
        if (!isset($services[$service])) { $services[$service] = ['name' => $service, 'count' => 0, 'bandwidth' => 0]; }
        $services[$service]['count'] += $flows;
        $services[$service]['bandwidth'] += ($sent + $rcvd);

        // Protocol stats
        $protocols[$proto] = ($protocols[$proto] ?? 0) + $flows;
    }

    // Sort data
    uasort($sources, fn($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);
    uasort($destinations, fn($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);
    uasort($applications, fn($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);
    uasort($services, fn($a, $b) => $b['bandwidth'] <=> $a['bandwidth']);
    arsort($protocols);
    arsort($countries);

    return compact(
        'total_flows', 'total_sent', 'total_received', 'sources', 'destinations',
        'bandwidth_timeline', 'traffic_dist', 'applications', 'services', 'protocols', 'countries'
    );
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please try again.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);
@file_put_contents('/tmp/janus_traffic_debug.log', "Cache Loaded: Key = $cache_key, Total Flows = " . ($total_flows ?? 'null') . ", Total Sent = " . ($total_sent ?? 'null') . "\n", FILE_APPEND);

$total_bw_all = $total_sent + $total_received;
$accept_pct = $total_flows > 0 ? round(($traffic_dist['accepted'] / $total_flows) * 100, 1) : 0;

mysqli_close($con);
?>

<link rel="stylesheet" href="/css/theme.css?v=<?= time() ?>">
<link rel="stylesheet" href="/css/pages/ss_traffic.css?v=<?= time() ?>">

<!-- Summary Statistics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-exchange-alt"></i></div>
            <div class="stat-value" data-raw="<?= (int)$total_flows ?>">0</div>
            <span class="stat-chip chip-info">Total Traffic Flows</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i class="fas fa-arrow-up"></i></div>
            <div class="stat-value"><?= formatBytes($total_sent) ?></div>
            <span class="stat-chip chip-success">Data Sent</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-arrow-down"></i></div>
            <div class="stat-value"><?= formatBytes($total_received) ?></div>
            <span class="stat-chip chip-info">Data Received</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-network-wired"></i></div>
            <div class="stat-value"><?= formatBytes($total_bw_all) ?></div>
            <span class="stat-chip chip-warning">Bandwidth &middot; <?= $accept_pct ?>% Accepted</span>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="report-card">
            <h5><i class="fas fa-chart-line"></i> Bandwidth Timeline</h5>
            <div class="chart-container">
                <canvas id="bandwidthChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="report-card">
            <h5><i class="fas fa-chart-pie"></i> Traffic Distribution</h5>
            <div class="chart-container">
                <canvas id="trafficDistChart"></canvas>
            </div>
            <div class="mix-legend">
                <span><span class="dot" style="background:#2be8a4"></span>Accepted <?= number_format($traffic_dist['accepted']) ?></span>
                <span><span class="dot" style="background:#ff4d5e"></span>Denied <?= number_format($traffic_dist['denied']) ?></span>
                <span><span class="dot" style="background:#ffb020"></span>Timeout <?= number_format($traffic_dist['timeout']) ?></span>
                <span><span class="dot" style="background:#56626f"></span>Other <?= number_format($traffic_dist['other']) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Top IPs Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i class="fas fa-arrow-up"></i> Top Source IPs</h5>
            <div class="table-container">
                <table class="table table-hover">
                    <thead>
                        <tr><th>Rank</th><th>Source IP</th><th>Flows</th><th>Bandwidth</th><th>Top Apps</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $max_src_bw = !empty($sources) ? max(array_column($sources, 'bandwidth')) : 1;
                        foreach (array_values(array_slice($sources, 0, 10)) as $rank => $s):
                            arsort($s['apps']);
                            $top_apps = array_slice(array_keys($s['apps']), 0, 2);
                            $pct = $max_src_bw > 0 ? round(($s['bandwidth'] / $max_src_bw) * 100) : 0;
                        ?>
                            <tr>
                                <td><?= $rank + 1 ?></td>
                                <td>
                                    <a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($s['ip']) ?>')" class="clickable-ip">
                                        <i class="fas fa-search-plus"></i><?= htmlspecialchars($s['ip']) ?>
                                    </a>
                                </td>
                                <td><?= number_format($s['count']) ?></td>
                                <td>
                                    <?= formatBytes($s['bandwidth']) ?>
                                    <span class="mini-bar-track"><span class="mini-bar-fill" style="width:<?= $pct ?>%"></span></span>
                                </td>
                                <td><small><?= implode(', ', array_map('htmlspecialchars', $top_apps)) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sources)): ?>
                            <tr><td colspan="5" class="text-center">No source data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="report-card">
            <h5><i class="fas fa-arrow-down"></i> Top Destination IPs</h5>
            <div class="table-container">
                <table class="table table-hover">
                    <thead>
                        <tr><th>Rank</th><th>Destination IP</th><th>Flows</th><th>Bandwidth</th><th>Top Services</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $max_dst_bw = !empty($destinations) ? max(array_column($destinations, 'bandwidth')) : 1;
                        foreach (array_values(array_slice($destinations, 0, 10)) as $rank => $d):
                            arsort($d['services']);
                            $top_services = array_slice(array_keys($d['services']), 0, 2);
                            $pct = $max_dst_bw > 0 ? round(($d['bandwidth'] / $max_dst_bw) * 100) : 0;
                        ?>
                            <tr>
                                <td><?= $rank + 1 ?></td>
                                <td>
                                    <a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($d['ip']) ?>')" class="clickable-ip">
                                        <i class="fas fa-search-plus"></i><?= htmlspecialchars($d['ip']) ?>
                                    </a>
                                </td>
                                <td><?= number_format($d['count']) ?></td>
                                <td>
                                    <?= formatBytes($d['bandwidth']) ?>
                                    <span class="mini-bar-track"><span class="mini-bar-fill" style="width:<?= $pct ?>%; background:var(--amber);"></span></span>
                                </td>
                                <td><small><?= implode(', ', array_map('htmlspecialchars', $top_services)) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($destinations)): ?>
                            <tr><td colspan="5" class="text-center">No destination data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Applications and Protocols -->
<div class="row g-3 mt-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i class="fas fa-layer-group"></i> Top Applications</h5>
            <div class="table-container">
                <table class="table table-hover">
                    <thead><tr><th>Application</th><th>Flows</th><th>Bandwidth</th><th>% of Total</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($applications, 0, 10) as $app): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($app['name']) ?></strong></td>
                                <td><?= number_format($app['count']) ?></td>
                                <td><?= formatBytes($app['bandwidth']) ?></td>
                                <td><?= $total_bw_all > 0 ? round(($app['bandwidth'] / $total_bw_all) * 100, 2) : 0 ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($applications)): ?>
                            <tr><td colspan="4" class="text-center">No application data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="report-card">
            <h5><i class="fas fa-network-wired"></i> Protocol Distribution</h5>
            <div class="chart-container">
                <canvas id="protocolChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Geographic Distribution -->
<?php if (!empty($countries)): ?>
<div class="row g-3 mt-4">
    <div class="col-lg-12">
        <div class="report-card">
            <h5><i class="fas fa-globe"></i> Geographic Distribution</h5>
            <div class="table-container">
                <table class="table table-hover">
                    <thead><tr><th>Country</th><th>Connections</th><th>Share</th></tr></thead>
                    <tbody>
                        <?php
                        $total_country_conns = array_sum($countries);
                        foreach (array_slice($countries, 0, 10, true) as $country => $count):
                            $cpct = round(($count / $total_country_conns) * 100, 1);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($country) ?></td>
                                <td><?= number_format($count) ?></td>
                                <td>
                                    <?= $cpct ?>%
                                    <span class="mini-bar-track"><span class="mini-bar-fill" style="width:<?= $cpct ?>%"></span></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(() => {
const bandwidthData = {
    labels: <?= json_encode(array_keys($bandwidth_timeline)) ?>,
    values: <?= json_encode(array_values($bandwidth_timeline)) ?>
};

createChart('bandwidthChart', {
    type: 'line',
    data: {
        labels: bandwidthData.labels,
        datasets: [{
            label: 'Bandwidth (Bytes)',
            data: bandwidthData.values,
            borderColor: '#29d3ee',
            backgroundColor: 'rgba(41,211,238,0.10)',
            pointBackgroundColor: '#29d3ee',
            pointRadius: bandwidthData.values.length <= 3 ? 4 : 0,
            pointHoverRadius: 5,
            borderWidth: 2,
            fill: true,
            tension: 0.35
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

const trafficDistData = <?= json_encode($traffic_dist) ?>;

createChart('trafficDistChart', {
    type: 'doughnut',
    data: {
        labels: ['Accepted', 'Denied', 'Timeout', 'Other'],
        datasets: [{
            data: [
                trafficDistData.accepted || 0,
                trafficDistData.denied   || 0,
                trafficDistData.timeout  || 0,
                trafficDistData.other    || 0
            ],
            backgroundColor: ['#2be8a4', '#ff4d5e', '#ffb020', '#56626f'],
            borderColor: '#10151d',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: { legend: { display: false } }
    }
});

const protocolData = <?= json_encode($protocols) ?>;

createChart('protocolChart', {
    type: 'bar',
    data: {
        labels: Object.keys(protocolData),
        datasets: [{
            label: 'Flows',
            data: Object.values(protocolData),
            backgroundColor: '#ffb020',
            borderRadius: 2,
            maxBarThickness: 34
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
})();
</script>
