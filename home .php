<?php
session_start();
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth_check.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

// === NOC Summary Stats (Network Devices) ===
$total = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM devices"))['c'] ?? 0);
$up = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM devices d LEFT JOIN ( SELECT device_id, status, ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn FROM ping_logs WHERE link_id IS NULL ) pl ON d.id = pl.device_id AND pl.rn = 1 WHERE COALESCE(pl.status, 'down') = 'up'"))['c'] ?? 0);
$down = max(0, $total - $up);
$uptime = $total > 0 ? round(($up / $total) * 100, 1) : 0;

// === SOC/NOC Incident/Ticket Stats ===
$my_id = intval($_SESSION['id']);
$my_tasks_count = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM incidents WHERE assigned_to = $my_id AND status != 'closed'"))['c'] ?? 0);
$open_tickets = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM incidents WHERE status != 'closed'"))['c'] ?? 0);
$overdue_tickets = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM incidents WHERE due_date < NOW() AND status != 'closed'"))['c'] ?? 0);

// --- Servers Chart ---
$server_query = "SELECT CASE WHEN model IN ('Hypervisor','Virtual Machine','Container') THEN model ELSE COALESCE(NULLIF(model,''),'Other') END AS model, COUNT(*) AS cnt FROM devices WHERE type = 'server' GROUP BY model ORDER BY cnt DESC";
$server_result = mysqli_query($con, $server_query);
$server_labels = $server_data = $server_colors = [];
$modern_palette = ['Hypervisor' => '#2be8a4', 'Virtual Machine' => '#29d3ee', 'Container' => '#ffb020', 'Other' => '#93a1b0', 'Unknown' => '#ff4d5e'];
while ($row = mysqli_fetch_assoc($server_result)) {
    $lbl = $row['model'] ?: 'Unknown';
    $server_labels[] = $lbl;
    $server_data[] = intval($row['cnt']);
    $server_colors[] = $modern_palette[$lbl] ?? '#9d8cff';
}

// --- Firewalls Chart ---
$fw_query = "SELECT COALESCE(NULLIF(model,''),'Unknown') AS model, COUNT(*) AS cnt FROM devices WHERE type = 'firewall' GROUP BY model ORDER BY cnt DESC";
$fw_result = mysqli_query($con, $fw_query);
$fw_labels = $fw_data = $fw_colors = [];
$fw_palette = ['#ff4d5e', '#ffb020', '#9d8cff', '#29d3ee', '#2be8a4', '#ff8a3d'];
$i = 0;
while ($row = mysqli_fetch_assoc($fw_result)) {
    $fw_labels[] = $row['model'];
    $fw_data[] = intval($row['cnt']);
    $fw_colors[] = $fw_palette[$i++ % count($fw_palette)];
}

// --- Switches Chart ---
$sw_query = "SELECT COALESCE(NULLIF(model,''),'Unknown') AS model, COUNT(*) AS cnt FROM devices WHERE type = 'switch' GROUP BY model ORDER BY cnt DESC";
$sw_result = mysqli_query($con, $sw_query);
$sw_labels = $sw_data = [];
while ($row = mysqli_fetch_assoc($sw_result)) {
    $sw_labels[] = $row['model'];
    $sw_data[] = intval($row['cnt']);
}

