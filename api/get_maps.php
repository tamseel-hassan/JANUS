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


// GET fetching
$map_id = isset($_GET['map_id']) ? intval($_GET['map_id']) : 0;

$all_maps = [];
$mr = mysqli_query($con, "SELECT * FROM topology_maps ORDER BY name ASC");
while ($r = mysqli_fetch_assoc($mr)) {
    $all_maps[] = $r;
}

if ($map_id === 0 && count($all_maps) > 0) {
    $map_id = intval($all_maps[0]['id']);
}

$devices = [];
if ($map_id > 0) {
    $devices_query = "
        SELECT
            d.id, d.name, d.ip, d.type, d.model, d.city, d.sub_office, d.country,
            'up'                                     AS status,
            0                                        AS rtt_avg,
            NOW()                                    AS checked_at,
            COALESCE(dp.x, 50 + (d.id % 5)*150)     AS x,
            COALESCE(dp.y, 100 + FLOOR(d.id/5)*120) AS y,
            COALESCE(mdv.is_visible, 1)              AS is_visible,
            NOW()                                    AS last_status_change,
            dp.icon_set,
            COALESCE(dp.icon_size, 36)               AS icon_size
        FROM devices d
        LEFT JOIN device_positions      dp  ON d.id = dp.device_id  AND dp.map_id  = $map_id
        LEFT JOIN map_device_visibility mdv ON d.id = mdv.device_id AND mdv.map_id = $map_id
        ORDER BY d.name
    ";
    $devices_result = mysqli_query($con, $devices_query);
    while ($row = mysqli_fetch_assoc($devices_result)) {
        $row['is_visible'] = (bool)$row['is_visible'];
        $devices[] = $row;
    }
}

$links = [];
$lr = mysqli_query($con, "
    SELECT l.id, l.name, l.from_device_id, l.to_device_id,
           COALESCE(pl.status,'down') AS status
    FROM links l
    LEFT JOIN ping_logs pl ON l.id = pl.link_id
        AND pl.checked_at = (SELECT MAX(checked_at) FROM ping_logs WHERE link_id = l.id)
    ORDER BY l.name
");
while ($r = mysqli_fetch_assoc($lr)) {
    $links[] = $r;
}

echo json_encode([
    'maps' => $all_maps,
    'current_map_id' => $map_id,
    'devices' => $devices,
    'links' => $links
]);
exit;
