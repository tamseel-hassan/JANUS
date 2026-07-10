<?php
// save_icon_size.php - Save custom icon size for a device on a specific map
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
$icon_size = isset($_POST['icon_size']) ? intval($_POST['icon_size']) : 36;

// Clamp between 24px and 128px
$icon_size = max(24, min(128, $icon_size));

if ($device_id <= 0 || $map_id <= 0) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid device_id or map_id']));
}

$stmt = $con->prepare("
    INSERT INTO device_positions (device_id, map_id, x, y, icon_size, updated_at)
    VALUES (?, ?, 0, 0, ?, NOW())
    ON DUPLICATE KEY UPDATE
        icon_size  = VALUES(icon_size),
        updated_at = NOW()
");
$stmt->bind_param('iii', $device_id, $map_id, $icon_size);

if ($stmt->execute()) {
    echo json_encode([
        'status'    => 'success',
        'device_id' => $device_id,
        'map_id'    => $map_id,
        'icon_size' => $icon_size
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $stmt->error]);
}

$stmt->close();
mysqli_close($con);
?>
