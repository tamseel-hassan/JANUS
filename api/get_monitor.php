<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    echo json_encode(['error' => 'DB Error']);
    http_response_code(500);
    exit;
}

$query = "
    SELECT d.id, d.name, d.ip, d.type, d.model, d.city, d.sub_office, d.country,
           COALESCE(dsc.status, 'down') as status,
           dsc.rtt_avg,
           dsc.checked_at,
           dsc.last_status_change
    FROM devices d
    LEFT JOIN device_status_cache dsc ON d.id = dsc.device_id
    ORDER BY d.name
";
$result = mysqli_query($con, $query);
$devices = [];
$up = 0;
$total_rtt = 0;
$rtt_count = 0;

while($row = mysqli_fetch_assoc($result)) {
    // Typecast to appropriate types for JSON
    $row['id'] = (int)$row['id'];
    $row['rtt_avg'] = $row['rtt_avg'] !== null ? (float)$row['rtt_avg'] : null;
    $devices[] = $row;
    
    if($row['status'] === 'up') {
        $up++;
        if($row['rtt_avg']) {
            $total_rtt += $row['rtt_avg'];
            $rtt_count++;
        }
    }
}

$device_count = count($devices);
$down = $device_count - $up;
$avg_rtt = $rtt_count ? round($total_rtt/$rtt_count, 1) : 0;

mysqli_close($con);

echo json_encode([
    'stats' => [
        'total' => $device_count,
        'up' => $up,
        'down' => $down,
        'avg_rtt' => $avg_rtt
    ],
    'devices' => $devices
]);
