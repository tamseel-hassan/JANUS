<?php
require_once __DIR__ . '/../db_config.php';
// ss/endpoint_activity.php - Windows endpoint fleet overview
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
session_write_close();
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

// Endpoint tables are independent of the start/end range filter (they show current
// fleet state); we still read the range so the click-through correlation reuses it.
$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');

$check_tables = mysqli_query($con, "SHOW TABLES LIKE 'endpoints'");
if (!$check_tables || mysqli_num_rows($check_tables) === 0) {
    echo '<div class="alert alert-warning"><i class="fas fa-info-circle"></i> Endpoint tables not found yet. Run <code>sql/endpoint_schema.sql</code> against your database, deploy <code>agent/janus_agent.ps1</code> to a Windows client via Scheduled Task, and this page will populate automatically.</div>';
    mysqli_close($con);
    exit;
}

$endpoints_res = mysqli_query($con, "SELECT * FROM endpoints ORDER BY last_seen DESC");
$endpoints = [];
while ($row = mysqli_fetch_assoc($endpoints_res)) { $endpoints[] = $row; }

$online_cutoff = date('Y-m-d H:i:s', strtotime('-10 minutes'));
$online_count = count(array_filter($endpoints, fn($e) => $e['last_seen'] >= $online_cutoff));

$total_apps_res = mysqli_query($con, "SELECT COUNT(DISTINCT app_name) AS c FROM endpoint_apps");
$total_apps = $total_apps_res ? (int)mysqli_fetch_assoc($total_apps_res)['c'] : 0;

$rdp_24h_res = mysqli_query($con, "SELECT COUNT(*) AS c FROM endpoint_rdp_sessions WHERE event_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$rdp_24h = $rdp_24h_res ? (int)mysqli_fetch_assoc($rdp_24h_res)['c'] : 0;

mysqli_close($con);
?>

<style>
.endpoint-row { cursor: pointer; }
.endpoint-row:hover { background: rgba(41,211,238,0.05); }
.online-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 7px; }
.online-dot.on { background: var(--green); box-shadow: 0 0 6px 1px var(--green); }
.online-dot.off { background: var(--text-lo); }
</style>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-desktop"></i></div>
            <div class="stat-value" data-raw="<?= count($endpoints) ?>">0</div>
            <span class="stat-chip chip-info">Enrolled Endpoints</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i class="fas fa-signal"></i></div>
            <div class="stat-value" data-raw="<?= $online_count ?>">0</div>
            <span class="stat-chip chip-success">Reporting Now</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-th-large"></i></div>
            <div class="stat-value" data-raw="<?= $total_apps ?>">0</div>
            <span class="stat-chip chip-info">Distinct Apps Across Fleet</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i class="fas fa-desktop"></i></div>
            <div class="stat-value" data-raw="<?= $rdp_24h ?>">0</div>
            <span class="stat-chip chip-warning">Remote Session Events (24h)</span>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-desktop"></i> Endpoint Fleet</h5>
    <p class="text-muted small" style="font-family: var(--font-mono); font-size: 0.75rem; margin-top:-10px; margin-bottom:16px;">Click a row for logons, installed apps, listening ports, remote sessions, and correlated network traffic for that host</p>
    <div class="table-container">
        <table class="table table-hover">
            <thead><tr><th></th><th>Hostname</th><th>IP Address</th><th>OS</th><th>Agent</th><th>Last Check-in</th></tr></thead>
            <tbody>
                <?php foreach ($endpoints as $e):
                    $online = $e['last_seen'] >= $online_cutoff;
                ?>
                    <tr class="endpoint-row" onclick="showEndpointDetail(<?= (int)$e['id'] ?>, '<?= htmlspecialchars($e['hostname']) ?>')">
                        <td><span class="online-dot <?= $online ? 'on' : 'off' ?>"></span></td>
                        <td><strong><?= htmlspecialchars($e['hostname']) ?></strong></td>
                        <td><code><?= htmlspecialchars($e['ip_address']) ?></code></td>
                        <td><small><?= htmlspecialchars($e['os_version']) ?></small></td>
                        <td><small><?= htmlspecialchars($e['agent_version']) ?></small></td>
                        <td><small><?= timeAgo($e['last_seen']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($endpoints)): ?>
                    <tr><td colspan="6" class="text-center">No endpoints have checked in yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function showEndpointDetail(endpointId, hostname) {
    // Reuse a single Modal instance per element instead of creating a new
    // one on every click — repeated `new bootstrap.Modal(...)` calls on the
    // same element can leave a stray backdrop / body scroll-lock behind
    // when closed, which is what was blocking page scroll after hitting X.
    const modalEl = document.getElementById('drillDownModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i class="fas fa-desktop"></i> ${hostname}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Loading endpoint detail&hellip;</p>
        </div>
    `;
    modal.show();

    try {
        const params = new URLSearchParams({
            endpoint_id: endpointId,
            start: startTime,
            end: endTime
        });
        const response = await fetch(`ss/endpoint_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);
        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });
    } catch (error) {
        modalBody.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Error loading endpoint detail: ${error.message}</div>`;
    }
}
window.showEndpointDetail = showEndpointDetail;
</script>
