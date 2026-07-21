<?php
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../log_parsers.php';

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

$page          = max(1, intval($_GET['page'] ?? 1));
$per_page      = 100;
$offset        = ($page - 1) * $per_page;
$device_ip     = trim($_GET['device'] ?? '');
$srcip_filter  = trim($_GET['srcip'] ?? '');
$dstip_filter  = trim($_GET['dstip'] ?? '');
$srcport_filter = trim($_GET['srcport'] ?? '');
$dstport_filter = trim($_GET['dstport'] ?? '');
$service_filter = trim($_GET['service'] ?? '');
$action_filter = trim($_GET['action'] ?? '');
$view_mode     = $_GET['view'] ?? 'live';

// Fetch devices
$devices = [];
$res = mysqli_query($con, "
    SELECT DISTINCT se.source_ip, ss.appliance_type
    FROM syslog_entries se
    LEFT JOIN syslog_sources ss ON se.source_id = ss.id
    ORDER BY se.source_ip
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $devices[] = $r;
    }
}

$filter_conditions = [];
$params = [];
$types  = '';

if ($device_ip !== '') {
    $filter_conditions[] = "source_ip = ?";
    $params[] = $device_ip;
    $types .= 's';
}

if ($srcip_filter !== '') {
    $filter_conditions[] = "(message LIKE ? OR message LIKE ? OR message LIKE ?)";
    $params[] = '%srcip=' . $srcip_filter . '%';
    $params[] = '%src=' . $srcip_filter . '%';
    $params[] = '%' . $srcip_filter . '%';
    $types .= 'sss';
}

if ($dstip_filter !== '') {
    $filter_conditions[] = "(message LIKE ? OR message LIKE ? OR message LIKE ?)";
    $params[] = '%dstip=' . $dstip_filter . '%';
    $params[] = '%dst=' . $dstip_filter . '%';
    $params[] = '%' . $dstip_filter . '%';
    $types .= 'sss';
}

if ($srcport_filter !== '') {
    $filter_conditions[] = "message LIKE ?";
    $params[] = '%' . $srcport_filter . '%';
    $types .= 's';
}

if ($dstport_filter !== '') {
    $filter_conditions[] = "message LIKE ?";
    $params[] = '%' . $dstport_filter . '%';
    $types .= 's';
}

if ($service_filter !== '') {
    $filter_conditions[] = "(message LIKE ? OR message LIKE ?)";
    $params[] = '%service=' . $service_filter . '%';
    $params[] = '%' . $service_filter . '%';
    $types .= 'ss';
}

if ($action_filter !== '') {
    $accept_terms = ['accept' => ['accept', 'permit', 'allow', 'pass', 'built'], 'deny' => ['deny', 'block', 'reject', 'drop']];
    $terms = $accept_terms[$action_filter] ?? [$action_filter];
    $ors = [];
    foreach ($terms as $t) {
        $ors[] = "message LIKE ?";
        $params[] = '%' . $t . '%';
        $types .= 's';
    }
    $filter_conditions[] = '(' . implode(' OR ', $ors) . ')';
}

$filter_where = empty($filter_conditions) ? '' : ' AND ' . implode(' AND ', $filter_conditions);
$traffic_sig = traffic_signature_sql('message');

$archive_exists = false;
$check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
if ($check && mysqli_num_rows($check) > 0) {
    $archive_exists = true;
}

$use_union = ($view_mode === 'historical' && $archive_exists);

if ($use_union) {
    $count_query = "
        SELECT COUNT(*) FROM (
            SELECT se.id
            FROM syslog_entries se
            WHERE $traffic_sig
              $filter_where

            UNION ALL

            SELECT se.id
            FROM syslog_entries_archive se
            WHERE $traffic_sig
              $filter_where
        ) AS combined
    ";

    $query = "
        SELECT log_id, received_at, message, source_ip, source_table FROM (
            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'live' as source_table
            FROM syslog_entries se
            WHERE $traffic_sig
              $filter_where

            UNION ALL

            SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'archive' as source_table
            FROM syslog_entries_archive se
            WHERE $traffic_sig
              $filter_where
        ) AS combined_results
        ORDER BY received_at DESC
        LIMIT ? OFFSET ?
    ";
} else {
    $count_query = "
        SELECT COUNT(*)
        FROM syslog_entries se
        WHERE $traffic_sig
          $filter_where
    ";

    $query = "
        SELECT se.id AS log_id, se.received_at, se.message, se.source_ip, 'live' as source_table
        FROM syslog_entries se
        WHERE $traffic_sig
          $filter_where
        ORDER BY se.received_at DESC
        LIMIT ? OFFSET ?
    ";
}

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
        $total = 0;
    }
} else {
    $result = mysqli_query($con, $count_query);
    $total = mysqli_fetch_row($result)[0] ?? 0;
}

$total_pages = ceil($total / $per_page);

$final_params = $use_union ? array_merge($params, $params, [$per_page, $offset]) : array_merge($params, [$per_page, $offset]);
$final_types  = $use_union ? $types . $types . 'ii' : $types . 'ii';

$stmt = $con->prepare($query);
if ($stmt) {
    if (!empty($final_params)) {
        $stmt->bind_param($final_types, ...$final_params);
    } else {
        $stmt->bind_param('ii', $per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
}

$flows = [];
$vendor_counts = [];

if (isset($result) && $result) {
    while ($row = $result->fetch_assoc()) {
        $entry = normalize_log_entry($row['message'], $row['source_ip']);
        $vendor_counts[$entry['vendor']] = ($vendor_counts[$entry['vendor']] ?? 0) + 1;

        $flows[] = [
            'log_id'     => $row['log_id'],
            'time'       => $row['received_at'],
            'vendor'     => $entry['vendor'],
            'parsed'     => $entry['parsed'],
            'src'        => $entry['src_ip'] ? $entry['src_ip'] . ':' . ($entry['src_port'] ?? '-') : '—',
            'dst'        => $entry['dst_ip'] ? $entry['dst_ip'] . ':' . ($entry['dst_port'] ?? '-') : '—',
            'service'    => $entry['service'] ?? (($entry['protocol'] ?? '-') . '/' . ($entry['dst_port'] ?? '-')),
            'action'     => $entry['action'] ?: 'unknown',
            'policyid'   => $entry['policy'] ?? '-',
            'devname'    => $entry['devname'] ?: $row['source_ip'],
            'source_ip'  => $row['source_ip'],
            'sentbyte'   => $entry['bytes_sent'],
            'rcvdbyte'   => $entry['bytes_recv'],
            'normalized' => $entry,
            'raw'        => $row['message'],
            'is_archive' => isset($row['source_table']) && $row['source_table'] === 'archive'
        ];
    }
    $stmt->close();
}

echo json_encode([
    'devices' => $devices,
    'flows' => $flows,
    'total' => $total,
    'total_pages' => $total_pages,
    'current_page' => $page,
    'vendor_counts' => $vendor_counts,
    'view_mode' => $view_mode
]);
