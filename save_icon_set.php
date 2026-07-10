<?php
// save_icon_set.php - Save custom icon set for a device on a specific map
require_once __DIR__ . '/db_config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    http_response_code(500);
    exit(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

$device_id = isset($_POST['device_id']) ? intval($_POST['device_id']) : 0;
$map_id    = isset($_POST['map_id'])    ? intval($_POST['map_id'])    : 0;
$icon_set  = isset($_POST['icon_set'])  ? trim($_POST['icon_set'])    : '';

// Validate icon set dynamically against files that actually exist on disk
// Sanitize first — only allow safe characters
if (!preg_match('/^[a-z0-9_-]+$/', $icon_set)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid icon set name']));
}

$icons_dir = __DIR__ . '/images/icons/maps/';

// Special case: "node" set uses nodeup.png / nodedown.png (no dash)
if ($icon_set === 'node') {
    $valid = file_exists($icons_dir . 'nodeup.png');
} else {
    // Any other set must have at least the -up.png file present
    $valid = file_exists($icons_dir . $icon_set . '-up.png');
}

if (!$valid) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => "Icon set '{$icon_set}' not found on server"]));
}

if ($device_id <= 0 || $map_id <= 0) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid device_id or map_id']));
}

// Upsert into device_positions — preserve x/y if row already exists
$stmt = $con->prepare("
    INSERT INTO device_positions (device_id, map_id, x, y, icon_set, updated_at)
    VALUES (?, ?, 0, 0, ?, NOW())
    ON DUPLICATE KEY UPDATE
        icon_set   = VALUES(icon_set),
        updated_at = NOW()
");
$stmt->bind_param('iis', $device_id, $map_id, $icon_set);

if ($stmt->execute()) {
    echo json_encode([
        'status'    => 'success',
        'device_id' => $device_id,
        'map_id'    => $map_id,
        'icon_set'  => $icon_set
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $stmt->error]);
}

$stmt->close();
mysqli_close($con);
?>
