<?php
/**
 * noc/drivers/driver_snmp.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Vendor-agnostic SNMP polling driver for JanusNAC.
 */
function nac_snmp_poll(string $ip, string $community, int $snmp_port = 161,
                       int $version = 2, string $plkey = ''): array
{
    if (!function_exists('snmp2_real_walk')) {
        throw new RuntimeException('PHP SNMP extension not loaded');
    }
    snmp_set_quick_print(true);
    snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
    
    $is_cisco  = str_contains($plkey, 'ios');
    $is_junos  = str_contains($plkey, 'junos');
    $community = trim($community);
    
    $ports = _snmp_walk_interfaces($ip, $community, $snmp_port);
    $vlans = _snmp_get_vlans($ip, $community, $snmp_port, $is_cisco);
    $bp_to_ifidx = _snmp_walk_simple($ip, $community, $snmp_port, SNMP_OID_BASE_PORT_IFIDX);
    
    $macs = [];
    if ($is_cisco) {
        $macs = _snmp_cisco_mac_walk($ip, $community, $snmp_port, $vlans, $bp_to_ifidx, $ports);
    } else {
        $macs = _snmp_standard_mac_walk($ip, $community, $snmp_port, $vlans, $bp_to_ifidx, $ports);
    }
    
    $lldp = _snmp_walk_lldp($ip, $community, $snmp_port, $ports);
    if (empty($lldp) && $is_cisco) {
        $lldp = _snmp_walk_cdp($ip, $community, $snmp_port, $ports);
    }
    
    foreach ($lldp as $entry) {
        $lp = $entry['local_port'];
        if (isset($ports[$lp])) {
            $ports[$lp]['lldp_neighbor'] = $entry['system_name'] ?: $entry['chassis_id'];
        }
    }
    
    _snmp_classify_trunks($ports, $macs);
    
    foreach ($macs as &$m) {
        $p = $m['port'];
        if (isset($ports[$p]) && !empty($ports[$p]['is_trunk'])) {
            $m['via_trunk'] = 1;
        }
    }
    unset($m);
    
    return [
        'macs'  => array_values($macs),
        'ports' => array_values($ports),
        'lldp'  => $lldp,
    ];
}

const SNMP_OID_IF_NAME        = '1.3.6.1.2.1.31.1.1.1.1';
const SNMP_OID_IF_ADMIN       = '1.3.6.1.2.1.2.2.1.7';
const SNMP_OID_IF_OPER        = '1.3.6.1.2.1.2.2.1.8';
const SNMP_OID_IF_ALIAS       = '1.3.6.1.2.1.31.1.1.1.18';
const SNMP_OID_IF_SPEED       = '1.3.6.1.2.1.2.2.1.5';
const SNMP_OID_IF_HCSPEED     = '1.3.6.1.2.1.31.1.1.1.15';
const SNMP_OID_HC_IN          = '1.3.6.1.2.1.31.1.1.1.6';
const SNMP_OID_HC_OUT         = '1.3.6.1.2.1.31.1.1.1.10';
const SNMP_OID_FDB_MAC        = '1.3.6.1.2.1.17.4.3.1.1';
const SNMP_OID_FDB_PORT       = '1.3.6.1.2.1.17.4.3.1.2';
const SNMP_OID_FDB_STATUS     = '1.3.6.1.2.1.17.4.3.1.3';
const SNMP_OID_BASE_PORT_IFIDX = '1.3.6.1.2.1.17.1.4.1.2';
const SNMP_OID_QFDB_PORT      = '1.3.6.1.2.1.17.7.1.2.2.1.2';
const SNMP_OID_VLAN_STATIC    = '1.3.6.1.2.1.17.7.1.4.3.1.1';
const SNMP_OID_VTP_VLAN_STATE = '1.3.6.1.4.1.9.9.46.1.3.1.1.2';
const SNMP_OID_LLDP_REM_NAME  = '1.0.8802.1.1.2.1.4.1.1.9';
const SNMP_OID_LLDP_REM_PORT  = '1.0.8802.1.1.2.1.4.1.1.7';
const SNMP_OID_LLDP_REM_CHASSIS = '1.0.8802.1.1.2.1.4.1.1.5';
const SNMP_OID_LLDP_LOC_PORT  = '1.0.8802.1.1.2.1.3.7.1.3';
const SNMP_OID_CDP_DEVNAME    = '1.3.6.1.4.1.9.9.23.1.2.1.1.6';
const SNMP_OID_CDP_DEVPORT    = '1.3.6.1.4.1.9.9.23.1.2.1.1.7';

