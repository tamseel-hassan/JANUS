<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * soc/ipam_scan.php  –  AJAX scan endpoint
 * Always outputs valid JSON. Auto-detects correct NIC per subnet.
 */
session_start();
header('Content-Type: application/json');

// Catch all PHP errors as exceptions so they return JSON not raw text
set_exception_handler(function(Throwable $e) {
    http_response_code(200);
    echo json_encode(['error' => $e->getMessage(), 'scanned' => 0, 'changes' => []]);
    exit;
});
// Do NOT use set_error_handler that throws - it will catch mysqli warnings mid-flight
// Instead suppress notices/warnings from reaching output
error_reporting(E_ERROR | E_PARSE);

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    exit(json_encode(['error' => 'Authentication required']));
}

require_once __DIR__ . '/ipam_lib.php';

/* ── Validate CIDR ── */
$cidr = trim($_POST['network'] ?? '');
if (!$cidr) {
    exit(json_encode(['error' => 'No network CIDR provided']));
}
if (!preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $cidr)) {
    exit(json_encode(['error' => 'Invalid CIDR format (e.g. 10.11.1.0/24)']));
}
[, $prefix] = explode('/', $cidr);
if ((int)$prefix < 8 || (int)$prefix > 32) {
    exit(json_encode(['error' => 'Prefix length must be 8-32']));
}

$t0 = microtime(true);

/* ── Detect interface FIRST (before opening main DB) ── */
// Avoids any DB sync issues - uses shell only
$iface = _detect_iface_shell($cidr);

try {
    $db = ipam_db();
} catch (RuntimeException $e) {
    exit(json_encode(['error' => $e->getMessage()]));
}

[$net_long, $broadcast] = subnet_bounds($cidr);

/* ── Phase 1: ARP scan ── */
$found_ips   = run_arp_scan($cidr);
$ping_checked = 0;

/* ── Phase 2: Ping fallback for no-MAC devices in this subnet ── */
$nm = $db->prepare("SELECT ip FROM janus_ipam WHERE (mac IS NULL OR mac = '') AND status != 'reserved'");
if ($nm) {
    $nm->execute();
    $no_mac_rows = $nm->get_result()->fetch_all(MYSQLI_ASSOC);
    $nm->close();
    foreach ($no_mac_rows as $row) {
        if (!ip_in_subnet($row['ip'], $net_long, $broadcast)) continue;
        if (isset($found_ips[$row['ip']])) continue;
        $result = ping_host($row['ip']);
        $ping_checked++;
        if ($result['alive']) {
            $found_ips[$row['ip']] = '';
        }
    }
}

/* ── Process & update DB ── */
$changes  = process_discovered($db, $found_ips, $net_long, $broadcast);
$duration = round(microtime(true) - $t0, 2);

$db->close();

echo json_encode([
    'ok'           => true,
    'cidr'         => $cidr,
    'iface'        => $iface,
    'scanned'      => count($found_ips),
    'changes'      => $changes,
    'duration'     => $duration,
    'ping_fallback'=> $ping_checked,
    'devices'      => array_map(
        fn($ip, $mac) => ['ip' => $ip, 'mac' => $mac],
        array_keys($found_ips), array_values($found_ips)
    ),
]);

/* ── Detect interface via shell only (no DB) ── */
function _detect_iface_shell(string $cidr): string {
    [$net] = explode('/', $cidr);
    $parts    = explode('.', $net);
    $parts[3] = (int)$parts[3] + 1;
    $probe    = implode('.', $parts);
    $out = @shell_exec('ip route get ' . escapeshellarg($probe) . ' 2>/dev/null');
    if ($out && preg_match('/\bdev\s+(\S+)/', $out, $m)) {
        return $m[1];
    }
    // Manual fallback mapping based on known routes
    $net_long = ip2long($net);
    // 10.x.x.x and 192.168.x.x → ens192 (internal)
    // 172.17.128.x → ens160
    if (($net_long & 0xFF000000) === ip2long('10.0.0.0') ||
        ($net_long & 0xFFFF0000) === ip2long('192.168.0.0')) {
        return 'ens192';
    }
    if (($net_long & 0xFFFFFF00) === ip2long('172.17.128.0')) {
        return 'ens160';
    }
    return 'ens160';
}
