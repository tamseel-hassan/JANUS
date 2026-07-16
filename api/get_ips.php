<?php
require_once __DIR__ . '/../db_config.php';
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
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

echo json_encode([
    'logs' => $logs,
    'total' => $total,
    'page' => $page,
    'total_pages' => $total_pages
]);
exit;
