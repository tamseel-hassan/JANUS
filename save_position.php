<?php
// save_position_map.php - Save device position for a specific map
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
    exit(json_encode(['status' => 'error', 'message' => 'DB connection failed']));
}

$device_id = isset($_POST['device_id']) ? intval($_POST['device_id']) : 0;
$map_id    = isset($_POST['map_id'])    ? intval($_POST['map_id'])    : 0;
$x         = isset($_POST['x'])         ? floatval($_POST['x'])       : 0;
$y         = isset($_POST['y'])         ? floatval($_POST['y'])       : 0;

if ($device_id <= 0 || $map_id <= 0) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid device_id or map_id']));
}

$icon_size = isset($_POST['icon_size']) ? intval($_POST['icon_size']) : null;

$stmt = $con->prepare("
    INSERT INTO device_positions (device_id, map_id, x, y, updated_at)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        x          = VALUES(x),
        y          = VALUES(y),
        updated_at = NOW()
");
$stmt->bind_param('iidd', $device_id, $map_id, $x, $y);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $stmt->error]);
}

$stmt->close();
mysqli_close($con);
?>
