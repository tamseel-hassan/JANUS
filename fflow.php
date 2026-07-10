<?php
// fflow.php - Fixed Traffic Analyzer with Working Historical Mode
session_start();
require_once __DIR__ . '/db_config.php';
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die('DB connection failed');
}

$theme = $_COOKIE['theme'] ?? 'dark';
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// Parameters
$page          = max(1, intval($_GET['page'] ?? 1));
$per_page      = 100;
$offset        = ($page - 1) * $per_page;
$device_ip     = trim($_GET['device'] ?? '');
$srcip_filter  = trim($_GET['srcip'] ?? '');
$dstip_filter  = trim($_GET['dstip'] ?? '');
$srcport_filter = trim($_GET['srcport'] ?? '');
$dstport_filter = trim($_GET['dstport'] ?? '');
$service_filter = trim($_GET['service'] ?? '');
$action_filter = $_GET['action'] ?? '';
$sort          = $_GET['sort'] ?? 'time_desc';
$view_mode     = $_GET['view'] ?? 'live';

// Fetch devices
$devices = [];
$res = mysqli_query($con, "
    SELECT DISTINCT se.source_ip, ss.appliance_type 
    FROM syslog_entries se
    JOIN syslog_sources ss ON se.source_id = ss.id
    WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
      AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
    ORDER BY se.source_ip
");
while ($r = mysqli_fetch_assoc($res)) {
    $devices[] = $r;
}

// Build filter conditions (separate from base WHERE)
$filter_conditions = [];
$params = [];
$types  = '';

if ($device_ip !== '') {
    $filter_conditions[] = "source_ip = ?";
    $params[] = $device_ip;
    $types .= 's';
}

if ($action_filter !== '') {
    $filter_conditions[] = "(message LIKE ? OR message LIKE ?)";
    $params[] = '%action=' . $action_filter . '%';
    $params[] = '%action="' . $action_filter . '"%';
    $types .= 'ss';
}

if ($srcip_filter !== '') {
    $filter_conditions[] = "message LIKE ?";
    $params[] = '%srcip=' . $srcip_filter . '%';
    $types .= 's';
}

if ($dstip_filter !== '') {
    $filter_conditions[] = "message LIKE ?";
    $params[] = '%dstip=' . $dstip_filter . '%';
    $types .= 's';
}

if ($srcport_filter !== '') {
    $filter_conditions[] = "message LIKE ?";
    $params[] = '%srcport=' . $srcport_filter . '%';
    $types .= 's';
}

if ($dstport_filter !== '') {
    $filter_conditions[] = "message LIKE ?";
    $params[] = '%dstport=' . $dstport_filter . '%';
    $types .= 's';
}

if ($service_filter !== '') {
    $filter_conditions[] = "(message LIKE ? OR message LIKE ?)";
    $params[] = '%service=' . $service_filter . '%';
    $params[] = '%service="' . $service_filter . '"%';
    $types .= 'ss';
}

$filter_where = empty($filter_conditions) ? '' : ' AND ' . implode(' AND ', $filter_conditions);

// Check archive table exists
$archive_exists = false;
$check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
if (mysqli_num_rows($check) > 0) {
    $archive_exists = true;
}

$use_union = ($view_mode === 'historical' && $archive_exists);

// Build queries - FIXED for historical mode
if ($use_union) {
    // FIXED: Handle NULL appliance_type in archive with COALESCE
    $count_query = "
        SELECT COUNT(*) FROM (
            SELECT se.id 
            FROM syslog_entries se
            JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
              AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
              $filter_where
            
            UNION ALL
            
            SELECT se.id
            FROM syslog_entries_archive se
            WHERE (se.appliance_type LIKE '%ngfw%' OR se.appliance_type LIKE '%forti%' OR se.appliance_type LIKE '%firewall%')
              AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
              $filter_where
        ) AS combined
    ";
    
    $query = "
        SELECT log_id, received_at, message, source_ip, source_table FROM (
            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'live' as source_table
            FROM syslog_entries se
            JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
              AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
              $filter_where
            
            UNION ALL
            
            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'archive' as source_table
            FROM syslog_entries_archive se
            WHERE (se.appliance_type LIKE '%ngfw%' OR se.appliance_type LIKE '%forti%' OR se.appliance_type LIKE '%firewall%')
              AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
              $filter_where
        ) AS combined_results
        ORDER BY received_at DESC
        LIMIT ? OFFSET ?
    ";
} else {
    $count_query = "
        SELECT COUNT(*) 
        FROM syslog_entries se
        JOIN syslog_sources ss ON se.source_id = ss.id
        WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
          AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
          $filter_where
    ";
    
    $query = "
        SELECT se.id AS log_id, se.received_at, se.message, se.source_ip
        FROM syslog_entries se
        JOIN syslog_sources ss ON se.source_id = ss.id
        WHERE (ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
          AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')
          $filter_where
        ORDER BY se.received_at DESC
        LIMIT ? OFFSET ?
    ";
}

// Count
if (!empty($params)) {
    $count_params = $use_union ? array_merge($params, $params) : $params;
    $count_types = $use_union ? $types . $types : $types;
    
    $count_stmt = $con->prepare($count_query);
    if ($count_stmt) {
        $count_stmt->bind_param($count_types, ...$count_params);
        $count_stmt->execute();
        $count_stmt->bind_result($total);
        $count_stmt->fetch();
        $count_stmt->close();
    } else {
        error_log("Count query error: " . mysqli_error($con));
        $total = 0;
    }
} else {
    $result = mysqli_query($con, $count_query);
    $total = mysqli_fetch_row($result)[0] ?? 0;
}

$total_pages = ceil($total / $per_page);

// Main query
$final_params = $use_union ? array_merge($params, $params, [$per_page, $offset]) : array_merge($params, [$per_page, $offset]);
$final_types  = $use_union ? $types . $types . 'ii' : $types . 'ii';

$stmt = $con->prepare($query);
if ($stmt) {
    if (!empty($final_params)) {
        $stmt->bind_param($final_types, ...$final_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    error_log("Main query error: " . mysqli_error($con));
    die('Query error: ' . mysqli_error($con));
}

$flows = [];
while ($row = $result->fetch_assoc()) {
    $msg = $row['message'];
    $parsed = [];
    if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
            $parsed[$m[1]] = $value;
        }
    }

    if (isset($parsed['srcip'], $parsed['dstip'])) {
        $flows[] = [
            'log_id'     => $row['log_id'],
            'time'       => $row['received_at'],
            'src'        => $parsed['srcip'] . ':' . ($parsed['srcport'] ?? '-'),
            'dst'        => $parsed['dstip'] . ':' . ($parsed['dstport'] ?? '-'),
            'service'    => $parsed['service'] ?? ($parsed['proto'] ?? '-') . '/' . ($parsed['dstport'] ?? '-'),
            'action'     => strtolower($parsed['action'] ?? 'unknown'),
            'policyid'   => $parsed['policyid'] ?? '-',
            'devname'    => $parsed['devname'] ?? $row['source_ip'],
            'source_ip'  => $row['source_ip'],
            'sentbyte'   => $parsed['sentbyte'] ?? 0,
            'rcvdbyte'   => $parsed['rcvdbyte'] ?? 0,
            'full_parsed'=> $parsed,
            'raw'        => htmlspecialchars($msg),
            'is_archive' => isset($row['source_table']) && $row['source_table'] === 'archive'
        ];
    }
}
$stmt->close();
mysqli_close($con);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['flows' => $flows, 'total' => $total, 'page' => $page, 'total_pages' => $total_pages]);
    exit;
}

