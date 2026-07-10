<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

require_once __DIR__ . '/db_config.php';

date_default_timezone_set('Asia/Karachi');

// NOTE: status/RTT now come from device_status_cache (the same fast-read
// table maps.php uses) instead of re-deriving "latest ping_logs row per
// device" with a window function on every page load. This also means a
// device with NO ping_logs rows yet (brand new, not yet swept by cron)
// still shows up correctly instead of defaulting to a misleading "down".
$query = "
    SELECT d.id, d.name, d.ip, d.type, d.model, d.city, d.sub_office, d.country,
           COALESCE(dsc.status, 'down') as status,
           dsc.rtt_avg,
           dsc.checked_at,
           dsc.last_status_change
    FROM devices d
    LEFT JOIN device_status_cache dsc ON d.id = dsc.device_id
    ORDER BY d.name
";
$result = mysqli_query($con, $query);
$device_count = mysqli_num_rows($result);

// Calculate summary statistics
mysqli_data_seek($result, 0);
$up = 0;
$total_rtt = 0;
$rtt_count = 0;
while($row = mysqli_fetch_assoc($result)) {
    if($row['status'] === 'up') {
        $up++;
        if($row['rtt_avg']) {
            $total_rtt += $row['rtt_avg'];
            $rtt_count++;
        }
    }
}
$down = $device_count - $up;
$avg_rtt = $rtt_count ? round($total_rtt/$rtt_count, 1) : 0;

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<title>Monitor - JanusNMS</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- Full-page refresh removed - replaced with in-place AJAX polling (see script below) -->

<!-- Local Bootstrap CSS -->
<link href="css/bootstrap.min.css" rel="stylesheet">
<!-- Local Font Awesome CSS -->
<link href="css/font-awesome/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">

<style>
body.loggedin { font-family: 'Segoe UI', sans-serif; }

/* Updated Content Margin to match the sidebar */
#main-content {
    margin-left: 250px;
    padding: 80px 25px 25px 25px;
    min-height: 100vh;
    transition: margin-left 0.3s ease;
}
.sidebar.collapsed ~ #main-content {
    margin-left: 80px;
}

/* Enhanced Summary Box Styles */
.summary-card {
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    transition: all 0.3s ease;
    border: 1px solid transparent;
    position: relative;
    overflow: hidden;
}

.summary-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: currentColor;
    opacity: 0.7;
}

.summary-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.summary-card .metric {
    font-size: 2.5rem;
    font-weight: 800;
    margin: 10px 0;
    line-height: 1;
    color: inherit;
}

.summary-card .label {
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.9;
    color: inherit;
}

.summary-card small {
    color: inherit;
    opacity: 0.8;
}

/* Color variants for summary cards */
.summary-total {
    background: linear-gradient(135deg, rgba(96, 165, 250, 0.15), var(--card-bg));
    color: #60a5fa;
    border-color: #60a5fa;
}

.summary-up {
    background: linear-gradient(135deg, rgba(22, 163, 74, 0.15), var(--card-bg));
    color: #16a34a;
    border-color: #16a34a;
}

.summary-down {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), var(--card-bg));
    color: #ef4444;
    border-color: #ef4444;
}

.summary-rtt {
    background: linear-gradient(135deg, rgba(168, 85, 247, 0.15), var(--card-bg));
    color: #a855f7;
    border-color: #a855f7;
}

/* Card Styles */
.card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.card-header {
    background: var(--header-bg);
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-weight: 600;
    padding: 15px 20px;
}

/* Fix text colors in table */
.table-dark {
    background: var(--card-bg);
    color: var(--text);
    border-radius: 8px;
    overflow: hidden;
}