function _snmp_walk_interfaces(string $ip, string $community, int $port): array
{
    $ports = [];
    $names = _snmp_walk_simple($ip, $community, $port, SNMP_OID_IF_NAME);
    $admins = _snmp_walk_simple($ip, $community, $port, SNMP_OID_IF_ADMIN);
    $opers = _snmp_walk_simple($ip, $community, $port, SNMP_OID_IF_OPER);
    $alias = _snmp_walk_simple($ip, $community, $port, SNMP_OID_IF_ALIAS);
    
    foreach ($admins as $oid => $admin_val) {
        $idx = _snmp_last_oid_segment($oid);
        if (!$idx) continue;
        $pname = $names['.' . SNMP_OID_IF_NAME . '.' . $idx] ?? $names[SNMP_OID_IF_NAME . '.' . $idx] ?? null;
        if (!$pname) continue;
        if (_snmp_skip_interface($pname)) continue;
        
        $oper_val = $opers['.' . SNMP_OID_IF_OPER . '.' . $idx] ?? $opers[SNMP_OID_IF_OPER . '.' . $idx] ?? '2';
        $alias_val = $alias['.' . SNMP_OID_IF_ALIAS . '.' . $idx] ?? $alias[SNMP_OID_IF_ALIAS . '.' . $idx] ?? '';
        
        $ports[$pname] = [
            'port_name' => $pname,
            'if_index' => (int)$idx,
            'admin_status' => ((int)$admin_val === 1) ? 'up' : 'down',
            'link_status' => ((int)$oper_val === 1) ? 'up' : 'down',
            'description' => $alias_val ?: null,
            'mac_count' => 0,
            'vlans' => [],
            'port_type' => 'access',
            'is_trunk' => 0,
        ];
    }
    return $ports;
}

function _snmp_get_vlans(string $ip, string $community, int $port, bool $is_cisco): array
{
    $vlans = [];
    if ($is_cisco) {
        $vtp = _snmp_walk_simple($ip, $community, $port, SNMP_OID_VTP_VLAN_STATE);
        foreach ($vtp as $oid => $state) {
            if ((int)$state !== 1) continue;
            $parts = explode('.', trim($oid, '.'));
            $vid = (int)end($parts);
            if ($vid >= 1 && $vid <= 4094 && !($vid >= 1002 && $vid <= 1005)) {
                $vlans[$vid] = 'vlan' . $vid;
            }
        }
        if (empty($vlans)) {
            $vlans = _snmp_qbridge_vlans($ip, $community, $port);
        }
    } else {
        $vlans = _snmp_qbridge_vlans($ip, $community, $port);
    }
    if (empty($vlans)) $vlans[1] = 'default';
    return $vlans;
}

function _snmp_qbridge_vlans(string $ip, string $community, int $port): array
{
    $vlans = [];
    $names = _snmp_walk_simple($ip, $community, $port, SNMP_OID_VLAN_STATIC);
    foreach ($names as $oid => $name) {
        $parts = explode('.', trim($oid, '.'));
        $vid = (int)end($parts);
        if ($vid >= 1 && $vid <= 4094) $vlans[$vid] = $name ?: ('vlan' . $vid);
    }
    return $vlans;
}

