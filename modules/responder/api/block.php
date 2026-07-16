<?php
/**
 * modules/responder/api/block.php - AJAX endpoint: Block IP from reports
 * POST: ip, reason, duration (optional, seconds), device_id (optional, for vendor dispatch)
 * Returns JSON
 *
 * Multi-vendor: detects firewall vendor from response_config and dispatches
 * to FortiGate, Cisco ASA, Palo Alto, or Juniper API accordingly.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../db_config.php';
require_once __DIR__ . '/../../fortigate.php';
require_once __DIR__ . '/../../cisco.php';
require_once __DIR__ . '/../../paloalto.php';
require_once __DIR__ . '/../../juniper.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

$ip = trim($_POST['ip'] ?? '');
$reason = trim($_POST['reason'] ?? 'Manual block from SIEM report');
$duration = intval($_POST['duration'] ?? 0);
$device_id = isset($_POST['device_id']) ? (int)$_POST['device_id'] : 0;

if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Valid IP address required']);
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
    echo json_encode(['ok' => false, 'error' => 'Failed to block IP: ' . $stmt->error]);
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
            $fwUser = $firewall['api_key'] ?? '';
            $fwPass = $firewall['firewall_vdom'] ?? '';
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
            $jUser = $firewall['api_key'] ?? '';
            $jPass = $firewall['firewall_vdom'] ?? '';
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

echo json_encode([
    'ok' => $success,
    'blocked_id' => $blocked_id,
    'ip' => $ip,
    'vendor' => $vendor,
    'firewall_ip' => $firewall['firewall_ip'] ?? null,
    'api_result' => $apiResult,
    'message' => $success
        ? "IP $ip has been blocked" . ($vendor !== 'database_only' ? " via $vendor" : " (database only)")
        : "IP $ip recorded in DB but firewall block failed: " . ($apiResult['error'] ?? 'unknown error'),
]);
