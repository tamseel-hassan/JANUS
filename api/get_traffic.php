<?php
// fflow.php - Professional Traffic Flow Analyzer with Archive Support
// This file is optimized to handle millions of logs by:
// 1. Querying only last 6 hours of data in "Live" mode (super fast)
// 2. Querying both live + archive tables in "Historical" mode
// 3. Using indexes and efficient queries
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

echo json_encode(['total' => $total, 'flows' => $flows]);
exit;
