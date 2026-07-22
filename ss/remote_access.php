<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/remote_access.php - VPN / remote session activity
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

$remote_filter = "is_remote_access = 1";

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $remote_filter $device_where";

require_once __DIR__ . '/../includes/cache.php';
$range = $_GET['range'] ?? '24h';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("remote_access", $start, $end, $device, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $where) {
    $result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at ASC', 8000);

    $sessions = [];
    $total_events = 0;
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $p = parseMessage($row['message']);
            $user = $p['user'] ?? $p['xauthuser'] ?? 'unknown';
            $tunnel = $p['vpntunnel'] ?? $p['tunnel'] ?? 'n/a';
            $remip = $p['remip'] ?? $row['source_ip'];
            $key = $user . '|' . $remip;

            if (!isset($sessions[$key])) {
                $sessions[$key] = ['user' => $user, 'ip' => $remip, 'tunnel' => $tunnel, 'first' => $row['received_at'], 'last' => $row['received_at'], 'events' => 0];
            }
            $sessions[$key]['last'] = $row['received_at'];
            $sessions[$key]['events']++;
            $total_events++;
        }
    }

    uasort($sessions, fn($a, $b) => strtotime($b['last']) <=> strtotime($a['last']));
    $unique_users = count(array_unique(array_column($sessions, 'user')));

    return compact('sessions', 'total_events', 'unique_users');
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please refresh.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);

mysqli_close($con);
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="door-open" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= count($sessions) ?>">0</div>
            <span class="stat-chip chip-info">Remote Sessions</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i data-lucide="user" class="icon-lucide s"></i></div>
            <div class="stat-value" data-raw="<?= $unique_users ?>">0</div>
            <span class="stat-chip chip-info">Unique Users</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i data-lucide="arrow-right-left" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $total_events ?>">0</div>
            <span class="stat-chip chip-success">Total VPN Events</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="shield" class="icon-lucide"></i></div>
            <div class="stat-value"><?= count($sessions) ? 'MONITORED' : 'IDLE' ?></div>
            <span class="stat-chip <?= count($sessions) ? 'chip-success' : 'chip-neutral' ?>">Tunnel Status</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i data-lucide="user" class="icon-lucide -shield"></i> Remote Access Sessions</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>User</th><th>Source IP</th><th>Tunnel</th><th>Events</th><th>First Seen</th><th>Last Seen</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($sessions, 0, 30) as $s): ?>
                    <tr>
                        <td><i class="fas fa-user" style="color:var(--text-lo); margin-right:6px;"></i><?= htmlspecialchars($s['user']) ?></td>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($s['ip']) ?>')" class="clickable-ip"><?= htmlspecialchars($s['ip']) ?></a></td>
                        <td><span class="badge bg-info"><?= htmlspecialchars($s['tunnel']) ?></span></td>
                        <td><?= number_format($s['events']) ?></td>
                        <td><small><?= date('M d, H:i', strtotime($s['first'])) ?></small></td>
                        <td><small><?= timeAgo($s['last']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="6" class="text-center">No remote access activity in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
