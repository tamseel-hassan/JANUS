<?php
// save_device_visibility.php - Save device visibility for specific map
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

// Get POST data
$device_id = isset($_POST['device_id']) ? intval($_POST['device_id']) : 0;
$map_id = isset($_POST['map_id']) ? intval($_POST['map_id']) : 0;
$visible = isset($_POST['visible']) ? intval($_POST['visible']) : 1;

// Validate inputs
if ($device_id <= 0 || $map_id <= 0) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid device_id or map_id']));
}

// Verify device exists
$device_check = $con->prepare("SELECT id FROM devices WHERE id = ?");
$device_check->bind_param('i', $device_id);
$device_check->execute();
$device_result = $device_check->get_result();

if ($device_result->num_rows === 0) {
    http_response_code(404);
    exit(json_encode(['status' => 'error', 'message' => 'Device not found']));
}
$device_check->close();

// Verify map exists
$map_check = $con->prepare("SELECT id FROM topology_maps WHERE id = ?");
$map_check->bind_param('i', $map_id);
$map_check->execute();
$map_result = $map_check->get_result();

if ($map_result->num_rows === 0) {
    http_response_code(404);
    exit(json_encode(['status' => 'error', 'message' => 'Map not found']));
}
$map_check->close();

// Insert or update visibility
$stmt = $con->prepare("
    INSERT INTO device_positions (device_id, map_id, x, y, visible, updated_at) 
    VALUES (?, ?, 0, 0, ?, NOW())
    ON DUPLICATE KEY UPDATE 
        visible = VALUES(visible), 
        updated_at = NOW()
");

$stmt->bind_param('iii', $device_id, $map_id, $visible);

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'device_id' => $device_id,
        'map_id' => $map_id,
        'visible' => $visible
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to save visibility: ' . $stmt->error
    ]);
}

$stmt->close();
mysqli_close($con);
?>
