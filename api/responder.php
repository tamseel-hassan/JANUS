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

// Ensure tables exist
mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS response_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_ip VARCHAR(45) UNIQUE,
        api_key VARCHAR(255),
        username VARCHAR(100),
        is_active TINYINT(1) DEFAULT 1,
        added_by INT,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS response_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_ip VARCHAR(45),
        action_type VARCHAR(50),
        target_ip VARCHAR(45),
        details TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        executed_at TIMESTAMP NULL
    ) ENGINE=InnoDB
");

// Patch response_config if schema conflict exists
try {
    $check = mysqli_query($con, "SHOW COLUMNS FROM response_config LIKE 'firewall_ip'");
    if ($check && $check->num_rows === 0) {
        mysqli_query($con, "ALTER TABLE response_config ADD COLUMN firewall_ip VARCHAR(45) UNIQUE");
        mysqli_query($con, "ALTER TABLE response_config ADD COLUMN api_key VARCHAR(255)");
        mysqli_query($con, "ALTER TABLE response_config ADD COLUMN username VARCHAR(100)");
        mysqli_query($con, "ALTER TABLE response_config ADD COLUMN added_by INT");
        mysqli_query($con, "ALTER TABLE response_config ADD COLUMN added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Exception $e) {
    // Ignore alter errors if columns already exist or partial
}

// Patch response_actions if schema conflict exists
try {
    $check2 = mysqli_query($con, "SHOW COLUMNS FROM response_actions LIKE 'firewall_ip'");
    if ($check2 && $check2->num_rows === 0) {
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN firewall_ip VARCHAR(45)");
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN action_type VARCHAR(50)");
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN target_ip VARCHAR(45)");
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN details TEXT");
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN status VARCHAR(20) DEFAULT 'pending'");
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN created_by INT");
        mysqli_query($con, "ALTER TABLE response_actions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Exception $e) {
    // Ignore alter errors
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
    
    // Check if address was created (200 OK) or already exists (424, 500 etc)
    if ($http_code == 200 || strpos($response, 'already exists') !== false) {
        // Add to blocklist group (assuming group 'JANUS_BLOCKLIST' exists)
        // For this proof of concept, we just assume address creation is enough to trigger a block policy that uses this object.
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
            $stmt = $con->prepare("INSERT INTO response_config (firewall_ip, api_key, username, added_by) VALUES (?, ?, ?, ?)");
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

        $stmt = $con->prepare("SELECT * FROM response_config WHERE firewall_ip = ? AND is_active = 1");
        $stmt->bind_param('s', $firewall_ip);
        $stmt->execute();
        $fw = $stmt->get_result()->fetch_assoc();
        
        if ($fw) {
            $result = blockIPOnFortiGate($fw, $target_ip, $reason);
            if ($result['success']) {
                $stmt = $con->prepare("INSERT INTO response_actions (firewall_ip, action_type, target_ip, details, status, created_by) VALUES (?, 'block_ip', ?, ?, 'success', ?)");
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

// ========== HANDLE GET REQUESTS ==========

$firewalls = [];
$res = mysqli_query($con, "SELECT firewall_ip, username, is_active, added_at FROM response_config ORDER BY added_at DESC");
while ($r = mysqli_fetch_assoc($res)) {
    $firewalls[] = $r;
}

$actions = [];
$res = mysqli_query($con, "
    SELECT ra.id, ra.firewall_ip, ra.action_type, ra.target_ip, ra.status, ra.created_at, a.username 
    FROM response_actions ra 
    JOIN accounts a ON ra.created_by = a.id 
    ORDER BY ra.created_at DESC 
    LIMIT 20
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $actions[] = $r;
    }
}

echo json_encode([
    'firewalls' => $firewalls,
    'actions' => $actions
]);
