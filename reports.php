<?php
require_once __DIR__ . '/db_config.php';
// reports.php - SNOC Security Operations Dashboard
// VISUAL REDESIGN: Tactical HUD console theme (backend logic unchanged)
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    exit('DB Error: ' . mysqli_connect_error());
}

date_default_timezone_set('Asia/Karachi');

$report_type = $_GET['type'] ?? 'traffic';
$time_range = $_GET['range'] ?? '24h';
$device_filter = $_GET['device'] ?? '';

// Timezone-safe calculations computed by MySQL to align queries with DB server time
$res = mysqli_query($con, "
    SELECT 
        NOW() as end_time,
        DATE_SUB(NOW(), INTERVAL 15 MINUTE) as start_15m,
        DATE_SUB(NOW(), INTERVAL 1 HOUR) as start_1h,
        DATE_SUB(NOW(), INTERVAL 6 HOUR) as start_6h,
        DATE_SUB(NOW(), INTERVAL 24 HOUR) as start_24h,
        DATE_SUB(NOW(), INTERVAL 7 DAY) as start_7d,
        DATE_SUB(NOW(), INTERVAL 30 DAY) as start_30d
");
$row = mysqli_fetch_assoc($res);
$end_time = $row['end_time'];

switch (strtolower($time_range)) {
    case '15m': $start_time = $row['start_15m']; break;
    case '1h':  $start_time = $row['start_1h']; break;
    case '6h':  $start_time = $row['start_6h']; break;
    case '24h': $start_time = $row['start_24h']; break;
    case '7d':  $start_time = $row['start_7d']; break;
    case '30d': $start_time = $row['start_30d']; break;
    default:    $start_time = $row['start_24h'];
}

$devices = [];
$res = mysqli_query($con, "SELECT DISTINCT source_ip FROM syslog_entries ORDER BY source_ip");
while ($r = mysqli_fetch_assoc($res)) {
    $devices[] = $r['source_ip'];
}

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SNOC :: Security Analytics Console</title>
<link href="css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/font-awesome/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/theme.css?v=<?= time() ?>">
<link rel="stylesheet" href="/css/pages/reports.css?v=<?= time() ?>">
</head>
<body class="loggedin">

<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">

    <!-- HEADER -->
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-lg-6 col-md-12 mb-3 mb-lg-0">
                <div class="eyebrow"><span class="led"></span> SNOC / SECURITY OPERATIONS &mdash; LIVE FEED</div>
                <h1 class="page-title"><span class="accent">&gt;</span> Security Analytics Console</h1>
                <p class="page-subtitle">Network intelligence, threat detection and compliance telemetry across all monitored assets</p>
            </div>
            <div class="col-lg-6 col-md-12 d-flex justify-content-lg-end justify-content-start gap-2 flex-wrap align-items-center">
                <button class="btn btn-success" onclick="exportReport()">
                    <i data-lucide="download" class="icon-lucide"></i> Export
                </button>
                <button class="btn btn-outline-primary" onclick="scheduledReports()">
                    <i data-lucide="clock" class="icon-lucide"></i> Schedule
                </button>
                <button class="btn btn-primary" onclick="refreshReport()">
                    <i data-lucide="refresh-ccw" class="icon-lucide"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- DASHBOARD SWITCHER (saved-view style strip, single live dashboard today) -->
    <div class="dash-strip">
        <button class="dash-tab active" type="button">SNOC Security Dashboard <i data-lucide="chevron-down" class="icon-lucide"></i></button>
        <button class="dash-add" type="button" title="Custom dashboards are on the roadmap" onclick="alert('Custom saved dashboards aren\'t wired up yet — this build always shows the live SNOC view. Ping your admin if you want multi-dashboard support added.')">
            <i data-lucide="plus" class="icon-lucide"></i>
        </button>
    </div>

    <!-- TIME RANGE + DEVICE FILTER -->
    <div class="filters-panel">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label"><i data-lucide="clock" class="icon-lucide"></i> Time Range</label>
                <div class="range-strip">
                    <div class="range-pill-group" id="rangePillGroup">
                        <button type="button" class="range-pill" data-range="15m">15M</button>
                        <button type="button" class="range-pill" data-range="1h">1H</button>
                        <button type="button" class="range-pill" data-range="6h">6H</button>
                        <button type="button" class="range-pill" data-range="24h">1D</button>
                        <button type="button" class="range-pill" data-range="7d">7D</button>
                        <button type="button" class="range-pill" data-range="30d">30D</button>
                    </div>
                    <button type="button" class="refresh-btn" id="refreshBtn" onclick="refreshReport()" title="Refresh now">
                        <i data-lucide="refresh-ccw" class="icon-lucide"></i>
                    </button>
                    <span class="period-readout" style="font-size:0.78rem;">
                        <?= date('M d, H:i', strtotime($start_time)) ?> <span class="arrow">&rarr;</span> <?= date('M d, H:i', strtotime($end_time)) ?>
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i data-lucide="server" class="icon-lucide"></i> Device Filter</label>
                <div class="dropdown w-100">
                    <button class="form-select text-start" type="button" id="deviceFilterBtn" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= $device_filter ? htmlspecialchars($device_filter) : 'All Devices' ?>
                    </button>
                    <ul class="dropdown-menu w-100" aria-labelledby="deviceFilterBtn">
                        <li><a class="dropdown-item <?= empty($device_filter) ? 'active' : '' ?>" href="#" onclick="event.preventDefault(); setDeviceFilter('')">All Devices</a></li>
                        <?php foreach($devices as $dev): ?>
                            <li><a class="dropdown-item <?= $device_filter==$dev ? 'active' : '' ?>" href="#" onclick="event.preventDefault(); setDeviceFilter('<?= htmlspecialchars($dev) ?>')">
                                <?= htmlspecialchars($dev) ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" id="deviceFilter" value="<?= htmlspecialchars($device_filter) ?>">
            </div>
            <div class="col-md-2 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end align-items-center" style="height: 38px;">
                <span style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-lo); text-transform:uppercase; letter-spacing:0.08em; display:inline-flex; align-items:center; gap:5px;">
                    <span style="width: 6px; height: 6px; background-color: var(--green); border-radius: 50%; display: inline-block;"></span> Manual Refresh
                </span>
            </div>
        </div>
    </div>

    <!-- CATEGORY TABS (dashboard groups) -->
    <div class="category-tabs" id="categoryTabs"></div>

    <!-- REPORT CHIPS (widgets within active category) -->
    <div class="report-chips" id="reportChips"></div>

    <!-- CONTENT -->
    <div id="reportContent">
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted" style="font-family: var(--font-mono); font-size:0.8rem; letter-spacing:0.05em;">LOADING ANALYTICS&hellip;</p>
        </div>
    </div>

