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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (isset($data['action']) && $data['action'] === 'save_comment') {
        $device_id = intval($data['device_id']);
        $event_start = mysqli_real_escape_string($con, $data['event_start']);
        $event_end = !empty($data['event_end']) ? mysqli_real_escape_string($con, $data['event_end']) : null;
        $comments = mysqli_real_escape_string($con, $data['comments']);
        $action_taken = mysqli_real_escape_string($con, $data['action_taken']);
        $escalation_level = intval($data['escalation_level']);
        $vendor_contacted = mysqli_real_escape_string($con, $data['vendor_contacted']);
        $ticket_number = mysqli_real_escape_string($con, $data['ticket_number']);
        $resolution_time = !empty($data['resolution_time']) ? mysqli_real_escape_string($con, $data['resolution_time']) : null;
        
        $check_stmt = $con->prepare("SELECT id FROM event_comments WHERE device_id = ? AND event_start_time = ?");
        $check_stmt->bind_param('is', $device_id, $event_start);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $stmt = $con->prepare("UPDATE event_comments SET comments = ?, action_taken = ?, escalation_level = ?, vendor_contacted = ?, ticket_number = ?, resolution_time = ?, event_end_time = ? WHERE device_id = ? AND event_start_time = ?");
            $stmt->bind_param('ssissssis', $comments, $action_taken, $escalation_level, $vendor_contacted, $ticket_number, $resolution_time, $event_end, $device_id, $event_start);
        } else {
            $stmt = $con->prepare("INSERT INTO event_comments (device_id, event_start_time, event_end_time, comments, action_taken, escalation_level, vendor_contacted, ticket_number, resolution_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssisss', $device_id, $event_start, $event_end, $comments, $action_taken, $escalation_level, $vendor_contacted, $ticket_number, $resolution_time);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => 'Event comment saved successfully']);
        } else {
            echo json_encode(['error' => 'Failed to save comment']);
        }
        exit;
    }
}

// GET fetching
$devices_query = "SELECT id, name, city, sub_office, country FROM devices ORDER BY name ASC";
$devices_result = mysqli_query($con, $devices_query);
$devices = [];
while ($row = mysqli_fetch_assoc($devices_result)) {
    $devices[] = $row;
}

$selected_device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;
$time_range = isset($_GET['time_range']) ? $_GET['time_range'] : '24h';
$start_datetime = isset($_GET['start_datetime']) ? $_GET['start_datetime'] : null;
$end_datetime = isset($_GET['end_datetime']) ? $_GET['end_datetime'] : null;

$report_data = null;
$rtt_data = [];