mysqli_close($con);
$theme = $_COOKIE['theme'] ?? 'dark';
$now_label = date('M d, H:i:s');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Janus :: Command Center</title>
<link href="css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="css/theme.css">
<script src="js/chart.js"></script>
<script src="js/chartjs-plugin-datalabels.min.js"></script>
<style>
:root {
    --void:        #090c11;
    --panel:       #10151d;
    --panel-raised:#141b25;
    --inset:       #0a0e14;
    --line:        #1d2733;
    --line-active: #2c3948;
    --amber:       #ffb020;
    --amber-dim:   #8a5f16;
    --cyan:        #29d3ee;
    --cyan-dim:    #146575;
    --red:         #ff4d5e;
    --red-dim:     #7a1f28;
    --green:       #2be8a4;
    --green-dim:   #146346;
    --violet:      #9d8cff;
    --text-hi:     #eef3f7;
    --text-mid:    #93a1b0;
    --text-lo:     #56626f;
    --font-mono: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
    --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    --radius: 3px;
}
[data-theme="light"] {
    --void:        #eef1f5;
    --panel:       #ffffff;
    --panel-raised:#f6f8fa;
    --inset:       #edf0f4;
    --line:        #d7dde4;
    --line-active: #b9c2cd;
    --text-hi:     #10151d;
    --text-mid:    #4b5768;
    --text-lo:     #8894a3;
}
* { box-sizing: border-box; }
body.loggedin {
    background:
        radial-gradient(ellipse 900px 500px at 15% -10%, rgba(41,211,238,0.06), transparent 60%),
        radial-gradient(ellipse 900px 500px at 100% 0%, rgba(255,176,32,0.05), transparent 60%),
        var(--void);
    color: var(--text-hi);
    font-family: var(--font-sans);
    line-height: 1.6;
}
#main-content {
    margin-left: 250px;
    padding: 80px 25px 25px 25px;
    min-height: 100vh;
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.sidebar.collapsed ~ #main-content { margin-left: 80px; }
.page-header { margin-bottom: 26px; }
.eyebrow {
    font-family: var(--font-mono);
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.25em;
    color: var(--cyan);
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.eyebrow .led {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 6px 1px var(--green);
    animation: pulse-led 1.8s ease-in-out infinite;
}
@keyframes pulse-led { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }
.page-title {
    font-family: var(--font-mono);
    font-size: 1.9rem;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--text-hi);
    margin-bottom: 6px;
}
.page-title .accent { color: var(--amber); }
.page-subtitle { color: var(--text-mid); font-size: 0.9rem; margin-bottom: 0; }
.readout {
    font-family: var(--font-mono);
    font-size: 0.75rem;
    color: var(--text-lo);
    text-align: right;
}
.readout strong { color: var(--text-mid); font-weight: 600; }
.stat-box {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 20px 22px;
    transition: all 0.2s;
    position: relative;
    height: 100%;
}
.stat-box::before, .stat-box::after {
    content: ''; position: absolute; width: 9px; height: 9px; pointer-events: none;
}
.stat-box::before { top: -1px; left: -1px; border-top: 2px solid var(--cyan); border-left: 2px solid var(--cyan); }
.stat-box::after { bottom: -1px; right: -1px; border-bottom: 2px solid var(--cyan); border-right: 2px solid var(--cyan); }
.stat-box:hover { border-color: var(--line-active); transform: translateY(-2px); }
.stat-icon { font-size: 1.5rem; margin-bottom: 12px; opacity: 0.85; }
.stat-icon.ic-total  { color: var(--cyan); }
.stat-icon.ic-up     { color: var(--green); }
.stat-icon.ic-down   { color: var(--red); }
.stat-icon.ic-uptime { color: var(--amber); }
.stat-icon.ic-tasks    { color: var(--cyan); }
.stat-icon.ic-tickets  { color: var(--violet); }
.stat-icon.ic-overdue  { color: var(--red); }
.stat-value {
    font-family: var(--font-mono);
    font-size: 1.85rem;
    font-weight: 700;
    color: var(--text-hi);
    margin-bottom: 4px;
    letter-spacing: -0.01em;
}
.stat-label {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--text-lo);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 600;
}
.stat-chip {
    display: inline-block;
    padding: 5px 13px;
    border-radius: 3px;
    font-family: var(--font-mono);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-top: 14px;
}
.chip-danger  { background: var(--red);   color: #2b0a0e; }
.chip-warning { background: var(--amber); color: #1a1204; }
.chip-info    { background: var(--cyan);  color: #04232a; }
.chip-success { background: var(--green); color: #04231a; }
.chip-violet  { background: var(--violet); color: #14103a; }
.chip-neutral { background: var(--line-active); color: var(--text-hi); }
.uptime-track {
    width: 100%; height: 6px; border-radius: 3px;
    background: var(--inset); border: 1px solid var(--line);
    overflow: hidden; margin-top: 14px;
}
.uptime-fill { height: 100%; background: linear-gradient(90deg, var(--amber), var(--green)); }
.section-eyebrow {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-lo);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 30px 0 14px 0;
}
.section-eyebrow i { color: var(--amber); }
.section-eyebrow::after { content: ''; flex: 1; height: 1px; background: var(--line); }
.report-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 22px;
    margin-bottom: 22px;
    position: relative;
    height: 100%;
}
.report-card::before, .report-card::after { content: ''; position: absolute; width: 9px; height: 9px; pointer-events: none; }
.report-card::before { top: -1px; left: -1px; border-top: 2px solid var(--line-active); border-left: 2px solid var(--line-active); }
.report-card::after { bottom: -1px; right: -1px; border-bottom: 2px solid var(--line-active); border-right: 2px solid var(--line-active); }
.report-card h5 {
    font-family: var(--font-mono);
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 18px;
    color: var(--text-hi);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--line);
}
.report-card h5 i { color: var(--amber); }
.chart-container { position: relative; height: 280px; width: 100%; }
.status-list { list-style: none; margin: 0; padding: 0; }
.status-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 4px;
    border-bottom: 1px solid var(--line);
    font-size: 0.88rem;
}
.status-list li:last-child { border-bottom: none; }
.status-list .name { display: flex; align-items: center; gap: 10px; color: var(--text-mid); }
.status-list .led-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--green); box-shadow: 0 0 6px 1px var(--green);
    flex-shrink: 0; animation: pulse-led 1.8s ease-in-out infinite;
}
.status-list .val { font-family: var(--font-mono); font-weight: 700; color: var(--text-hi); font-size: 0.82rem; }
.btn-ghost {
    padding: 8px 16px;
    border-radius: var(--radius);
    font-family: var(--font-mono);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    background: transparent;
    border: 1px solid var(--line-active);
    color: var(--text-mid);
    transition: all 0.15s;
}
.btn-ghost:hover { border-color: var(--cyan); color: var(--cyan); background: rgba(41,211,238,0.06); }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.report-card, .stat-box { animation: fadeIn 0.35s ease-out; }
@media (prefers-reduced-motion: reduce) { .report-card, .stat-box, .eyebrow .led, .led-dot { animation: none; } }
@media (max-width: 768px) {
    #main-content { margin-left: 0; padding: 80px 16px 16px 16px; }
    .page-title { font-size: 1.4rem; }
    .readout { text-align: left; margin-top: 10px; }
}
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/topbar.php'; ?>
<?php include __DIR__ . '/sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">

    <!-- HEADER -->
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="eyebrow"><span class="led"></span> JANUS / COMMAND CENTER &mdash; LIVE FEED</div>
                <h1 class="page-title"><span class="accent">&gt;</span> Secure Hybrid Intelligence Console</h1>
                <p class="page-subtitle">Welcome, <strong style="color:var(--text-hi);"><?= htmlspecialchars($_SESSION['name']) ?></strong> &mdash; network, security and incident telemetry at a glance</p>
            </div>
            <div class="col-md-4">
                <div class="readout">
                    Snapshot as of <strong><?= $now_label ?></strong><br>
                    <button class="btn-ghost mt-2" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
                </div>
            </div>
        </div>
    </div>

    <!-- NOC SUMMARY STATS -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-icon ic-total"><i class="fas fa-server"></i></div>
                <div class="stat-value" data-raw="<?= $total ?>">0</div>
                <span class="stat-chip chip-info">Total Devices</span>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-icon ic-up"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value" data-raw="<?= $up ?>">0</div>
                <span class="stat-chip chip-success">Up</span>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-icon ic-down"><i class="fas fa-times-circle"></i></div>
                <div class="stat-value" data-raw="<?= $down ?>">0</div>
                <span class="stat-chip chip-danger">Down</span>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-icon ic-uptime"><i class="fas fa-heartbeat"></i></div>
                <div class="stat-value" data-raw="<?= $uptime ?>" data-suffix="%"><?= $uptime ?>%</div>
                <span class="stat-chip chip-warning">Network Uptime</span>
                <div class="uptime-track"><div class="uptime-fill" style="width:<?= $uptime ?>%"></div></div>
            </div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="section-eyebrow"><i class="fas fa-chart-pie"></i> Fleet Composition</div>
    <div class="row g-3">
        <div class="col-lg-4 col-md-6">
            <div class="report-card">
                <h5><i class="fas fa-hdd"></i> Servers by Type</h5>
                <div class="chart-container"><canvas id="serversChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="report-card">
                <h5><i class="fas fa-shield-alt"></i> Firewalls by Model</h5>
                <div class="chart-container" style="height:280px"><canvas id="firewallsChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="report-card">
                <h5><i class="fas fa-network-wired"></i> Switches by Model</h5>
                <div class="chart-container"><canvas id="switchesChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- SOC/NOC INCIDENT OVERVIEW -->
    <div class="section-eyebrow"><i class="fas fa-user-shield"></i> SOC / NOC Incident Overview</div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-icon ic-tasks"><i class="fas fa-tasks"></i></div>
                <div class="stat-value" data-raw="<?= $my_tasks_count ?>">0</div>
                <span class="stat-chip chip-info">My Assigned Tasks</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-icon ic-tickets"><i class="fas fa-ticket-alt"></i></div>
                <div class="stat-value" data-raw="<?= $open_tickets ?>">0</div>
                <span class="stat-chip chip-violet">Open Tickets</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-icon ic-overdue"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-value" data-raw="<?= $overdue_tickets ?>">0</div>
                <span class="stat-chip chip-danger">Overdue Tickets</span>
            </div>
        </div>
    </div>

    <!-- SYSTEM STATUS -->
    <div class="row g-3 mt-2 mb-4">
        <div class="col-12">
            <div class="report-card">
                <h5><i class="fas fa-satellite-dish"></i> System Status</h5>
                <ul class="status-list">
                    <li><span class="name"><span class="led-dot"></span> ICMP, SNMP, Traffic Flows</span><span class="val">Running &middot; every 2 min</span></li>
                    <li><span class="name"><span class="led-dot"></span> Device Inventory</span><span class="val"><?= $total ?> registered</span></li>
                    <li><span class="name"><span class="led-dot"></span> Syslog Collection</span><span class="val">Active</span></li>
                    <li><span class="name"><span class="led-dot"></span> SOC/NOC Incident Module</span><span class="val">Active &middot; Register / Assign / Track</span></li>
                </ul>
            </div>
        </div>
    </div>

