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

$devices = [];
$res = mysqli_query($con, "SELECT id, name, city, sub_office, country FROM devices ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $devices[] = $row;
}

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;
$time_range = isset($_GET['time_range']) ? $_GET['time_range'] : '24h';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;

if (!$device_id) {
    echo json_encode([
        'devices' => $devices,
        'report_data' => null
    ]);
    exit;
}

$end_time = date('Y-m-d H:i:s');
switch ($time_range) {
    case '24h':
        $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
        break;
    case '7d':
        $start_time = date('Y-m-d H:i:s', strtotime('-7 days'));
        break;
    case '30d':
        $start_time = date('Y-m-d H:i:s', strtotime('-30 days'));
        break;
    case 'custom':
        if ($start_date && $end_date) {
            $start_time = $start_date . ' 00:00:00';
            $end_time = $end_date . ' 23:59:59';
        } else {
            $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
        }
        break;
    default:
        $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
}

$device_info = null;
foreach ($devices as $d) {
    if ($d['id'] == $device_id) {
        $device_info = $d;
        break;
    }
}

if (!$device_info) {
    echo json_encode(['error' => 'Device not found']);
    exit;
}

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
$stmt->bind_param('ississ', $device_id, $start_time, $end_time, $device_id, $start_time, $end_time);
$stmt->execute();
$logs_result = $stmt->get_result();

$logs = [];
$sum_rtt = 0;
$count_rtt = 0;
$rtt_data = [];
$rtt_labels = [];

while ($row = $logs_result->fetch_assoc()) {
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
$current_status = 'unknown';

if (!empty($logs)) {
    $current_status = $logs[count($logs) - 1]['status'];
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

// To avoid sending massive amounts of data for the chart, we sample it if it's too big
$sampled_rtt_labels = [];
$sampled_rtt_data = [];
$step = ceil(count($rtt_data) / 200); // Target ~200 points max
if ($step < 1) $step = 1;

for ($i = 0; $i < count($rtt_data); $i += $step) {
    $sampled_rtt_labels[] = $rtt_labels[$i];
    $sampled_rtt_data[] = $rtt_data[$i];
}

echo json_encode([
    'devices' => $devices,
    'report_data' => [
        'device_info' => $device_info,
        'current_status' => $current_status,
        'periods' => array_reverse($periods), // Most recent first for UI
        'total_up_time' => $total_up_time,
        'total_down_time' => $total_down_time,
        'availability' => $availability,
        'avg_rtt' => $avg_rtt,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'rtt_labels' => $sampled_rtt_labels,
        'rtt_data' => $sampled_rtt_data
    ]
]);