if ($selected_device_id && $time_range) {
    // Determine start and end times in UTC based on range
    $end_time_raw = time();
    switch ($time_range) {
        case '24h': $start_time_raw = strtotime('-24 hours'); break;
        case '7d': $start_time_raw = strtotime('-7 days'); break;
        case '30d': $start_time_raw = strtotime('-30 days'); break;
        case 'custom':
            if ($start_datetime && $end_datetime) {
                $start_time_raw = strtotime(str_replace('T', ' ', $start_datetime) . ':00');
                $end_time_raw = strtotime(str_replace('T', ' ', $end_datetime) . ':59');
                if (!$start_time_raw || !$end_time_raw) {
                    $start_time_raw = strtotime('-24 hours');
                    $end_time_raw = time();
                }
            } else {
                $start_time_raw = strtotime('-24 hours');
            }
            break;
        default:
            $start_time_raw = strtotime('-24 hours');
    }

    $start_time = gmdate('Y-m-d H:i:s', $start_time_raw);
    $end_time = gmdate('Y-m-d H:i:s', $end_time_raw);
    $start_time_local = date('Y-m-d H:i:s', $start_time_raw);
    $end_time_local = date('Y-m-d H:i:s', $end_time_raw);

    $device_info = null;
    foreach ($devices as $dev) {
        if ($dev['id'] == $selected_device_id) {
            $device_info = $dev;
            break;
        }
    }

    if ($device_info) {
        $logs_query = "
            (SELECT status, checked_at, rtt_avg
            FROM ping_logs
            WHERE device_id = ? AND link_id IS NULL
            AND checked_at >= ? AND checked_at <= ?)
            UNION ALL
            (SELECT status, checked_at, rtt_avg
            FROM ping_logs_archive
            WHERE device_id = ? AND link_id IS NULL
            AND checked_at >= ? AND checked_at <= ?)
            ORDER BY checked_at ASC
        ";
        
        $stmt = $con->prepare($logs_query);
        $stmt->bind_param('ississ', $selected_device_id, $start_time, $end_time, $selected_device_id, $start_time, $end_time);
        $stmt->execute();
        $stmt->bind_result($db_status, $db_checked_at, $db_rtt_avg);
        
        $logs = [];
        $sum_rtt = 0;
        $count_rtt = 0;
        
        while ($stmt->fetch()) {
            $local_checked_at = date('Y-m-d H:i:s', strtotime($db_checked_at . ' UTC'));
            $row = [
                'status' => $db_status,
                'checked_at' => $local_checked_at,
                'rtt_avg' => $db_rtt_avg
            ];
            $logs[] = $row;
            if ($db_status === 'up' && $db_rtt_avg !== null) {
                $sum_rtt += $db_rtt_avg;
                $count_rtt++;
            }
            $rtt_data[] = [
                'time' => $local_checked_at,
                'rtt' => $db_rtt_avg !== null ? floatval($db_rtt_avg) : null,
                'status' => $db_status
            ];
        }
        $stmt->close();

        $avg_rtt = $count_rtt > 0 ? round($sum_rtt / $count_rtt, 1) : 0;

        $periods = [];
        $total_time = $end_time_raw - $start_time_raw;
        $total_up_time = 0;
        $total_down_time = 0;
        $event_comments = [];

        if (!empty($logs)) {
            $current_start = $logs[0]['checked_at'];
            $prev_status = $logs[0]['status'];

            for ($i = 1; $i < count($logs); $i++) {
                if ($logs[$i]['status'] !== $prev_status) {
                    $period_end = $logs[$i]['checked_at'];
                    $duration = strtotime($period_end) - strtotime($current_start);
                    $periods[] = [
                        'status' => $prev_status,
                        'start' => $current_start,
                        'end' => $period_end,
                        'duration' => $duration
                    ];
                    if ($prev_status === 'up') $total_up_time += $duration;
                    else $total_down_time += $duration;
                    
                    $current_start = $period_end;
                    $prev_status = $logs[$i]['status'];
                }
            }

            $duration = strtotime($end_time_local) - strtotime($current_start);
            $periods[] = [
                'status' => $prev_status,
                'start' => $current_start,
                'end' => $end_time_local,
                'duration' => $duration
            ];
            if ($prev_status === 'up') $total_up_time += $duration;
            else $total_down_time += $duration;
            
            if (!empty($periods)) {
                $comment_conditions = [];
                $comment_params = [];
                $comment_types = '';
                foreach ($periods as $index => $period) {
                    $comment_conditions[] = "(device_id = ? AND event_start_time = ?)";
                    $comment_params[] = $selected_device_id;
                    $comment_params[] = $period['start'];
                    $comment_types .= 'is';
                }
                if (!empty($comment_conditions)) {
                    $comments_query = "SELECT id, device_id, event_start_time, event_end_time, comments, action_taken, escalation_level, vendor_contacted, ticket_number, resolution_time, created_at FROM event_comments WHERE " . implode(' OR ', $comment_conditions);
                    $stmt = $con->prepare($comments_query);
                    $stmt->bind_param($comment_types, ...$comment_params);
                    $stmt->execute();
                    $stmt->bind_result($c_id, $c_dev_id, $c_start, $c_end, $c_comments, $c_action, $c_level, $c_vendor, $c_ticket, $c_res, $c_created);
                    while ($stmt->fetch()) {
                        $event_comments[$c_start] = [
                            'id' => $c_id,
                            'device_id' => $c_dev_id,
                            'event_start_time' => $c_start,
                            'event_end_time' => $c_end,
                            'comments' => $c_comments,
                            'action_taken' => $c_action,
                            'escalation_level' => $c_level,
                            'vendor_contacted' => $c_vendor,
                            'ticket_number' => $c_ticket,
                            'resolution_time' => $c_res,
                            'created_at' => $c_created
                        ];
                    }
                    $stmt->close();
                }
            }
        }
        
        // merge comments into periods
        foreach ($periods as &$p) {
            if (isset($event_comments[$p['start']])) {
                $p['comment_data'] = $event_comments[$p['start']];
            } else {
                $p['comment_data'] = null;
            }
        }

        $availability = $total_time > 0 ? round(($total_up_time / $total_time) * 100, 2) : 0;
        $num_outages = 0;
        $sum_outage_duration = 0;
        foreach ($periods as $period) {
            if ($period['status'] === 'down') {
                $num_outages++;
                $sum_outage_duration += $period['duration'];
            }
        }
        $avg_outage_duration = $num_outages > 0 ? $sum_outage_duration / $num_outages : 0;

        $report_data = [
            'device' => $device_info,
            'start_time' => $start_time_local,
            'end_time' => $end_time_local,
            'total_up_time' => $total_up_time,
            'total_down_time' => $total_down_time,
            'availability_percent' => $availability,
            'avg_rtt' => $avg_rtt,
            'num_outages' => $num_outages,
            'avg_outage_duration' => $avg_outage_duration,
            'periods' => array_reverse($periods), // newest first
            'rtt_data' => $rtt_data
        ];
    }
}

echo json_encode([
    'devices' => $devices,
    'report' => $report_data
]);
exit;
