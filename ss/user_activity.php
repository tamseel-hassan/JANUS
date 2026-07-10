<?php
require_once __DIR__ . '/../db_config.php';
// ss/user_activity.php - Per-user activity audit trail
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
          AND (message LIKE '%user=%' OR message LIKE '%xauthuser=%') $device_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 8000);

$users = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $user = $p['user'] ?? $p['xauthuser'] ?? null;
        if (!$user || $user === 'N/A') continue;
        $action = $p['action'] ?? ($p['status'] ?? 'event');

        if (!isset($users[$user])) {
            $users[$user] = ['user' => $user, 'events' => 0, 'ips' => [], 'failed' => 0, 'last' => $row['received_at']];
        }
        $users[$user]['events']++;
        $users[$user]['ips'][$row['source_ip']] = true;
        if (stripos($row['message'], 'fail') !== false) $users[$user]['failed']++;
        if (strtotime($row['received_at']) > strtotime($users[$user]['last'])) $users[$user]['last'] = $row['received_at'];
    }
}

uasort($users, fn($a, $b) => $b['events'] <=> $a['events']);
$total_failed = array_sum(array_column($users, 'failed'));
mysqli_close($con);
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-users"></i></div>
            <div class="stat-value" data-raw="<?= count($users) ?>">0</div>
            <span class="stat-chip chip-info">Active Users</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-list"></i></div>
            <div class="stat-value" data-raw="<?= array_sum(array_column($users, 'events')) ?>">0</div>
            <span class="stat-chip chip-info">Total Events</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i class="fas fa-user-lock"></i></div>
            <div class="stat-value" data-raw="<?= $total_failed ?>">0</div>
            <span class="stat-chip chip-danger">Failed Actions</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-map-marker-alt"></i></div>
            <div class="stat-value" data-raw="<?= !empty($users) ? max(array_map(fn($u)=>count($u['ips']), $users)) : 0 ?>">0</div>
            <span class="stat-chip chip-warning">Most IPs / User</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-user-clock"></i> User Activity Summary</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>User</th><th>Events</th><th>Failed</th><th>Source IPs Used</th><th>Last Activity</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($users, 0, 30) as $u): ?>
                    <tr>
                        <td><i class="fas fa-user-circle" style="color:var(--text-lo); margin-right:6px;"></i><strong><?= htmlspecialchars($u['user']) ?></strong></td>
                        <td><?= number_format($u['events']) ?></td>
                        <td><?= $u['failed'] > 0 ? '<span class="badge bg-danger">' . $u['failed'] . '</span>' : '<span class="badge bg-success">0</span>' ?></td>
                        <td><?= count($u['ips']) ?> <small style="color:var(--text-lo);">(<?= htmlspecialchars(implode(', ', array_slice(array_keys($u['ips']), 0, 3))) ?><?= count($u['ips']) > 3 ? '&hellip;' : '' ?>)</small></td>
                        <td><small><?= timeAgo($u['last']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" class="text-center">No user-attributed activity in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
