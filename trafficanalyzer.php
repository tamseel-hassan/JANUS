<?php
// fflow.php - Professional Traffic Flow Analyzer with Archive Support
// This file is optimized to handle millions of logs by:
// 1. Querying only last 6 hours of data in "Live" mode (super fast)
// 2. Querying both live + archive tables in "Historical" mode
// 3. Using indexes and efficient queries
require_once __DIR__ . '/db_config.php';
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die('DB connection failed: ' . mysqli_connect_error());
}

$theme = $_COOKIE['theme'] ?? 'light';  // Changed default to 'light' for a cooler, Forti Analyzer-like feel

// Check if this is an AJAX request for table refresh
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
$view_mode     = $_GET['view'] ?? 'live'; // 'live' or 'historical'

// Fetch available devices
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

// Determine which table(s) to query
$use_union = ($view_mode === 'historical');

// Build WHERE clause
$where_base = "(ss.appliance_type LIKE '%ngfw%' OR ss.appliance_type LIKE '%forti%' OR ss.appliance_type LIKE '%firewall%')
          AND (se.message LIKE '%type=traffic%' OR se.message LIKE '%type=\"traffic\"%' OR se.message LIKE '%subtype=forward%')";

$params = [];
$types  = '';

$where_conditions = [];

if ($device_ip !== '') {
    $where_conditions[] = "se.source_ip = ?";
    $params[] = $device_ip;
    $types .= 's';
}

if ($action_filter !== '') {
    $where_conditions[] = "(se.message LIKE ? OR se.message LIKE ?)";
    $params[] = '%action=' . $action_filter . '%';
    $params[] = '%action="' . $action_filter . '"%';
    $types .= 'ss';
}

if ($srcip_filter !== '') {
    $where_conditions[] = "se.message LIKE ?";
    $params[] = '%srcip=' . $srcip_filter . '%';
    $types .= 's';
}

if ($dstip_filter !== '') {
    $where_conditions[] = "se.message LIKE ?";
    $params[] = '%dstip=' . $dstip_filter . '%';
    $types .= 's';
}

if ($srcport_filter !== '') {
    $where_conditions[] = "se.message LIKE ?";
    $params[] = '%srcport=' . $srcport_filter . '%';
    $types .= 's';
}

if ($dstport_filter !== '') {
    $where_conditions[] = "se.message LIKE ?";
    $params[] = '%dstport=' . $dstport_filter . '%';
    $types .= 's';
}

if ($service_filter !== '') {
    $where_conditions[] = "(se.message LIKE ? OR se.message LIKE ?)";
    $params[] = '%service=' . $service_filter . '%';
    $params[] = '%service="' . $service_filter . '"%';
    $types .= 'ss';
}

$where_extra = empty($where_conditions) ? '' : ' AND ' . implode(' AND ', $where_conditions);

// Initialize variables
$total = 0;
$flows = [];

