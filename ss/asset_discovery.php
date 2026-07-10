<?php
require_once __DIR__ . '/../db_config.php';
// ss/asset_discovery.php - Asset inventory derived from observed sources
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
    SELECT source_ip, COUNT(*) AS events, MIN(received_at) AS first_seen, MAX(received_at) AS last_seen
    FROM (
        SELECT source_ip, received_at FROM syslog_entries WHERE $where
        UNION ALL
        SELECT source_ip, received_at FROM syslog_entries_archive WHERE $where
    ) AS combined
    GROUP BY source_ip
    ORDER BY events DESC
    LIMIT 300
";
$result = mysqli_query($con, $agg_query);

$assets = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $assets[] = $row;
    }
}

// Sample one message per asset (cheap heuristic) to guess a device role
$roles = [];
$sample_query = "
    SELECT source_ip, message FROM (
        SELECT source_ip, message, received_at FROM syslog_entries WHERE $where
        UNION ALL
        SELECT source_ip, message, received_at FROM syslog_entries_archive WHERE $where
    ) AS combined
    ORDER BY received_at DESC
    LIMIT 3000
";
$sample_result = mysqli_query($con, $sample_query);
if ($sample_result) {
    while ($row = mysqli_fetch_assoc($sample_result)) {
        if (isset($roles[$row['source_ip']])) continue;
        $m = strtolower($row['message']);
        if (strpos($m, 'type=traffic') !== false) $roles[$row['source_ip']] = 'Firewall/Gateway';
        elseif (strpos($m, 'vpntunnel') !== false) $roles[$row['source_ip']] = 'VPN Endpoint';
        elseif (strpos($m, 'cfgpath') !== false) $roles[$row['source_ip']] = 'Managed Device';
        else $roles[$row['source_ip']] = 'Unclassified';
    }
}

mysqli_close($con);
$new_last_24h = count(array_filter($assets, fn($a) => strtotime($a['first_seen']) > strtotime('-24 hours')));
?>

<div class="alert alert-info"><i class="fas fa-info-circle"></i> Assets here are inferred from observed <code>source_ip</code> values in your syslog stream, not a dedicated asset/CMDB scan. Device role is a rough heuristic from sampled log content.</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-sitemap"></i></div>
            <div class="stat-value" data-raw="<?= count($assets) ?>">0</div>
            <span class="stat-chip chip-info">Assets Observed</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i class="fas fa-plus-circle"></i></div>
            <div class="stat-value" data-raw="<?= $new_last_24h ?>">0</div>
            <span class="stat-chip chip-success">First Seen (24h)</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-question-circle"></i></div>
            <div class="stat-value" data-raw="<?= count(array_filter($roles, fn($r) => $r === 'Unclassified')) ?>">0</div>
            <span class="stat-chip chip-warning">Unclassified</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-value" data-raw="<?= count(array_filter($roles, fn($r) => $r === 'Firewall/Gateway')) ?>">0</div>
            <span class="stat-chip chip-info">Firewalls/Gateways</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-list"></i> Asset Inventory</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>IP Address</th><th>Inferred Role</th><th>Events</th><th>First Seen</th><th>Last Seen</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($assets, 0, 50) as $a): ?>
                    <tr>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($a['source_ip']) ?>')" class="clickable-ip"><?= htmlspecialchars($a['source_ip']) ?></a></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($roles[$a['source_ip']] ?? 'Unclassified') ?></span></td>
                        <td><?= number_format($a['events']) ?></td>
                        <td><small><?= date('M d, H:i', strtotime($a['first_seen'])) ?></small></td>
                        <td><small><?= timeAgo($a['last_seen']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($assets)): ?>
                    <tr><td colspan="5" class="text-center">No assets observed in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
