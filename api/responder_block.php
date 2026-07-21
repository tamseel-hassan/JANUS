<?php
/**
 * api/responder_block.php - Next.js AJAX endpoint: Block IP
 * POST: ip, reason, duration (optional, seconds), device_id (optional, for vendor dispatch)
 * Returns JSON
 *
 * Multi-vendor: detects firewall vendor from response_config and dispatches
 * to FortiGate, Cisco ASA, Palo Alto, or Juniper API accordingly.
 */
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../modules/responder/fortigate.php';
require_once __DIR__ . '/../modules/responder/cisco.php';
require_once __DIR__ . '/../modules/responder/paloalto.php';
require_once __DIR__ . '/../modules/responder/juniper.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Next.js uses JSON payloads typically.
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$ip = trim($data['target_ip'] ?? $data['ip'] ?? '');
$reason = trim($data['reason'] ?? 'Manual block from SIEM report');
$duration = intval($data['duration'] ?? 0);
$device_id = isset($data['device_id']) ? (int)$data['device_id'] : 0;

if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid IP address required']);
    exit;
}

// Get active firewall config (multi-vendor)
$fw_result = mysqli_query($con, "SELECT * FROM response_config WHERE is_active = 1 LIMIT 1");
$firewall = $fw_result ? mysqli_fetch_assoc($fw_result) : null;

// Insert into blocked IPs table
$expires = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration) : null;
$blocked_by = intval($_SESSION['id'] ?? 0);

$stmt = $con->prepare(
    "INSERT INTO responder_blocked_ips (ip_address, reason, source, block_method, expires_at, blocked_by)
     VALUES (?, ?, 'siem_report', 'api', ?, ?)"
);
$stmt->bind_param('sssi', $ip, $reason, $expires, $blocked_by);
$stmt->execute();

if ($stmt->error) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to block IP: ' . $stmt->error]);
    exit;
}
$stmt->close();
$blocked_id = $con->insert_id;

$vendor = 'database_only';
$apiResult = null;

// Dispatch to vendor-specific API
if ($firewall && !empty($firewall['firewall_ip'])) {
    $fwVendor = $firewall['vendor'] ?? 'fortigate';
    $fwIp = $firewall['firewall_ip'];
    $fwKey = $firewall['api_key'] ?? '';

    switch ($fwVendor) {
        case 'cisco_asa':
        case 'cisco':
            $fwUser = $firewall['username'] ?? ''; // Fixed username key
            $fwPass = $firewall['api_key'] ?? '';
            $fwPort = $firewall['firewall_port'] ?? 443;
            $ciscoApi = new CiscoASAAPI($fwIp, $fwUser, $fwPass, $fwPort);
            $apiResult = $ciscoApi->blockIP($ip, $reason);
            $vendor = 'cisco_asa';
            break;

        case 'paloalto':
        case 'palo_alto':
            $paApi = new PaloAltoAPI($fwIp, $fwKey);
            $apiResult = $paApi->blockIP($ip, $reason);
            $vendor = 'paloalto';
            break;

        case 'juniper':
        case 'juniper_srx':
            $jUser = $firewall['username'] ?? ''; // Fixed username key
            $jPass = $firewall['api_key'] ?? '';
            $jPort = $firewall['firewall_port'] ?? 22;
            $juniperApi = new JuniperAPI($fwIp, $jUser, $jPass, $jPort);
            $apiResult = $juniperApi->blockIP($ip, $reason);
            $vendor = 'juniper';
            break;

        case 'fortigate':
        default:
            $fgApi = new FortiGateAPI($fwIp, $fwKey);
            $apiResult = $fgApi->blockIP($ip, $reason);
            $vendor = 'fortigate';
            break;
    }
}

// Log the action in responder_executions
$con->query("INSERT INTO responder_executions (playbook_id, status, step_results)
    VALUES (0, 'manual_block', '" . mysqli_real_escape_string($con, json_encode([
        'ip' => $ip, 'reason' => $reason, 'vendor' => $vendor,
        'firewall' => $firewall['firewall_ip'] ?? null,
        'api_result' => $apiResult, 'expires' => $expires
    ])) . "')");

$success = $apiResult ? ($apiResult['success'] ?? false) : true;

if ($success) {
    echo json_encode([
        'success' => "IP $ip has been blocked" . ($vendor !== 'database_only' ? " via $vendor" : " (database only)"),
        'blocked_id' => $blocked_id,
        'vendor' => $vendor,
        'api_result' => $apiResult
    ]);
} else {
    echo json_encode([
        'error' => "IP $ip recorded in DB but firewall block failed: " . ($apiResult['error'] ?? 'unknown error')
    ]);
}
