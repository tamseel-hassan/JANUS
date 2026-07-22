<?php
require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$res = $con->query("SELECT id FROM devices LIMIT 1");
$device = $res->fetch_assoc();
$did = $device['id'];

$map_id = 2;
$x = 100.5;
$y = 100.5;

$stmt = $con->prepare("INSERT INTO device_positions (device_id, map_id, x, y) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE x = ?, y = ?");
if (!$stmt) {
    die("Prepare failed: " . $con->error);
}
$stmt->bind_param('iidddd', $did, $map_id, $x, $y, $x, $y);
if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

echo "Success! Affected rows: " . $stmt->affected_rows . "\n";
