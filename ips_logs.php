<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth_check.php';
// ips_logs.php - FortiGate IPS & Application Control Logs
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die('DB connection failed');
}

$theme = $_COOKIE['theme'] ?? 'dark';

// Check if this is an AJAX request
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// Parameters
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 100;
$offset = ($page - 1) * $per_page;
$device_ip = trim($_GET['device'] ?? '');
$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? ''; // ips, app
$action_filter = $_GET['action'] ?? ''; // detected, blocked, allowed
$severity_filter = $_GET['severity'] ?? ''; // critical, high, medium, low

// Fetch available devices
$devices = [];
$res = mysqli_query($con, "
    SELECT DISTINCT se.source_ip, ss.appliance_type 
    FROM syslog_entries se
    JOIN syslog_sources ss ON se.source_id = ss.id
    WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
      AND (se.message LIKE '%type=ips%' OR se.message LIKE '%type=app-ctrl%' OR se.message LIKE '%type=\"ips\"%' OR se.message LIKE '%type=\"app-ctrl\"%')
    ORDER BY se.source_ip
");
while ($r = mysqli_fetch_assoc($res)) {
    $devices[] = $r;
}

// Build WHERE clause for IPS/App Control logs
$where = "WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
          AND (se.message LIKE '%type=ips%' OR se.message LIKE '%type=app-ctrl%' OR se.message LIKE '%type=\"ips\"%' OR se.message LIKE '%type=\"app-ctrl\"%')";

$params = [];
$types = '';

if ($device_ip !== '') {
    $where .= " AND se.source_ip = ?";
    $params[] = $device_ip;
    $types .= 's';
}

if ($type_filter === 'ips') {
    $where .= " AND se.message LIKE '%type=ips%'";
} elseif ($type_filter === 'app') {
    $where .= " AND se.message LIKE '%type=app-ctrl%'";
}

if ($action_filter !== '') {
    $where .= " AND se.message LIKE ?";
    $params[] = '%action=' . $action_filter . '%';
    $types .= 's';
}

if ($severity_filter !== '') {
    $where .= " AND se.message LIKE ?";
    $params[] = '%severity=' . $severity_filter . '%';
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (
        se.message LIKE ? OR se.message LIKE ? OR se.message LIKE ? OR
        se.message LIKE ? OR se.message LIKE ?
    )";
    $search_like = '%' . $search . '%';
    $params = array_merge($params, [
        '%srcip=' . $search . '%', '%dstip=' . $search . '%',
        '%attack=' . $search . '%', '%app=' . $search . '%',
        $search_like
    ]);
    $types .= 'sssss';
}

$order = "ORDER BY se.received_at DESC";

// Count total
$count_query = "SELECT COUNT(*) FROM syslog_entries se JOIN syslog_sources ss ON se.source_id = ss.id $where";
$count_stmt = $con->prepare($count_query);
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_stmt->bind_result($total);
$count_stmt->fetch();
$count_stmt->close();
$total_pages = ceil($total / $per_page);

// Main query
$query = "
    SELECT se.id AS log_id, se.received_at, se.message, se.source_ip
    FROM syslog_entries se
    JOIN syslog_sources ss ON se.source_id = ss.id
    $where
    $order
    LIMIT ? OFFSET ?
";

$final_params = array_merge($params, [$per_page, $offset]);
$final_types = $types . 'ii';

$stmt = $con->prepare($query);
$stmt->bind_param($final_types, ...$final_params);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $msg = $row['message'];
    
    $parsed = [];
    if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
            $parsed[$m[1]] = $value;
        }
    }
    
    $logType = 'unknown';
    if (stripos($msg, 'type=ips') !== false) {
        $logType = 'ips';
    } elseif (stripos($msg, 'type=app-ctrl') !== false) {
        $logType = 'app-ctrl';
    }
    
    $logs[] = [
        'log_id' => $row['log_id'],
        'time' => $row['received_at'],
        'type' => $logType,
        'severity' => $parsed['severity'] ?? '-',
        'srcip' => $parsed['srcip'] ?? '-',
        'dstip' => $parsed['dstip'] ?? '-',
        'attack' => $parsed['attack'] ?? $parsed['app'] ?? '-',
        'action' => strtolower($parsed['action'] ?? 'detected'),
        'devname' => $parsed['devname'] ?? $row['source_ip'],
        'source_ip' => $row['source_ip'],
        'full_parsed' => $parsed,
        'raw' => htmlspecialchars($msg)
    ];
}
$stmt->close();
mysqli_close($con);

