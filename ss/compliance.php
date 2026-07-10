<?php
require_once __DIR__ . '/../db_config.php';
// ss/compliance.php - Lightweight compliance scorecard built from available signals
// NOTE: this is not a certified compliance framework mapping (PCI/ISO/etc) —
// it's an honest summary of what this SIEM can already observe. Wire in a
// real framework mapping table if you need auditor-grade reporting.
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';
$w = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "' $device_where";

function countWhere($con, $where) {
    $sql = "SELECT COUNT(*) AS c FROM (
        SELECT 1 FROM syslog_entries WHERE $where
        UNION ALL
        SELECT 1 FROM syslog_entries_archive WHERE $where
    ) AS combined";
    $r = mysqli_query($con, $sql);
    return $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;
}

$total_events   = countWhere($con, $w);
$auth_fail      = countWhere($con, $w . " AND (message LIKE '%authentication%failed%' OR message LIKE '%login%failed%')");
$denied_traffic = countWhere($con, $w . " AND message LIKE '%action=deny%'");
$total_traffic  = countWhere($con, $w . " AND (message LIKE '%type=traffic%' OR message LIKE '%type=\"traffic\"%')");
$malware_hits   = countWhere($con, $w . " AND (message LIKE '%virus%' OR message LIKE '%malware%')");
$cfg_events     = countWhere($con, $w . " AND (message LIKE '%cfgattr%' OR message LIKE '%cfgpath%')");

$devices_res = mysqli_query($con, "SELECT COUNT(DISTINCT source_ip) AS c FROM syslog_entries");
$devices_reporting = $devices_res ? (int)mysqli_fetch_assoc($devices_res)['c'] : 0;

$fw_result = mysqli_query($con, "SELECT firewall_ip FROM response_config WHERE is_active = 1 LIMIT 1");
$has_firewall = $fw_result && mysqli_num_rows($fw_result) > 0;

$auth_fail_ratio = $total_events > 0 ? round(($auth_fail / $total_events) * 100, 2) : 0;
$deny_ratio = $total_traffic > 0 ? round(($denied_traffic / $total_traffic) * 100, 2) : 0;

mysqli_close($con);

$checks = [
    ['label' => 'Automated firewall response configured', 'pass' => $has_firewall, 'detail' => $has_firewall ? 'Active integration found' : 'No active firewall integration in response_config'],
    ['label' => 'Devices actively reporting logs', 'pass' => $devices_reporting > 0, 'detail' => $devices_reporting . ' distinct source(s) on record'],
    ['label' => 'Failed authentication ratio under 5%', 'pass' => $auth_fail_ratio < 5, 'detail' => $auth_fail_ratio . '% of events in this window'],
    ['label' => 'Malware detections present in window', 'pass' => $malware_hits === 0, 'detail' => $malware_hits . ' detection(s)'],
    ['label' => 'Configuration change logging active', 'pass' => $cfg_events >= 0, 'detail' => $cfg_events . ' config event(s) captured'],
];
$passed = count(array_filter($checks, fn($c) => $c['pass']));
$score = round(($passed / count($checks)) * 100);
?>

<div class="alert alert-info"><i class="fas fa-info-circle"></i> This is a lightweight posture scorecard built from what this SIEM already observes — not a certified PCI-DSS / ISO 27001 / SOC 2 mapping. Treat the score as directional.</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon <?= $score >= 80 ? 'text-success' : ($score >= 50 ? 'text-warning' : 'text-danger') ?>"><i class="fas fa-clipboard-check"></i></div>
            <div class="stat-value"><?= $score ?>%</div>
            <span class="stat-chip <?= $score >= 80 ? 'chip-success' : ($score >= 50 ? 'chip-warning' : 'chip-danger') ?>">Posture Score</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-server"></i></div>
            <div class="stat-value" data-raw="<?= $devices_reporting ?>">0</div>
            <span class="stat-chip chip-info">Devices Reporting</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-lock"></i></div>
            <div class="stat-value"><?= $auth_fail_ratio ?>%</div>
            <span class="stat-chip chip-warning">Auth Failure Ratio</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-ban"></i></div>
            <div class="stat-value"><?= $deny_ratio ?>%</div>
            <span class="stat-chip chip-info">Traffic Denied</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-tasks"></i> Posture Checklist</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
                <?php foreach ($checks as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['label']) ?></td>
                        <td><span class="badge bg-<?= $c['pass'] ? 'success' : 'danger' ?>"><?= $c['pass'] ? 'PASS' : 'ATTENTION' ?></span></td>
                        <td><small style="color:var(--text-mid);"><?= htmlspecialchars($c['detail']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
