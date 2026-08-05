<?php
// siem_logs.php - Professional SIEM-style Syslog Management
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die('Database connection failed: ' . htmlspecialchars(mysqli_connect_error()));
}

// Log storage directory
$log_base_dir = '/var/log/janus_siem';
if (!is_dir($log_base_dir)) {
    mkdir($log_base_dir, 0755, true);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete logs for specific IP and date
    if (isset($_POST['delete_log_file'])) {
        $ip = trim($_POST['ip']);
        $date = trim($_POST['date']);
        $filename = str_replace('.', '-', $ip) . '-' . $date . '.log';
        $filepath = $log_base_dir . '/' . $filename;
        
        if (file_exists($filepath)) {
            unlink($filepath);
            $_SESSION['message'] = 'Log file deleted successfully';
        } else {
            $_SESSION['message'] = 'error: Log file not found';
        }
        header('Location: siem_logs.php');
        exit;
    }
    
    // Purge all logs for an IP
    if (isset($_POST['purge_ip_logs'])) {
        $ip = trim($_POST['ip']);
        $pattern = str_replace('.', '-', $ip) . '-*.log';
        $files = glob($log_base_dir . '/' . $pattern);
        $count = 0;
        foreach ($files as $file) {
            if (unlink($file)) $count++;
        }
        $_SESSION['message'] = "Purged $count log file(s) for IP $ip";
        header('Location: siem_logs.php');
        exit;
    }
    
    // Disable source (from logmanage.php integration)
    if (isset($_POST['disable_source'])) {
        $source_id = intval($_POST['source_id']);
        $stmt = mysqli_prepare($con, "UPDATE syslog_sources SET is_active = 0 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $source_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Source disabled - no new logs will be accepted';
        header('Location: siem_logs.php');
        exit;
    }
}

// Get filter parameters
$filter_ip = $_GET['filter_ip'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$search_term = $_GET['search'] ?? '';

// Fetch active sources
$sources = [];
$res = mysqli_query($con, "
    SELECT ss.*, a.username,
           (SELECT COUNT(*) FROM syslog_entries WHERE source_id = ss.id) as log_count,
           (SELECT MAX(received_at) FROM syslog_entries WHERE source_id = ss.id) as last_log
    FROM syslog_sources ss
    LEFT JOIN accounts a ON ss.added_by = a.id
    ORDER BY ss.is_active DESC, ss.added_at DESC
");
while ($r = mysqli_fetch_assoc($res)) $sources[] = $r;

// Scan log files from disk
$log_files = [];
if (is_dir($log_base_dir)) {
    $files = scandir($log_base_dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (preg_match('/^(\d+-\d+-\d+-\d+)-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
            $ip = str_replace('-', '.', $m[1]);
            $date = $m[2];
            $filepath = $log_base_dir . '/' . $file;
            $size = filesize($filepath);
            $lines = 0;
            $handle = fopen($filepath, 'r');
            while (!feof($handle)) {
                fgets($handle);
                $lines++;
            }
            fclose($handle);
            
            // Apply filters
            if ($filter_ip && $ip !== $filter_ip) continue;
            if ($filter_date && $date !== $filter_date) continue;
            
            $log_files[] = [
                'filename' => $file,
                'ip' => $ip,
                'date' => $date,
                'size' => $size,
                'lines' => $lines,
                'modified' => filemtime($filepath)
            ];
        }
    }
}

// Sort by date desc
usort($log_files, function($a, $b) {
    return strcmp($b['date'], $a['date']);
});

// Get unique IPs and dates for filters
$unique_ips = array_unique(array_column($log_files, 'ip'));
$unique_dates = array_unique(array_column($log_files, 'date'));
sort($unique_ips);
rsort($unique_dates);

// Live log viewer - get recent entries from DB
$live_logs = [];
$query = "SELECT se.*, ss.appliance_type, ss.is_active 
          FROM syslog_entries se 
          LEFT JOIN syslog_sources ss ON se.source_id = ss.id 
          WHERE 1=1";
$params = [];
$types = '';

if ($filter_ip) {
    $query .= " AND se.source_ip = ?";
    $params[] = $filter_ip;
    $types .= 's';
}
if ($filter_type) {
    $query .= " AND se.appliance_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}
if ($search_term) {
    $query .= " AND se.message LIKE ?";
    $params[] = '%' . $search_term . '%';
    $types .= 's';
}

$query .= " ORDER BY se.received_at DESC LIMIT 500";

$stmt = mysqli_prepare($con, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($r = mysqli_fetch_assoc($result)) $live_logs[] = $r;
mysqli_stmt_close($stmt);

// Statistics
$stats = [
    'total_sources' => count($sources),
    'active_sources' => count(array_filter($sources, fn($s) => $s['is_active'])),
    'total_files' => count($log_files),
    'total_size' => array_sum(array_column($log_files, 'size')),
    'today_logs' => count(array_filter($log_files, fn($f) => $f['date'] === date('Y-m-d')))
];

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>SIEM Log Management · Janus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<link rel="stylesheet" href="/css/pages/siem_logs.css">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <div class="container-fluid">
        
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= strpos($_SESSION['message'],'error')===0 ? 'alert-danger' : 'alert-success' ?> alert-dismissible fade show" role="alert">
                <?php
                    $m = $_SESSION['message'];
                    echo strpos($m,'error:')===0 ? htmlspecialchars(substr($m,6)) : htmlspecialchars($m);
                    unset($_SESSION['message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="siem-header mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i data-lucide="shield" class="icon-lucide"></i> SIEM Log Management</h2>
                    <p class="text-muted mb-0">Centralized security information and event monitoring</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="logmanage.php" class="btn btn-outline-light btn-sm">
                        <i data-lucide="settings" class="icon-lucide"></i> Source Management
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-server fa-2x mb-2" style="color: var(--siem-accent);"></i>
                    <span class="stat-value"><?= $stats['active_sources'] ?>/<?= $stats['total_sources'] ?></span>
                    <span class="stat-label">Active Sources</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-file-alt fa-2x mb-2" style="color: var(--siem-success);"></i>
                    <span class="stat-value"><?= $stats['total_files'] ?></span>
                    <span class="stat-label">Log Files</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-database fa-2x mb-2" style="color: var(--siem-warning);"></i>
                    <span class="stat-value"><?= round($stats['total_size']/1024/1024, 2) ?> MB</span>
                    <span class="stat-label">Total Storage</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-calendar-day fa-2x mb-2" style="color: var(--siem-danger);"></i>
                    <span class="stat-value"><?= $stats['today_logs'] ?></span>
                    <span class="stat-label">Today's Files</span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-panel">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Filter by IP</label>
                    <select name="filter_ip" class="form-select form-select-sm">
                        <option value="">All IPs</option>
                        <?php foreach ($unique_ips as $ip): ?>
                            <option value="<?= htmlspecialchars($ip) ?>" <?= $filter_ip === $ip ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ip) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Filter by Date</label>
                    <select name="filter_date" class="form-select form-select-sm">
                        <option value="">All Dates</option>
                        <?php foreach ($unique_dates as $date): ?>
                            <option value="<?= htmlspecialchars($date) ?>" <?= $filter_date === $date ? 'selected' : '' ?>>
                                <?= htmlspecialchars($date) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Search Logs</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Search message..." value="<?= htmlspecialchars($search_term) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-siem btn-sm w-100">
                        <i data-lucide="search" class="icon-lucide"></i> Apply Filters
                    </button>
                    <a href="siem_logs.php" class="btn btn-outline-secondary btn-sm w-100 mt-1">
                        <i data-lucide="x" class="icon-lucide"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Content: Two Columns -->
        <div class="row g-3">
            <!-- Left: Log Files Archive -->
            <div class="col-lg-6">
                <div class="siem-card">
                    <h5 class="mb-3">
                        <i data-lucide="archive" class="icon-lucide"></i> Archived Log Files
                        <span class="badge bg-secondary ms-2"><?= count($log_files) ?></span>
                    </h5>
                    
                    <div style="max-height: 600px; overflow-y: auto;">
                        <?php if (empty($log_files)): ?>
                            <p class="text-muted">No log files found.</p>
                        <?php else: ?>
                            <table class="table table-sm log-table">
                                <thead>
                                    <tr>
                                        <th>Source IP</th>
                                        <th>Date</th>
                                        <th>Lines</th>
                                        <th>Size</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($log_files as $lf): ?>
                                        <tr>
                                            <td>
                                                <i data-lucide="network" class="icon-lucide text-primary"></i>
                                                <strong><?= htmlspecialchars($lf['ip']) ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($lf['date']) ?></td>
                                            <td><?= number_format($lf['lines']) ?></td>
                                            <td><?= round($lf['size']/1024, 2) ?> KB</td>
                                            <td>
                                                <a href="view_log.php?file=<?= urlencode($lf['filename']) ?>" 
                                                   class="btn btn-sm btn-outline-info" target="_blank" title="View">
                                                    <i data-lucide="eye" class="icon-lucide"></i>
                                                </a>
                                                <a href="download_log.php?file=<?= urlencode($lf['filename']) ?>" 
                                                   class="btn btn-sm btn-outline-success" title="Download">
                                                    <i data-lucide="download" class="icon-lucide"></i>
                                                </a>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        onclick="deleteLogFile('<?= htmlspecialchars($lf['ip']) ?>', '<?= htmlspecialchars($lf['date']) ?>')"
                                                        title="Delete">
                                                    <i data-lucide="trash" class="icon-lucide"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sources Quick View -->
                <div class="siem-card mt-3">
                    <h5 class="mb-3"><i data-lucide="list" class="icon-lucide"></i> Active Sources</h5>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($sources as $src): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 mb-2" 
                                 style="background: rgba(0,0,0,0.2); border-radius: 6px;">
                                <div>
                                    <span class="status-badge status-<?= $src['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= strtoupper($src['appliance_type']) ?>
                                    </span>
                                    <strong class="ms-2"><?= htmlspecialchars($src['source_ip']) ?></strong>
                                    <small class="text-muted ms-2">(<?= $src['log_count'] ?> logs)</small>
                                </div>
                                <div>
                                    <?php if ($src['is_active']): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="source_id" value="<?= $src['id'] ?>">
                                            <button name="disable_source" class="btn btn-sm btn-outline-warning" 
                                                    onclick="return confirm('Disable this source?')" title="Disable">
                                                <i data-lucide="ban" class="icon-lucide"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="ip" value="<?= htmlspecialchars($src['source_ip']) ?>">
                                        <button name="purge_ip_logs" class="btn btn-sm btn-danger-siem" 
                                                onclick="return confirm('Delete ALL logs for this IP?')" title="Purge All">
                                            <i data-lucide="fire" class="icon-lucide"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Live Log Viewer -->
            <div class="col-lg-6">
                <div class="siem-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i data-lucide="terminal" class="icon-lucide"></i> Live Log Stream
                            <span class="badge bg-success ms-2" id="liveCount"><?= count($live_logs) ?></span>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-outline-success" onclick="exportSiemLogsToCSV()">
                                <i data-lucide="download" class="icon-lucide"></i> Export CSV
                            </button>
                            <button class="btn btn-sm btn-siem" onclick="refreshLogs()">
                                <i data-lucide="refresh-ccw" class="icon-lucide"></i> Refresh
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="clearLogView()">
                                <i data-lucide="eraser" class="icon-lucide"></i> Clear
                            </button>
                        </div>
                    </div>

                    <div class="log-viewer" id="logViewer">
                        <?php if (empty($live_logs)): ?>
                            <div class="text-muted">No logs to display. Waiting for events...</div>
                        <?php else: ?>
                            <?php foreach ($live_logs as $log): ?>
                                <div class="log-entry">
                                    <span class="log-timestamp">[<?= date('Y-m-d H:i:s', strtotime($log['received_at'])) ?>]</span>
                                    <span class="log-type">[<?= htmlspecialchars(strtoupper($log['appliance_type'])) ?>]</span>
                                    <span class="log-ip"><?= htmlspecialchars($log['source_ip']) ?></span>
                                    <div class="log-message"><?= htmlspecialchars($log['message']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3 small text-muted text-center">
                        Auto-refresh every 30 seconds | Last updated: <span id="lastUpdate"><?= date('H:i:s') ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Delete Form (Hidden) -->
<form id="deleteForm" method="post" style="display:none;">
    <input type="hidden" name="ip" id="deleteIp">
    <input type="hidden" name="date" id="deleteDate">
    <input type="hidden" name="delete_log_file" value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function deleteLogFile(ip, date) {
    if (!confirm(`Delete log file for ${ip} on ${date}?`)) return;
    document.getElementById('deleteIp').value = ip;
    document.getElementById('deleteDate').value = date;
    document.getElementById('deleteForm').submit();
}

function refreshLogs() {
    const params = new URLSearchParams(window.location.search);
    fetch('siem_logs.php?' + params.toString())
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newLogs = doc.getElementById('logViewer').innerHTML;
            const newCount = doc.getElementById('liveCount').textContent;
            document.getElementById('logViewer').innerHTML = newLogs;
            document.getElementById('liveCount').textContent = newCount;
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
        });
}

function clearLogView() {
    document.getElementById('logViewer').innerHTML = '<div class="text-muted">View cleared. Click Refresh to reload.</div>';
}

function exportSiemLogsToCSV() {
    const entries = document.querySelectorAll('#logViewer .log-entry');
    if (!entries || entries.length === 0) {
        alert('No log entries available to export.');
        return;
    }
    const rows = [['Timestamp', 'Type', 'Source IP', 'Message']];
    entries.forEach(el => {
        const time = el.querySelector('.log-timestamp')?.textContent.replace(/^\[|\]$/g, '').trim() || '';
        const type = el.querySelector('.log-type')?.textContent.replace(/^\[|\]$/g, '').trim() || '';
        const ip = el.querySelector('.log-ip')?.textContent.trim() || '';
        const msg = el.querySelector('.log-message')?.textContent.trim() || '';
        rows.push([time, type, ip, msg]);
    });
    const csv = '\uFEFF' + rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'siem_logs_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// Auto-refresh every 30 seconds
setInterval(refreshLogs, 30000);

// Auto-scroll to bottom of log viewer
const logViewer = document.getElementById('logViewer');
if (logViewer) {
    logViewer.scrollTop = logViewer.scrollHeight;
}
</script>
</body>
</html>
