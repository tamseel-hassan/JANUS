<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/config_changes.php - Device / admin configuration change audit
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

$cfg_filter = "(
    message LIKE '%cfgattr%' OR message LIKE '%cfgpath%' OR message LIKE '%admin login%'
    OR message LIKE '%added user%' OR message LIKE '%user added%' OR message LIKE '%config%'
    OR message LIKE '%logid=\"010003%'
)";

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $cfg_filter $device_where";

require_once __DIR__ . '/../includes/cache.php';
$range = $_GET['range'] ?? '24h';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("config_changes", $start, $end, $device, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $where) {
    $result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 500);

    $changes = [];
    $by_admin = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $p = parseMessage($row['message']);
            $admin = $p['user'] ?? $p['admin'] ?? 'system';
            $path = $p['cfgpath'] ?? $p['cfgobj'] ?? 'n/a';
            $attr = $p['cfgattr'] ?? '';
            $msg = $p['msg'] ?? mb_substr($row['message'], 0, 130);

            $changes[] = ['time' => $row['received_at'], 'admin' => $admin, 'source' => $row['source_ip'], 'path' => $path, 'attr' => $attr, 'summary' => $msg];
            $by_admin[$admin] = ($by_admin[$admin] ?? 0) + 1;
        }
    }
    arsort($by_admin);

    return compact('changes', 'by_admin');
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
    <div class="col-md-4">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="wrench" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= count($changes) ?>">0</div>
            <span class="stat-chip chip-warning">Config Events</span>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="user" class="icon-lucide -cog"></i></div>
            <div class="stat-value" data-raw="<?= count($by_admin) ?>">0</div>
            <span class="stat-chip chip-info">Admins Involved</span>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i data-lucide="history" class="icon-lucide"></i></div>
            <div class="stat-value"><?= !empty($changes) ? timeAgo($changes[0]['time']) : '—' ?></div>
            <span class="stat-chip chip-info">Most Recent Change</span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="report-card">
            <h5><i data-lucide="user" class="icon-lucide -cog"></i> Changes by Admin</h5>
            <div class="table-container" style="max-height:340px;">
                <table class="table table-hover">
                    <thead><tr><th>Admin</th><th>Changes</th></tr></thead>
                    <tbody>
                        <?php foreach ($by_admin as $admin => $count): ?>
                            <tr><td><?= htmlspecialchars($admin) ?></td><td><span class="badge bg-warning"><?= $count ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($by_admin)): ?><tr><td colspan="2" class="text-center">No config activity</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="report-card">
            <h5><i data-lucide="clipboard-list" class="icon-lucide"></i> Change Log</h5>
            <div class="table-container" style="max-height:340px;">
                <table class="table table-hover">
                    <thead><tr><th>Time</th><th>Admin</th><th>Source</th><th>Path</th><th>Summary</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($changes, 0, 40) as $c): ?>
                            <tr>
                                <td><small><?= date('M d, H:i:s', strtotime($c['time'])) ?></small></td>
                                <td><?= htmlspecialchars($c['admin']) ?></td>
                                <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($c['source']) ?>')" class="clickable-ip"><?= htmlspecialchars($c['source']) ?></a></td>
                                <td><small><code><?= htmlspecialchars($c['path']) ?></code></small></td>
                                <td><small><?= htmlspecialchars($c['summary']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($changes)): ?><tr><td colspan="5" class="text-center">No configuration changes in this window</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
