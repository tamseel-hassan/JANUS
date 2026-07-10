<?php
require_once __DIR__ . '/../db_config.php';
// ss/ip_drilldown.php - FIXED: No chart melting issues
// Detailed IP Address Analysis

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
}

function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . ['B','KB','MB','GB','TB'][$i];
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
    echo '<div class="alert alert-danger">DB connection failed</div>';
    exit;
}

$ip = $_GET['drilldown_ip'] ?? '';
$start = $_GET['start'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end = $_GET['end'] ?? date('Y-m-d H:i:s');

if (!$ip) {
    echo '<div class="alert alert-warning">No IP specified</div>';
    exit;
}

$query = "
    SELECT message, received_at, source_ip
    FROM (
        SELECT message, received_at, source_ip
        FROM syslog_entries
        WHERE received_at BETWEEN '$start' AND '$end'
          AND (message LIKE '%srcip=\"$ip\"%' OR message LIKE '%dstip=\"$ip\"%'
               OR message LIKE '%srcip=$ip %' OR message LIKE '%dstip=$ip %')
        UNION ALL
        SELECT message, received_at, source_ip
        FROM syslog_entries_archive
        WHERE received_at BETWEEN '$start' AND '$end'
          AND (message LIKE '%srcip=\"$ip\"%' OR message LIKE '%dstip=\"$ip\"%'
               OR message LIKE '%srcip=$ip %' OR message LIKE '%dstip=$ip %')
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 5000
";

$result = mysqli_query($con, $query);

$total_flows = 0;
$sent_bytes = 0;
$received_bytes = 0;
$top_destinations = [];
$top_sources = [];
$applications = [];
$services = [];
$ports = [];
$protocols = [];
$actions = ['accept' => 0, 'deny' => 0, 'timeout' => 0];
$recent_events = [];
$countries = [];
$timeline = [];

while ($row = mysqli_fetch_assoc($result)) {
    $parsed = parseMessage($row['message']);
    $srcip = $parsed['srcip'] ?? '';
    $dstip = $parsed['dstip'] ?? '';
    
    if ($srcip !== $ip && $dstip !== $ip) continue;
    
    $total_flows++;
    $sent = intval($parsed['sentbyte'] ?? 0);
    $rcvd = intval($parsed['rcvdbyte'] ?? 0);
    
    if ($srcip === $ip) {
        $sent_bytes += $sent;
        $direction = 'outbound';
    } else {
        $received_bytes += $rcvd;
        $direction = 'inbound';
    }
    
    $top_destinations[$dstip] = ($top_destinations[$dstip] ?? 0) + 1;
    $top_sources[$srcip] = ($top_sources[$srcip] ?? 0) + 1;
    
    $app = $parsed['app'] ?? $parsed['appcat'] ?? 'Unknown';
    $service = $parsed['service'] ?? $parsed['proto'] ?? 'Unknown';
    $dstport = $parsed['dstport'] ?? 'N/A';
    $srcport = $parsed['srcport'] ?? 'N/A';
    $proto = $parsed['proto'] ?? 'Unknown';
    $action = strtolower($parsed['action'] ?? 'other');
    $country = $parsed['srccountry'] ?? $parsed['dstcountry'] ?? '';
    
    $applications[$app] = ($applications[$app] ?? 0) + 1;
    $services[$service] = ($services[$service] ?? 0) + 1;
    $ports[$dstport] = ($ports[$dstport] ?? 0) + 1;
    $protocols[$proto] = ($protocols[$proto] ?? 0) + 1;
    
    if (isset($actions[$action])) {
        $actions[$action]++;
    }
    
    if ($country) {
        $countries[$country] = ($countries[$country] ?? 0) + 1;
    }
    
    $hour = date('Y-m-d H:00', strtotime($row['received_at']));
    $timeline[$hour] = ($timeline[$hour] ?? 0) + 1;
    
    if (count($recent_events) < 50) {
        $recent_events[] = [
            'time' => $row['received_at'],
            'direction' => $direction,
            'src' => $srcip,
            'dst' => $dstip,
            'srcport' => $srcport,
            'dstport' => $dstport,
            'service' => $service,
            'app' => $app,
            'proto' => $proto,
            'action' => $action,
            'sent' => $sent,
            'rcvd' => $rcvd
        ];
    }
}

arsort($top_destinations);
arsort($top_sources);
arsort($applications);
arsort($services);
arsort($ports);
arsort($protocols);
arsort($countries);

mysqli_close($con);

// Generate unique chart ID
$chartId = 'modalChart' . md5($ip . time());
?>

<style>
.drill-stat-box {
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    margin-bottom: 15px;
}
.drill-stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: #3b82f6;
}
.drill-stat-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
    text-transform: uppercase;
}
.connection-row {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.9rem;
}
.connection-row:hover {
    background: var(--surface-hover);
}
</style>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="drill-stat-box">
            <div class="drill-stat-value"><?= number_format($total_flows) ?></div>
            <div class="drill-stat-label">Total Flows</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="drill-stat-box">
            <div class="drill-stat-value"><?= formatBytes($sent_bytes) ?></div>
            <div class="drill-stat-label">Data Sent</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="drill-stat-box">
            <div class="drill-stat-value"><?= formatBytes($received_bytes) ?></div>
            <div class="drill-stat-label">Data Received</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="drill-stat-box">
            <div class="drill-stat-value"><?= count($top_destinations) + count($top_sources) ?></div>
            <div class="drill-stat-label">Unique Connections</div>
        </div>
    </div>
