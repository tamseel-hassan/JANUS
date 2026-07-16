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

date_default_timezone_set('Asia/Karachi');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($type && $id) {
    $detail_data = null;
    if ($type === 'device') {
        $stmt = $con->prepare("SELECT * FROM devices WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $detail_data = $result->fetch_assoc();
        $stmt->close();
    } elseif ($type === 'link') {
        $stmt = $con->prepare("
            SELECT l.*, d1.name AS from_name, d1.ip AS from_ip, d2.name AS to_name, d2.ip AS to_ip
            FROM links l
            LEFT JOIN devices d1 ON l.from_device_id = d1.id
            LEFT JOIN devices d2 ON l.to_device_id = d2.id
            WHERE l.id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $detail_data = $result->fetch_assoc();
        $stmt->close();
    }

    if ($detail_data) {
        $end_time = date('Y-m-d H:i:s');
        $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        $logs_query = "
            SELECT status, checked_at, rtt_avg
            FROM ping_logs
            WHERE " . ($type === 'device' ? 'device_id' : 'link_id') . " = ? 
            AND checked_at >= ? AND checked_at <= ?
            ORDER BY checked_at ASC
        ";
        $stmt = $con->prepare($logs_query);
        $stmt->bind_param('iss', $id, $start_time, $end_time);
        $stmt->execute();
        $logs_result = $stmt->get_result();
        
        $logs = [];
        $sum_rtt = 0;
        $count_rtt = 0;
        $rtt_labels = [];
        $rtt_data = [];
        
        while ($row = mysqli_fetch_assoc($logs_result)) {
            $logs[] = $row;
            if ($row['status'] === 'up' && $row['rtt_avg'] !== null) {
                $sum_rtt += floatval($row['rtt_avg']);
                $count_rtt++;
            }
            $rtt_labels[] = $row['checked_at'];
            $rtt_data[] = $row['rtt_avg'] ?? 0;
        }
        $stmt->close();

        $avg_rtt = $count_rtt > 0 ? round($sum_rtt / $count_rtt, 1) : null;

        $periods = [];
        $total_time = strtotime($end_time) - strtotime($start_time);
        $total_up_time = 0;
        $total_down_time = 0;
        
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
        }
        $availability = $total_time > 0 ? round(($total_up_time / $total_time) * 100, 2) : 0;

        echo json_encode([
            'detail_data' => $detail_data,
            'report_data' => [
                'periods' => $periods,
                'total_up_time' => $total_up_time,
                'total_down_time' => $total_down_time,
                'availability' => $availability,
                'rtt_labels' => $rtt_labels,
                'rtt_data' => $rtt_data,
                'avg_rtt' => $avg_rtt
            ]
        ]);
    } else {
        echo json_encode(['error' => 'No details found for this item.']);
    }
} elseif (!empty($q)) {
    $q_escaped = '%' . $q . '%';
    $results = [];

    $devices_query = "SELECT id, 'device' AS type, name, ip, city, sub_office, country 
                      FROM devices 
                      WHERE name LIKE ? OR ip LIKE ?";
    $stmt = $con->prepare($devices_query);
    $stmt->bind_param('ss', $q_escaped, $q_escaped);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();

    $links_query = "
        SELECT l.id, 'link' AS type, l.name, '' AS ip, d1.name AS from_name, d2.name AS to_name
        FROM links l
        LEFT JOIN devices d1 ON l.from_device_id = d1.id
        LEFT JOIN devices d2 ON l.to_device_id = d2.id
        WHERE l.name LIKE ?
    ";
    $stmt = $con->prepare($links_query);
    $stmt->bind_param('s', $q_escaped);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();

    echo json_encode(['results' => $results, 'query' => $q]);
} else {
    echo json_encode(['error' => 'No search term provided']);
}
