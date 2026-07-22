<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Error']);
    exit;
}

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Decode JSON body
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$action = $data['action'] ?? '';

switch ($action) {
    case 'add_source':
        $appliance_type = trim($data['appliance_type'] ?? '');
        $source_ip = trim($data['source_ip'] ?? '');
        $added_by = intval($_SESSION['id'] ?? 0);

        if ($appliance_type === '' || !filter_var($source_ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit;
        }

        $stmt = mysqli_prepare($con, "INSERT INTO syslog_sources (appliance_type, source_ip, added_by) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssi', $appliance_type, $source_ip, $added_by);
        if (@mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
        } else {
            $err = mysqli_error($con);
            if (stripos($err, 'Duplicate') !== false) {
                echo json_encode(['success' => false, 'error' => 'Source already exists']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
        }
        mysqli_stmt_close($stmt);
        break;

    case 'toggle_source':
        $id = intval($data['id'] ?? 0);
        $stmt = mysqli_prepare($con, "SELECT is_active FROM syslog_sources WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $cur_active);
        if (mysqli_stmt_fetch($stmt)) {
            mysqli_stmt_close($stmt);
            $new = $cur_active ? 0 : 1;
            $stmt = mysqli_prepare($con, "UPDATE syslog_sources SET is_active = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $new, $id);
            mysqli_stmt_execute($stmt);
            echo json_encode(['success' => true, 'new_status' => $new]);
        } else {
            mysqli_stmt_close($stmt);
            echo json_encode(['success' => false, 'error' => 'Source not found']);
        }
        break;

    case 'delete_source':
        $id = intval($data['id'] ?? 0);
        $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries WHERE source_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($con, "DELETE FROM syslog_sources WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        mysqli_stmt_close($stmt);
        break;

    case 'purge_logs':
        $id = intval($data['id'] ?? 0);
        $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries WHERE source_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        mysqli_stmt_close($stmt);
        break;

    case 'save_retention':
        $hours = intval($data['hours'] ?? 0);
        if ($hours < 1 || $hours > 8760) {
            echo json_encode(['success' => false, 'error' => 'Hours must be between 1 and 8760']);
            exit;
        }
        $stmt = mysqli_prepare($con, "UPDATE system_config SET `value` = ? WHERE `key` = 'archive_retention_hours'");
        mysqli_stmt_bind_param($stmt, 'i', $hours);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save settings']);
        }
        mysqli_stmt_close($stmt);
        break;

    case 'cleanup_archive':
        $hours = intval($data['hours'] ?? 0);
        if ($hours < 1) {
            echo json_encode(['success' => false, 'error' => 'Invalid hours']);
            exit;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
        if (mysqli_num_rows($check) > 0) {
            $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries_archive WHERE received_at < ?");
            mysqli_stmt_bind_param($stmt, 's', $cutoff);
            if (mysqli_stmt_execute($stmt)) {
                $deleted = mysqli_stmt_affected_rows($stmt);
                echo json_encode(['success' => true, 'deleted' => $deleted]);
            } else {
                echo json_encode(['success' => false, 'error' => mysqli_error($con)]);
            }
            mysqli_stmt_close($stmt);
        } else {
            echo json_encode(['success' => false, 'error' => 'Archive table not found']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