</div>
</div>

<!-- DRILL-DOWN MODAL -->
<div class="modal fade" id="drillDownModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="drillDownModalLabel"><i data-lucide="zoom-in" class="icon-lucide"></i> Detailed Analysis</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="drillDownContent">
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Loading details...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal"><i data-lucide="x" class="icon-lucide"></i> Close</button>
        <button type="button" class="btn btn-primary" onclick="exportDrillDown()"><i data-lucide="download" class="icon-lucide"></i> Export</button>
      </div>
    </div>
  </div>
</div>

<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/chart.js"></script>
<script>
// GLOBAL VARIABLES
let currentType = '<?= $report_type ?>';
let currentRange = '<?= $time_range ?>';
let currentDevice = '<?= $device_filter ?>';
const startTime = '<?= $start_time ?>';
const endTime = '<?= $end_time ?>';

// Chart.js global HUD defaults
if (window.Chart) {
    Chart.defaults.color = '#8592a3';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
    Chart.defaults.font.family = "'JetBrains Mono', monospace";
    Chart.defaults.font.size = 11;
}

// CRITICAL: Chart instances registry to prevent duplicates
const chartInstances = {};

function destroyChart(chartId) {
    if (chartInstances[chartId]) {
        chartInstances[chartId].destroy();
        delete chartInstances[chartId];
    }
}

function createChart(canvasId, config) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (canvas) {
        chartInstances[canvasId] = new Chart(canvas, config);
    }
}