.table-dark thead th {
    background: var(--header-bg);
    cursor: pointer;
    position: sticky;
    top: 0;
    z-index: 1;
    color: var(--text);
    border-bottom: 1px solid var(--border);
    padding: 15px 12px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.table-dark thead th:hover {
    background: var(--hover-bg);
    color: var(--accent);
}

.table-dark tbody tr {
    transition: all 0.2s ease;
    color: var(--text);
}

.table-dark tbody tr:not(.hidden) {
    background: var(--hover-bg);
    transform: translateX(2px);
}

.table-dark tbody td {
    color: var(--text) !important;
    border-color: var(--border) !important;
    padding: 12px;
    vertical-align: middle;
}

/* Fix specific text colors that were black */
.table-dark .text-muted {
    color: var(--text-muted) !important;
}

.table-dark .bg-secondary {
    background-color: var(--metric-total) !important;
    color: white !important;
}

/* Badge Styles */
.badge-up {
    background: #16a34a;
    color: #fff;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.badge-down {
    background: #ef4444;
    color: #fff;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.badge-pending {
    background: #94a3b8;
    color: #fff;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* Stale data indicator - shown next to Last Check when checked_at is old */
.stale-warning {
    color: #f59e0b;
    font-size: 0.75rem;
    margin-left: 6px;
    cursor: help;
}

/* Row flash animation when a row's data changes via polling or Check Now */
@keyframes rowUpdateFlash {
    0%   { background-color: rgba(0, 198, 255, 0.25); }
    100% { background-color: transparent; }
}
tr.row-flash {
    animation: rowUpdateFlash 1.2s ease-out;
}

/* Row currently being checked on-demand */
tr.row-checking {
    opacity: 0.6;
}
tr.row-checking td:first-child i.fa-network-wired {
    display: none;
}
tr.row-checking td:first-child .checking-spinner {
    display: inline-block !important;
}
.checking-spinner {
    display: none;
    margin-right: 8px;
}

/* Right-click context menu for device rows */
#device-ctx-menu {
    display: none;
    position: fixed;
    z-index: 5000;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
    min-width: 190px;
    padding: 4px 0;
    font-size: 14px;
}
#device-ctx-menu .ctx-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 16px;
    cursor: pointer;
    color: var(--text);
    transition: background .15s;
}
#device-ctx-menu .ctx-item:hover { background: rgba(0,198,255,.15); color: var(--accent); }
#device-ctx-menu .ctx-item.disabled { opacity: .5; cursor: not-allowed; pointer-events: none; }
#device-ctx-menu .ctx-divider { border-top: 1px solid var(--border); margin: 4px 0; }

/* Clickable row hint */
#deviceTable tbody tr { cursor: context-menu; }

/* Polling indicator */
#poll-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    color: var(--text-muted);
}
#poll-indicator .poll-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #16a34a;
    animation: pollPulse 2s ease-in-out infinite;
}
@keyframes pollPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

/* Tab Styles */
.tab-content {
    min-height: 500px;
    padding: 20px 0;
}

.nav-tabs {
    border-bottom: 2px solid var(--border);
    margin-bottom: 20px;
}

.nav-tabs .nav-link {
    color: var(--text-muted);
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 12px 24px;
    font-weight: 500;
    transition: all 0.3s ease;
    margin-bottom: -2px;
}

.nav-tabs .nav-link:hover {
    color: var(--accent);
    background: var(--hover-bg);
    border-bottom-color: var(--accent);
}

.nav-tabs .nav-link.active {
    color: var(--accent);
    background: transparent;
    border-bottom: 3px solid var(--accent);
}

/* Resource Content Styles */
#resourceContent {
    min-height: 400px;
    background: var(--card-bg);
    border-radius: 8px;
    border: 1px solid var(--border);
}

.loading-spinner {
    text-align: center;
    color: var(--text-muted);
    padding: 60px 20px;
}

.loading-spinner i {
    font-size: 2rem;
    margin-bottom: 10px;
    color: var(--accent);
}

/* Responsive Design */
@media (max-width: 768px) {
    #main-content {
        padding: 80px 15px 15px 15px;
    }

    .summary-card .metric {
        font-size: 2rem;
    }

    .nav-tabs .nav-link {
        padding: 10px 15px;
        font-size: 0.9rem;
    }
}

/* Filter button styles */
.filter-btn {
    background: var(--accent);
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    margin-left: 5px;
    cursor: pointer;
    font-size: 0.8rem;
}

.filter-btn:hover {
    opacity: 0.9;
}

.filter-btn.pakistan-only {
    background: var(--danger);
}

.filter-btn.other-countries {
    background: var(--success);
}

.hidden {
    display: none;
}
</style>

</head>