try {
    if ($use_union) {
        // Query both live and archive tables
        
        // Count query for UNION - SIMPLIFIED APPROACH
        $live_count = 0;
        $archive_count = 0;
        
        // Live count
        $live_count_query = "
            SELECT COUNT(*) as count
            FROM syslog_entries se
            JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE $where_base $where_extra
        ";
        
        // Archive count  
        $archive_count_query = "
            SELECT COUNT(*) as count
            FROM syslog_entries_archive se
            LEFT JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE $where_base $where_extra
        ";
        
        // Execute live count
        if (!empty($params)) {
            $stmt1 = $con->prepare($live_count_query);
            if ($stmt1) {
                $stmt1->bind_param($types, ...$params);
                $stmt1->execute();
                $result1 = $stmt1->get_result();
                $row1 = $result1->fetch_assoc();
                $live_count = $row1['count'] ?? 0;
                $stmt1->close();
            }
        } else {
            $result1 = mysqli_query($con, $live_count_query);
            $row1 = mysqli_fetch_assoc($result1);
            $live_count = $row1['count'] ?? 0;
        }
        
        // Execute archive count
        if (!empty($params)) {
            $stmt2 = $con->prepare($archive_count_query);
            if ($stmt2) {
                $stmt2->bind_param($types, ...$params);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                $row2 = $result2->fetch_assoc();
                $archive_count = $row2['count'] ?? 0;
                $stmt2->close();
            }
        } else {
            $result2 = mysqli_query($con, $archive_count_query);
            $row2 = mysqli_fetch_assoc($result2);
            $archive_count = $row2['count'] ?? 0;
        }
        
        $total = $live_count + $archive_count;
        
        // Main query for UNION - SIMPLIFIED
        // We'll query live and archive separately then merge in PHP for better control
        $live_query = "
            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'live' as source_table
            FROM syslog_entries se
            JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE $where_base $where_extra
        ";
        
        $archive_query = "
            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'archive' as source_table
            FROM syslog_entries_archive se
            LEFT JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE $where_base $where_extra
        ";
        
        $all_results = [];
        
        // Get live results
        if (!empty($params)) {
            $stmt_live = $con->prepare($live_query);
            if ($stmt_live) {
                $stmt_live->bind_param($types, ...$params);
                $stmt_live->execute();
                $result_live = $stmt_live->get_result();
                while ($row = $result_live->fetch_assoc()) {
                    $all_results[] = $row;
                }
                $stmt_live->close();
            }
        } else {
            $result_live = mysqli_query($con, $live_query);
            while ($row = mysqli_fetch_assoc($result_live)) {
                $all_results[] = $row;
            }
        }
        
        // Get archive results
        if (!empty($params)) {
            $stmt_archive = $con->prepare($archive_query);
            if ($stmt_archive) {
                $stmt_archive->bind_param($types, ...$params);
                $stmt_archive->execute();
                $result_archive = $stmt_archive->get_result();
                while ($row = $result_archive->fetch_assoc()) {
                    $all_results[] = $row;
                }
                $stmt_archive->close();
            }
        } else {
            $result_archive = mysqli_query($con, $archive_query);
            while ($row = mysqli_fetch_assoc($result_archive)) {
                $all_results[] = $row;
            }
        }
        
        // Sort in PHP (since UNION with ORDER BY and LIMIT is complex)
        if ($sort === 'time_asc') {
            usort($all_results, function($a, $b) {
                return strtotime($a['received_at']) - strtotime($b['received_at']);
            });
        } else {
            // Default: time_desc
            usort($all_results, function($a, $b) {
                return strtotime($b['received_at']) - strtotime($a['received_at']);
            });
        }
        
        // Apply pagination manually
        $paginated_results = array_slice($all_results, $offset, $per_page);
        
        // Process results
        foreach ($paginated_results as $row) {
            $msg = $row['message'];
            $parsed = [];
            if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
                    $parsed[$m[1]] = $value;
                }
            }

            if (isset($parsed['srcip'], $parsed['dstip'])) {
                $action = strtolower($parsed['action'] ?? 'unknown');
                $icon = '';
                switch ($action) {
                    case 'accept':
                        $icon = 'check';
                        break;
                    case 'deny':
                        $icon = 'times';
                        break;
                    case 'close':
                        $icon = 'ban';
                        break;
                    case 'timeout':
                        $icon = 'hourglass-end';
                        break;
                    default:
                        $icon = 'question';
                }
                $flows[] = [
                    'log_id'     => $row['log_id'],
                    'time'       => $row['received_at'],
                    'src'        => $parsed['srcip'] . ':' . ($parsed['srcport'] ?? '-'),
                    'dst'        => $parsed['dstip'] . ':' . ($parsed['dstport'] ?? '-'),
                    'service'    => $parsed['service'] ?? ($parsed['proto'] ?? '-') . '/' . ($parsed['dstport'] ?? '-'),
                    'action'     => $action,
                    'icon'       => $icon,
                    'policyid'   => $parsed['policyid'] ?? '-',
                    'devname'    => $parsed['devname'] ?? $row['source_ip'],
                    'source_ip'  => $row['source_ip'],
                    'sentbyte'   => $parsed['sentbyte'] ?? 0,
                    'rcvdbyte'   => $parsed['rcvdbyte'] ?? 0,
                    'full_parsed'=> $parsed,
                    'raw'        => htmlspecialchars($msg),
                    'is_archive' => $row['source_table'] === 'archive'
                ];
            }
        }
    } else {
        // Live mode only (add else block for live mode, assuming similar logic without archive)
        // Count
        $live_count_query = "
            SELECT COUNT(*) as count
            FROM syslog_entries se
            JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE $where_base $where_extra
            AND se.received_at >= NOW() - INTERVAL 6 HOUR
        ";
        if (!empty($params)) {
            $stmt = $con->prepare($live_count_query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $total = $row['count'] ?? 0;
            $stmt->close();
        } else {
            $result = mysqli_query($con, $live_count_query);
            $row = mysqli_fetch_assoc($result);
            $total = $row['count'] ?? 0;
        }

        // Data query
        $order_by = ($sort === 'time_asc') ? 'received_at ASC' : 'received_at DESC';
        $live_query = "
            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'live' as source_table
            FROM syslog_entries se
            JOIN syslog_sources ss ON se.source_id = ss.id
            WHERE $where_base $where_extra
            AND se.received_at >= NOW() - INTERVAL 6 HOUR
            ORDER BY $order_by
            LIMIT $per_page OFFSET $offset
        ";
        $paginated_results = [];
        if (!empty($params)) {
            $stmt = $con->prepare($live_query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $paginated_results[] = $row;
            }
            $stmt->close();
        } else {
            $result = mysqli_query($con, $live_query);
            while ($row = mysqli_fetch_assoc($result)) {
                $paginated_results[] = $row;
            }
        }

        // Process
        foreach ($paginated_results as $row) {
            $msg = $row['message'];
            $parsed = [];
            if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
                    $parsed[$m[1]] = $value;
                }
            }

            if (isset($parsed['srcip'], $parsed['dstip'])) {
                $action = strtolower($parsed['action'] ?? 'unknown');
                $icon = '';
                switch ($action) {
                    case 'accept':
                        $icon = 'check';
                        break;
                    case 'deny':
                        $icon = 'times';
                        break;
                    case 'close':
                        $icon = 'ban';
                        break;
                    case 'timeout':
                        $icon = 'hourglass-end';
                        break;
                    default:
                        $icon = 'question';
                }
                $flows[] = [
                    'log_id'     => $row['log_id'],
                    'time'       => $row['received_at'],
                    'src'        => $parsed['srcip'] . ':' . ($parsed['srcport'] ?? '-'),
                    'dst'        => $parsed['dstip'] . ':' . ($parsed['dstport'] ?? '-'),
                    'service'    => $parsed['service'] ?? ($parsed['proto'] ?? '-') . '/' . ($parsed['dstport'] ?? '-'),
                    'action'     => $action,
                    'icon'       => $icon,
                    'policyid'   => $parsed['policyid'] ?? '-',
                    'devname'    => $parsed['devname'] ?? $row['source_ip'],
                    'source_ip'  => $row['source_ip'],
                    'sentbyte'   => $parsed['sentbyte'] ?? 0,
                    'rcvdbyte'   => $parsed['rcvdbyte'] ?? 0,
                    'full_parsed'=> $parsed,
                    'raw'        => htmlspecialchars($msg),
                    'is_archive' => $row['source_table'] === 'archive'
                ];
            }
        }
    }
} catch (Exception $e) {
    // Error handling
    $total = 0;
    $flows = [];
}

