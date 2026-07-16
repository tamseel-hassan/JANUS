<?php
require_once __DIR__ . '/../db_config.php';
// ss/dns_attacks.php - DNS abuse: query floods and possible tunneling
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$dns_filter = "(message LIKE '%dstport=53%' OR message LIKE '%service=\"DNS\"%' OR message LIKE '%service=DNS%')";

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $dns_filter $device_where";

$result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 10000);

$per_source = [];
$denied = 0;
$total = 0;
$long_query_hits = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $p = parseMessage($row['message']);
        $total++;
        $src = $row['source_ip'];
        $action = strtolower($p['action'] ?? '');
        if ($action === 'deny' || $action === 'block') $denied++;

        $qname = $p['qname'] ?? '';
        $is_long = strlen($qname) > 50;
        if ($is_long) $long_query_hits++;

        if (!isset($per_source[$src])) $per_source[$src] = ['ip' => $src, 'queries' => 0, 'denied' => 0, 'long_queries' => 0];
        $per_source[$src]['queries']++;
        if ($action === 'deny' || $action === 'block') $per_source[$src]['denied']++;
        if ($is_long) $per_source[$src]['long_queries']++;
    }
}

uasort($per_source, fn($a, $b) => $b['queries'] <=> $a['queries']);
mysqli_close($con);
?>

<div class="alert alert-info"><i data-lucide="info" class="icon-lucide"></i> "Possible tunneling" flags sources sending an unusual volume of long DNS query names (&gt;50 chars) — a common (but not definitive) exfiltration/tunneling signal. Verify manually before acting.</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="server" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $total ?>">0</div>
            <span class="stat-chip chip-info">DNS Events</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="ban" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $denied ?>">0</div>
            <span class="stat-chip chip-danger">Denied Queries</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="route" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $long_query_hits ?>">0</div>
            <span class="stat-chip chip-warning">Possible Tunneling Hits</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i data-lucide="user" class="icon-lucide s"></i></div>
            <div class="stat-value" data-raw="<?= count($per_source) ?>">0</div>
            <span class="stat-chip chip-info">Unique DNS Clients</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i data-lucide="server" class="icon-lucide"></i> Top DNS Clients</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th>Rank</th><th>Source</th><th>Queries</th><th>Denied</th><th>Long-Name Hits</th><th>Flag</th></tr></thead>
            <tbody>
                <?php foreach (array_values(array_slice($per_source, 0, 20)) as $rank => $s): ?>
                    <tr>
                        <td><?= $rank + 1 ?></td>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($s['ip']) ?>')" class="clickable-ip"><?= htmlspecialchars($s['ip']) ?></a></td>
                        <td><?= number_format($s['queries']) ?></td>
                        <td><?= $s['denied'] > 0 ? '<span class="badge bg-danger">' . $s['denied'] . '</span>' : '0' ?></td>
                        <td><?= $s['long_queries'] ?></td>
                        <td><?= $s['long_queries'] > 10 ? '<span class="sev-pill sev-high">TUNNEL SUSPECT</span>' : '<span class="sev-pill sev-low">NORMAL</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($per_source)): ?>
                    <tr><td colspan="6" class="text-center">No DNS activity in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