// Count-up animation for stat readouts
function animateStatValues(container) {
    (container || document).querySelectorAll('.stat-value[data-raw]').forEach(el => {
        const raw = parseFloat(el.getAttribute('data-raw'));
        if (isNaN(raw)) return;
        const suffix = el.getAttribute('data-suffix') || '';
        const duration = 600;
        const start = performance.now();
        function step(now) {
            const p = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(raw * eased).toLocaleString() + suffix;
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}

// -----------------------------------------------------------------
// REPORT CATALOG — drives the two-tier nav (category tabs -> chips)
// -----------------------------------------------------------------
const REPORT_CATALOG = {
    network: { label: 'Network Analysis', icon: 'fa-network-wired', reports: [
        { type: 'traffic', label: 'Traffic Analysis', icon: 'fa-exchange-alt' },
        { type: 'applications', label: 'Applications', icon: 'fa-layer-group' },
        { type: 'bandwidth', label: 'Bandwidth', icon: 'fa-chart-area' },
    ]},
    security: { label: 'Security & Threats', icon: 'fa-shield-virus', reports: [
        { type: 'security_analysis', label: 'MITRE ATT&CK', icon: 'fa-shield-alt' },
        { type: 'threat_hunting', label: 'Threat Hunting', icon: 'fa-crosshairs' },
        { type: 'bruteforce', label: 'Brute Force', icon: 'fa-user-lock' },
        { type: 'anomaly', label: 'Anomaly Detection', icon: 'fa-exclamation-triangle' },
    ]},
    access: { label: 'Access & Authentication', icon: 'fa-user-shield', reports: [
        { type: 'remote_access', label: 'Remote Access', icon: 'fa-door-open' },
        { type: 'user_activity', label: 'User Activity', icon: 'fa-users' },
    ]},
    attacks: { label: 'Attack Detection', icon: 'fa-bomb', reports: [
        { type: 'dns_attacks', label: 'DNS Attacks', icon: 'fa-server' },
        { type: 'ddos', label: 'DDoS', icon: 'fa-radiation' },
        { type: 'malware', label: 'Malware', icon: 'fa-bug' },
    ]},
    config: { label: 'Configuration & Compliance', icon: 'fa-cog', reports: [
        { type: 'config_changes', label: 'Config Changes', icon: 'fa-wrench' },
        { type: 'compliance', label: 'Compliance', icon: 'fa-clipboard-check' },
    ]},
    assets: { label: 'Asset & Inventory', icon: 'fa-sitemap', reports: [
        { type: 'asset_discovery', label: 'Asset Discovery', icon: 'fa-radar' },
        { type: 'vulnerability', label: 'Vulnerabilities', icon: 'fa-shield-alt' },
        { type: 'endpoint_activity', label: 'Endpoint Activity', icon: 'fa-desktop' },
    ]},
};

function categoryOf(type) {
    for (const key in REPORT_CATALOG) {
        if (REPORT_CATALOG[key].reports.some(r => r.type === type)) return key;
    }
    return 'network';
}

let currentCategory = categoryOf(currentType);

function renderCategoryTabs() {
    const el = document.getElementById('categoryTabs');
    el.innerHTML = Object.keys(REPORT_CATALOG).map(key => {
        const cat = REPORT_CATALOG[key];
        return `<button type="button" class="category-tab ${key === currentCategory ? 'active' : ''}" data-cat="${key}">
                    <i class="fas ${cat.icon}"></i> ${cat.label}
                </button>`;
    }).join('');
    el.querySelectorAll('.category-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            currentCategory = btn.getAttribute('data-cat');
            renderCategoryTabs();
            const firstReport = REPORT_CATALOG[currentCategory].reports[0];
            selectReport(firstReport.type);
        });
    });
}

function renderReportChips() {
    const el = document.getElementById('reportChips');
    el.innerHTML = REPORT_CATALOG[currentCategory].reports.map(r => {
        return `<button type="button" class="report-chip ${r.type === currentType ? 'active' : ''}" data-type="${r.type}">
                    <i class="fas ${r.icon}"></i> ${r.label}
                </button>`;
    }).join('');
    el.querySelectorAll('.report-chip').forEach(btn => {
        btn.addEventListener('click', () => selectReport(btn.getAttribute('data-type')));
    });
}

function selectReport(type) {
    currentType = type;
    currentCategory = categoryOf(type);
    renderCategoryTabs();
    renderReportChips();
    loadReportContent(currentType);
    const url = new URL(window.location);
    url.searchParams.set('type', currentType);
    window.history.pushState({}, '', url);
}

