<?php
/**
 * drivers/driver_ios.php
 * Cisco IOS / IOS-XE driver
 */

function driver_ios_meta(): array {
    return [
        'platform_key'   => 'ios',
        'vendor'         => 'cisco',
        'display_name'   => 'Cisco IOS (2950/2960/3560/3750)',
        'cmd_mac_table'  => 'show mac address-table dynamic',
        'cmd_interfaces' => 'show interfaces status',
        'cmd_lldp'       => 'show cdp neighbors',
        'disable_pager'  => 'terminal length 0',
        'prompt_pattern' => '[A-Za-z0-9_\-\.]+[#>]',
    ];
}

function driver_ios_xe_meta(): array {
    return [
        'platform_key'   => 'ios-xe',
        'vendor'         => 'cisco',
        'display_name'   => 'Cisco IOS-XE (Catalyst 9k / 3850)',
        'cmd_mac_table'  => 'show mac address-table dynamic',
        'cmd_interfaces' => 'show interfaces status',
        'cmd_lldp'       => 'show cdp neighbors',
        'disable_pager'  => 'terminal length 0',
        'prompt_pattern' => '[A-Za-z0-9_\-\.]+[#>]',
    ];
}

function driver_ios_legacy_meta(): array {
    return [
        'platform_key'   => 'ios-legacy',
        'vendor'         => 'cisco',
        'display_name'   => 'Cisco IOS Legacy (2950/2955 IOS 12.1) Telnet',
        'cmd_mac_table'  => 'show mac-address-table',
        'cmd_interfaces' => 'show interfaces status',
        'cmd_lldp'       => 'show cdp neighbors',
        'disable_pager'  => 'terminal length 0',
        'prompt_pattern' => '[A-Za-z0-9_\-\.]+[#>]',
    ];
}

function driver_ios_telnet_meta(): array {
    return [
        'platform_key'   => 'ios-telnet',
        'vendor'         => 'cisco',
        'display_name'   => 'Cisco IOS Telnet (2960/3560 IOS 12.2+) Telnet',
        'cmd_mac_table'  => 'show mac address-table',
        'cmd_interfaces' => 'show interfaces status',
        'cmd_lldp'       => 'show cdp neighbors',
        'disable_pager'  => 'terminal length 0',
        'prompt_pattern' => '[A-Za-z0-9_\-\.]+[#>]',
    ];
}

function driver_ios_parse(string $mac_out, string $ifc_out, string $lldp_out): array {
    $macs  = _ios_parse_mac_table($mac_out);
    $ports = _ios_parse_interfaces($ifc_out, $macs);
    $lldp  = _ios_parse_cdp($lldp_out, $ports);

    _ios_classify_trunks($ports);
    _ios_tag_via_trunk($macs, $ports);

    return [
        'macs'  => array_values($macs),
        'ports' => array_values($ports),
        'lldp'  => $lldp,
    ];
}

function _ios_parse_mac_table(string $raw): array {
    $macs = [];

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line) continue;

        if (preg_match('/^(Vlan|Mac\s+Address|----|-\s*All|Total)/i', $line)) continue;

        if (!preg_match(
            '/^\*?\s*(\d+)\s+([0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4})\s+(\S+)\s+(\S+)/i',
            $line, $m
        )) continue;

        $vlan  = 'vlan' . $m[1];
        $mac   = _ios_mac_to_std($m[2]);
        $type  = strtoupper($m[3][0]);
        $iface = $m[4];

        if (in_array(strtolower($iface), ['cpu', 'drop', 'redirect', 'all'])) continue;

        if (!isset($macs[$mac])) {
            $macs[$mac] = ['mac' => $mac, 'vlan' => $vlan, 'port' => $iface, 'type' => $type];
        }
    }

    return $macs;
}

