<?php
require_once __DIR__ . '/../db_config.php';
// ss/endpoint_drilldown.php - full detail for one endpoint, correlated with syslog traffic
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$endpoint_id = (int)($_GET['endpoint_id'] ?? 0);
$start = $_GET['start'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end   = $_GET['end']   ?? date('Y-m-d H:i:s');
$safe_start_f = mysqli_real_escape_string($con, $start);
$safe_end_f   = mysqli_real_escape_string($con, $end);

$ep_res = mysqli_query($con, "SELECT * FROM endpoints WHERE id = $endpoint_id LIMIT 1");
if (!$ep_res || mysqli_num_rows($ep_res) === 0) {
    echo '<div class="alert alert-danger">Endpoint not found</div>';
    exit;
}
$endpoint = mysqli_fetch_assoc($ep_res);
$ip = $endpoint['ip_address'];

// Recent logons (within selected time range)
$logons_res = mysqli_query($con, "SELECT * FROM endpoint_logons WHERE endpoint_id = $endpoint_id AND logon_time BETWEEN '$safe_start_f' AND '$safe_end_f' ORDER BY logon_time DESC LIMIT 300");
$logons = [];
while ($logons_res && $row = mysqli_fetch_assoc($logons_res)) { $logons[] = $row; }

// Remote sessions - RDP/SSH/Telnet/VNC, inbound or outbound (within selected time range)
$rdp_res = mysqli_query($con, "SELECT * FROM endpoint_rdp_sessions WHERE endpoint_id = $endpoint_id AND event_time BETWEEN '$safe_start_f' AND '$safe_end_f' ORDER BY event_time DESC LIMIT 300");
$rdp_sessions = [];
while ($rdp_res && $row = mysqli_fetch_assoc($rdp_res)) { $rdp_sessions[] = $row; }

// Installed apps
$apps_res = mysqli_query($con, "SELECT * FROM endpoint_apps WHERE endpoint_id = $endpoint_id ORDER BY app_name ASC LIMIT 200");
$apps = [];
while ($apps_res && $row = mysqli_fetch_assoc($apps_res)) { $apps[] = $row; }

// Latest port snapshot (most recent collected_at batch)
$latest_res = mysqli_query($con, "SELECT MAX(collected_at) AS m FROM endpoint_ports WHERE endpoint_id = $endpoint_id");
$latest_collected = $latest_res ? mysqli_fetch_assoc($latest_res)['m'] : null;
$ports = [];
if ($latest_collected) {
    $ports_res = mysqli_query($con, "SELECT * FROM endpoint_ports WHERE endpoint_id = $endpoint_id AND collected_at = '$latest_collected' ORDER BY local_port ASC");
    while ($ports_res && $row = mysqli_fetch_assoc($ports_res)) { $ports[] = $row; }
}

// --- Correlation: pull this host's traffic from the SIEM's syslog data ---
$safe_start = mysqli_real_escape_string($con, $start);
$safe_end = mysqli_real_escape_string($con, $end);
$safe_ip = mysqli_real_escape_string($con, $ip);

$traffic_where = "received_at BETWEEN '$safe_start' AND '$safe_end' AND source_ip = '$safe_ip'";
$traffic_result = unionQuery($con, 'message, received_at', $traffic_where, 'received_at DESC', 3000);

$traffic_summary = ['flows' => 0, 'denied' => 0, 'bandwidth' => 0, 'destinations' => []];
if ($traffic_result) {
    while ($row = mysqli_fetch_assoc($traffic_result)) {
        $p = parseMessage($row['message']);
        if (isset($p['dstip'])) {
            $traffic_summary['flows']++;
            $traffic_summary['bandwidth'] += intval($p['sentbyte'] ?? 0) + intval($p['rcvdbyte'] ?? 0);
            if (strtolower($p['action'] ?? '') === 'deny') $traffic_summary['denied']++;
            $dst = $p['dstip'];
            $traffic_summary['destinations'][$dst] = ($traffic_summary['destinations'][$dst] ?? 0) + 1;
        }
    }
}
arsort($traffic_summary['destinations']);

// Was this host also flagged as an attacker/target in the auth-failure feed?
$auth_filter = "(message LIKE '%authentication%failed%' OR message LIKE '%login%failed%')";
$auth_where = "received_at BETWEEN '$safe_start' AND '$safe_end' AND source_ip = '$safe_ip' AND $auth_filter";
$auth_result = unionQuery($con, 'message', $auth_where, '', 0);
$auth_fail_count = $auth_result ? mysqli_num_rows($auth_result) : 0;

mysqli_close($con);

// Maps a raw session_action string (set by the Windows agent) to a
// human-facing protocol + direction pair for display.
function remoteSessionMeta($action) {
    $map = [
        'inbound_rdp'      => ['protocol' => 'RDP',    'direction' => 'Inbound'],
        'logon'            => ['protocol' => 'RDP',    'direction' => 'Inbound'], // legacy value, pre-rename
        'outbound_rdp'     => ['protocol' => 'RDP',    'direction' => 'Outbound'],
        'inbound_ssh'      => ['protocol' => 'SSH',    'direction' => 'Inbound'],
        'outbound_ssh'     => ['protocol' => 'SSH',    'direction' => 'Outbound'],
        'inbound_telnet'   => ['protocol' => 'Telnet', 'direction' => 'Inbound'],
        'outbound_telnet'  => ['protocol' => 'Telnet', 'direction' => 'Outbound'],
        'inbound_vnc'      => ['protocol' => 'VNC',    'direction' => 'Inbound'],
        'outbound_vnc'     => ['protocol' => 'VNC',    'direction' => 'Outbound'],
    ];
    return $map[$action] ?? ['protocol' => 'Unknown', 'direction' => $action];
}
$remoteProtocolBadge = [
    'RDP'     => 'bg-info',
    'SSH'     => 'bg-success',
    'Telnet'  => 'bg-warning',
    'VNC'     => 'bg-primary',
    'Unknown' => 'bg-secondary',
];
?>

<style>
.detail-tabs { display: flex; gap: 6px; border-bottom: 1px solid var(--line); margin-bottom: 16px; flex-wrap: wrap; }
.detail-tab-btn {
    font-family: var(--font-mono); font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--text-mid); background: none; border: none;
    padding: 9px 6px; margin-right: 16px; border-bottom: 2px solid transparent; cursor: pointer;
}
.detail-tab-btn.active { color: var(--amber); border-bottom-color: var(--amber); }
.detail-pane { display: none; }
.detail-pane.active { display: block; }
</style>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="stat-box" style="padding:14px 16px;">
            <div class="stat-value" style="font-size:1.3rem;"><?= number_format($traffic_summary['flows']) ?></div>
            <span class="stat-chip chip-info" style="margin-top:6px;">Correlated Flows</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box" style="padding:14px 16px;">
            <div class="stat-value" style="font-size:1.3rem;"><?= formatBytes($traffic_summary['bandwidth']) ?></div>
            <span class="stat-chip chip-success" style="margin-top:6px;">Bandwidth</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box" style="padding:14px 16px;">
            <div class="stat-value" style="font-size:1.3rem;"><?= $traffic_summary['denied'] ?></div>
            <span class="stat-chip chip-warning" style="margin-top:6px;">Denied Flows</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box" style="padding:14px 16px;">
            <div class="stat-value" style="font-size:1.3rem; <?= $auth_fail_count > 0 ? 'color:var(--red);' : '' ?>"><?= $auth_fail_count ?></div>
            <span class="stat-chip <?= $auth_fail_count > 0 ? 'chip-danger' : 'chip-success' ?>" style="margin-top:6px;">Auth Failures (as source)</span>
        </div>
    </div>