if ($isAjax) {
    echo json_encode(['total' => $total, 'flows' => $flows]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<title>Traffic Flow Analyzer</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/pages/trafficanalyzer.css">
<link rel="stylesheet" href="/css/theme.css">
</head>
<body>
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content" id="main-content">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i data-lucide="stream" class="icon-lucide me-2"></i>Traffic Flow Analyzer</h5>
                        <div class="d-flex gap-2">
                            <button id="pauseBtn" class="btn btn-sm btn-outline-secondary"><i data-lucide="pause" class="icon-lucide"></i> Pause</button>
                            <button id="exportBtn" class="btn btn-sm btn-outline-primary"><i data-lucide="download" class="icon-lucide"></i> Export</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Stats row -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-2 col-6">
                                <div class="stat-card border-end">
                                    <div class="stat-icon"><i data-lucide="network" class="icon-lucide"></i></div>
                                    <div class="stat-value" id="totalFlows"><?= $total ?></div>
                                    <div class="stat-label">Total Flows</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6">
                                <div class="stat-card border-end">
                                    <div class="stat-icon text-success"><i data-lucide="check-circle" class="icon-lucide"></i></div>
                                    <div class="stat-value text-success" id="acceptedFlows">0</div>
                                    <div class="stat-label">Accepted</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6">
                                <div class="stat-card border-end">
                                    <div class="stat-icon text-danger"><i data-lucide="x" class="icon-lucide -circle"></i></div>
                                    <div class="stat-value text-danger" id="deniedFlows">0</div>
                                    <div class="stat-label">Denied</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6">
                                <div class="stat-card border-end">
                                    <div class="stat-icon text-warning"><i data-lucide="ban" class="icon-lucide"></i></div>
                                    <div class="stat-value text-warning" id="closeFlows">0</div>
                                    <div class="stat-label">Closed</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6">
                                <div class="stat-card border-end">
                                    <div class="stat-icon text-info"><i data-lucide="hourglass-end" class="icon-lucide"></i></div>
                                    <div class="stat-value text-info" id="timeoutFlows">0</div>
                                    <div class="stat-label">Timeout</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6">
                                <div class="stat-card">
                                    <div class="stat-icon"><i data-lucide="refresh-ccw" class="icon-lucide"></i></div>
                                    <div class="stat-value" id="refreshTimer">5s</div>
                                    <div class="stat-label">Refresh</div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <form method="GET" class="row g-3 mb-4">
                            <input type="hidden" name="view" value="<?= $view_mode ?>">
                            <div class="col-md-2">
                                <select name="device" class="form-select">
                                    <option value="">All Devices</option>
                                    <?php foreach ($devices as $dev): ?>
                                        <option value="<?= $dev['source_ip'] ?>" <?= $device_ip === $dev['source_ip'] ? 'selected' : '' ?>><?= $dev['source_ip'] ?> (<?= $dev['appliance_type'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="text" name="srcip" placeholder="Source IP" value="<?= $srcip_filter ?>" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <input type="text" name="dstip" placeholder="Destination IP" value="<?= $dstip_filter ?>" class="form-control">
                            </div>
                            <div class="col-md-1">
                                <input type="text" name="srcport" placeholder="Src Port" value="<?= $srcport_filter ?>" class="form-control">
                            </div>
                            <div class="col-md-1">
                                <input type="text" name="dstport" placeholder="Dst Port" value="<?= $dstport_filter ?>" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <input type="text" name="service" placeholder="Service" value="<?= $service_filter ?>" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <select name="action" class="form-select">
                                    <option value="">All Actions</option>
                                    <option value="accept" <?= $action_filter === 'accept' ? 'selected' : '' ?>>Accept</option>
                                    <option value="deny" <?= $action_filter === 'deny' ? 'selected' : '' ?>>Deny</option>
                                    <option value="close" <?= $action_filter === 'close' ? 'selected' : '' ?>>Close</option>
                                    <option value="timeout" <?= $action_filter === 'timeout' ? 'selected' : '' ?>>Timeout</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="sort" class="form-select">
                                    <option value="time_desc" <?= $sort === 'time_desc' ? 'selected' : '' ?>>Time Desc</option>
                                    <option value="time_asc" <?= $sort === 'time_asc' ? 'selected' : '' ?>>Time Asc</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100"><i data-lucide="filter" class="icon-lucide"></i> Apply Filters</button>
                            </div>
                            <div class="col-md-2">
                                <a href="?view=<?= $view_mode === 'live' ? 'historical' : 'live' ?>" class="btn btn-outline-secondary w-100">
                                    <i data-lucide="circle" class="icon-lucide fas fa-<?= $view_mode === 'live' ? 'history' : 'bolt' ?>"></i> Switch to <?= $view_mode === 'live' ? 'Historical' : 'Live' ?> Mode
                                </a>
                            </div>
                        </form>

                        <!-- Table -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Source</th>
                                        <th>Destination</th>
                                        <th>Service</th>
                                        <th class="text-center">Action</th>
                                        <th>Policy ID</th>
                                        <th>Bandwidth</th>
                                        <th>Device</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody id="flowTableBody">
                                    <?php if (empty($flows)): ?>
                                        <tr><td colspan="9" class="text-center text-muted py-5"><i data-lucide="inbox" class="icon-lucide fa-3x mb-3 d-block"></i>No traffic flows match your current filters</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($flows as $f): ?>
                                            <tr>
                                                <td>
                                                    <?= date('H:i:s', strtotime($f['time'])) ?>
                                                    <?= $f['is_archive'] ? '<span class="archive-indicator" title="Archived Log"><i data-lucide="archive" class="icon-lucide"></i></span>' : '' ?>
                                                </td>
                                                <td><code><?= $f['src'] ?></code></td>
                                                <td><code><?= $f['dst'] ?></code></td>
                                                <td><?= $f['service'] ?></td>
                                                <td class="text-center"><span class="action-badge action-<?= $f['action'] ?>"><i data-lucide="circle" class="icon-lucide fas fa-<?= $f['icon'] ?>"></i></span></td>
                                                <td><?= $f['policyid'] ?></td>
                                                <td>
                                                    <div class="bandwidth">
                                                        <span class="up"><i data-lucide="arrow-up" class="icon-lucide"></i> <?= number_format($f['sentbyte'] / 1024, 1) ?> KB</span>
                                                        <span class="down"><i data-lucide="arrow-down" class="icon-lucide"></i> <?= number_format($f['rcvdbyte'] / 1024, 1) ?> KB</span>
                                                    </div>
                                                </td>
                                                <td><?= $f['devname'] ?></td>
                                                <td><span class="details-link" onclick="showDetails(<?= $f['log_id'] ?>)"><i data-lucide="search" class="icon-lucide"></i></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <nav aria-label="Pagination">
                            <ul class="pagination justify-content-center">
                                <?php $total_pages = ceil($total / $per_page); ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>">Previous</a>
                                </li>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Traffic Flow Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let flowsData = <?= json_encode($flows) ?>;
let isPaused = false;
let secondsLeft = 5;
let refreshInterval;
let countdownInterval;

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function showDetails(logId) {
    const flow = flowsData.find(f => f.log_id === logId);
    if (!flow) return;
    
    const p = flow.full_parsed;
    const modalBody = document.getElementById('modalBody');
    const modalTitle = document.getElementById('modalTitle');
    
    modalTitle.textContent = `Traffic Flow Details - ${flow.devname}`;
    
    modalBody.innerHTML = `
        <div class="row g-3">
            <div class="col-md-6">
                <h6><i data-lucide="info" class="icon-lucide"></i> General Information</h6>
                <ul class="list-unstyled">
                    <li><strong>Log ID:</strong> ${p.logid || '-'}</li>
                    <li><strong>Session ID:</strong> ${p.sessionid || '-'}</li>
                    <li><strong>Virtual Domain:</strong> ${p.vd || 'root'}</li>
                    <li><strong>Date/Time:</strong> ${p.date || ''} ${p.time || ''}</li>
                    <li><strong>Duration:</strong> ${p.duration || '-'} sec</li>
                    <li><strong>Archive Status:</strong> ${flow.is_archive ? '<span class="text-warning"><i data-lucide="archive" class="icon-lucide"></i> Archived</span>' : '<span class="text-success"><i data-lucide="zap" class="icon-lucide"></i> Live</span>'}</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i data-lucide="shield" class="icon-lucide"></i> Action & Policy</h6>
                <ul class="list-unstyled">
                    <li><strong>Action:</strong> <span class="action-badge action-${flow.action}">${flow.action.toUpperCase()}</span></li>
                    <li><strong>Policy ID:</strong> ${p.policyid || '-'}</li>
                    <li><strong>Policy Name:</strong> ${p.policyname || '-'}</li>
                    <li><strong>Policy Type:</strong> ${p.policytype || '-'}</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i data-lucide="arrow-right" class="icon-lucide"></i> Source Information</h6>
                <ul class="list-unstyled">
                    <li><strong>IP:</strong> <code>${p.srcip || '-'}</code></li>
                    <li><strong>Port:</strong> ${p.srcport || '-'}</li>
                    <li><strong>Interface:</strong> ${p.srcintf || '-'}</li>
                    <li><strong>Country:</strong> ${p.srccountry || 'Reserved'}</li>
                    <li><strong>MAC:</strong> ${p.srcmac || '-'}</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i data-lucide="arrow-left" class="icon-lucide"></i> Destination Information</h6>
                <ul class="list-unstyled">
                    <li><strong>IP:</strong> <code>${p.dstip || '-'}</code></li>
                    <li><strong>Port:</strong> ${p.dstport || '-'}</li>
                    <li><strong>Interface:</strong> ${p.dstintf || '-'}</li>
                    <li><strong>Country:</strong> ${p.dstcountry || 'Reserved'}</li>
                    <li><strong>MAC:</strong> ${p.dstmac || '-'}</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i data-lucide="server" class="icon-lucide"></i> Application & Service</h6>
                <ul class="list-unstyled">
                    <li><strong>Service:</strong> ${p.service || '-'}</li>
                    <li><strong>Protocol:</strong> ${p.proto || '-'}</li>
                    <li><strong>Application:</strong> ${p.app || p.application || '-'}</li>
                    <li><strong>App Category:</strong> ${p.appcat || '-'}</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6><i data-lucide="arrow-right-left" class="icon-lucide"></i> Traffic Statistics</h6>
                <ul class="list-unstyled">
                    <li><strong>Sent Bytes:</strong> ${formatBytes(parseInt(p.sentbyte || 0))}</li>
                    <li><strong>Received Bytes:</strong> ${formatBytes(parseInt(p.rcvdbyte || 0))}</li>
                    <li><strong>Sent Packets:</strong> ${p.sentpkt || '-'}</li>
                    <li><strong>Received Packets:</strong> ${p.rcvdpkt || '-'}</li>
                </ul>
            </div>
            <div class="col-12">
                <h6><i data-lucide="network" class="icon-lucide"></i> Device Information</h6>
                <ul class="list-unstyled">
                    <li><strong>Device Name:</strong> ${p.devname || '-'}</li>
                    <li><strong>FortiGate IP:</strong> ${flow.source_ip}</li>
                    <li><strong>Device ID:</strong> ${p.devid || '-'}</li>
                </ul>
            </div>
            <div class="col-12">
                <h6><i data-lucide="code" class="icon-lucide"></i> Raw Message</h6>
                <pre class="small bg-dark text-light p-3 rounded overflow-auto" style="max-height: 200px;">${flow.raw}</pre>
            </div>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
}

// Refresh table data
async function refreshTable() {
    if (isPaused) return;
    
    try {
        const params = new URLSearchParams(window.location.search);
        params.set('ajax', '1');
        
        const response = await fetch('?' + params.toString());
        const data = await response.json();
        
        flowsData = data.flows;
        
        // Update stats
        document.getElementById('totalFlows').textContent = data.total;
        
        const accepted = data.flows.filter(f => f.action === 'accept').length;
        const denied = data.flows.filter(f => f.action === 'deny').length;
        const timeout = data.flows.filter(f => f.action === 'timeout').length;
        const close = data.flows.filter(f => f.action === 'close').length;
        
        document.getElementById('acceptedFlows').textContent = accepted;
        document.getElementById('deniedFlows').textContent = denied;
        document.getElementById('timeoutFlows').textContent = timeout;
        document.getElementById('closeFlows').textContent = close;
        
        // Update table body
        const tbody = document.getElementById('flowTableBody');
        if (data.flows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-5"><i data-lucide="inbox" class="icon-lucide fa-3x mb-3 d-block"></i>No traffic flows match your current filters</td></tr>';
        } else {
            tbody.innerHTML = data.flows.map(f => `
                <tr>
                    <td>
                        ${new Date(f.time).toLocaleTimeString()}
                        ${f.is_archive ? '<span class="archive-indicator" title="Archived Log"><i data-lucide="archive" class="icon-lucide"></i></span>' : ''}
                    </td>
                    <td><code>${f.src}</code></td>
                    <td><code>${f.dst}</code></td>
                    <td>${f.service}</td>
                    <td class="text-center"><span class="action-badge action-${f.action}"><i data-lucide="circle" class="icon-lucide fas fa-${f.icon}"></i></span></td>
                    <td>${f.policyid}</td>
                    <td>
                        <div class="bandwidth">
                            <span class="up"><i data-lucide="arrow-up" class="icon-lucide"></i> ${formatBytes(f.sentbyte)}</span>
                            <span class="down"><i data-lucide="arrow-down" class="icon-lucide"></i> ${formatBytes(f.rcvdbyte)}</span>
                        </div>
                    </td>
                    <td>${f.devname}</td>
                    <td><span class="details-link" onclick="showDetails(${f.log_id})"><i data-lucide="search" class="icon-lucide"></i></span></td>
                </tr>
            `).join('');
        }
    } catch (error) {
        console.error('Refresh error:', error);
    }
}

// Countdown timer
function updateCountdown() {
    if (isPaused) return;
    secondsLeft--;
    if (secondsLeft <= 0) {
        secondsLeft = 5;
        refreshTable();
    }
    document.getElementById('refreshTimer').textContent = secondsLeft + 's';
}

// Export to CSV
function exportToCSV() {
    const rows = [['Time', 'Source', 'Destination', 'Service', 'Action', 'Policy ID', 'Sent', 'Received', 'Device', 'Archive']];
    flowsData.forEach(f => {
        rows.push([
            new Date(f.time).toLocaleString(),
            f.src,
            f.dst,
            f.service,
            f.action.toUpperCase(),
            f.policyid,
            formatBytes(f.sentbyte),
            formatBytes(f.rcvdbyte),
            f.devname,
            f.is_archive ? 'Yes' : 'No'
        ]);
    });
    
    const csv = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'traffic_flows_' + new Date().toISOString().slice(0,10) + '_' + '<?= $view_mode ?>' + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    // Calculate initial stats
    const accepted = flowsData.filter(f => f.action === 'accept').length;
    const denied = flowsData.filter(f => f.action === 'deny').length;
    const timeout = flowsData.filter(f => f.action === 'timeout').length;
    const close = flowsData.filter(f => f.action === 'close').length;
    document.getElementById('acceptedFlows').textContent = accepted;
    document.getElementById('deniedFlows').textContent = denied;
    document.getElementById('timeoutFlows').textContent = timeout;
    document.getElementById('closeFlows').textContent = close;
    
    // Start auto-refresh (only for live mode)
    <?php if ($view_mode === 'live'): ?>
        refreshInterval = setInterval(refreshTable, 5000);
        countdownInterval = setInterval(updateCountdown, 1000);
    <?php endif; ?>

    // Pause button
    document.getElementById('pauseBtn').addEventListener('click', () => {
        isPaused = !isPaused;
        document.getElementById('pauseBtn').innerHTML = isPaused ? '<i data-lucide="play" class="icon-lucide"></i> Resume' : '<i data-lucide="pause" class="icon-lucide"></i> Pause';
    });

    // Export button
    document.getElementById('exportBtn').addEventListener('click', exportToCSV);
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    clearInterval(refreshInterval);
    clearInterval(countdownInterval);
});
</script>
</body>
</html>
