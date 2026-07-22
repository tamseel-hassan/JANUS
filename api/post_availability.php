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
if (isset($data['action']) && $data['action'] === 'save_comment') {
    $device_id = intval($data['device_id']);
    $event_start = mysqli_real_escape_string($con, $data['event_start']);
    $event_end = !empty($data['event_end']) ? mysqli_real_escape_string($con, $data['event_end']) : null;
    $comments = mysqli_real_escape_string($con, $data['comments']);
    $action_taken = mysqli_real_escape_string($con, $data['action_taken']);
    $escalation_level = intval($data['escalation_level']);
    $vendor_contacted = mysqli_real_escape_string($con, $data['vendor_contacted']);
    $ticket_number = mysqli_real_escape_string($con, $data['ticket_number']);
    $resolution_time = !empty($data['resolution_time']) ? mysqli_real_escape_string($con, $data['resolution_time']) : null;
    
    $check_stmt = $con->prepare("SELECT id FROM event_comments WHERE device_id = ? AND event_start_time = ?");
    $check_stmt->bind_param('is', $device_id, $event_start);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $stmt = $con->prepare("UPDATE event_comments SET comments = ?, action_taken = ?, escalation_level = ?, vendor_contacted = ?, ticket_number = ?, resolution_time = ?, event_end_time = ? WHERE device_id = ? AND event_start_time = ?");
        $stmt->bind_param('ssissssis', $comments, $action_taken, $escalation_level, $vendor_contacted, $ticket_number, $resolution_time, $event_end, $device_id, $event_start);
    } else {
        $stmt = $con->prepare("INSERT INTO event_comments (device_id, event_start_time, event_end_time, comments, action_taken, escalation_level, vendor_contacted, ticket_number, resolution_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssisss', $device_id, $event_start, $event_end, $comments, $action_taken, $escalation_level, $vendor_contacted, $ticket_number, $resolution_time);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => 'Event comment saved successfully']);
    } else {
        echo json_encode(['error' => 'Failed to save comment']);
    }
    exit;
}