<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fa fa-chart-line me-2"></i> Network Monitor</h2>
        <div class="d-flex align-items-center gap-3">
            <span id="poll-indicator"><span class="poll-dot"></span> Live &bull; next update in <span id="poll-countdown">30</span>s</span>
            <div class="text-muted">
                <small id="lastUpdatedText">Last updated: <?php echo date('Y-m-d H:i:s'); ?></small>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="monitorTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="node-tab" data-bs-toggle="tab" data-bs-target="#nodeTab" type="button" role="tab" aria-controls="nodeTab" aria-selected="true">
            <i class="fa fa-server me-2"></i>Node Status
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="resource-tab" data-bs-toggle="tab" data-bs-target="#resourceTab" type="button" role="tab" aria-controls="resourceTab" aria-selected="false">
            <i class="fa fa-chart-bar me-2"></i>Resource Utilization
        </button>
      </li>
    </ul>

    <div class="tab-content" id="monitorTabsContent">
      <div class="tab-pane fade show active" id="nodeTab" role="tabpanel" aria-labelledby="node-tab">
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="summary-card summary-total">
                    <div class="label">Total Devices</div>
                    <div class="metric" id="stat-total"><?php echo $device_count; ?></div>
                    <small>All monitored devices</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card summary-up">
                    <div class="label">Devices Up</div>
                    <div class="metric" id="stat-up"><?php echo $up; ?></div>
                    <small id="stat-up-pct"><?php echo $device_count ? round(($up/$device_count)*100, 1) : 0; ?>% availability</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card summary-down">
                    <div class="label">Devices Down</div>
                    <div class="metric" id="stat-down"><?php echo $down; ?></div>
                    <small>Requires attention</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card summary-rtt">
                    <div class="label">Avg RTT</div>
                    <div class="metric" id="stat-rtt"><?php echo $avg_rtt; ?><small>ms</small></div>
                    <small>Average response time</small>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fa fa-list me-2"></i><b>Device Status</b>
                    <span class="badge bg-primary ms-2" id="device-count-badge"><?php echo $device_count; ?> Devices</span>
                </div>
                <div class="text-muted">
                    <small>Click column headers to sort &bull; Right-click a row for actions</small>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" id="deviceTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)" class="sortable">
                                Name <i class="fa fa-sort ms-1"></i>
                            </th>
                            <th onclick="sortTable(1)" class="sortable">
                                IP <i class="fa fa-sort ms-1"></i>
                            </th>
                            <th>Type/Model</th>
                            <th onclick="sortTable(3)" class="sortable">
                                Location <i class="fa fa-sort ms-1"></i>
                            </th>
                            <th onclick="sortTable(4)" class="sortable">
                                Country <i class="fa fa-sort ms-1"></i>
                                <button class="filter-btn" onclick="event.stopPropagation(); toggleFilter();">Filter</button>
                            </th>
                            <th onclick="sortTable(5, true)" class="sortable">
                                Status <i class="fa fa-sort ms-1"></i>
                            </th>
                            <th>RTT (ms)</th>
                            <th>Last Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        mysqli_data_seek($result, 0);
                        $now_ts = time();
                        $stale_threshold_seconds = 300; // 2x the 2-min cron interval + headroom
                        while($row = mysqli_fetch_assoc($result)):
                            $checked_at_ts = $row['checked_at'] ? strtotime($row['checked_at']) : null;
                            $never_checked = $checked_at_ts === null;
                            $is_stale = $never_checked || (($now_ts - $checked_at_ts) > $stale_threshold_seconds);

                            $status_class = $row['status'] == "up" ? 'badge-up' : 'badge-down';
                            $status_text = $row['status'] == "up" ? 'UP' : 'DOWN';
                            // A device that's never been checked shows a neutral "PENDING" badge
                            // instead of a misleading red DOWN - this is the device you JUST
                            // added in manage.php and the cron sweep hasn't reached yet.
                            if ($never_checked) {
                                $status_class = 'badge-pending';
                                $status_text = 'PENDING';
                            }
                            $rtt_display = $row['rtt_avg'] ? $row['rtt_avg'] . ' ms' : 'N/A';
                            $last_check = $row['checked_at'] ? date('M j, H:i', strtotime($row['checked_at'])) : 'Never';
                            $rtt_class = $row['rtt_avg'] ? ($row['rtt_avg'] > 100 ? 'text-warning' : 'text-success') : 'text-muted';

                            $type_display = !empty($row['type']) ? $row['type'] : 'Unknown';
                            $model_display = !empty($row['model']) ? $row['model'] : 'Unknown';
                        ?>
                        <tr data-device-id="<?= $row['id'] ?>" data-ip="<?= htmlspecialchars($row['ip']) ?>" data-name="<?= htmlspecialchars($row['name']) ?>">
                            <td>
                                <i class="fa fa-network-wired me-2 text-primary"></i>
                                <i class="fa fa-spinner fa-spin checking-spinner"></i>
                                <?php echo htmlspecialchars($row['name']); ?>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars($row['ip']); ?></code>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($type_display); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($model_display); ?></small>
                            </td>
                            <td>
                                <i class="fa fa-map-marker-alt me-1 text-muted"></i>
                                <?php echo htmlspecialchars($row['city'] . " " . $row['sub_office']); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($row['country']); ?>
                            </td>
                            <td class="cell-status">
                                <span class="<?php echo $status_class; ?>">
                                    <i class="fa fa-<?php echo $row['status'] == 'up' ? 'check' : ($never_checked ? 'clock' : 'times'); ?> me-1"></i>
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td class="cell-rtt">
                                <span class="<?php echo $rtt_class; ?>">
                                    <?php echo $rtt_display; ?>
                                </span>
                            </td>
                            <td class="cell-lastcheck">
                                <small class="text-muted"><?php echo $last_check; ?></small>
                                <?php if ($is_stale): ?>
                                <i class="fa fa-exclamation-triangle stale-warning" title="<?= $never_checked ? 'Not yet checked - will be picked up by the next scan, or right-click to Check Now' : 'Data may be stale - last check was over 5 minutes ago' ?>"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                        endwhile;
                        mysqli_close($con);
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
      </div>

      <div class="tab-pane fade" id="resourceTab" role="tabpanel" aria-labelledby="resource-tab">
        <div id="resourceContent">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Resource utilization data will load when you click this tab...</p>
            </div>
        </div>
      </div>
    </div>
