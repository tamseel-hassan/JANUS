<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/db_config.php';
date_default_timezone_set('Asia/Karachi');

$query = "
    SELECT d.id, d.name, d.ip, d.type, d.model, d.city, d.sub_office, d.country,
           COALESCE(dsc.status, 'down') as status,
           dsc.rtt_avg, dsc.checked_at, dsc.last_status_change
    FROM devices d
    LEFT JOIN device_status_cache dsc ON d.id = dsc.device_id
    ORDER BY d.name
";
$result = mysqli_query($con, $query);

$devices = [];
$up = 0; $total_rtt = 0; $rtt_count = 0;
$now_ts = time();
$stale_threshold_seconds = 300;

while ($row = mysqli_fetch_assoc($result)) {
    $checked_at_ts = $row['checked_at'] ? strtotime($row['checked_at']) : null;
    $is_stale = ($checked_at_ts === null) || (($now_ts - $checked_at_ts) > $stale_threshold_seconds);
    $never_checked = $checked_at_ts === null;

    if ($row['status'] === 'up') {
        $up++;
        if ($row['rtt_avg']) { $total_rtt += $row['rtt_avg']; $rtt_count++; }
    }

    $devices[] = [
        'id' => (int)$row['id'], 'name' => $row['name'], 'ip' => $row['ip'],
        'type' => $row['type'], 'model' => $row['model'],
        'city' => $row['city'], 'sub_office' => $row['sub_office'],
        'country' => $row['country'], 'status' => $row['status'],
        'rtt_avg' => $row['rtt_avg'] !== null ? (float)$row['rtt_avg'] : null,
        'checked_at' => $row['checked_at'],
        'last_status_change' => $row['last_status_change'],
        'is_stale' => $is_stale, 'never_checked' => $never_checked,
    ];
}

$device_count = count($devices);
$down = $device_count - $up;
$avg_rtt = $rtt_count ? round($total_rtt / $rtt_count, 1) : 0;

mysqli_close($con);

echo json_encode([
    'status' => 'success',
    'summary' => ['total' => $device_count, 'up' => $up, 'down' => $down, 'avg_rtt' => $avg_rtt],
    'devices' => $devices,
    'server_time' => date('Y-m-d H:i:s'),
]);
