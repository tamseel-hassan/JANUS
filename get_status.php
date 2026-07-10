<?php
// get_status.php - Returns current device and link statuses
session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

require_once __DIR__ . '/db_config.php';

date_default_timezone_set('Asia/Karachi');

$devices_query = "
    SELECT d.id, d.type,
           COALESCE(dsc.status, 'down') as status,
           dsc.rtt_avg, dsc.checked_at, dsc.last_status_change
    FROM devices d
    LEFT JOIN device_status_cache dsc ON d.id = dsc.device_id
    ORDER BY d.name
";
$devices_result = mysqli_query($con, $devices_query);
$devices = [];
while ($row = mysqli_fetch_assoc($devices_result)) {
    $devices[] = [
        'id' => (int)$row['id'],
        'type' => $row['type'] ?: 'others',
        'status' => $row['status'] ?: 'down',
        'rtt_avg' => $row['rtt_avg'],
        'checked_at' => $row['checked_at'],
        'last_status_change' => $row['last_status_change']
    ];
}

$links_query = "
    SELECT l.id,
           COALESCE((SELECT status FROM ping_logs WHERE link_id = l.id ORDER BY checked_at DESC LIMIT 1), 'down') as status
    FROM links l ORDER BY l.name
";
$links_result = mysqli_query($con, $links_query);
$links = [];
while ($row = mysqli_fetch_assoc($links_result)) {
    $links[] = ['id' => (int)$row['id'], 'status' => $row['status'] ?: 'down'];
}

mysqli_close($con);

header('Content-Type: application/json');
echo json_encode([
    'devices' => $devices,
    'links' => $links,
    'timestamp' => date('Y-m-d H:i:s')
]);
