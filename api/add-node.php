<?php
require_once __DIR__ . '/../db_config.php';
session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Connect first – fix for escape
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo json_encode(['error' => 'DB Error: ' . mysqli_connect_error()]);
    exit;
}

$name = mysqli_real_escape_string($con, $input['name']);
$type = mysqli_real_escape_string($con, $input['type']);
$city = mysqli_real_escape_string($con, $input['city']);
$subOffice = mysqli_real_escape_string($con, $input['subOffice']);
$ip = mysqli_real_escape_string($con, $input['ip']);
$mac = mysqli_real_escape_string($con, $input['mac']);
$contact = mysqli_real_escape_string($con, $input['contact']);
$address = mysqli_real_escape_string($con, $input['address']);
$country = mysqli_real_escape_string($con, $input['country']);

$query = "INSERT INTO devices (name, type, city, sub_office, ip, mac, contact, address, country) VALUES ('$name', '$type', '$city', '$subOffice', '$ip', '$mac', '$contact', '$address', '$country')";
if (mysqli_query($con, $query)) {
    echo json_encode(['message' => 'Node added successfully! ID: ' . mysqli_insert_id($con)]);
} else {
    echo json_encode(['error' => 'Insert failed: ' . mysqli_error($con)]);
}
mysqli_close($con);
?>