</div>

<!-- Right-click context menu for device rows -->
<div id="device-ctx-menu">
    <div class="ctx-item" id="ctx-check-now">
        <i class="fas fa-bolt"></i> Check Now
    </div>
    <div class="ctx-divider"></div>
    <div class="ctx-item" id="ctx-view-report">
        <i class="fas fa-file-alt"></i> View Availability Report
    </div>
    <div class="ctx-item" id="ctx-edit-device">
        <i class="fas fa-edit"></i> Edit Device
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;" id="toastContainer"></div>

<script>
// ============================================================
// Table sort/filter (unchanged from before)
// ============================================================
let allRows = [];
let filterMode = 'all';
let lastSortedColumn = 0;
let sortDir = {};

function initializeTable() {
  const table = document.getElementById("deviceTable");
  allRows = [...table.rows].slice(1).map(row => row.cloneNode(true));
  updateFilterButton();
}

function sortTable(col, statusSort = false) {
  lastSortedColumn = col;
  sortDir[col] = sortDir[col] === "asc" ? "desc" : "asc";
  let rows = allRows.map(row => row.cloneNode(true));

  rows.sort((a, b) => {
    let x = a.cells[col].innerText.trim().toLowerCase();
    let y = b.cells[col].innerText.trim().toLowerCase();

    if (statusSort) {
      x = x.startsWith("up") ? 0 : (x.startsWith("pending") ? 1 : 2);
      y = y.startsWith("up") ? 0 : (y.startsWith("pending") ? 1 : 2);
      return sortDir[col] === "asc" ? x - y : y - x;
    }

    return sortDir[col] === "asc" ? x.localeCompare(y) : y.localeCompare(x);
  });

  applyFilter(rows);
  attachRowHandlers();
}

function applyFilter(rows) {
  const table = document.getElementById("deviceTable");
  const tbody = table.tBodies[0];
  while (tbody.firstChild) tbody.removeChild(tbody.firstChild);

  rows.forEach(row => {
    const country = row.cells[4].innerText.trim().toLowerCase();
    if (filterMode === 'all' ||
        (filterMode === 'pakistan' && country === "pakistan") ||
        (filterMode === 'other' && country !== "pakistan")) {
      tbody.appendChild(row);
    }
  });
}

