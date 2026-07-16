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

    if (isset($data['action'])) {
        if ($data['action'] === 'create_map') {
            $name = mysqli_real_escape_string($con, $data['map_name']);
            $stmt = $con->prepare("INSERT INTO topology_maps (name, created_at) VALUES (?, NOW())");
            $stmt->bind_param('s', $name);
            if ($stmt->execute()) {
                $new_map_id = $stmt->insert_id;
                $ds = $con->prepare("SELECT id FROM devices");
                $ds->execute();
                $dr = $ds->get_result();
                while ($dev = $dr->fetch_assoc()) {
                    $vs = $con->prepare("INSERT INTO map_device_visibility (map_id, device_id, is_visible) VALUES (?, ?, TRUE)");
                    $vs->bind_param('ii', $new_map_id, $dev['id']);
                    $vs->execute();
                }
                echo json_encode(['success' => 'Map created!', 'map_id' => $new_map_id]);
            } else {
                echo json_encode(['error' => 'Failed to create map: ' . $stmt->error]);
            }
            exit;
        }

        if ($data['action'] === 'delete_map') {
            $map_id = intval($data['map_id']);
            $con->prepare("DELETE FROM device_positions WHERE map_id = $map_id")->execute();
            $con->prepare("DELETE FROM map_device_visibility WHERE map_id = $map_id")->execute();
            $s = $con->prepare("DELETE FROM topology_maps WHERE id = ?");
            $s->bind_param('i', $map_id);
            if ($s->execute()) {
                echo json_encode(['success' => 'Map deleted!']);
            } else {
                echo json_encode(['error' => 'Failed to delete map']);
            }
            exit;
        }

        if ($data['action'] === 'update_visibility') {
            $map_id = intval($data['map_id']);
            $device_id = intval($data['device_id']);
            $is_visible = intval($data['is_visible']);
            $stmt = $con->prepare("INSERT INTO map_device_visibility (map_id, device_id, is_visible) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = ?");
            $stmt->bind_param('iiii', $map_id, $device_id, $is_visible, $is_visible);
            $stmt->execute();
            echo json_encode(['success' => 'Visibility updated']);
            exit;
        }
        
        if ($data['action'] === 'bulk_visibility') {
            $map_id = intval($data['map_id']);
            $device_ids = $data['device_ids'] ?? [];
            $is_visible = intval($data['is_visible']);
            foreach ($device_ids as $did) {
                $did = intval($did);
                $stmt = $con->prepare("INSERT INTO map_device_visibility (map_id, device_id, is_visible) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = ?");
                $stmt->bind_param('iiii', $map_id, $did, $is_visible, $is_visible);
                $stmt->execute();
            }
            echo json_encode(['success' => 'Visibility updated']);
            exit;
        }

        if ($data['action'] === 'save_positions') {
            $map_id = intval($data['map_id']);
            $positions = $data['positions'] ?? [];
            foreach ($positions as $pos) {
                $did = intval($pos['device_id']);
                $x = floatval($pos['x']);
                $y = floatval($pos['y']);
                $stmt = $con->prepare("INSERT INTO device_positions (device_id, map_id, x, y) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE x = ?, y = ?");
                $stmt->bind_param('iidddd', $did, $map_id, $x, $y, $x, $y);
                $stmt->execute();
            }
            echo json_encode(['success' => 'Positions saved!']);
            exit;
        }

        if ($data['action'] === 'save_icon_set') {
            $map_id = intval($data['map_id']);
            $device_id = intval($data['device_id']);
            $icon_set = mysqli_real_escape_string($con, $data['icon_set']);
            $stmt = $con->prepare("INSERT INTO device_positions (device_id, map_id, x, y, icon_set) VALUES (?, ?, 0, 0, ?) ON DUPLICATE KEY UPDATE icon_set = ?");
            $stmt->bind_param('iiss', $device_id, $map_id, $icon_set, $icon_set);
            $stmt->execute();
            echo json_encode(['success' => 'Icon set updated']);
            exit;
        }

        if ($data['action'] === 'save_icon_size') {
            $map_id = intval($data['map_id']);
            $device_id = intval($data['device_id']);
            $size = intval($data['icon_size']);
            $stmt = $con->prepare("INSERT INTO device_positions (device_id, map_id, x, y, icon_size) VALUES (?, ?, 0, 0, ?) ON DUPLICATE KEY UPDATE icon_size = ?");
            $stmt->bind_param('iiii', $device_id, $map_id, $size, $size);
            $stmt->execute();
            echo json_encode(['success' => 'Icon size updated']);
            exit;
        }
    }
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