// If AJAX, return JSON
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'logs' => $logs,
        'total' => $total,
        'page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>IPS & App Control Logs</title>
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<link rel="stylesheet" href="/css/pages/ips_logs.css">
</head>
<body class="loggedin">
<?php include __DIR__ . '/topbar.php'; ?>
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="update-indicator" id="updateIndicator">
    <i data-lucide="refresh-ccw" class="icon-lucide -alt fa-spin"></i> Refreshing...
</div>

<div id="main-content">
    <div class="container-fluid">
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h2><i data-lucide="shield" class="icon-lucide"></i> IPS & Application Control Logs</h2>
                <p class="page-subtitle mb-0">Real-time intrusion prevention and app control events</p>
            </div>
<div class="col-md-6 text-end">
    <a href="ips_logs.php" class="btn btn-sm btn-outline-light <?= $type_filter===''?'active':'' ?>">All</a>
    <a href="ips_logs.php?type=ips" class="btn btn-sm btn-warning <?= $type_filter==='ips'?'active':'' ?>">IPS Only</a>
    <a href="ips_logs.php?type=app" class="btn btn-sm btn-info <?= $type_filter==='app'?'active':'' ?>">App Control</a>
    <a href="ips_logs.php?action=blocked" class="btn btn-sm btn-danger <?= $action_filter==='blocked'?'active':'' ?>">Blocked</a>
    <a href="ips_logs.php?action=detected" class="btn btn-sm btn-warning <?= $action_filter==='detected'?'active':'' ?>">Detected</a>
    <a href="fflow.php" class="btn btn-sm btn-secondary">Traffic Logs</a>
</div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4 g-3">
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stat-icon text-primary"><i data-lucide="list" class="icon-lucide"></i></div>
                    <div>
                        <div class="stat-value" id="totalLogs"><?= $total ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stat-icon text-danger"><i data-lucide="ban" class="icon-lucide"></i></div>
                    <div>
                        <div class="stat-value text-danger" id="blockedCount">-</div>
                        <div class="stat-label">Blocked</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stat-icon text-warning"><i data-lucide="triangle-alert" class="icon-lucide"></i></div>
                    <div>
                        <div class="stat-value text-warning" id="detectedCount">-</div>
                        <div class="stat-label">Detected</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stat-icon text-danger"><i data-lucide="skull-crossbones" class="icon-lucide"></i></div>
                    <div>
                        <div class="stat-value text-danger" id="criticalCount">-</div>
                        <div class="stat-label">Critical</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stat-icon" style="color: #ea580c;"><i data-lucide="fire" class="icon-lucide"></i></div>
                    <div>
                        <div class="stat-value" style="color: #ea580c;" id="highCount">-</div>
                        <div class="stat-label">High</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stat-icon text-info"><i data-lucide="clock" class="icon-lucide"></i></div>
                    <div>
                        <div class="stat-value text-info" id="refreshTimer">5s</div>
                        <div class="stat-label">Next Refresh</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3 g-2">
            <div class="col-md-3">
                <form method="get" class="d-flex">
                    <select name="device" class="form-select me-2">
                        <option value="">All Devices</option>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= htmlspecialchars($d['source_ip']) ?>" <?= $device_ip === $d['source_ip'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['source_ip']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-lucide"></i></button>
                    <?php
                    $preserve = $_GET;
                    unset($preserve['device']);
                    foreach ($preserve as $k => $v): ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                    <?php endforeach; ?>
                </form>
            </div>
            <div class="col-md-3">
                <form method="get" class="d-flex">
                    <select name="severity" class="form-select me-2">
                        <option value="">All Severities</option>
                        <option value="critical" <?= $severity_filter==='critical'?'selected':'' ?>>Critical</option>
                        <option value="high" <?= $severity_filter==='high'?'selected':'' ?>>High</option>
                        <option value="medium" <?= $severity_filter==='medium'?'selected':'' ?>>Medium</option>
                        <option value="low" <?= $severity_filter==='low'?'selected':'' ?>>Low</option>
                    </select>
                    <button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-lucide"></i></button>
                    <?php
                    $preserve = $_GET;
                    unset($preserve['severity']);
                    foreach ($preserve as $k => $v): ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                    <?php endforeach; ?>
                </form>
            </div>
            <div class="col-md-6">
                <form method="get" class="d-flex">
                    <input type="text" name="search" class="form-control me-2" placeholder="Search IP, attack, app..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary" type="submit"><i data-lucide="search" class="icon-lucide"></i></button>
                    <?php if ($search || $device_ip || $type_filter || $severity_filter): ?>
                        <a href="ips_logs.php" class="btn btn-outline-secondary ms-2">Clear</a>
                    <?php endif; ?>
                    <?php
                    $preserve = $_GET;
                    unset($preserve['search']);
                    foreach ($preserve as $k => $v): ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                    <?php endforeach; ?>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-striped table-hover flow-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Source IP</th>
                        <th>Destination IP</th>
                        <th>Attack / Application</th>
                        <th>Action</th>
                        <th>Device</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-5">No IPS/App Control logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="small"><?= date('H:i:s', strtotime($log['time'])) ?></td>
                                <td><span class="type-badge type-<?= $log['type'] ?>"><?= strtoupper($log['type']) ?></span></td>
                                <td><span class="severity-<?= $log['severity'] ?>"><?= strtoupper($log['severity']) ?></span></td>
                                <td><code><?= htmlspecialchars($log['srcip']) ?></code></td>
                                <td><code><?= htmlspecialchars($log['dstip']) ?></code></td>
                                <td class="small"><?= htmlspecialchars($log['attack']) ?></td>
                                <td class="text-center action-<?= $log['action'] ?>"><?= strtoupper($log['action']) ?></td>
                                <td class="small"><?= htmlspecialchars($log['devname']) ?></td>
                                <td><span class="details-link" onclick="showDetails(<?= $log['log_id'] ?>)">View</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page==1?'disabled':'' ?>">
                        <a class="page-link" href="?page=<?= $page-1 ?>&<?= http_build_query(array_diff_key($_GET, ['page'=>0])) ?>">Previous</a>
                    </li>
                    <?php for ($i = max(1, $page-5); $i <= min($total_pages, $page+5); $i++): ?>
                        <li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_diff_key($_GET, ['page'=>0])) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page==$total_pages?'disabled':'' ?>">
                        <a class="page-link" href="?page=<?= $page+1 ?>&<?= http_build_query(array_diff_key($_GET, ['page'=>0])) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

        <div class="text-center mt-3 stats-footer">
            Page <?= $page ?> of <?= $total_pages ?> · Total events: <span id="totalCount"><?= $total ?></span>
        </div>
    </div>
