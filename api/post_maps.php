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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

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
        $errors = [];
        
        $stmt = $con->prepare("INSERT INTO device_positions (device_id, map_id, x, y) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE x = VALUES(x), y = VALUES(y)");
        if (!$stmt) {
            echo json_encode(['error' => 'Prepare failed', 'details' => $con->error]);
            exit;
        }

        foreach ($positions as $pos) {
            $did = intval($pos['device_id']);
            $x = floatval($pos['x']);
            $y = floatval($pos['y']);
            $stmt->bind_param('iidd', $did, $map_id, $x, $y);
            if (!$stmt->execute()) {
                $errors[] = "Execute failed for device $did: " . $stmt->error;
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['error' => 'Errors occurred', 'details' => $errors]);
        } else {
            echo json_encode(['success' => 'Positions saved!']);
        }
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
