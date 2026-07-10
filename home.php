<?php
session_start();
require_once __DIR__ . '/db_config.php';
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

// === Original NOC Summary Stats (Network Devices) ===
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
$modern_palette = ['Hypervisor' => '#16a34a', 'Virtual Machine' => '#2563eb', 'Container' => '#f97316', 'Other' => '#6b7280', 'Unknown' => '#ef4444'];
while ($row = mysqli_fetch_assoc($server_result)) {
    $lbl = $row['model'] ?: 'Unknown';
    $server_labels[] = $lbl;
    $server_data[] = intval($row['cnt']);
    $server_colors[] = $modern_palette[$lbl] ?? '#6b7280';
}

// --- Firewalls Chart ---
$fw_query = "SELECT COALESCE(NULLIF(model,''),'Unknown') AS model, COUNT(*) AS cnt FROM devices WHERE type = 'firewall' GROUP BY model ORDER BY cnt DESC";
$fw_result = mysqli_query($con, $fw_query);
$fw_labels = $fw_data = $fw_colors = [];
$fw_palette = ['#ef4444', '#fb923c', '#8b5cf6', '#06b6d4', '#fbbf24', '#3b82f6'];
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
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Dashboard</title>
<!-- Local Bootstrap CSS -->
<link href="css/bootstrap.min.css" rel="stylesheet">
<!-- Local Font Awesome -->
<link rel="stylesheet" href="css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="css/theme.css">
<!-- Local Chart.js -->
<script src="js/chart.js"></script>
<script src="js/chartjs-plugin-datalabels.min.js"></script>
<style>
.chart-container { position: relative; height: 300px; width: 100%; }
.card-header.bg-danger, .card-header.bg-warning { color: #fff; }
.metric { font-size: 2rem; font-weight: bold; }
</style>
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">
<!-- Header -->
<div class="row mb-4">
    <div class="col-12">
        <h2>Joint Analytics for Networks & Unifed Security</h2>
        <p class="lead">Welcome, <strong><?= htmlspecialchars($_SESSION['name']) ?></strong> — Core monitoring active.</p>
    </div>
</div>

<!-- NOC Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="metric metric-total" style="color: purple;"><?= $total ?></div>
            <div class="label">Total Devices</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="metric metric-up" style="color: green;"><?= $up ?></div>
            <div class="label">Up</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="metric metric-down" style="color: red;"><?= $down ?></div>
            <div class="label">Down</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3">
            <div class="metric metric-uptime" style="color: blue;"><?= $uptime ?>%</div>
            <div class="label">Network Uptime</div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-4 col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Servers by Type</h5></div>
            <div class="card-body"><div class="chart-container"><canvas id="serversChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card">
            <div class="card-header bg-danger"><h5 class="mb-0">Firewalls by Model</h5></div>
            <div class="card-body"><div class="chart-container" style="height:320px"><canvas id="firewallsChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card">
            <div class="card-header bg-warning"><h5 class="mb-0">Switches by Model</h5></div>
            <div class="card-body"><div class="chart-container"><canvas id="switchesChart"></canvas></div></div>
        </div>
    </div>
</div>

<!-- SOC/NOC Incident Overview -->
<div class="row mb-4">
    <div class="col-12"><h4 class="mb-3">SOC/NOC Incident Overview</h4></div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-primary text-center p-3">
            <i class="fas fa-tasks fa-2x mb-2 text-primary"></i>
            <div class="metric metric-up"><?= $my_tasks_count ?></div>
            <div class="label">My Assigned Tasks</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-info text-center p-3">
            <i class="fas fa-ticket-alt fa-2x mb-2 text-info"></i>
            <div class="metric metric-total"><?= $open_tickets ?></div>
            <div class="label">Open Tickets</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger text-center p-3">
            <i class="fas fa-exclamation-triangle fa-2x mb-2 text-danger"></i>
            <div class="metric metric-down"><?= $overdue_tickets ?></div>
            <div class="label">Overdue Tickets</div>
        </div>
    </div>
</div>

<!-- System Status Info -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card p-4">
            <h5>System Status</h5>
            <ul class="list-unstyled mt-3">
                <li>ICMP, SNMP, Traffic Flows: <strong>Running (every 2 min)</strong></li>
                <li>Device Inventory: <strong><?= $total ?> registered</strong></li>
                <li>Syslog Collection: <strong>Active</strong></li>
                <li>SOC/NOC Incident Module: <strong>Active (Register, Assign, Track Tickets)</strong></li>
            </ul>
        </div>
    </div>
</div>
</div>

<script>
function cssVar(name, fallback = '') {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
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
    const ctx = document.getElementById('serversChart').getContext('2d');
    if(serversChart) serversChart.destroy();
    serversChart = new Chart(ctx,{
        type:'doughnut',
        data:{labels:SERVER_LABELS,datasets:[{data:SERVER_DATA,backgroundColor:SERVER_COLORS,borderColor:cssVar('--bg'),borderWidth:2}]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            plugins:{
                legend:{position:'bottom',labels:{color:cssVar('--chart-text')}},
                datalabels:{color:cssVar('--chart-text'),font:{weight:'bold'},formatter:v=>v}
            }
        },
        plugins:[ChartDataLabels]
    });
}

function createFirewallsChart(){
    const ctx=document.getElementById('firewallsChart').getContext('2d');
    if(firewallsChart) firewallsChart.destroy();
    firewallsChart=new Chart(ctx,{
        type:'bar',
        data:{labels:FW_LABELS,datasets:[{label:'Count',data:FW_DATA,backgroundColor:FW_COLORS,borderColor:FW_COLORS.map(c=>c),borderWidth:1}]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            plugins:{legend:{display:false}},
            scales:{y:{beginAtZero:true,ticks:{color:cssVar('--chart-text')}},x:{ticks:{color:cssVar('--chart-text')}}}
        }
    });
}

function createSwitchesChart(){
    const ctx=document.getElementById('switchesChart').getContext('2d');
    if(switchesChart) switchesChart.destroy();
    switchesChart=new Chart(ctx,{
        type:'radar',
        data:{labels:SW_LABELS,datasets:[{label:'Switch Count',data:SW_DATA,backgroundColor:'rgba(249,115,22,0.15)',borderColor:'#f59e0b',pointBackgroundColor:'#fff',pointBorderColor:'#f59e0b'}]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            scales:{r:{angleLines:{color:cssVar('--chart-angle')},grid:{color:cssVar('--chart-grid')},pointLabels:{color:cssVar('--chart-text')}}},
            plugins:{legend:{position:'bottom',labels:{color:cssVar('--chart-text')}}}
        }
    });
}

function renderAllCharts(){
    createServersChart();
    createFirewallsChart();
    createSwitchesChart();
}

document.addEventListener('DOMContentLoaded', function(){
    renderAllCharts();
    const s=document.getElementById('sidebar'), c=document.getElementById('main-content');
    function adj(){c.style.marginLeft = s && s.classList.contains('collapsed') ? '80px':'250px';}
    adj();
    if(s) new MutationObserver(adj).observe(s,{attributes:true,attributeFilter:['class']});
});

new MutationObserver(mutations=>{
    for(const m of mutations){
        if(m.type==='attributes' && m.attributeName==='data-theme'){
            if(window._themeChangeTimeout) clearTimeout(window._themeChangeTimeout);
            window._themeChangeTimeout=setTimeout(()=>{renderAllCharts();},80);
        }
    }
}).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
</script>

<!-- Local Bootstrap JS -->
<script src="js/bootstrap.bundle.min.js"></script>

</body>
</html>