function toggleFilter() {
  if (filterMode === 'all') filterMode = 'pakistan';
  else if (filterMode === 'pakistan') filterMode = 'other';
  else filterMode = 'all';
  updateFilterButton();
  const rows = allRows.map(row => row.cloneNode(true));
  applyFilter(rows);
  attachRowHandlers();
}

function updateFilterButton() {
  const filterBtn = document.querySelector('.filter-btn');
  if (filterMode === 'all') { filterBtn.textContent = "Filter"; filterBtn.className = "filter-btn"; }
  else if (filterMode === 'pakistan') { filterBtn.textContent = "Pakistan Only"; filterBtn.className = "filter-btn pakistan-only"; }
  else { filterBtn.textContent = "Other Countries"; filterBtn.className = "filter-btn other-countries"; }
}

// ============================================================
// Right-click context menu: Check Now / View Report / Edit
// ============================================================
const ctxMenu = document.getElementById('device-ctx-menu');
let ctxTargetRow = null;

function attachRowHandlers() {
    document.querySelectorAll('#deviceTable tbody tr').forEach(tr => {
        tr.addEventListener('contextmenu', onRowContextMenu);
    });
}

function onRowContextMenu(e) {
    e.preventDefault();
    ctxTargetRow = e.currentTarget;
    ctxMenu.style.display = 'block';
    let x = e.clientX, y = e.clientY;
    const mw = ctxMenu.offsetWidth || 190;
    const mh = ctxMenu.offsetHeight || 120;
    if (x + mw > window.innerWidth - 8) x = window.innerWidth - mw - 8;
    if (y + mh > window.innerHeight - 8) y = window.innerHeight - mh - 8;
    ctxMenu.style.left = x + 'px';
    ctxMenu.style.top = y + 'px';
}

document.addEventListener('click', e => {
    if (!ctxMenu.contains(e.target)) ctxMenu.style.display = 'none';
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') ctxMenu.style.display = 'none';
});

document.getElementById('ctx-check-now').addEventListener('click', () => {
    ctxMenu.style.display = 'none';
    if (!ctxTargetRow) return;
    checkDeviceNow(ctxTargetRow);
});

document.getElementById('ctx-view-report').addEventListener('click', () => {
    ctxMenu.style.display = 'none';
    if (!ctxTargetRow) return;
    const id = ctxTargetRow.dataset.deviceId;
    window.location.href = `avalibility.php?device_id=${id}&time_range=24h`;
});

document.getElementById('ctx-edit-device').addEventListener('click', () => {
    ctxMenu.style.display = 'none';
    window.location.href = `manage.php`;
});

// ============================================================
// Check Now - on-demand instant ping for a single device
// ============================================================
function checkDeviceNow(row) {
    const deviceId = row.dataset.deviceId;
    const deviceName = row.dataset.name;

    row.classList.add('row-checking');
    showToast(`Checking ${deviceName}...`, 'info', 0, `checking-${deviceId}`);

    const fd = new FormData();
    fd.append('device_id', deviceId);

    fetch('check_now.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            removeToast(`checking-${deviceId}`);
            row.classList.remove('row-checking');

            if (data.status !== 'success') {
                showToast(`Check failed for ${deviceName}: ${data.message}`, 'danger');
                return;
            }

            updateRowFromResult(row, data.result);
            flashRow(row);

            const r = data.result;
            const msg = r.status === 'up'
                ? `${deviceName} is UP (${r.rtt_avg !== null ? r.rtt_avg + ' ms' : 'no RTT'})`
                : `${deviceName} is DOWN`;
            showToast(msg, r.status === 'up' ? 'success' : 'danger');

            recalcSummaryFromTable();
        })
        .catch(err => {
            removeToast(`checking-${deviceId}`);
            row.classList.remove('row-checking');
            showToast(`Error checking ${deviceName}: ${err.message}`, 'danger');
        });
}