function _ios_parse_interfaces(string $raw, array $macs): array {
    $ports = [];

    foreach ($macs as $m) {
        $iface = $m['port'];
        if (in_array(strtolower($iface), ['cpu', 'drop', 'redirect', 'all', 'all-members', 'flood'])) continue;
        if (!isset($ports[$iface])) {
            $ports[$iface] = [
                'port_name'    => $iface,
                'mac_count'    => 0,
                'vlans'        => [],
                'link_status'  => 'unknown',
                'admin_status' => 'up',
                'port_type'    => 'access',
                'is_trunk'     => 0,
            ];
        }
        $ports[$iface]['mac_count']++;
        if (!in_array($m['vlan'], $ports[$iface]['vlans'])) {
            $ports[$iface]['vlans'][] = $m['vlan'];
        }
    }

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line) continue;

        if (!preg_match(
            '/^(Gi\S+|Fa\S+|Te\S+|Hu\S+|Tw\S+|Po\d+|Eth\S+)\s+(.*?)\s*(connected|notconnect|disabled|err-disabled|monitoring)\s+(\S+)/i',
            $line, $m
        )) continue;

        $pname       = $m[1];
        $description = trim($m[2]);
        $status      = strtolower($m[3]);
        $vlan        = $m[4];
        $link        = ($status === 'connected' || $status === 'monitoring') ? 'up' : 'down';
        $admin       = ($status === 'disabled' || $status === 'err-disabled') ? 'down' : 'up';

        if (!isset($ports[$pname])) {
            $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
        }
        $ports[$pname]['admin_status'] = $admin;
        $ports[$pname]['link_status']  = $link;
        if ($description !== '') {
            $ports[$pname]['description'] = $description;
        }

        if (strtolower($vlan) === 'trunk') {
            $ports[$pname]['port_type'] = 'trunk';
            $ports[$pname]['is_trunk']  = 1;
        } elseif (str_starts_with(strtolower($pname), 'po')) {
            $ports[$pname]['port_type'] = 'lag';
            $ports[$pname]['is_trunk']  = 1;
        } else {
            $ports[$pname]['port_type'] = 'access';
            $ports[$pname]['is_trunk']  = 0;
        }
    }

    foreach ($ports as &$p) {
        if (($p['link_status'] ?? 'unknown') === 'unknown') {
            $p['link_status']  = 'down';
            $p['admin_status'] = $p['admin_status'] ?? 'up';
        }
    }
    unset($p);

    return $ports;
}

function _ios_parse_cdp(string $raw, array &$ports): array {
    $lldp = [];

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!preg_match('/^(\S+)\s+((?:Gig|Fas|Ten|Eth|Ser)\s*\S+)\s+\d+\s+\S.*?\s+(\S+)\s*$/i', $line, $m))
            continue;

        $sys_name    = $m[1];
        $local_raw   = preg_replace('/\s+/', '', $m[2]);
        $local_port  = _ios_expand_iface($local_raw);
        $remote_port = _ios_expand_iface($m[3]);

        $lldp[] = [
            'local_port'  => $local_port,
            'chassis_id'  => '',
            'remote_port' => $remote_port,
            'system_name' => $sys_name,
        ];

        if (isset($ports[$local_port])) {
            $ports[$local_port]['lldp_neighbor'] = $sys_name;
        }
    }

    return $lldp;
}

function _ios_classify_trunks(array &$ports): void {
    $threshold = defined('NAC_TRUNK_THRESHOLD') ? NAC_TRUNK_THRESHOLD : 8;
    foreach ($ports as &$p) {
        if (($p['mac_count'] ?? 0) >= $threshold && empty($p['is_trunk'])) {
            $p['is_trunk']  = 1;
            $p['port_type'] = 'trunk';
        }
    }
    unset($p);
}

function _ios_tag_via_trunk(array &$macs, array $ports): void {
    $trunk_ports = [];
    foreach ($ports as $pn => $pd) {
        if (!empty($pd['is_trunk'])) $trunk_ports[$pn] = true;
    }
    foreach ($macs as &$m) {
        if (isset($trunk_ports[$m['port']])) $m['via_trunk'] = 1;
    }
    unset($m);
}

function _ios_mac_to_std(string $mac): string {
    $hex   = str_replace('.', '', $mac);
    $parts = str_split($hex, 2);
    return strtoupper(implode(':', $parts));
}

function _ios_expand_iface(string $abbr): string {
    $map = [
        '/^Gig?(\S+)/i'  => 'GigabitEthernet$1',
        '/^Fas?(\S+)/i'  => 'FastEthernet$1',
        '/^Ten?(\S+)/i'  => 'TenGigabitEthernet$1',
        '/^Twe?(\S+)/i'  => 'TwentyFiveGigE$1',
        '/^Hu(\S+)/i'    => 'HundredGigE$1',
    ];
    foreach ($map as $pattern => $replace) {
        $result = preg_replace($pattern, $replace, $abbr);
        if ($result !== $abbr) return $result;
    }
    return $abbr;
}
