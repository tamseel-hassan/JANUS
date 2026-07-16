<?php
/**
 * check_now.php
 * ---------------------------------------------------------------
 * On-demand "Check Now" endpoint for monitor.php.
 *
 * Pings exactly ONE device immediately (using the same logic the
 * cron sweep uses, via ping_functions.php), updates
 * device_status_cache + ping_logs right away, and returns the
 * fresh status as JSON. This means you don't have to wait for the
 * next 2-minute cron sweep to reach that device — same pattern as
 * the "Check Now" / "Scan Now" button in PRTG/Nagios/SolarWinds.
 *
 * Security:
 *   - Requires an active logged-in session (any role: admin/analyst)
 *   - Per-device rate limit (5 seconds) to stop a user from
 *     spam-clicking and hammering a single host or the DB
 *   - device_id is validated as a positive int and checked against
 *     the devices table before anything is shelled out to ping
 * ---------------------------------------------------------------
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/ping_functions.php';

$device_id = isset($_POST['device_id']) ? intval($_POST['device_id']) : 0;

if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid device_id']);
    exit;
}

// Look up the device - also doubles as existence validation
$stmt = $con->prepare("SELECT id, name, ip FROM devices WHERE id = ?");
$stmt->bind_param('i', $device_id);
$stmt->execute();
$stmt->bind_result($db_id, $db_name, $db_ip);
$device = null;
if ($stmt->fetch()) {
    $device = [
        'id'   => $db_id,
        'name' => $db_name,
        'ip'   => $db_ip
    ];
}
$stmt->close();

if (!$device) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Device not found']);
    exit;
}

if (empty($device['ip'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Device has no IP address configured']);
    exit;
}

// ---- Simple per-device rate limit (5 seconds) using the session ----
// Prevents spam-clicking "Check Now" on the same device repeatedly.
$rate_limit_key = 'last_check_now_' . $device_id;
$now_ts = time();
if (isset($_SESSION[$rate_limit_key]) && ($now_ts - $_SESSION[$rate_limit_key]) < 5) {
    $wait = 5 - ($now_ts - $_SESSION[$rate_limit_key]);
    http_response_code(429);
    echo json_encode([
        'status'  => 'error',
        'message' => "Please wait {$wait}s before checking this device again"
    ]);
    exit;
}
$_SESSION[$rate_limit_key] = $now_ts;

// ---- Run the actual ping ----
$ping = pingHost($device['ip']);
$status   = $ping['status'];
$rtt_avg  = $ping['rtt_avg'];
$sent     = $ping['sent'];
$received = $ping['received'];
$loss_pct = $ping['loss_pct'];

// Always refresh the cache so monitor.php/maps.php see the new result immediately
updateDeviceStatusCache($con, $device_id, $status, $rtt_avg);

// Log it - "up" always logs; "down" respects the same 10-min throttle as the cron
// (so a Check Now on a device that's been down for a while doesn't spam ping_logs)
$logged = false;
if (shouldLogStatus($con, $status, $device_id, null)) {
    logPing($con, $device_id, null, $status, $rtt_avg, $sent, $received, $loss_pct);
    $logged = true;
}

// Pull the freshly-updated cache row back out so the response reflects
// exactly what's now in the DB (last_status_change, etc.)
$stmt = $con->prepare("SELECT status, rtt_avg, checked_at, last_status_change FROM device_status_cache WHERE device_id = ?");
$stmt->bind_param('i', $device_id);
$stmt->execute();
$stmt->bind_result($c_status, $c_rtt, $c_checked, $c_change);
$cache_row = [];
if ($stmt->fetch()) {
    $cache_row = [
        'status'             => $c_status,
        'rtt_avg'            => $c_rtt,
        'checked_at'         => $c_checked,
        'last_status_change' => $c_change
    ];
}
$stmt->close();

mysqli_close($con);

echo json_encode([
    'status'  => 'success',
    'device'  => [
        'id'   => (int)$device['id'],
        'name' => $device['name'],
        'ip'   => $device['ip'],
    ],
    'result' => [
        'status'             => $cache_row['status'] ?? $status,
        'rtt_avg'            => isset($cache_row['rtt_avg']) ? (float)$cache_row['rtt_avg'] : null,
        'checked_at'         => $cache_row['checked_at'] ?? date('Y-m-d H:i:s'),
        'last_status_change' => $cache_row['last_status_change'] ?? null,
        'packets_sent'       => $sent,
        'packets_received'   => $received,
        'packet_loss_pct'    => $loss_pct,
        'logged'             => $logged,
    ],
]);