function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<title>Traffic Analyzer · Janus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
<style>
:root{
    --bg-dark:#0a0c14;
    --card-dark:rgba(20,24,36,0.85);
    --text-dark:#e8eef7;
    --text-muted-dark:#9aa6b2;
    --accent:#00d4ff;
    --success:#10b981;
    --danger:#ef4444;
    --warning:#f59e0b;
    --table-bg-dark:#1a1e2e;
    --table-stripe-dark:rgba(0,212,255,0.05);
}
[data-theme="light"]{
    --bg-light:#f8fafc;
    --card-light:#ffffff;
    --text-light:#1e293b;
    --text-muted-light:#64748b;
    --accent:#3b82f6;
    --table-bg-light:#ffffff;
    --table-stripe-light:#f1f5f9;
}
body.loggedin{
    background:var(--bg-dark);
    color:var(--text-dark);
    min-height:100vh;
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
}
[data-theme="light"] body{
    background:var(--bg-light);
    color:var(--text-light);
}
.container-fluid{padding-top:78px;}
#main-content{margin-left:260px;transition:margin-left .25s;padding:20px 24px;}
.sidebar.collapsed ~ #main-content{margin-left:78px;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:15px;margin-bottom:25px;}
.stat-card{
    background:var(--card-dark);
    border:1px solid rgba(0,212,255,0.2);
    border-radius:12px;
    padding:20px 16px;
    text-align:center;
    transition:all 0.3s;
}
[data-theme="light"] .stat-card{
    background:var(--card-light);
    border:1px solid rgba(59,130,246,0.2);
    box-shadow:0 1px 3px rgba(0,0,0,0.1);
}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(0,212,255,0.3);}
[data-theme="light"] .stat-card:hover{box-shadow:0 10px 30px rgba(59,130,246,0.2);}
.stat-icon{font-size:2rem;margin-bottom:10px;}
.stat-value{font-size:2.2rem;font-weight:800;line-height:1;margin-bottom:4px;}
.view-mode-toggle{
    display:inline-flex;
    background:var(--card-dark);
    border:1px solid rgba(0,212,255,0.2);
    border-radius:8px;
    overflow:hidden;
}
[data-theme="light"] .view-mode-toggle{
    background:var(--card-light);
    border:1px solid rgba(59,130,246,0.2);
}
.view-mode-btn{
    padding:8px 20px;
    border:none;
    background:transparent;
    color:var(--text-dark);
    cursor:pointer;
    transition:all 0.3s;
    font-weight:600;
    font-size:0.9rem;
}
[data-theme="light"] .view-mode-btn{color:var(--text-light);}
.view-mode-btn.active{background:var(--accent);color:#fff;}
.view-mode-btn:hover:not(.active){background:rgba(0,212,255,0.1);}
[data-theme="light"] .view-mode-btn:hover:not(.active){background:rgba(59,130,246,0.1);}
.search-panel{
    background:var(--card-dark);
    border:1px solid rgba(0,212,255,0.15);
    border-radius:12px;
    padding:20px;
    margin-bottom:24px;
}
[data-theme="light"] .search-panel{
    background:var(--card-light);
    border:1px solid #e2e8f0;
}
.control-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.refresh-control{
    display:flex;
    align-items:center;
    gap:12px;
    padding:8px 16px;
    background:var(--card-dark);
    border:1px solid rgba(0,212,255,0.2);
    border-radius:8px;
}
[data-theme="light"] .refresh-control{
    background:var(--card-light);
    border:1px solid #e2e8f0;
}
.pause-btn{
    background:var(--accent);
    color:#fff;
    border:none;
    width:36px;
    height:36px;
    border-radius:50%;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:all 0.3s;
}
.pause-btn:hover{transform:scale(1.1);box-shadow:0 0 20px var(--accent);}
.pause-btn.paused{background:var(--danger);}

.table-responsive{
    background:var(--table-bg-dark);
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 4px 20px rgba(0,0,0,0.3);
}
[data-theme="light"] .table-responsive{
    background:var(--table-bg-light);
    box-shadow:0 1px 3px rgba(0,0,0,0.1);
}
.flow-table{
    margin-bottom:0;
    color:var(--text-dark);
}
[data-theme="light"] .flow-table{
    color:var(--text-light);
}
.flow-table th{
    position:sticky;
    top:0;
    background:var(--table-bg-dark);
    color:var(--accent)!important;
    font-weight:700;
    text-transform:uppercase;
    font-size:0.8rem;
    letter-spacing:0.05em;
    border-bottom:2px solid var(--accent);
    padding:16px 12px;
    z-index:10;
}
[data-theme="light"] .flow-table th{
    background:var(--table-bg-light);
    border-bottom:2px solid var(--accent);
}
.flow-table th a{
    color:var(--accent)!important;
    text-decoration:none;
    display:flex;
    align-items:center;
    gap:6px;
}
.flow-table tbody tr{
    transition:all 0.2s;
    border-bottom:1px solid rgba(0,212,255,0.1);
}
[data-theme="light"] .flow-table tbody tr{
    border-bottom:1px solid #e2e8f0;
}
.flow-table tbody tr:nth-child(even){
    background:var(--table-stripe-dark);
}
[data-theme="light"] .flow-table tbody tr:nth-child(even){
    background:var(--table-stripe-light);
}
.flow-table tbody tr:hover{
    background:rgba(0,212,255,0.15)!important;
    transform:scale(1.005);
}
[data-theme="light"] .flow-table tbody tr:hover{
    background:rgba(59,130,246,0.1)!important;
}
.flow-table td{padding:12px;}

.flow-table code{
    background:rgba(0,212,255,0.1);
    color:#00d4ff;
    padding:4px 8px;
    border-radius:4px;
    font-family:'JetBrains Mono','Fira Code','Consolas',monospace;
    font-size:0.85rem;
    font-weight:500;
}
[data-theme="light"] .flow-table code{
    background:rgba(59,130,246,0.1);
    color:#3b82f6;
}

.action-badge{
    padding:5px 14px;
    border-radius:20px;
    font-weight:700;
    font-size:0.75rem;
    text-transform:uppercase;
    letter-spacing:0.05em;
    display:inline-block;
}
.action-accept{background:var(--success);color:#fff;}
.action-deny{background:var(--danger);color:#fff;}
.action-timeout,.action-close,.action-dns{background:var(--warning);color:#fff;}
.action-unknown{background:#6b7280;color:#fff;}
.bandwidth{display:flex;align-items:center;gap:8px;font-size:0.8rem;}
.bandwidth .up{color:#10b981;}
.bandwidth .down{color:#3b82f6;}
.details-link{cursor:pointer;color:var(--accent);text-decoration:underline;font-weight:600;}
.archive-badge{
    background:#8b5cf6;
    color:#fff;
    padding:2px 8px;
    border-radius:10px;
    font-size:0.7rem;
    font-weight:700;
    margin-left:8px;
}
.filter-badge{
    background:rgba(0,212,255,0.2);
    color:var(--accent);
    padding:6px 12px;
    border-radius:20px;
    font-size:0.85rem;
    display:flex;
    align-items:center;
    gap:8px;
}
[data-theme="light"] .filter-badge{
    background:rgba(59,130,246,0.1);
    color:#3b82f6;
}
.active-filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.modal-content{
    background:var(--card-dark);
    color:var(--text-dark);
    border:1px solid rgba(0,212,255,0.2);
}
[data-theme="light"] .modal-content{
    background:var(--card-light);
    color:var(--text-light);
    border:1px solid #e2e8f0;
}
@media(max-width:992px){
    #main-content{margin-left:78px;}
    .stats-grid{grid-template-columns:repeat(auto-fit,minmax(70px,1fr));}
}
</style>
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">
<div class="row mb-4 align-items-center">
<div class="col-md-6">
<h2><i class="fas fa-chart-network"></i> Traffic Flow Analyzer</h2>
<p style="color:var(--text-muted-dark);">Real-time network traffic monitoring</p>
</div>
<div class="col-md-6 text-end">
<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
<div class="view-mode-toggle">
<button class="view-mode-btn <?= $view_mode==='live'?'active':'' ?>" onclick="location.href='?view=live'">
<i class="fas fa-bolt"></i> Live
</button>
<button class="view-mode-btn <?= $view_mode==='historical'?'active':'' ?>" onclick="location.href='?view=historical'" <?= !$archive_exists?'disabled':'' ?>>
<i class="fas fa-history"></i> Historical
</button>
</div>
<a href="ips_logs.php" class="btn btn-sm btn-outline-warning"><i class="fas fa-shield-alt"></i> IPS</a>
<button class="btn btn-sm btn-outline-success" onclick="exportToCSV()"><i class="fas fa-file-export"></i> Export</button>
</div>
</div>
</div>

<div class="stats-grid">
<div class="stat-card">
<div class="stat-icon" style="color:#60a5fa;"><i class="fas fa-stream"></i></div>
<div class="stat-value" style="color:#60a5fa;" id="totalFlows"><?= number_format($total) ?></div>
</div>
<div class="stat-card">
<div class="stat-icon" style="color:var(--success);"><i class="fas fa-check-circle"></i></div>
<div class="stat-value" style="color:var(--success);" id="acceptedFlows">-</div>
</div>
<div class="stat-card">
<div class="stat-icon" style="color:var(--danger);"><i class="fas fa-ban"></i></div>
<div class="stat-value" style="color:var(--danger);" id="deniedFlows">-</div>
</div>
<div class="stat-card">
<div class="stat-icon" style="color:var(--warning);"><i class="fas fa-clock"></i></div>
<div class="stat-value" style="color:var(--warning);" id="timeoutFlows">-</div>
</div>
<div class="stat-card">
<div class="stat-icon" style="color:#8b5cf6;"><i class="fas fa-times-circle"></i></div>
<div class="stat-value" style="color:#8b5cf6;" id="closeFlows">-</div>
</div>
</div>

<div class="search-panel">
<div class="d-flex justify-content-between align-items-center mb-3">
<h6 class="mb-0"><i class="fas fa-filter"></i> Advanced Filters</h6>
<button class="btn btn-sm btn-outline-secondary" onclick="toggleSearchPanel()">
<i class="fas fa-chevron-up" id="panelToggleIcon"></i>
</button>
</div>
<div id="searchPanelContent">
<form method="get">
<input type="hidden" name="view" value="<?= $view_mode ?>">
<div class="row g-3">
<div class="col-md-2">
<label class="form-label small">Device</label>
<select name="device" class="form-select form-select-sm">
<option value="">All</option>
<?php foreach($devices as $d): ?>
<option value="<?= htmlspecialchars($d['source_ip']) ?>" <?= $device_ip===$d['source_ip']?'selected':'' ?>><?= htmlspecialchars($d['source_ip']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label small">Source IP</label>
<input type="text" name="srcip" class="form-control form-control-sm" value="<?= htmlspecialchars($srcip_filter) ?>">
</div>
<div class="col-md-2">
<label class="form-label small">Destination IP</label>
<input type="text" name="dstip" class="form-control form-control-sm" value="<?= htmlspecialchars($dstip_filter) ?>">
</div>
<div class="col-md-1">
<label class="form-label small">Src Port</label>
<input type="text" name="srcport" class="form-control form-control-sm" value="<?= htmlspecialchars($srcport_filter) ?>">
</div>
<div class="col-md-1">
<label class="form-label small">Dst Port</label>
<input type="text" name="dstport" class="form-control form-control-sm" value="<?= htmlspecialchars($dstport_filter) ?>">
</div>
<div class="col-md-2">
<label class="form-label small">Service</label>
<input type="text" name="service" class="form-control form-control-sm" value="<?= htmlspecialchars($service_filter) ?>">
</div>
<div class="col-md-2">
<label class="form-label small">Action</label>
<select name="action" class="form-select form-select-sm">
<option value="">All</option>
<option value="accept" <?= $action_filter==='accept'?'selected':'' ?>>Accept</option>
<option value="deny" <?= $action_filter==='deny'?'selected':'' ?>>Deny</option>
<option value="timeout" <?= $action_filter==='timeout'?'selected':'' ?>>Timeout</option>
<option value="close" <?= $action_filter==='close'?'selected':'' ?>>Close</option>
</select>
</div>
</div>
<div class="row mt-3">
<div class="col-12">
<button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Apply</button>
<a href="fflow.php?view=<?= $view_mode ?>" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-times"></i> Clear</a>
</div>
</div>
</form>
</div>
</div>

<?php if($device_ip||$srcip_filter||$dstip_filter||$srcport_filter||$dstport_filter||$service_filter||$action_filter): ?>
<div class="active-filters">
<strong>Filters:</strong>
<?php if($device_ip): ?><span class="filter-badge">Device: <?= htmlspecialchars($device_ip) ?> <i class="fas fa-times" onclick="removeFilter('device')"></i></span><?php endif; ?>
<?php if($srcip_filter): ?><span class="filter-badge">Src: <?= htmlspecialchars($srcip_filter) ?> <i class="fas fa-times" onclick="removeFilter('srcip')"></i></span><?php endif; ?>
<?php if($dstip_filter): ?><span class="filter-badge">Dst: <?= htmlspecialchars($dstip_filter) ?> <i class="fas fa-times" onclick="removeFilter('dstip')"></i></span><?php endif; ?>
<?php if($service_filter): ?><span class="filter-badge">Service: <?= htmlspecialchars($service_filter) ?> <i class="fas fa-times" onclick="removeFilter('service')"></i></span><?php endif; ?>
<?php if($action_filter): ?><span class="filter-badge">Action: <?= strtoupper($action_filter) ?> <i class="fas fa-times" onclick="removeFilter('action')"></i></span><?php endif; ?>
</div>
<?php endif; ?>

<div class="control-bar">
<?php if($view_mode==='live'): ?>
<div class="refresh-control">
<button class="pause-btn" id="pauseBtn" onclick="toggleRefresh()"><i class="fas fa-pause"></i></button>
<div style="font-family:monospace;font-weight:700;font-size:1.1rem;color:var(--accent);" id="refreshTimer">5s</div>
</div>
<?php else: ?>
<div class="alert alert-info mb-0 py-2"><i class="fas fa-database"></i> Historical mode - showing combined data from live and archive</div>
<?php endif; ?>
<small style="color:var(--text-muted-dark);">Showing <?= count($flows) ?> of <?= number_format($total) ?> flows</small>
</div>

<div class="table-responsive">
<table class="table flow-table">
<thead>
<tr>
<th style="width:100px;"><a href="?sort=<?= $sort==='time_desc'?'time_asc':'time_desc' ?>&<?= http_build_query(array_diff_key($_GET,['sort'=>0])) ?>"><i class="far fa-clock"></i> Time</a></th>
<th><i class="fas fa-arrow-right"></i> Source</th>
<th><i class="fas fa-arrow-left"></i> Destination</th>
<th><i class="fas fa-server"></i> Service</th>
<th style="width:110px;"><a href="?sort=action&<?= http_build_query(array_diff_key($_GET,['sort'=>0])) ?>"><i class="fas fa-filter"></i> Action</a></th>
<th><i class="fas fa-shield-alt"></i> Policy</th>
<th><i class="fas fa-exchange-alt"></i> Bandwidth</th>
<th><i class="fas fa-network-wired"></i> Device</th>
<th style="width:60px;"><i class="fas fa-info-circle"></i></th>
</tr>
</thead>
<tbody id="flowTableBody">
<?php if(empty($flows)): ?>
<tr><td colspan="9" class="text-center py-5"><i class="fas fa-inbox fa-3x mb-3 d-block" style="color:var(--text-muted-dark);"></i><span style="color:var(--text-muted-dark);">No flows match your filters</span></td></tr>
<?php else: ?>
<?php foreach($flows as $f): ?>
<tr>
<td class="small"><?= date('H:i:s',strtotime($f['time'])) ?><?php if($f['is_archive']): ?><span class="archive-badge">ARCHIVE</span><?php endif; ?></td>
<td><code><?= htmlspecialchars($f['src']) ?></code></td>
<td><code><?= htmlspecialchars($f['dst']) ?></code></td>
<td class="small"><?= htmlspecialchars($f['service']) ?></td>
<td class="text-center"><span class="action-badge action-<?= $f['action'] ?>"><?= strtoupper($f['action']) ?></span></td>
<td class="small"><?= htmlspecialchars($f['policyid']) ?></td>
<td class="small"><div class="bandwidth"><span class="up"><i class="fas fa-arrow-up"></i> <?= formatBytes($f['sentbyte']) ?></span><span class="down"><i class="fas fa-arrow-down"></i> <?= formatBytes($f['rcvdbyte']) ?></span></div></td>
<td class="small"><?= htmlspecialchars($f['devname']) ?></td>
<td><span class="details-link" onclick="showDetails(<?= $f['log_id'] ?>)"><i class="fas fa-search"></i></span></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if($total_pages>1): ?>
<nav class="mt-4"><ul class="pagination justify-content-center">
<li class="page-item <?= $page==1?'disabled':'' ?>"><a class="page-link" href="?page=<?= $page-1 ?>&<?= http_build_query(array_diff_key($_GET,['page'=>0])) ?>">Previous</a></li>
<?php for($i=max(1,$page-5);$i<=min($total_pages,$page+5);$i++): ?>
<li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_diff_key($_GET,['page'=>0])) ?>"><?= $i ?></a></li>
<?php endfor; ?>
<li class="page-item <?= $page==$total_pages?'disabled':'' ?>"><a class="page-link" href="?page=<?= $page+1 ?>&<?= http_build_query(array_diff_key($_GET,['page'=>0])) ?>">Next</a></li>
</ul></nav>
<?php endif; ?>
</div>
</div>

<div class="modal fade" id="detailsModal" tabindex="-1">
<div class="modal-dialog modal-lg" style="margin-top:70px;">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title" id="modalTitle">Details</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="modalBody">Loading...</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let refreshInterval,countdownInterval,secondsLeft=5,isPaused=false,flowsData=<?= json_encode($flows) ?>;
const viewMode='<?= $view_mode ?>';
function formatBytes(b){if(!b)return'0 B';const k=1024,s=['B','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(k));return Math.round(b/Math.pow(k,i)*100)/100+' '+s[i];}
function toggleSearchPanel(){const c=document.getElementById('searchPanelContent'),i=document.getElementById('panelToggleIcon');c.style.display=c.style.display==='none'?'block':'none';i.className=c.style.display==='none'?'fas fa-chevron-down':'fas fa-chevron-up';}
function removeFilter(f){const u=new URL(location);u.searchParams.delete(f);location=u;}
function toggleRefresh(){isPaused=!isPaused;const b=document.getElementById('pauseBtn');b.querySelector('i').className=isPaused?'fas fa-play':'fas fa-pause';b.classList.toggle('paused',isPaused);if(isPaused){clearInterval(refreshInterval);clearInterval(countdownInterval);}else{secondsLeft=5;refreshInterval=setInterval(refreshTable,5000);countdownInterval=setInterval(updateCountdown,1000);}}
function showDetails(id){const f=flowsData.find(x=>x.log_id===id);if(!f)return;const p=f.full_parsed;document.getElementById('modalTitle').textContent=`Flow Details - ${f.devname}`;document.getElementById('modalBody').innerHTML=`<div class="row g-3"><div class="col-md-6"><h6>General</h6><ul class="list-unstyled small"><li><strong>Time:</strong> ${p.date||''} ${p.time||''}</li><li><strong>Duration:</strong> ${p.duration||'-'}s</li><li><strong>Session:</strong> ${p.sessionid||'-'}</li></ul></div><div class="col-md-6"><h6>Action</h6><ul class="list-unstyled small"><li><strong>Action:</strong> <span class="action-badge action-${f.action}">${f.action.toUpperCase()}</span></li><li><strong>Policy:</strong> ${p.policyid||'-'} / ${p.policyname||'-'}</li></ul></div><div class="col-md-6"><h6>Source</h6><ul class="list-unstyled small"><li><code>${p.srcip||'-'}</code>:${p.srcport||'-'}</li><li><strong>Interface:</strong> ${p.srcintf||'-'}</li><li><strong>Country:</strong> ${p.srccountry||'-'}</li></ul></div><div class="col-md-6"><h6>Destination</h6><ul class="list-unstyled small"><li><code>${p.dstip||'-'}</code>:${p.dstport||'-'}</li><li><strong>Interface:</strong> ${p.dstintf||'-'}</li><li><strong>Country:</strong> ${p.dstcountry||'-'}</li></ul></div><div class="col-md-6"><h6>Service</h6><ul class="list-unstyled small"><li><strong>Service:</strong> ${p.service||'-'}</li><li><strong>Protocol:</strong> ${p.proto||'-'}</li><li><strong>App:</strong> ${p.app||'-'}</li></ul></div><div class="col-md-6"><h6>Traffic</h6><ul class="list-unstyled small"><li><strong>Sent:</strong> ${formatBytes(parseInt(p.sentbyte||0))}</li><li><strong>Received:</strong> ${formatBytes(parseInt(p.rcvdbyte||0))}</li></ul></div><div class="col-12"><h6>Raw Message</h6><pre class="small bg-dark text-light p-3 rounded" style="max-height:180px;overflow:auto;">${f.raw}</pre></div></div>`;new bootstrap.Modal(document.getElementById('detailsModal')).show();}
async function refreshTable(){if(isPaused||viewMode!=='live')return;try{const p=new URLSearchParams(location.search);p.set('ajax','1');const r=await fetch('?'+p),d=await r.json();flowsData=d.flows;document.getElementById('totalFlows').textContent=d.total.toLocaleString();const a=d.flows.filter(f=>f.action==='accept').length,dn=d.flows.filter(f=>f.action==='deny').length,t=d.flows.filter(f=>f.action==='timeout').length,c=d.flows.filter(f=>f.action==='close'||f.action==='dns').length;document.getElementById('acceptedFlows').textContent=a;document.getElementById('deniedFlows').textContent=dn;document.getElementById('timeoutFlows').textContent=t;document.getElementById('closeFlows').textContent=c;const tb=document.getElementById('flowTableBody');tb.innerHTML=d.flows.length?d.flows.map(f=>`<tr><td class="small">${new Date(f.time).toLocaleTimeString()}</td><td><code>${f.src}</code></td><td><code>${f.dst}</code></td><td class="small">${f.service}</td><td class="text-center"><span class="action-badge action-${f.action}">${f.action.toUpperCase()}</span></td><td class="small">${f.policyid}</td><td class="small"><div class="bandwidth"><span class="up"><i class="fas fa-arrow-up"></i> ${formatBytes(f.sentbyte)}</span><span class="down"><i class="fas fa-arrow-down"></i> ${formatBytes(f.rcvdbyte)}</span></div></td><td class="small">${f.devname}</td><td><span class="details-link" onclick="showDetails(${f.log_id})"><i class="fas fa-search"></i></span></td></tr>`).join(''):'<tr><td colspan="9" class="text-center py-5">No flows</td></tr>';}catch(e){console.error(e);}}
function updateCountdown(){if(isPaused)return;secondsLeft--;if(secondsLeft<=0){secondsLeft=5;refreshTable();}document.getElementById('refreshTimer').textContent=secondsLeft+'s';}
function exportToCSV(){const rows=[['Time','Source','Destination','Service','Action','Policy','Sent','Received','Device']];flowsData.forEach(f=>rows.push([new Date(f.time).toLocaleString(),f.src,f.dst,f.service,f.action.toUpperCase(),f.policyid,formatBytes(f.sentbyte),formatBytes(f.rcvdbyte),f.devname]));const csv=rows.map(r=>r.map(c=>`"${c}"`).join(',')).join('\n'),blob=new Blob([csv],{type:'text/csv'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='traffic_'+new Date().toISOString().slice(0,10)+'.csv';a.click();}
document.addEventListener('DOMContentLoaded',()=>{const a=flowsData.filter(f=>f.action==='accept').length,d=flowsData.filter(f=>f.action==='deny').length,t=flowsData.filter(f=>f.action==='timeout').length,c=flowsData.filter(f=>f.action==='close'||f.action==='dns').length;document.getElementById('acceptedFlows').textContent=a;document.getElementById('deniedFlows').textContent=d;document.getElementById('timeoutFlows').textContent=t;document.getElementById('closeFlows').textContent=c;if(viewMode==='live'){refreshInterval=setInterval(refreshTable,5000);countdownInterval=setInterval(updateCountdown,1000);}});
window.addEventListener('beforeunload',()=>{clearInterval(refreshInterval);clearInterval(countdownInterval);});
</script>
</body>
</html>