function _snmp_cisco_mac_walk(string $ip, string $community, int $port, array $vlans, array $bp_to_ifidx, array &$ports): array
{
    $macs = [];
    $ifidx_to_port = [];
    foreach ($ports as $pname => $pd) $ifidx_to_port[$pd['if_index']] = $pname;
    
    foreach ($vlans as $vid => $vname) {
        $vlan_community = $community . '@' . $vid;
        $fdb_macs = _snmp_walk_simple($ip, $vlan_community, $port, SNMP_OID_FDB_MAC);
        $fdb_ports = _snmp_walk_simple($ip, $vlan_community, $port, SNMP_OID_FDB_PORT);
        $fdb_status = _snmp_walk_simple($ip, $vlan_community, $port, SNMP_OID_FDB_STATUS);
        
        foreach ($fdb_macs as $oid => $mac_hex) {
            $suffix = _snmp_last_n_oid_segments($oid, 6);
            $mac_std = _snmp_hex_oid_to_mac($suffix);
            if (!$mac_std) continue;
            
            $bp_oid = SNMP_OID_FDB_PORT . '.' . $suffix;
            $bp_num = (int)($fdb_ports[$bp_oid] ?? $fdb_ports['.' . $bp_oid] ?? 0);
            if ($bp_num === 0) continue;
            
            $st_oid = SNMP_OID_FDB_STATUS . '.' . $suffix;
            $status = (int)($fdb_status[$st_oid] ?? $fdb_status['.' . $st_oid] ?? 3);
            if ($status !== 3) continue;
            
            $ifidx = (int)($bp_to_ifidx[SNMP_OID_BASE_PORT_IFIDX . '.' . $bp_num] ?? $bp_to_ifidx['.' . SNMP_OID_BASE_PORT_IFIDX . '.' . $bp_num] ?? 0);
            if ($ifidx === 0) continue;
            $pname = $ifidx_to_port[$ifidx] ?? null;
            if (!$pname) continue;
            
            if (!isset($macs[$mac_std])) {
                $macs[$mac_std] = ['mac' => $mac_std, 'vlan' => $vname, 'port' => $pname, 'type' => 'D'];
            }
            if (isset($ports[$pname])) {
                $ports[$pname]['mac_count']++;
                if (!in_array($vname, $ports[$pname]['vlans'])) $ports[$pname]['vlans'][] = $vname;
            }
        }
    }
    return $macs;
}

function _snmp_standard_mac_walk(string $ip, string $community, int $port, array $vlans, array $bp_to_ifidx, array &$ports): array
{
    $macs = [];
    $ifidx_to_port = [];
    foreach ($ports as $pname => $pd) $ifidx_to_port[$pd['if_index']] = $pname;
    
    $qfdb_ports = _snmp_walk_simple($ip, $community, $port, SNMP_OID_QFDB_PORT);
    if (!empty($qfdb_ports)) {
        foreach ($qfdb_ports as $oid => $bp_num) {
            $bp_num = (int)$bp_num;
            if ($bp_num === 0) continue;
            $suffix_parts = _snmp_oid_suffix_parts($oid, SNMP_OID_QFDB_PORT);
            if (count($suffix_parts) < 7) continue;
            $vid = (int)$suffix_parts[0];
            $mac_parts = array_slice($suffix_parts, 1, 6);
            $mac_std = _snmp_decimal_octets_to_mac($mac_parts);
            if (!$mac_std) continue;
            $vname = $vlans[$vid] ?? ('vlan' . $vid);
            $ifidx = (int)($bp_to_ifidx[SNMP_OID_BASE_PORT_IFIDX . '.' . $bp_num] ?? $bp_to_ifidx['.' . SNMP_OID_BASE_PORT_IFIDX . '.' . $bp_num] ?? 0);
            if ($ifidx === 0) $ifidx = $bp_num;
            $pname = $ifidx_to_port[$ifidx] ?? null;
            if (!$pname) continue;
            if (!isset($macs[$mac_std])) {
                $macs[$mac_std] = ['mac' => $mac_std, 'vlan' => $vname, 'port' => $pname, 'type' => 'D'];
            }
            if (isset($ports[$pname])) {
                $ports[$pname]['mac_count']++;
                if (!in_array($vname, $ports[$pname]['vlans'])) $ports[$pname]['vlans'][] = $vname;
            }
        }
        return $macs;
    }
    
    $fdb_macs = _snmp_walk_simple($ip, $community, $port, SNMP_OID_FDB_MAC);
    $fdb_ports = _snmp_walk_simple($ip, $community, $port, SNMP_OID_FDB_PORT);
    $fdb_status = _snmp_walk_simple($ip, $community, $port, SNMP_OID_FDB_STATUS);
    foreach ($fdb_macs as $oid => $mac_hex) {
        $suffix = _snmp_last_n_oid_segments($oid, 6);
        $mac_std = _snmp_hex_oid_to_mac($suffix);
        if (!$mac_std) continue;
        $bp_oid = SNMP_OID_FDB_PORT . '.' . $suffix;
        $bp_num = (int)($fdb_ports[$bp_oid] ?? $fdb_ports['.' . $bp_oid] ?? 0);
        if ($bp_num === 0) continue;
        $st_oid = SNMP_OID_FDB_STATUS . '.' . $suffix;
        $status = (int)($fdb_status[$st_oid] ?? $fdb_status['.' . $st_oid] ?? 3);
        if ($status !== 3) continue;
        $ifidx = (int)($bp_to_ifidx[SNMP_OID_BASE_PORT_IFIDX . '.' . $bp_num] ?? $bp_to_ifidx['.' . SNMP_OID_BASE_PORT_IFIDX . '.' . $bp_num] ?? 0);
        if ($ifidx === 0) $ifidx = $bp_num;
        $pname = $ifidx_to_port[$ifidx] ?? null;
        if (!$pname) continue;
        $vname = 'default';
        if (!isset($macs[$mac_std])) {
            $macs[$mac_std] = ['mac' => $mac_std, 'vlan' => $vname, 'port' => $pname, 'type' => 'D'];
        }
        if (isset($ports[$pname])) {
            $ports[$pname]['mac_count']++;
            if (!in_array($vname, $ports[$pname]['vlans'])) $ports[$pname]['vlans'][] = $vname;
        }
    }
    return $macs;
}