// ============================================================
// Apply a fresh status/rtt/checked_at result onto an existing row
// (shared by both Check Now and the periodic poll below)
// ============================================================
function updateRowFromResult(row, result) {
    const statusCell = row.querySelector('.cell-status');
    const rttCell = row.querySelector('.cell-rtt');
    const lastCheckCell = row.querySelector('.cell-lastcheck');

    const isUp = result.status === 'up';
    const neverChecked = !!result.never_checked;

    let badgeClass = isUp ? 'badge-up' : 'badge-down';
    let icon = isUp ? 'check' : 'times';
    let text = isUp ? 'UP' : 'DOWN';
    if (neverChecked) { badgeClass = 'badge-pending'; icon = 'clock'; text = 'PENDING'; }

    statusCell.innerHTML = `<span class="${badgeClass}"><i class="fa fa-${icon} me-1"></i>${text}</span>`;

    const rttClass = result.rtt_avg ? (result.rtt_avg > 100 ? 'text-warning' : 'text-success') : 'text-muted';
    const rttText = result.rtt_avg !== null && result.rtt_avg !== undefined ? `${result.rtt_avg} ms` : 'N/A';
    rttCell.innerHTML = `<span class="${rttClass}">${rttText}</span>`;

    const checkedAt = result.checked_at ? new Date(result.checked_at.replace(' ', 'T')) : null;
    const lastCheckText = checkedAt
        ? checkedAt.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
        : 'Never';

    let staleHtml = '';
    if (result.is_stale) {
        const title = neverChecked
            ? 'Not yet checked - will be picked up by the next scan, or right-click to Check Now'
            : 'Data may be stale - last check was over 5 minutes ago';
        staleHtml = ` <i class="fa fa-exclamation-triangle stale-warning" title="${title}"></i>`;
    }
    lastCheckCell.innerHTML = `<small class="text-muted">${lastCheckText}</small>${staleHtml}`;
}

function flashRow(row) {
    row.classList.remove('row-flash');
    // restart animation even if it was just played
    void row.offsetWidth;
    row.classList.add('row-flash');
}

// ============================================================
// Periodic polling - replaces the old full-page <meta refresh>
// ============================================================
const POLL_INTERVAL_SECONDS = 30;
let pollCountdown = POLL_INTERVAL_SECONDS;
let pollTimer = null;
let countdownTimer = null;

function pollStatus() {
    fetch('get_monitor_status.php')
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;

            let changedCount = 0;
            data.devices.forEach(d => {
                const row = document.querySelector(`#deviceTable tbody tr[data-device-id="${d.id}"]`);
                if (!row) return; // device might be filtered out of view right now

                const statusCell = row.querySelector('.cell-status');
                const currentText = statusCell.textContent.trim();
                const newIsUp = d.status === 'up';
                const changed = (newIsUp && !currentText.startsWith('UP')) ||
                                 (!newIsUp && currentText.startsWith('UP')) ||
                                 (d.never_checked && !currentText.startsWith('PENDING'));

                updateRowFromResult(row, d);
                if (changed) {
                    flashRow(row);
                    changedCount++;
                }
            });

            // Update summary cards
            document.getElementById('stat-total').textContent = data.summary.total;
            document.getElementById('stat-up').textContent = data.summary.up;
            document.getElementById('stat-down').textContent = data.summary.down;
            document.getElementById('stat-rtt').innerHTML = data.summary.avg_rtt + '<small>ms</small>';
            document.getElementById('stat-up-pct').textContent =
                (data.summary.total ? Math.round((data.summary.up / data.summary.total) * 1000) / 10 : 0) + '% availability';
            document.getElementById('device-count-badge').textContent = data.summary.total + ' Devices';
            document.getElementById('lastUpdatedText').textContent = 'Last updated: ' + data.server_time;

            if (changedCount > 0) {
                showToast(`${changedCount} device(s) changed status`, 'info');
            }

            // Keep the cached allRows array (used by sort/filter) in sync too,
            // otherwise sorting/filtering after a poll would show stale data.
            allRows = [...document.getElementById('deviceTable').rows].slice(1).map(r => r.cloneNode(true));
        })
        .catch(err => console.error('Poll error:', err));
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    if (countdownTimer) clearInterval(countdownTimer);

    pollCountdown = POLL_INTERVAL_SECONDS;
    countdownTimer = setInterval(() => {
        pollCountdown--;
        if (pollCountdown <= 0) pollCountdown = POLL_INTERVAL_SECONDS;
        const el = document.getElementById('poll-countdown');
        if (el) el.textContent = pollCountdown;
    }, 1000);

    pollTimer = setInterval(pollStatus, POLL_INTERVAL_SECONDS * 1000);
}