</div>

<div class="alert alert-info mb-3">
    <strong><i class="fas fa-info-circle"></i> IP Address:</strong> <code><?= htmlspecialchars($ip) ?></code><br>
    <strong><i class="fas fa-clock"></i> Period:</strong> <?= date('M d, H:i', strtotime($start)) ?> → <?= date('M d, H:i', strtotime($end)) ?>
</div>

<?php if (!empty($timeline)): ?>
<div class="mb-4">
    <h6><i class="fas fa-chart-line"></i> Activity Timeline</h6>
    <div style="height: 200px;">
        <canvas id="<?= $chartId ?>"></canvas>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#connections">Recent Connections</button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#destinations">Top Destinations</button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#apps">Applications</button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ports">Ports & Protocols</button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="connections">
    <div style="max-height: 400px; overflow-y: auto;">
        <?php foreach ($recent_events as $evt): ?>
            <div class="connection-row">
                <div>
                    <span class="badge bg-<?= $evt['direction'] === 'inbound' ? 'success' : 'primary' ?>">
                        <?= strtoupper($evt['direction']) ?>
                    </span>
                    <span class="badge bg-<?= $evt['action'] === 'accept' ? 'success' : 'danger' ?>">
                        <?= strtoupper($evt['action']) ?>
                    </span>
                </div>
                <div><small><?= date('M d, H:i:s', strtotime($evt['time'])) ?></small></div>
                <div>
                    <code><?= htmlspecialchars($evt['src']) ?>:<?= $evt['srcport'] ?></code>
                    <i class="fas fa-arrow-right mx-1"></i>
                    <code><?= htmlspecialchars($evt['dst']) ?>:<?= $evt['dstport'] ?></code>
                </div>
                <div><small><strong><?= htmlspecialchars($evt['service']) ?></strong></small></div>
                <div><small><?= formatBytes($evt['sent'] + $evt['rcvd']) ?></small></div>
            </div>
        <?php endforeach; ?>
    </div>
  </div>

  <div class="tab-pane fade" id="destinations">
    <div class="row">
        <div class="col-md-6">
            <h6>Top Destinations</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>IP Address</th><th>Connections</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($top_destinations, 0, 15, true) as $dest => $count): ?>
                        <?php if ($dest !== $ip): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($dest) ?></code></td>
                            <td><span class="badge bg-info"><?= number_format($count) ?></span></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="col-md-6">
            <h6>Top Sources</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>IP Address</th><th>Connections</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($top_sources, 0, 15, true) as $src => $count): ?>
                        <?php if ($src !== $ip): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($src) ?></code></td>
                            <td><span class="badge bg-info"><?= number_format($count) ?></span></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>

  <div class="tab-pane fade" id="apps">
    <div class="row">
        <div class="col-md-6">
            <h6>Applications</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>Application</th><th>Flows</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($applications, 0, 15, true) as $app => $count): ?>
                        <tr>
                            <td><?= htmlspecialchars($app) ?></td>
                            <td><?= number_format($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="col-md-6">
            <h6>Services</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>Service</th><th>Flows</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($services, 0, 15, true) as $svc => $count): ?>
                        <tr>
                            <td><?= htmlspecialchars($svc) ?></td>
                            <td><?= number_format($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>

  <div class="tab-pane fade" id="ports">
    <div class="row">
        <div class="col-md-6">
            <h6>Top Ports</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>Port</th><th>Connections</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($ports, 0, 20, true) as $port => $count): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($port) ?></code></td>
                            <td><?= number_format($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="col-md-6">
            <h6>Protocols</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>Protocol</th><th>Flows</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($protocols as $proto => $count): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($proto) ?></strong></td>
                            <td><?= number_format($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>
</div>

<!-- CRITICAL: Chart creation at END, OUTSIDE any loops -->
<?php if (!empty($timeline)): ?>
<script>
(function() {
    const canvas = document.getElementById('<?= $chartId ?>');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_keys($timeline)) ?>,
            datasets: [{
                label: 'Connections',
                data: <?= json_encode(array_values($timeline)) ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
})();
</script>
<?php endif; ?>