function _snmp_walk_lldp(string $ip, string $community, int $port, array $ports): array
{
    $lldp = [];
    $loc_ports = _snmp_walk_simple($ip, $community, $port, SNMP_OID_LLDP_LOC_PORT);
    $port_num_to_name = [];
    foreach ($loc_ports as $oid => $desc) {
        $num = (int)_snmp_last_oid_segment($oid);
        if ($num) $port_num_to_name[$num] = $desc;
    }
    $sys_names = _snmp_walk_simple($ip, $community, $port, SNMP_OID_LLDP_REM_NAME);
    $rem_ports = _snmp_walk_simple($ip, $community, $port, SNMP_OID_LLDP_REM_PORT);
    $chassis = _snmp_walk_simple($ip, $community, $port, SNMP_OID_LLDP_REM_CHASSIS);
    foreach ($sys_names as $oid => $sys_name) {
        if (!$sys_name) continue;
        $parts = explode('.', trim($oid, '.'));
        $n = count($parts);
        if ($n < 3) continue;
        $loc_pnum = (int)$parts[$n - 2];
        $local_port = $port_num_to_name[$loc_pnum] ?? ('port' . $loc_pnum);
        $remote_port = $rem_ports[str_replace(SNMP_OID_LLDP_REM_NAME, SNMP_OID_LLDP_REM_PORT, $oid)] ?? '';
        $chassis_id = $chassis[str_replace(SNMP_OID_LLDP_REM_NAME, SNMP_OID_LLDP_REM_CHASSIS, $oid)] ?? '';
        $lldp[] = ['local_port' => $local_port, 'chassis_id' => strtoupper($chassis_id), 'remote_port' => $remote_port, 'system_name' => $sys_name];
    }
    return $lldp;
}

function _snmp_walk_cdp(string $ip, string $community, int $port, array $ports): array
{
    $lldp = [];
    $dev_names = _snmp_walk_simple($ip, $community, $port, SNMP_OID_CDP_DEVNAME);
    $dev_ports = _snmp_walk_simple($ip, $community, $port, SNMP_OID_CDP_DEVPORT);
    $ifidx_to_port = [];
    foreach ($ports as $pname => $pd) $ifidx_to_port[$pd['if_index']] = $pname;
    foreach ($dev_names as $oid => $dev_name) {
        if (!$dev_name) continue;
        $parts = explode('.', trim($oid, '.'));
        $n = count($parts);
        if ($n < 2) continue;
        $ifidx = (int)$parts[$n - 2];
        $pname = $ifidx_to_port[$ifidx] ?? null;
        if (!$pname) continue;
        $remote_port = $dev_ports[str_replace(SNMP_OID_CDP_DEVNAME, SNMP_OID_CDP_DEVPORT, $oid)] ?? '';
        $lldp[] = ['local_port' => $pname, 'chassis_id' => '', 'remote_port' => $remote_port, 'system_name' => $dev_name];
    }
    return $lldp;
}

function _snmp_classify_trunks(array &$ports, array $macs): void
{
    $threshold = defined('NAC_TRUNK_THRESHOLD') ? NAC_TRUNK_THRESHOLD : 8;
    foreach ($ports as $pname => &$p) {
        $mc = $p['mac_count'] ?? 0;
        if (preg_match('/^(ae\d+|Po\d+|trk\d+|bond\d+)/i', $pname)) {
            $p['port_type'] = 'lag'; $p['is_trunk'] = 1;
        } elseif (preg_match('/^(irb\.|Vlan|vlan\.)/i', $pname)) {
            $p['port_type'] = 'irb'; $p['is_trunk'] = 0;
        } elseif ($mc >= $threshold) {
            $p['port_type'] = 'trunk'; $p['is_trunk'] = 1;
        } else {
            $p['port_type'] = 'access'; $p['is_trunk'] = 0;
        }
    }
    unset($p);
}