function renderRangePills() {
    document.querySelectorAll('.range-pill').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-range') === currentRange);
        btn.addEventListener('click', () => {
            currentRange = btn.getAttribute('data-range');
            applyFilters();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Auto-refresh every 5 minutes
    setInterval(() => {
        const btn = document.getElementById('refreshBtn');
        if (btn) btn.classList.add('spinning');
        loadReportContent(currentType);
        setTimeout(() => { if (btn) btn.classList.remove('spinning'); }, 800);
    }, 5 * 60 * 1000);
    renderCategoryTabs();
    renderReportChips();
    renderRangePills();
    loadReportContent(currentType);
    // Auto-refresh the active report every 5 minutes (matches the agent check-in cadence)
    setInterval(() => {
        const btn = document.getElementById('refreshBtn');
        if (btn) btn.classList.add('spinning');
        loadReportContent(currentType);
        setTimeout(() => { if (btn) btn.classList.remove('spinning'); }, 800);
    }, 5 * 60 * 1000);
});

function setDeviceFilter(val) {
    document.getElementById('deviceFilter').value = val;
    applyFilters();
}

function applyFilters() {
    const device = document.getElementById('deviceFilter').value;
    const url = new URL(window.location);
    url.searchParams.set('type', currentType);
    url.searchParams.set('range', currentRange);
    url.searchParams.set('device', device);
    window.location.href = url.toString();
}

async function loadReportContent(type, extraParams = '') {
    const contentDiv = document.getElementById('reportContent');
    contentDiv.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted" style="font-family: var(--font-mono); font-size:0.8rem; letter-spacing:0.05em;">LOADING ${type.replace(/_/g, ' ').toUpperCase()}&hellip;</p>
        </div>
    `;

    Object.keys(chartInstances).forEach(chartId => destroyChart(chartId));

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            range: currentRange
        });

        if (extraParams) {
            const extra = new URLSearchParams(extraParams);
            extra.forEach((value, key) => params.append(key, value));
        }

        const url = `ss/${type}.php?${params.toString()}`;
        console.log('Loading:', url);

        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`Server error: ${response.status}`);
        }

        const html = await response.text();
        contentDiv.innerHTML = html;
        animateStatValues(contentDiv);

        const scripts = contentDiv.querySelectorAll('script');
        scripts.forEach(script => {
            const newScript = document.createElement('script');
            newScript.textContent = script.textContent;
            document.head.appendChild(newScript);
            document.head.removeChild(newScript);
        });

        if (window.lucide) {
            window.lucide.createIcons();
        }

    } catch (error) {
        console.error('Load error:', error);
        contentDiv.innerHTML = `
            <div class="alert alert-danger">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                <strong>Error loading report:</strong> ${error.message}
            </div>
        `;
    }
}

async function drillDownIP(ip) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('drillDownModal'));
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i data-lucide="network" class="icon-lucide"></i> IP Analysis: ${ip}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Analyzing ${ip}...</p>
        </div>
    `;

    modal.show();
    Object.keys(chartInstances).filter(id => id.startsWith('modal-')).forEach(destroyChart);

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            drilldown_ip: ip
        });

        const response = await fetch(`ss/ip_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);

        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });

    } catch (error) {
        console.error('Drill-down error:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                Error loading IP details: ${error.message}
            </div>
        `;
    }
}

async function drillDownTechnique(techniqueId, techniqueName) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('drillDownModal'));
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i data-lucide="shield-virus" class="icon-lucide"></i> ${techniqueId}: ${techniqueName}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Loading technique details...</p>
        </div>
    `;

    modal.show();
    Object.keys(chartInstances).filter(id => id.startsWith('modal-')).forEach(destroyChart);

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            technique: techniqueId
        });

        const response = await fetch(`ss/mitre_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);

        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });

    } catch (error) {
        console.error('Drill-down error:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <i data-lucide="triangle-alert" class="icon-lucide"></i>
                Error: ${error.message}
            </div>
        `;
    }
}

function refreshReport() { loadReportContent(currentType); }
function exportReport() { window.print(); }

function exportDrillDown() {
    const content = document.getElementById('drillDownContent');
    const title = document.getElementById('drillDownModalLabel').textContent;
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>' + title + '</title>');
    printWindow.document.write(`<link rel="stylesheet" href="/css/theme.css">
<link rel="stylesheet" href="/css/pages/reports.css">`);
    printWindow.document.write('</head><body><h2>' + title + '</h2>');
    printWindow.document.write(content.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

function scheduledReports() {
    alert('Scheduled Reports:\n\nConfigure automated report generation and delivery.\n\nContact your administrator to enable this feature.');
}

document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey) {
        if (e.key === 'r') { e.preventDefault(); refreshReport(); }
        else if (e.key === 'p') { e.preventDefault(); exportReport(); }
    }
});

window.drillDownIP = drillDownIP;
window.drillDownTechnique = drillDownTechnique;
window.loadReportContent = loadReportContent;
window.createChart = createChart;
window.destroyChart = destroyChart;
window.animateStatValues = animateStatValues;

// Safety net: force-clean any stuck backdrop / body scroll-lock after this
// shared modal closes, no matter which function opened it.
document.getElementById('drillDownModal').addEventListener('hidden.bs.modal', () => {
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
});
</script>
</body>
</html>