</div>

<div class="detail-tabs">
    <button class="detail-tab-btn active" data-pane="pane-logons">Logons (<?= count($logons) ?>)</button>
    <button class="detail-tab-btn" data-pane="pane-rdp">Remote Sessions (<?= count($rdp_sessions) ?>)</button>
    <button class="detail-tab-btn" data-pane="pane-apps">Installed Apps (<?= count($apps) ?>)</button>
    <button class="detail-tab-btn" data-pane="pane-ports">Listening Ports (<?= count($ports) ?>)</button>
    <button class="detail-tab-btn" data-pane="pane-traffic">Correlated Traffic</button>
</div>

<div class="detail-pane active" id="pane-logons">
    <div class="table-container" style="max-height:340px;">
        <table class="table table-hover">
            <thead><tr><th>Time</th><th>User</th><th>Domain</th><th>Type</th><th>Source IP</th></tr></thead>
            <tbody>
                <?php foreach ($logons as $l): ?>
                    <tr>
                        <td><small><?= date('M d, H:i:s', strtotime($l['logon_time'])) ?></small></td>
                        <td><?= htmlspecialchars($l['username']) ?></td>
                        <td><small><?= htmlspecialchars($l['domain_name']) ?></small></td>
                        <td><span class="badge bg-secondary"><?= $l['logon_type'] == 10 ? 'RDP' : ($l['logon_type'] == 2 ? 'Console' : 'Type ' . $l['logon_type']) ?></span></td>
                        <td><?= $l['source_ip'] ? '<code>' . htmlspecialchars($l['source_ip']) . '</code>' : '&mdash;' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($logons)): ?><tr><td colspan="5" class="text-center">No logon events recorded</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="detail-pane" id="pane-rdp">
    <div class="table-container" style="max-height:340px;">
        <table class="table table-hover">
            <thead><tr><th>Time</th><th>User</th><th>Client IP</th><th>Protocol</th><th>Direction</th></tr></thead>
            <tbody>
                <?php foreach ($rdp_sessions as $r):
                    $meta = remoteSessionMeta($r['session_action']);
                    $badgeClass = $remoteProtocolBadge[$meta['protocol']] ?? 'bg-secondary';
                ?>
                    <tr>
                        <td><small><?= date('M d, H:i:s', strtotime($r['event_time'])) ?></small></td>
                        <td><?= htmlspecialchars($r['username']) ?></td>
                        <td><?= $r['client_ip'] ? '<a href="javascript:void(0)" onclick="drillDownIP(\'' . htmlspecialchars($r['client_ip']) . '\')" class="clickable-ip">' . htmlspecialchars($r['client_ip']) . '</a>' : '&mdash;' ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $meta['protocol'] ?></span></td>
                        <td><small><?= $meta['direction'] ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rdp_sessions)): ?><tr><td colspan="5" class="text-center">No remote session events recorded</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="detail-pane" id="pane-apps">
    <div class="table-container" style="max-height:340px;">
        <table class="table table-hover">
            <thead><tr><th>Application</th><th>Version</th><th>Publisher</th><th>Install Date</th><th>Last Seen</th></tr></thead>
            <tbody>
                <?php foreach ($apps as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['app_name']) ?></td>
                        <td><small><?= htmlspecialchars($a['app_version']) ?></small></td>
                        <td><small><?= htmlspecialchars($a['publisher']) ?></small></td>
                        <td><small><?= $a['install_date'] ?: '&mdash;' ?></small></td>
                        <td><small><?= timeAgo($a['last_seen']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($apps)): ?><tr><td colspan="5" class="text-center">No application inventory collected yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="detail-pane" id="pane-ports">
    <p class="text-muted small" style="font-family: var(--font-mono); font-size:0.7rem;"><?= $latest_collected ? 'Snapshot from ' . date('M d, H:i:s', strtotime($latest_collected)) : '' ?></p>
    <div class="table-container" style="max-height:340px;">
        <table class="table table-hover">
            <thead><tr><th>Port</th><th>Protocol</th><th>Process</th><th>Path</th><th>PID</th></tr></thead>
            <tbody>
                <?php foreach ($ports as $p): ?>
                    <tr>
                        <td><code><?= $p['local_port'] ?></code></td>
                        <td><?= htmlspecialchars($p['protocol']) ?></td>
                        <td><strong><?= htmlspecialchars($p['process_name']) ?></strong></td>
                        <td><small style="color:var(--text-lo);"><?= htmlspecialchars($p['process_path']) ?></small></td>
                        <td><?= $p['pid'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($ports)): ?><tr><td colspan="5" class="text-center">No port snapshot collected yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="detail-pane" id="pane-traffic">
    <p class="text-muted small" style="font-family: var(--font-mono); font-size:0.7rem;">Traffic where <code><?= htmlspecialchars($ip) ?></code> is the source, matched against <code>syslog_entries</code> for the currently selected time range</p>
    <div class="table-container" style="max-height:320px;">
        <table class="table table-hover">
            <thead><tr><th>Destination IP</th><th>Flows</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($traffic_summary['destinations'], 0, 20, true) as $dst => $count): ?>
                    <tr>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($dst) ?>')" class="clickable-ip"><?= htmlspecialchars($dst) ?></a></td>
                        <td><?= number_format($count) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($traffic_summary['destinations'])): ?>
                    <tr><td colspan="2" class="text-center">No matching network traffic for this host/window &mdash; check that the endpoint's IP matches its <code>source_ip</code> in syslog_entries</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function() {
    document.querySelectorAll('.detail-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.detail-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.detail-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.getAttribute('data-pane')).classList.add('active');
        });
    });
})();
</script>
