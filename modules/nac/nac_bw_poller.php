#!/usr/bin/env php
require_once __DIR__ . '/../../db_config.php';
<?php
/**
 * NAC Bandwidth Poller - Final Working Version
 * Maps ifName (high indices) to ifDescr (low indices) for correct speed/counter retrieval
 */
define('NAC_BW_RETAIN_HOURS', 24);
require_once __DIR__ . '/drivers/driver_snmp.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    echo "DB connect failed: " . $db->connect_error . "\n";
    exit(1);
}
$db->set_charset('utf8mb4');

// Ensure table exists
$db->query("CREATE TABLE IF NOT EXISTS nac_port_bw (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    switch_id INT UNSIGNED NOT NULL,
    port_name VARCHAR(64) NOT NULL,
    if_index INT UNSIGNED NOT NULL DEFAULT 0,
    in_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
    out_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
    in_errors BIGINT UNSIGNED NOT NULL DEFAULT 0,
    out_errors BIGINT UNSIGNED NOT NULL DEFAULT 0,
    speed_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
    polled_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_sw_port_time (switch_id, port_name, polled_at),
    INDEX idx_polled (polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Purge old records
$db->query("DELETE FROM nac_port_bw WHERE polled_at < DATE_SUB(NOW(), INTERVAL " . NAC_BW_RETAIN_HOURS . " HOUR)");

// Get SNMP switches
$switches = [];
$r = $db->query("SELECT id, hostname, ip, snmp_community, snmp_port FROM nac_switches 
                 WHERE enabled=1 AND connection_type='snmp' AND snmp_community IS NOT NULL");
if ($r) {
    while ($row = $r->fetch_assoc()) $switches[] = $row;
    $r->free();
}

if (empty($switches)) {
    echo "No SNMP switches found\n";
    $db->close();
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Polling bandwidth for " . count($switches) . " SNMP switch(es)\n";

foreach ($switches as $sw) {
    $ip = $sw['ip'];
    $community = trim($sw['snmp_community']);
    $snmp_port = (int)($sw['snmp_port'] ?? 161);
    $sw_id = (int)$sw['id'];

    echo "  Polling {$sw['hostname']} ({$ip})...\n";

    try {
        // Get ifName (high indices like 537, 538)
        $if_name = _snmp_walk_simple($ip, $community, $snmp_port, '1.3.6.1.2.1.31.1.1.1.1');
        
        // Get ifDescr (low indices like 501, 502, 503)
        $if_descr = _snmp_walk_simple($ip, $community, $snmp_port, '1.3.6.1.2.1.2.2.1.2');
        
        // Build mapping from interface name to ifDescr index
        $name_to_descr_idx = [];
        foreach ($if_name as $oid_name => $name) {
            $name_clean = preg_replace('/\.0$/', '', $name);
            $name_clean = _snmp_normalize_port_name($name);
            
            // Skip .0 sub-interfaces
            if (preg_match('/\.0$/', $name) && !preg_match('/^irb\./i', $name)) {
                continue;
            }
            if (_snmp_skip_interface($name)) {
                continue;
            }
            
            // Find matching ifDescr entry
            foreach ($if_descr as $oid_desc => $descr) {
                $descr_clean = preg_replace('/\.0$/', '', $descr);
                if ($descr_clean === $name_clean || $descr_clean === $name) {
                    $idx_desc = (int)_snmp_last_oid_segment($oid_desc);
                    $name_to_descr_idx[$name_clean] = $idx_desc;
                    break;
                }
            }
        }
        
        // Get counters and speeds using ifDescr indices
        $hc_in = _snmp_walk_simple($ip, $community, $snmp_port, '1.3.6.1.2.1.31.1.1.1.6');
        $hc_out = _snmp_walk_simple($ip, $community, $snmp_port, '1.3.6.1.2.1.31.1.1.1.10');
        $if_speed = _snmp_walk_simple($ip, $community, $snmp_port, '1.3.6.1.2.1.2.2.1.5');
        
        $stmt = $db->prepare("INSERT INTO nac_port_bw 
            (switch_id, port_name, if_index, in_octets, out_octets, in_errors, out_errors, speed_bps, polled_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3))");
        
        $inserted = 0;
        foreach ($name_to_descr_idx as $pname => $idx) {
            // Build keys with 'iso.' prefix (what _snmp_walk_simple returns)
            $in_key = 'iso.3.6.1.2.1.31.1.1.1.6.' . $idx;
            $out_key = 'iso.3.6.1.2.1.31.1.1.1.10.' . $idx;
            $speed_key = 'iso.3.6.1.2.1.2.2.1.5.' . $idx;
            
            $in_val = (int)($hc_in[$in_key] ?? 0);
            $out_val = (int)($hc_out[$out_key] ?? 0);
            $speed_bps = (int)($if_speed[$speed_key] ?? 0);
            $in_err = 0;
            $out_err = 0;
            
            $stmt->bind_param('isiiiiii', $sw_id, $pname, $idx, $in_val, $out_val, $in_err, $out_err, $speed_bps);
            $stmt->execute();
            $inserted++;
        }
        $stmt->close();
        echo "    ✓ {$inserted} port counters stored\n";
    } catch (Throwable $e) {
        echo "    ✗ Error: " . $e->getMessage() . "\n";
    }
}
$db->close();
echo "Done.\n";
