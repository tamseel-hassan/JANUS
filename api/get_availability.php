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
    $end_time = date('Y-m-d H:i:s');
    switch ($time_range) {
        case '24h': $start_time = date('Y-m-d H:i:s', strtotime('-24 hours')); break;
        case '7d': $start_time = date('Y-m-d H:i:s', strtotime('-7 days')); break;
        case '30d': $start_time = date('Y-m-d H:i:s', strtotime('-30 days')); break;
        case 'custom':
            if ($start_datetime && $end_datetime) {
                $start_time = str_replace('T', ' ', $start_datetime) . ':00';
                $end_time = str_replace('T', ' ', $end_datetime) . ':59';
                if (!strtotime($start_time) || !strtotime($end_time)) {
                    $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
                    $end_time = date('Y-m-d H:i:s');
                }
            } else {
                $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
            }
            break;
        default:
            $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
    }

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
        $logs_result = $stmt->get_result();
        
        $logs = [];
        $sum_rtt = 0;
        $count_rtt = 0;
        
        while ($row = mysqli_fetch_assoc($logs_result)) {
            $logs[] = $row;
            if ($row['status'] === 'up' && $row['rtt_avg'] !== null) {
                $sum_rtt += $row['rtt_avg'];
                $count_rtt++;
            }
            $rtt_data[] = [
                'time' => $row['checked_at'],
                'rtt' => $row['rtt_avg'] !== null ? floatval($row['rtt_avg']) : null,
                'status' => $row['status']
            ];
        }
        $stmt->close();

        $avg_rtt = $count_rtt > 0 ? round($sum_rtt / $count_rtt, 1) : 0;

        $periods = [];
        $total_time = strtotime($end_time) - strtotime($start_time);
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

            $duration = strtotime($end_time) - strtotime($current_start);
            $periods[] = [
                'status' => $prev_status,
                'start' => $current_start,
                'end' => $end_time,
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
                    $comments_query = "SELECT * FROM event_comments WHERE " . implode(' OR ', $comment_conditions);
                    $stmt = $con->prepare($comments_query);
                    $stmt->bind_param($comment_types, ...$comment_params);
                    $stmt->execute();
                    $comments_result = $stmt->get_result();
                    while ($comment_row = $comments_result->fetch_assoc()) {
                        $event_comments[$comment_row['event_start_time']] = $comment_row;
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
            'start_time' => $start_time,
            'end_time' => $end_time,
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
