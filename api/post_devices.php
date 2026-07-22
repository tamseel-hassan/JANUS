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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (isset($data['action'])) {
    // --- DEVICE ACTIONS ---
    if ($data['action'] === 'add_device') {
        $name = mysqli_real_escape_string($con, $data['name']);
        $ip   = mysqli_real_escape_string($con, $data['ip']);
        $type = mysqli_real_escape_string($con, $data['type']);
        $model = mysqli_real_escape_string($con, $data['model'] === 'Other' && !empty($data['custom_model']) ? $data['custom_model'] : $data['model']);
        $city   = mysqli_real_escape_string($con, $data['city'] ?? '');
        $sub_office = mysqli_real_escape_string($con, $data['sub_office'] ?? '');
        $country= mysqli_real_escape_string($con, $data['country'] ?? '');
        $contact= mysqli_real_escape_string($con, $data['contact_number'] ?? '');
        $email  = mysqli_real_escape_string($con, $data['email'] ?? '');
        $snmp_c = mysqli_real_escape_string($con, $data['snmp_community'] ?? '');
        $snmp_v = mysqli_real_escape_string($con, $data['snmp_version'] ?? '2c');
        $snmp_p = intval($data['snmp_port'] ?? 161);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['error' => 'Invalid IP address']); exit;
        }

        $check = $con->prepare("SELECT id, name FROM devices WHERE ip = ?");
        $check->bind_param('s', $ip); $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['error' => 'IP address is already assigned to another device.']); exit;
        }
        
        $stmt = $con->prepare("INSERT INTO devices (name, type, ip, model, city, sub_office, country, contact_number, email, snmp_community, snmp_version, snmp_port) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssssssssi', $name, $type, $ip, $model, $city, $sub_office, $country, $contact, $email, $snmp_c, $snmp_v, $snmp_p);
        if ($stmt->execute()) echo json_encode(['success' => 'Device added successfully!']);
        else echo json_encode(['error' => $stmt->error]);
        exit;
    }

    if ($data['action'] === 'edit_device') {
        $id = intval($data['id']);
        $name = mysqli_real_escape_string($con, $data['name']);
        $ip   = mysqli_real_escape_string($con, $data['ip']);
        $type = mysqli_real_escape_string($con, $data['type']);
        $model = mysqli_real_escape_string($con, $data['model'] === 'Other' && !empty($data['custom_model']) ? $data['custom_model'] : $data['model']);
        $city   = mysqli_real_escape_string($con, $data['city'] ?? '');
        $sub_office = mysqli_real_escape_string($con, $data['sub_office'] ?? '');
        $country= mysqli_real_escape_string($con, $data['country'] ?? '');
        $contact= mysqli_real_escape_string($con, $data['contact_number'] ?? '');
        $email  = mysqli_real_escape_string($con, $data['email'] ?? '');
        $snmp_c = mysqli_real_escape_string($con, $data['snmp_community'] ?? '');
        $snmp_v = mysqli_real_escape_string($con, $data['snmp_version'] ?? '2c');
        $snmp_p = intval($data['snmp_port'] ?? 161);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['error' => 'Invalid IP address']); exit;
        }

        $check = $con->prepare("SELECT id, name FROM devices WHERE ip = ? AND id != ?");
        $check->bind_param('si', $ip, $id); $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['error' => 'IP address is already assigned to another device.']); exit;
        }
        
        $stmt = $con->prepare("UPDATE devices SET name=?, type=?, ip=?, model=?, city=?, sub_office=?, country=?, contact_number=?, email=?, snmp_community=?, snmp_version=?, snmp_port=? WHERE id=?");
        $stmt->bind_param('sssssssssssii', $name, $type, $ip, $model, $city, $sub_office, $country, $contact, $email, $snmp_c, $snmp_v, $snmp_p, $id);
        if ($stmt->execute()) echo json_encode(['success' => 'Device updated successfully!']);
        else echo json_encode(['error' => $stmt->error]);
        exit;
    }

    if ($data['action'] === 'delete_device') {
        $id = intval($data['id']);
        $con->prepare("DELETE FROM ping_logs WHERE device_id = $id")->execute();
        $con->prepare("DELETE FROM snmp_metrics WHERE device_id = $id")->execute();
        $stmt = $con->prepare("DELETE FROM devices WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) echo json_encode(['success' => 'Device deleted!']);
        else echo json_encode(['error' => $stmt->error]);
        exit;
    }

    // --- LINK ACTIONS ---
    if ($data['action'] === 'add_link') {
        $name = mysqli_real_escape_string($con, $data['name']);
        $ip   = mysqli_real_escape_string($con, $data['ip']);
        $from = intval($data['from_device_id']);
        $to   = intval($data['to_device_id']);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['error' => 'Invalid IP']); exit; }
        if ($from === $to) { echo json_encode(['error' => 'From and To devices cannot be the same']); exit; }
        $stmt = $con->prepare("INSERT INTO links (name, ip, from_device_id, to_device_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssii', $name, $ip, $from, $to);
        if ($stmt->execute()) echo json_encode(['success' => 'Link added!']);
        else echo json_encode(['error' => $stmt->error]);
        exit;
    }

    if ($data['action'] === 'edit_link') {
        $id   = intval($data['id']);
        $name = mysqli_real_escape_string($con, $data['name']);
        $ip   = mysqli_real_escape_string($con, $data['ip']);
        $from = intval($data['from_device_id']);
        $to   = intval($data['to_device_id']);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['error' => 'Invalid IP']); exit; }
        if ($from === $to) { echo json_encode(['error' => 'From and To devices cannot be the same']); exit; }
        $stmt = $con->prepare("UPDATE links SET name=?, ip=?, from_device_id=?, to_device_id=? WHERE id=?");
        $stmt->bind_param('ssiii', $name, $ip, $from, $to, $id);
        if ($stmt->execute()) echo json_encode(['success' => 'Link updated!']);
        else echo json_encode(['error' => $stmt->error]);
        exit;
    }

    if ($data['action'] === 'delete_link') {
        $id = intval($data['id']);
        $con->prepare("DELETE FROM ping_logs WHERE link_id = $id")->execute();
        $stmt = $con->prepare("DELETE FROM links WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) echo json_encode(['success' => 'Link deleted!']);
        else echo json_encode(['error' => $stmt->error]);
        exit;
    }
}

echo json_encode(['error' => 'Invalid action']);