</div>
</div>

<script>
function cssVar(name, fallback = '') {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}
if (window.Chart) {
    Chart.defaults.color = '#8592a3';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
    Chart.defaults.font.family = "'JetBrains Mono', monospace";
    Chart.defaults.font.size = 11;
}
let serversChart=null, firewallsChart=null, switchesChart=null;
const SERVER_LABELS = <?= json_encode($server_labels) ?>;
const SERVER_DATA = <?= json_encode($server_data) ?>;
const SERVER_COLORS = <?= json_encode($server_colors) ?>;
const FW_LABELS = <?= json_encode($fw_labels) ?>;
const FW_DATA = <?= json_encode($fw_data) ?>;
const FW_COLORS = <?= json_encode($fw_colors) ?>;
const SW_LABELS = <?= json_encode($sw_labels) ?>;
const SW_DATA = <?= json_encode($sw_data) ?>;

function createServersChart() {
    const canvas = document.getElementById('serversChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (serversChart) serversChart.destroy();
    serversChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: SERVER_LABELS, datasets: [{ data: SERVER_DATA, backgroundColor: SERVER_COLORS, borderColor: cssVar('--panel', '#10151d'), borderWidth: 2 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { color: cssVar('--text-mid') } },
                datalabels: { color: cssVar('--text-hi'), font: { weight: 'bold' }, formatter: v => v }
            }
        },
        plugins: [ChartDataLabels]
    });
}
function createFirewallsChart() {
    const canvas = document.getElementById('firewallsChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (firewallsChart) firewallsChart.destroy();
    firewallsChart = new Chart(ctx, {
        type: 'bar',
        data: { labels: FW_LABELS, datasets: [{ label: 'Count', data: FW_DATA, backgroundColor: FW_COLORS, borderRadius: 2, maxBarThickness: 36 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: cssVar('--text-mid') } },
                x: { grid: { display: false }, ticks: { color: cssVar('--text-mid') } }
            }
        }
    });
}
function createSwitchesChart() {
    const canvas = document.getElementById('switchesChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (switchesChart) switchesChart.destroy();
    switchesChart = new Chart(ctx, {
        type: 'radar',
        data: {
            labels: SW_LABELS,
            datasets: [{
                label: 'Switch Count',
                data: SW_DATA,
                backgroundColor: 'rgba(255,176,32,0.15)',
                borderColor: '#ffb020',
                pointBackgroundColor: '#ffb020',
                pointBorderColor: '#10151d'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    angleLines: { color: 'rgba(255,255,255,0.08)' },
                    grid: { color: 'rgba(255,255,255,0.08)' },
                    pointLabels: { color: cssVar('--text-mid') },
                    ticks: { display: false }
                }
            },
            plugins: { legend: { position: 'bottom', labels: { color: cssVar('--text-mid') } } }
        }
    });
}
function renderAllCharts() {
    createServersChart();
    createFirewallsChart();
    createSwitchesChart();
}
function animateStatValues(container) {
    (container || document).querySelectorAll('.stat-value[data-raw]').forEach(el => {
        const raw = parseFloat(el.getAttribute('data-raw'));
        if (isNaN(raw)) return;
        const suffix = el.getAttribute('data-suffix') || '';
        const duration = 700;
        const start = performance.now();
        function step(now) {
            const p = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            const val = raw % 1 === 0 ? Math.round(raw * eased) : (raw * eased).toFixed(1);
            el.textContent = val.toLocaleString ? val.toLocaleString() + suffix : val + suffix;
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}
document.addEventListener('DOMContentLoaded', function () {
    renderAllCharts();
    animateStatValues(document);
    const s = document.getElementById('sidebar'), c = document.getElementById('main-content');
    function adj() { c.style.marginLeft = s && s.classList.contains('collapsed') ? '80px' : '250px'; }
    adj();
    if (s) new MutationObserver(adj).observe(s, { attributes: true, attributeFilter: ['class'] });
});
new MutationObserver(mutations => {
    for (const m of mutations) {
        if (m.type === 'attributes' && m.attributeName === 'data-theme') {
            if (window._themeChangeTimeout) clearTimeout(window._themeChangeTimeout);
            window._themeChangeTimeout = setTimeout(() => { renderAllCharts(); }, 80);
        }
    }
}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
</script>
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