function recalcSummaryFromTable() {
    // Lightweight local recalculation right after a manual Check Now,
    // so the summary cards don't wait for the next 30s poll to reflect it.
    const rows = document.querySelectorAll('#deviceTable tbody tr');
    let up = 0, total = 0, rttSum = 0, rttCount = 0;
    rows.forEach(row => {
        total++;
        const statusText = row.querySelector('.cell-status').textContent.trim();
        if (statusText.startsWith('UP')) {
            up++;
            const rttText = row.querySelector('.cell-rtt').textContent.trim();
            const rttVal = parseFloat(rttText);
            if (!isNaN(rttVal)) { rttSum += rttVal; rttCount++; }
        }
    });
    document.getElementById('stat-total').textContent = total;
    document.getElementById('stat-up').textContent = up;
    document.getElementById('stat-down').textContent = total - up;
    document.getElementById('stat-rtt').innerHTML = (rttCount ? Math.round((rttSum/rttCount)*10)/10 : 0) + '<small>ms</small>';
    document.getElementById('stat-up-pct').textContent = (total ? Math.round((up/total)*1000)/10 : 0) + '% availability';
}

// ============================================================
// Toasts
// ============================================================
function showToast(msg, type = 'success', autohideMs = 4000, id = null) {
    const container = document.getElementById('toastContainer');
    const toastId = id || ('toast-' + Date.now() + '-' + Math.random().toString(36).slice(2));
    const el = document.createElement('div');
    el.id = toastId;
    el.className = `toast align-items-center text-bg-${type} border-0`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(el);
    const bsToast = new bootstrap.Toast(el, autohideMs > 0 ? { delay: autohideMs } : { autohide: false });
    bsToast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
function removeToast(id) {
    const el = document.getElementById(id);
    if (el) {
        const inst = bootstrap.Toast.getInstance(el);
        if (inst) inst.hide(); else el.remove();
    }
}

// ============================================================
// Resource Utilization tab (unchanged)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');

    function adjustContentMargin() {
        if (sidebar && mainContent) {
            mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
        }
    }
    adjustContentMargin();
    if (sidebar) {
        new MutationObserver(adjustContentMargin).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }

    const resourceTab = document.getElementById('resource-tab');
    if (resourceTab) {
        resourceTab.addEventListener('shown.bs.tab', function () {
            loadResourceUtilization();
        });
    }

    function loadResourceUtilization() {
        const resourceContent = document.getElementById('resourceContent');
        resourceContent.innerHTML = `
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading resource utilization data from resources.php...</p>
            </div>
        `;
        fetch('snmonitor.php')
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                let content = doc.querySelector('.content') ? doc.querySelector('.content').innerHTML : doc.body.innerHTML;
                resourceContent.innerHTML = content;
                resourceContent.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(t => new bootstrap.Tooltip(t));
            })
            .catch(error => {
                resourceContent.innerHTML = `
                    <div class="alert alert-danger m-4">
                        <h5><i class="fa fa-exclamation-triangle me-2"></i>Failed to Load Resource Data</h5>
                        <p class="mb-2">Could not load data from snmonitor.php</p>
                        <p class="mb-3"><small>Error: ${error.message}</small></p>
                        <button class="btn btn-sm btn-outline-danger" onclick="loadResourceUtilization()">
                            <i class="fa fa-refresh me-1"></i> Try Again
                        </button>
                    </div>
                `;
            });
    }
    window.loadResourceUtilization = loadResourceUtilization;

    // Init everything
    initializeTable();
    attachRowHandlers();
    sortTable(0);
    startPolling();
});

window.sortTable = sortTable;
window.toggleFilter = toggleFilter;

// Theme change observer (unchanged)
new MutationObserver(mutations => {
    for(const mutation of mutations) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
            console.log('Theme changed - monitor page');
        }
    }
}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
</script>

<!-- Local Bootstrap JS Bundle -->
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