function _snmp_walk_simple(string $ip, string $community, int $port, string $oid): array
{
    $result = [];
    set_error_handler(fn() => null);
    $raw = @snmp2_real_walk($ip . ':' . $port, $community, $oid, 5000000, 3);
    restore_error_handler();
    if (is_array($raw) && !empty($raw)) {
        foreach ($raw as $key => $val) $result[$key] = _snmp_strip_type($val);
        return $result;
    }
    $esc_ip = escapeshellarg($ip . ':' . $port);
    $esc_comm = escapeshellarg($community);
    $esc_oid = escapeshellarg($oid);
    exec("snmpwalk -v2c -c $esc_comm -On -Oq $esc_ip $esc_oid 2>/dev/null", $out);
    foreach ($out as $line) {
        if (preg_match('/^([\.\d]+)\s+(.*)$/', trim($line), $m)) $result[$m[1]] = trim($m[2]);
    }
    return $result;
}

function _snmp_strip_type(string $val): string
{
    if (preg_match('/^(?:STRING|INTEGER|Hex-STRING|OID|IpAddress|Counter\d*|Gauge\d*|TimeTicks|BITS|Opaque):\s*(.*)/i', $val, $m)) {
        $v = trim($m[1], '"');
        if (str_starts_with(strtoupper($val), 'HEX-STRING')) $v = str_replace(' ', ':', strtolower(trim($v)));
        return $v;
    }
    return trim($val, '"');
}

function _snmp_last_oid_segment(string $oid): string
{
    $parts = explode('.', trim($oid, '.'));
    return end($parts);
}

function _snmp_last_n_oid_segments(string $oid, int $n): string
{
    $parts = explode('.', trim($oid, '.'));
    return implode('.', array_slice($parts, -$n));
}

function _snmp_oid_suffix_parts(string $oid, string $prefix): array
{
    $oid = trim($oid, '.');
    $prefix = trim($prefix, '.');
    if (str_starts_with($oid, $prefix)) $suffix = ltrim(substr($oid, strlen($prefix)), '.');
    else $suffix = $oid;
    return $suffix !== '' ? array_map('intval', explode('.', $suffix)) : [];
}

function _snmp_hex_oid_to_mac(string $suffix): ?string
{
    $parts = explode('.', trim($suffix, '.'));
    if (count($parts) < 6) return null;
    $parts = array_slice($parts, -6);
    $hex = array_map(fn($b) => sprintf('%02X', (int)$b), $parts);
    return implode(':', $hex);
}

function _snmp_decimal_octets_to_mac(array $octets): ?string
{
    if (count($octets) !== 6) return null;
    return implode(':', array_map(fn($b) => sprintf('%02X', (int)$b), $octets));
}

function _snmp_normalize_port_name(string $name): string
{
    $name = trim($name);
    if (preg_match('/^irb\./i', $name)) return $name;
    return preg_replace('/\.0$/', '', $name);
}

function _snmp_skip_interface(string $name): bool
{
    $lc = strtolower(trim($name));
    $skip = ['lo', 'lo0', 'null', 'null0', 'nve', 'tunnel', 'loopback', 'mgmt', 'management', 'oob'];
    foreach ($skip as $s) {
        if ($lc === $s || str_starts_with($lc, $s . '.')) return true;
    }
    if (preg_match('/\.\d+$/', $name) && !preg_match('/^irb\./i', $name)) {
        if (!preg_match('/\.0$/', $name)) return true;
    }
    return false;
}

function nac_snmp_test(string $ip, string $community, int $snmp_port = 161): array
{
    if (!function_exists('snmp2_real_walk')) {
        return ['ok' => false, 'error' => 'PHP snmp extension not installed'];
    }
    snmp_set_quick_print(true);
    set_error_handler(fn() => null);
    $sysname = @snmpget($ip . ':' . $snmp_port, $community, SNMP_OID_SYSNAME, 3000000, 2);
    $sysdescr = @snmpget($ip . ':' . $snmp_port, $community, SNMP_OID_SYSDESCR, 3000000, 2);
    restore_error_handler();
    if ($sysname === false || $sysname === null) {
        return ['ok' => false, 'error' => 'SNMP unreachable'];
    }
    return ['ok' => true, 'sysname' => _snmp_strip_type((string)$sysname), 'sysdescr' => _snmp_strip_type((string)$sysdescr)];
}
