<?php
// agent_ingest.php - receives telemetry POSTs from janus_agent.ps1
// Auth: Authorization: Bearer <token>  (token must exist + be active in agent_tokens)
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db connection failed']);
    exit;
}

// --- Auth ---
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing bearer token']);
    exit;
}
$token = mysqli_real_escape_string($con, $m[1]);
$tokenCheck = mysqli_query($con, "SELECT id FROM agent_tokens WHERE token = '$token' AND active = 1 LIMIT 1");
if (!$tokenCheck || mysqli_num_rows($tokenCheck) === 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid or inactive token']);
    exit;
}

// --- Parse payload ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || empty($data['hostname'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

function esc($con, $v) { return mysqli_real_escape_string($con, (string)$v); }

$hostname   = esc($con, $data['hostname']);
$ip         = esc($con, $data['ip_address'] ?? '');
$os_version = esc($con, $data['os_version'] ?? '');
$agent_ver  = esc($con, $data['agent_version'] ?? '');
$now        = date('Y-m-d H:i:s');

// --- Upsert endpoint ---
mysqli_query($con, "
    INSERT INTO endpoints (hostname, ip_address, os_version, agent_version, first_seen, last_seen)
    VALUES ('$hostname', '$ip', '$os_version', '$agent_ver', '$now', '$now')
    ON DUPLICATE KEY UPDATE ip_address = '$ip', os_version = '$os_version', agent_version = '$agent_ver', last_seen = '$now'
");

$endpoint_res = mysqli_query($con, "SELECT id FROM endpoints WHERE hostname = '$hostname' LIMIT 1");
if (!$endpoint_res || mysqli_num_rows($endpoint_res) === 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'could not resolve endpoint id']);
    exit;
}
$endpoint_id = (int)mysqli_fetch_assoc($endpoint_res)['id'];

$counts = ['logons' => 0, 'rdp_sessions' => 0, 'apps' => 0, 'ports' => 0];

// --- Logons ---
foreach (($data['logons'] ?? []) as $l) {
    $username   = esc($con, $l['username'] ?? '');
    $domain     = esc($con, $l['domain_name'] ?? '');
    $logon_type = esc($con, $l['logon_type'] ?? '');
    $logon_time = esc($con, $l['logon_time'] ?? $now);
    $event_id   = (int)($l['event_id'] ?? 0);
    $source_ip  = esc($con, $l['source_ip'] ?? '');

    $r = mysqli_query($con, "
        INSERT IGNORE INTO endpoint_logons
            (endpoint_id, username, domain_name, logon_type, logon_time, event_id, source_ip, collected_at)
        VALUES
            ($endpoint_id, '$username', '$domain', '$logon_type', '$logon_time', $event_id, '$source_ip', '$now')
    ");
    if ($r) $counts['logons'] += mysqli_affected_rows($con);
}

// --- RDP sessions ---
foreach (($data['rdp_sessions'] ?? []) as $s) {
    $username = esc($con, $s['username'] ?? '');
    $client_ip = esc($con, $s['client_ip'] ?? '');
    $action = esc($con, $s['session_action'] ?? '');
    $event_id = (int)($s['event_id'] ?? 0);
    $event_time = esc($con, $s['event_time'] ?? $now);

    $r = mysqli_query($con, "
        INSERT IGNORE INTO endpoint_rdp_sessions
            (endpoint_id, username, client_ip, session_action, event_id, event_time, collected_at)
        VALUES
            ($endpoint_id, '$username', '$client_ip', '$action', $event_id, '$event_time', '$now')
    ");
    if ($r) $counts['rdp_sessions'] += mysqli_affected_rows($con);
}

// --- Installed apps (upsert; keep first_seen, refresh last_seen) ---
foreach (($data['apps'] ?? []) as $a) {
    $name = esc($con, $a['app_name'] ?? '');
    if ($name === '') continue;
    $version = esc($con, $a['app_version'] ?? '');
    $publisher = esc($con, $a['publisher'] ?? '');
    $install_date = !empty($a['install_date']) ? "'" . esc($con, $a['install_date']) . "'" : 'NULL';

    $r = mysqli_query($con, "
        INSERT INTO endpoint_apps
            (endpoint_id, app_name, app_version, publisher, install_date, first_seen, last_seen)
        VALUES
            ($endpoint_id, '$name', '$version', '$publisher', $install_date, '$now', '$now')
        ON DUPLICATE KEY UPDATE last_seen = '$now', publisher = '$publisher'
    ");
    if ($r) $counts['apps']++;
}

// --- Listening ports (fresh snapshot each run) ---
foreach (($data['ports'] ?? []) as $p) {
    $port = (int)($p['local_port'] ?? 0);
    if ($port === 0) continue;
    $proto = esc($con, $p['protocol'] ?? 'TCP');
    $pname = esc($con, $p['process_name'] ?? '');
    $ppath = esc($con, $p['process_path'] ?? '');
    $pid   = (int)($p['pid'] ?? 0);

    $r = mysqli_query($con, "
        INSERT INTO endpoint_ports
            (endpoint_id, local_port, protocol, process_name, process_path, pid, collected_at)
        VALUES
            ($endpoint_id, $port, '$proto', '$pname', '$ppath', $pid, '$now')
    ");
    if ($r) $counts['ports']++;
}

// Prune port snapshots older than 2 hours to keep the table from growing forever
mysqli_query($con, "DELETE FROM endpoint_ports WHERE endpoint_id = $endpoint_id AND collected_at < DATE_SUB('$now', INTERVAL 2 HOUR)");

mysqli_close($con);

echo json_encode(['ok' => true, 'endpoint_id' => $endpoint_id, 'inserted' => $counts]);
