<?php
/**
 * api/post_ipam.php - Next.js endpoint for IPAM write operations
 */
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../modules/ipam/ipam_lib.php';

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

try {
    $db = ipam_db();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $data['action'] ?? '';

if ($action === 'ping') {
    $ip = trim($data['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Invalid IP']);
        exit;
    }
    // basic ICMP check
    $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
        ? "ping -n 1 -w 1000 " . escapeshellarg($ip)
        : "ping -c 1 -W 1 " . escapeshellarg($ip);
    
    $out = [];
    $ret = 0;
    exec($cmd, $out, $ret);
    
    $status = ($ret === 0) ? 'online' : 'offline';
    
    // update DB if exists
    $stmt = $db->prepare("UPDATE janus_ipam SET status = ?, last_seen = NOW() WHERE ip = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $status, $ip);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true, 'status' => $status]);

} elseif ($action === 'acknowledge') {
    $log_id = intval($data['log_id'] ?? 0);
    $note   = trim($data['note'] ?? 'Acknowledged via Dashboard');
    $user   = $_SESSION['username'] ?? 'unknown';
    
    if (!$log_id) {
        echo json_encode(['error' => 'Missing log ID']);
        exit;
    }
    $stmt = $db->prepare("UPDATE janus_ipam_log SET acknowledged = 1, acknowledged_by = ?, ack_note = ?, acknowledged_at = NOW() WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('ssi', $user, $note, $log_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => $db->error]);
    }

} elseif ($action === 'save') {
    $id          = intval($data['id'] ?? 0);
    $ip          = trim($data['ip'] ?? '');
    $mac         = strtoupper(trim($data['mac'] ?? ''));
    $assigned_to = trim($data['assigned_to'] ?? '');
    $status      = $data['status'] ?? 'active';
    $vlan        = trim($data['vlan'] ?? '');
    $notes       = trim($data['notes'] ?? '');

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Invalid IP']);
        exit;
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE janus_ipam SET ip=?, mac=?, assigned_to=?, status=?, vlan=?, notes=? WHERE id=?");
        $stmt->bind_param('ssssssi', $ip, $mac, $assigned_to, $status, $vlan, $notes, $id);
    } else {
        $stmt = $db->prepare("INSERT INTO janus_ipam (ip, mac, assigned_to, status, vlan, notes, first_seen) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('ssssss', $ip, $mac, $assigned_to, $status, $vlan, $notes);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $id > 0 ? $id : $db->insert_id]);
    } else {
        echo json_encode(['error' => $stmt->error]);
    }
    $stmt->close();

} elseif ($action === 'delete') {
    $id = intval($data['id'] ?? 0);
    if ($id > 0) {
        $db->query("DELETE FROM janus_ipam WHERE id = $id");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid ID']);
    }

} elseif ($action === 'subnet_save') {
    $id           = intval($data['id'] ?? 0);
    $cidr         = trim($data['cidr'] ?? '');
    $label        = trim($data['label'] ?? '');
    $vlan_id      = trim($data['vlan_id'] ?? '');
    $description  = trim($data['description'] ?? '');
    $interface    = trim($data['interface'] ?? '');
    $scan_enabled = intval($data['scan_enabled'] ?? 1);

    if (!preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $cidr)) {
        echo json_encode(['error' => 'Invalid CIDR']);
        exit;
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE ipam_subnets SET cidr=?, label=?, vlan_id=?, description=?, interface=?, scan_enabled=? WHERE id=?");
        $stmt->bind_param('sssssii', $cidr, $label, $vlan_id, $description, $interface, $scan_enabled, $id);
    } else {
        $stmt = $db->prepare("INSERT INTO ipam_subnets (cidr, label, vlan_id, description, interface, scan_enabled) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssi', $cidr, $label, $vlan_id, $description, $interface, $scan_enabled);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $id > 0 ? $id : $db->insert_id]);
    } else {
        echo json_encode(['error' => $stmt->error]);
    }
    $stmt->close();

} elseif ($action === 'subnet_delete') {
    $id = intval($data['id'] ?? 0);
    if ($id > 0) {
        $db->query("DELETE FROM ipam_subnets WHERE id = $id");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid ID']);
    }

} elseif ($action === 'mapping_save') {
    $id          = intval($data['id'] ?? 0);
    $private_ip  = trim($data['private_ip'] ?? '');
    $public_ip   = trim($data['public_ip'] ?? '');
    $type        = $data['type'] ?? 'dnat';
    $description = trim($data['description'] ?? '');

    if (!filter_var($private_ip, FILTER_VALIDATE_IP) || !filter_var($public_ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Invalid IP address']);
        exit;
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE ipam_nat_map SET private_ip=?, public_ip=?, type=?, description=? WHERE id=?");
        $stmt->bind_param('ssssi', $private_ip, $public_ip, $type, $description, $id);
    } else {
        $stmt = $db->prepare("INSERT INTO ipam_nat_map (private_ip, public_ip, type, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $private_ip, $public_ip, $type, $description);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $id > 0 ? $id : $db->insert_id]);
    } else {
        echo json_encode(['error' => $stmt->error]);
    }
    $stmt->close();

} elseif ($action === 'mapping_delete') {
    $id = intval($data['id'] ?? 0);
    if ($id > 0) {
        $db->query("DELETE FROM ipam_nat_map WHERE id = $id");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid ID']);
    }

} elseif ($action === 'bulk_ack') {
    $note = trim($data['note'] ?? 'Bulk acknowledged');
    $user = $_SESSION['username'] ?? 'unknown';
    
    $stmt = $db->prepare("UPDATE janus_ipam_log SET acknowledged = 1, acknowledged_by = ?, ack_note = ?, acknowledged_at = NOW() WHERE acknowledged = 0");
    if ($stmt) {
        $stmt->bind_param('ss', $user, $note);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['success' => true, 'acknowledged' => $n]);
    } else {
        echo json_encode(['error' => $db->error]);
    }

} elseif ($action === 'bulk_ack_ip') {
    $ip   = trim($data['ip'] ?? '');
    $note = trim($data['note'] ?? 'Bulk acknowledged');
    $user = $_SESSION['username'] ?? 'unknown';

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Invalid IP']);
        exit;
    }
    
    $stmt = $db->prepare("UPDATE janus_ipam_log SET acknowledged = 1, acknowledged_by = ?, ack_note = ?, acknowledged_at = NOW() WHERE ip = ? AND acknowledged = 0");
    if ($stmt) {
        $stmt->bind_param('sss', $user, $note, $ip);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['success' => true, 'acknowledged' => $n]);
    } else {
        echo json_encode(['error' => $db->error]);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}

$db->close();
