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

// ========== FORTIGATE API FUNCTIONS ==========

function testFortiGateAPI($ip, $api_key) {
    $url = "https://$ip/api/v2/monitor/system/status";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $api_key]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $data = json_decode($response, true);
        return [
            'success' => true,
            'version' => $data['results']['version'] ?? 'Unknown'
        ];
    }
    return ['success' => false, 'error' => "HTTP Code: $http_code. $response"];
}

function blockIPOnFortiGate($fw, $ip, $reason) {
    $url = "https://{$fw['firewall_ip']}/api/v2/cmdb/firewall/address";
    $address_name = "BLOCK_" . str_replace('.', '_', $ip);
    
    // Create address object
    $data = [
        'name' => $address_name,
        'type' => 'ipmask',
        'subnet' => "$ip 255.255.255.255",
        'comment' => $reason . ' (' . date('Y-m-d H:i:s') . ')'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $fw['api_key'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 || strpos($response, 'already exists') !== false) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => "Failed to create address object. HTTP $http_code"];
}

// ========== HANDLE POST REQUESTS ==========

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $data['action'] ?? '';
    $user_id = $_SESSION['id'];

    if ($action === 'add_firewall') {
        $fw_ip = trim($data['firewall_ip'] ?? '');
        $api_key = trim($data['api_key'] ?? '');
        $username = trim($data['username'] ?? '') ?: 'admin';
        
        if (!filter_var($fw_ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['error' => 'Invalid firewall IP']);
            exit;
        }

        $test = testFortiGateAPI($fw_ip, $api_key);
        if ($test['success']) {
            $stmt = $con->prepare("INSERT INTO responder_firewalls (firewall_ip, api_key, username, added_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssi', $fw_ip, $api_key, $username, $user_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => 'Firewall added successfully! Version: ' . $test['version']]);
            } else {
                echo json_encode(['error' => 'Database error']);
            }
        } else {
            echo json_encode(['error' => 'API test failed: ' . $test['error']]);
        }
        exit;
    }

    if ($action === 'block_ip') {
        $target_ip = trim($data['target_ip'] ?? '');
        $firewall_ip = $data['firewall_ip'] ?? '';
        $reason = trim($data['reason'] ?? '') ?: 'Blocked via SIEM';
        
        if (!filter_var($target_ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['error' => 'Invalid target IP']);
            exit;
        }

        $stmt = $con->prepare("SELECT * FROM responder_firewalls WHERE firewall_ip = ? AND is_active = 1");
        $stmt->bind_param('s', $firewall_ip);
        $stmt->execute();
        $fw = $stmt->get_result()->fetch_assoc();
        
        if ($fw) {
            $result = blockIPOnFortiGate($fw, $target_ip, $reason);
            if ($result['success']) {
                $stmt = $con->prepare("INSERT INTO responder_actions (firewall_ip, action_type, target_ip, details, status, created_by) VALUES (?, 'block_ip', ?, ?, 'success', ?)");
                $details = json_encode(['reason' => $reason, 'result' => $result]);
                $stmt->bind_param('sssi', $firewall_ip, $target_ip, $details, $user_id);
                $stmt->execute();
                
                echo json_encode(['success' => 'IP ' . htmlspecialchars($target_ip) . ' blocked successfully!']);
            } else {
                echo json_encode(['error' => 'Failed to block IP: ' . $result['error']]);
            }
        } else {
            echo json_encode(['error' => 'Firewall not configured']);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