</div>

<!-- Universal Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Event Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">
                Loading...
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="/js/bootstrap.bundle.min.js"></script>
<script>
let refreshInterval;
let countdownInterval;
let secondsLeft = 5;
let logsData = <?= json_encode($logs) ?>;

function showDetails(logId) {
    const log = logsData.find(l => l.log_id === logId);
    if (!log) return;
    
    const p = log.full_parsed;
    const modalBody = document.getElementById('modalBody');
    const modalTitle = document.getElementById('modalTitle');
    
    modalTitle.textContent = `${log.type.toUpperCase()} Event Details - ${log.devname}`;
    
    modalBody.innerHTML = `
        <h6>Event Information</h6>
        <ul class="list-unstyled">
            <li><strong>Type:</strong> ${log.type.toUpperCase()}</li>
            <li><strong>Severity:</strong> <span class="severity-${log.severity}">${log.severity.toUpperCase()}</span></li>
            <li><strong>Action:</strong> ${log.action.toUpperCase()}</li>
            <li><strong>Time:</strong> ${p.date || ''} ${p.time || ''}</li>
        </ul>
        <h6>Attack / Application</h6>
        <ul class="list-unstyled">
            <li><strong>Name:</strong> ${log.attack}</li>
            <li><strong>Attack ID:</strong> ${p.attackid || '-'}</li>
            <li><strong>Category:</strong> ${p.cat || p.category || '-'}</li>
        </ul>
        <h6>Source</h6>
        <ul class="list-unstyled">
            <li><strong>IP:</strong> ${log.srcip}</li>
            <li><strong>Port:</strong> ${p.srcport || '-'}</li>
            <li><strong>Interface:</strong> ${p.srcintf || '-'}</li>
            <li><strong>Country:</strong> ${p.srccountry || '-'}</li>
        </ul>
        <h6>Destination</h6>
        <ul class="list-unstyled">
            <li><strong>IP:</strong> ${log.dstip}</li>
            <li><strong>Port:</strong> ${p.dstport || '-'}</li>
            <li><strong>Interface:</strong> ${p.dstintf || '-'}</li>
            <li><strong>Country:</strong> ${p.dstcountry || '-'}</li>
        </ul>
        <h6>Policy & Device</h6>
        <ul class="list-unstyled">
            <li><strong>Policy ID:</strong> ${p.policyid || '-'}</li>
            <li><strong>Device:</strong> ${log.devname}</li>
            <li><strong>Virtual Domain:</strong> ${p.vd || 'root'}</li>
        </ul>
        <h6>Additional Info</h6>
        <ul class="list-unstyled">
            <li><strong>Protocol:</strong> ${p.proto || '-'}</li>
            <li><strong>Ref:</strong> ${p.ref || '-'}</li>
            <li><strong>Log ID:</strong> ${p.logid || '-'}</li>
        </ul>
        <h6>Raw Message</h6>
        <pre class="small bg-dark text-light p-3 rounded overflow-auto">${log.raw}</pre>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
}

async function refreshTable() {
    const indicator = document.getElementById('updateIndicator');
    indicator.classList.add('show');
    
    try {
        const params = new URLSearchParams(window.location.search);
        params.set('ajax', '1');
        
        const response = await fetch('?' + params.toString());
        const data = await response.json();
        
        logsData = data.logs;
        
        document.getElementById('totalCount').textContent = data.total;
        document.getElementById('totalLogs').textContent = data.total;
        
        const blocked = data.logs.filter(l => l.action === 'blocked' || l.action === 'block').length;
        const detected = data.logs.filter(l => l.action === 'detected').length;
        const critical = data.logs.filter(l => l.severity === 'critical').length;
        const high = data.logs.filter(l => l.severity === 'high').length;
        
        document.getElementById('blockedCount').textContent = blocked;
        document.getElementById('detectedCount').textContent = detected;
        document.getElementById('criticalCount').textContent = critical;
        document.getElementById('highCount').textContent = high;
        
        const tbody = document.getElementById('logsTableBody');
        if (data.logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-5">No IPS/App Control logs found.</td></tr>';
        } else {
            tbody.innerHTML = data.logs.map(log => `
                <tr>
                    <td class="small">${new Date(log.time).toLocaleTimeString()}</td>
                    <td><span class="type-badge type-${log.type}">${log.type.toUpperCase()}</span></td>
                    <td><span class="severity-${log.severity}">${log.severity.toUpperCase()}</span></td>
                    <td><code>${log.srcip}</code></td>
                    <td><code>${log.dstip}</code></td>
                    <td class="small">${log.attack}</td>
                    <td class="text-center action-${log.action}">${log.action.toUpperCase()}</td>
                    <td class="small">${log.devname}</td>
                    <td><span class="details-link" onclick="showDetails(${log.log_id})">View</span></td>
                </tr>
            `).join('');
        }
    } catch (error) {
        console.error('Refresh error:', error);
    } finally {
        setTimeout(() => {
            indicator.classList.remove('show');
        }, 500);
    }
}

function updateCountdown() {
    secondsLeft--;
    if (secondsLeft <= 0) {
        secondsLeft = 5;
        refreshTable();
    }
    document.getElementById('refreshTimer').textContent = secondsLeft + 's';
}

document.addEventListener('DOMContentLoaded', () => {
    // Calculate initial stats
    const blocked = logsData.filter(l => l.action === 'blocked' || l.action === 'block').length;
    const detected = logsData.filter(l => l.action === 'detected').length;
    const critical = logsData.filter(l => l.severity === 'critical').length;
    const high = logsData.filter(l => l.severity === 'high').length;
    
    document.getElementById('blockedCount').textContent = blocked;
    document.getElementById('detectedCount').textContent = detected;
    document.getElementById('criticalCount').textContent = critical;
    document.getElementById('highCount').textContent = high;
    
    // Start auto-refresh
    refreshInterval = setInterval(refreshTable, 5000);
    countdownInterval = setInterval(updateCountdown, 1000);
});

window.addEventListener('beforeunload', () => {
    clearInterval(refreshInterval);
    clearInterval(countdownInterval);
});
</script>
</body>
</html>

