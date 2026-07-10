<?php
/**
 * drivers/driver_procurve.php
 * HP ProCurve / Aruba OS driver — 2510/2610/2910/2930/2920/3810
 *
 * Commands issued (platform_key = 'procurve' or 'aruba-os'):
 *   cmd_mac_table  : show mac-address
 *   cmd_interfaces : show interfaces status   (or "show interfaces brief" on some models)
 *   cmd_lldp       : show lldp info remote-device
 */

function driver_procurve_meta(): array {
    return [
        'platform_key'   => 'procurve',
        'vendor'         => 'hp',
        'display_name'   => 'HP ProCurve (2510/2610/2910)',
        'cmd_mac_table'  => 'show mac-address',
        'cmd_interfaces' => 'show interfaces status',
        'cmd_lldp'       => 'show lldp info remote-device',
        'disable_pager'  => 'no page',
        'prompt_pattern' => '[A-Za-z0-9_\-\.]+[#>]\s',
    ];
}

function driver_aruba_os_meta(): array {
    return [
        'platform_key'   => 'aruba-os',
        'vendor'         => 'hp',
        'display_name'   => 'HP Aruba 2930 / 2920 / 3810',
        'cmd_mac_table'  => 'show mac-address detail',   // bare 'show mac-address' drops into interactive menu
        'cmd_interfaces' => 'show interfaces status',  // gives Tagged/Untagged VLAN cols; 'brief' doesn't
        'cmd_lldp'       => 'show lldp info remote-device',
        'disable_pager'  => 'no page',
        'prompt_pattern' => '[A-Za-z0-9_\-\.]+[#>]\s',
    ];
}

function driver_procurve_parse(string $mac_out, string $ifc_out, string $lldp_out): array {
    $macs  = _pc_parse_mac_table($mac_out);
    $ports = _pc_parse_interfaces($ifc_out, $macs);
    $lldp  = _pc_parse_lldp($lldp_out, $ports);

    _pc_finalize_ports($ports);
    _pc_tag_via_trunk($macs, $ports);

    return [
        'macs'  => array_values($macs),
        'ports' => array_values($ports),
        'lldp'  => $lldp,
    ];
}

// Aruba OS uses same parsers as ProCurve (same CLI syntax, just different interface command)
function driver_aruba_os_parse(string $mac_out, string $ifc_out, string $lldp_out): array {
    return driver_procurve_parse($mac_out, $ifc_out, $lldp_out);
}

/* ─────────────────────────────────────────────────────────────
   MAC TABLE PARSER
   Format A (show mac-address — 3-col):
     MAC Address       Port   VLAN
     000c29-010bd5     27     1

   Format B (show mac-address detail — 4-col with Age):
     MAC Address       Port                    VLAN  Age (d:h:m:s.ms)
     000c29-010bd5     Trk24                   1     0000:00:03:17.32
     70e284-0edb1b     8                       111   0000:00:01:58.07

   HP compacted MAC notation: xxxxxx-xxxxxx
─────────────────────────────────────────────────────────────── */
function _pc_parse_mac_table(string $raw): array {
    $macs = [];

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (preg_match('/^(-|MAC|Status|Port|Vlan)/i', $line)) continue;

        // Match: xxxxxx-xxxxxx  <port>  <vlan>  [optional age d:h:m:s.ms]
        if (!preg_match(
            '/^([0-9a-f]{6}-[0-9a-f]{6})\s+(\S+)\s+(\d+)/i',
            $line, $m
        )) continue;

        $mac  = _pc_mac_to_std($m[1]);
        $port = $m[2];   // numeric or Trk#
        $vlan = 'vlan' . $m[3];

        // Normalise: Trk24 → trk24, numeric stays numeric
        $port_norm = is_numeric($port) ? $port : strtolower($port);

        if (!isset($macs[$mac])) {
            $macs[$mac] = ['mac' => $mac, 'vlan' => $vlan, 'port' => $port_norm, 'type' => 'D'];
        }
    }

    return $macs;
}

/* ─────────────────────────────────────────────────────────────
   INTERFACES PARSER
   Detects whether output is from "show interfaces status" (Format A)
   or "show interfaces brief" (Format B) based on header keywords.

   Format A — "show interfaces status":
     Port  Name  Status  Config-mode  Speed    Type       Tagged  Untagged
       1         Down    Auto    1000FDx  100/1000T  No     222
       3         Up      Auto    ...
       24-Trk1   Down    Auto    ...   (LAG member with cols)
       28-Trk24                        (standalone LAG member line)

   Format B — "show interfaces brief":
     Port  Type       | Alert Enabled Status  Mode    MDI Flow Bcast
       1   100/1000T  | No   Yes     Down    1000FDx  Auto off  0
       23-Trk1 100/T  | No   Yes     Down    ...
       25*  SFP+SR    | No   Yes     Up      10GigFD  NA   off  0
─────────────────────────────────────────────────────────────── */
function _pc_parse_interfaces(string $raw, array $macs): array {
    $ports = [];

    // Pre-populate from MAC data
    foreach ($macs as $m) {
        $pn = $m['port'];
        if (!isset($ports[$pn])) {
            $ports[$pn] = ['port_name' => $pn, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
        }
        if (!isset($ports[$pn]['_seen_macs'][$m['mac']])) {
            $ports[$pn]['_seen_macs'][$m['mac']] = true;
            $ports[$pn]['mac_count']++;
        }
        if (!in_array($m['vlan'], $ports[$pn]['vlans'])) {
            $ports[$pn]['vlans'][] = $m['vlan'];
        }
    }

    $use_brief = str_contains($raw, 'Intrusion') || str_contains($raw, 'Alert') || str_contains($raw, '|');

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line) continue;
        // Skip headers / legend lines / dashes
        if (preg_match('/^(-|Port|Status|Max|Primary|Manag|VLAN|\*)/i', $line)) continue;

        if ($use_brief) {
            _pc_parse_brief_line($line, $ports);
        } else {
            _pc_parse_status_line($line, $ports);
        }
    }

    return $ports;
}

function _pc_parse_brief_line(string $line, array &$ports): void {
    // Real 2930F "show interfaces brief" lines:
    //   "  1            100/1000T  | No        Yes     Down   1000FDx    Auto off  0"
    //   "  25                      | No        Yes     Down   .               off  0"  ← type EMPTY
    //   "  26*          1000SX     | No        Yes     Down   1000FDx    NA   off  0"  ← SFP *
    //   "  27*          SFP+SR     | No        Yes     Up     10GigFD    NA   off  0"
    //   "  28-Trk24                | No        Yes     Down   .               off  0"  ← LAG, type EMPTY
    //
    // The type field between port# and | may be completely empty (just spaces).
    // Use [^|]* to consume everything up to the pipe, then read Alert/Enabled/Status.
    if (!preg_match(
        '/^\s*(\d+)(?:-(Trk\w+))?\*?\s+[^|]*\|\s*\S+\s+(Yes|No)\s+(Up|Down)/i',
        $line, $m
    )) return;

    $pname    = $m[1];
    $lag_raw  = ($m[2] !== '') ? strtolower($m[2]) : null;   // 'trk24' or null
    $enabled  = strtolower($m[3]);
    $status   = strtolower($m[4]);

    if (!isset($ports[$pname])) {
        $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
    }
    $ports[$pname]['admin_status'] = ($enabled === 'yes') ? 'up' : 'down';
    $ports[$pname]['link_status']  = $status;

    if ($lag_raw) {
        $ports[$pname]['port_type'] = 'lag-member';
        $ports[$pname]['lag_group'] = $lag_raw;
        $ports[$pname]['is_trunk']  = 1;
        if (!isset($ports[$lag_raw])) {
            $ports[$lag_raw] = [
                'port_name'    => $lag_raw,
                'mac_count'    => 0,
                'vlans'        => [],
                'port_type'    => 'lag',
                'is_trunk'     => 1,
                'admin_status' => 'up',
                'link_status'  => $status,
                '_seen_macs'   => [],
            ];
        } elseif ($status === 'up') {
            $ports[$lag_raw]['link_status'] = 'up';
        }
    } else {
        if (!isset($ports[$pname]['port_type'])) {
            $ports[$pname]['port_type'] = 'access';
            $ports[$pname]['is_trunk']  = 0;
        }
    }
}

function _pc_parse_status_line(string $line, array &$ports): void {
    // Standalone LAG member line: "  28-Trk24"
    if (preg_match('/^\s*(\d+)-(\S+)\s*$/', $line, $m)) {
        $pname   = $m[1];
        $lag_grp = strtolower($m[2]);
        if (!isset($ports[$pname])) {
            $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
        }
        $ports[$pname] = array_merge($ports[$pname], [
            'port_type' => 'lag-member', 'lag_group' => $lag_grp,
            'admin_status' => 'up', 'link_status' => 'down', 'is_trunk' => 1,
        ]);
        return;
    }

    // LAG member WITH status — real 2930F format:
    //   "  28-Trk24            Down                                      No     1"
    //   Name col is empty, Config/Speed/Type cols are blank — only Port-TrkN and Status are reliable
    if (preg_match('/^\s*(\d+)-(Trk\w+)\s+(Up|Down)/i', $line, $m)) {
        $pname   = $m[1];
        $lag_grp = strtolower($m[2]);   // 'trk24'
        $status  = strtolower($m[3]);
        if (!isset($ports[$pname])) {
            $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
        }
        $ports[$pname] = array_merge($ports[$pname], [
            'port_type'    => 'lag-member',
            'lag_group'    => $lag_grp,
            'admin_status' => 'up',
            'link_status'  => $status,
            'is_trunk'     => 1,
        ]);
        if (!isset($ports[$lag_grp])) {
            $ports[$lag_grp] = ['port_name' => $lag_grp, 'mac_count' => 0, 'vlans' => [],
                                'port_type' => 'lag', 'is_trunk' => 1,
                                'admin_status' => 'up', 'link_status' => $status, '_seen_macs' => []];
        } elseif ($status === 'up') {
            $ports[$lag_grp]['link_status'] = 'up';
        }
        return;
    }

    // Normal port — real 2930F "show interfaces status" format:
    //   "  N  [Name]  Up|Down  Config-mode  Speed  Type  Tagged  Untagged"
    //   "  25         Down                                No      1"  ← Speed/Type blank (SFP empty)
    //   "  27         Up       Auto         10GigFD  10GbE-GEN  305  1"
    //
    //   Tagged col: 'No' = access only, 'multi' = many tagged VLANs (trunk), numeric = one tagged VLAN
    //   We need at minimum: port, status, and then eventually Tagged + Untagged
    //
    // Strategy: after port# and optional name, grab Up|Down, then take last two whitespace-separated tokens
    // as Tagged and Untagged (they're always at the end of the line).
    if (!preg_match('/^\s*(\d+)\s+\S*\s+(Up|Down)\s+(.*)/i', $line, $m)) return;

    $pname   = $m[1];
    $status  = strtolower($m[2]);
    $rest    = preg_split('/\s+/', trim($m[3]));

    // Last two tokens are Tagged and Untagged; everything before is Config/Speed/Type (ignored)
    $untagged = array_pop($rest);   // e.g. '1', '304', '302'
    $tagged   = array_pop($rest);   // e.g. 'No', 'multi', '2', '305'
    $tagged   = $tagged ?? 'No';

    if (!isset($ports[$pname])) {
        $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
    }
    $ports[$pname]['admin_status'] = 'up';
    $ports[$pname]['link_status']  = $status;

    // Determine trunk vs access from Tagged column
    // IMPORTANT: HP/Aruba 'show interfaces status' Tagged column:
    //   'No'     = pure access port, no tagged VLANs
    //   'multi'  = many tagged VLANs = TRUE TRUNK (uplink/downlink)
    //   numeric  = exactly ONE tagged VLAN (voice/auxiliary VLAN) — still an ACCESS port
    //              carrying data on untagged VLAN + voice on the one tagged VLAN.
    //              Do NOT mark these as trunk — the MAC threshold will catch real
    //              downstream switches regardless.
    $tagged_lc = strtolower($tagged ?? '');
    if ($tagged_lc === 'multi') {
        $ports[$pname]['port_type'] = 'trunk';
        $ports[$pname]['is_trunk']  = 1;
    } else {
        $ports[$pname]['port_type'] = 'access';
        $ports[$pname]['is_trunk']  = 0;
        if (is_numeric($untagged) && (int)$untagged > 0) {
            $vname = 'vlan' . $untagged;
            if (!in_array($vname, $ports[$pname]['vlans'])) {
                $ports[$pname]['vlans'][] = $vname;
            }
        }
    }

}

/* ─────────────────────────────────────────────────────────────
   LLDP PARSER
   Format: LocalPort | ChassisId  PortId  PortDescr  SysName
     20  | Outside...  GigabitEthernet0/5   cisco WS-...
     27  | b8d4e7-...  28  28  ServSW-Rack11
─────────────────────────────────────────────────────────────── */
function _pc_parse_lldp(string $raw, array &$ports): array {
    $lldp = [];

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!preg_match('/^(\d+|Trk\S+)\s*\|\s*(\S+)\s+(\S+)\s+(\S*)\s*(.*)/i', $line, $m)) continue;

        $local_port  = $m[1];
        $chassis_id  = $m[2];
        $remote_port = $m[3];
        $sys_name    = trim(preg_replace('/\s{2,}.*/', '', $m[5]) ?: $m[4]);

        if (strtolower($chassis_id) === 'chassisid') continue;

        $lldp[] = [
            'local_port'  => $local_port,
            'chassis_id'  => $chassis_id,
            'remote_port' => $remote_port,
            'system_name' => $sys_name,
        ];

        if (isset($ports[$local_port])) {
            $ports[$local_port]['lldp_neighbor'] = $sys_name ?: $chassis_id;
        }
    }

    return $lldp;
}

function _pc_finalize_ports(array &$ports): void {
    $threshold = defined('NAC_TRUNK_THRESHOLD') ? NAC_TRUNK_THRESHOLD : 8;
    foreach ($ports as $pname => &$pd) {
        // Ensure Trk# entries are always LAG
        if (preg_match('/^trk\d+$/i', $pname)) {
            $pd['port_type']    = 'lag';
            $pd['is_trunk']     = 1;
            $pd['admin_status'] = $pd['admin_status'] ?? 'up';
            $pd['link_status']  = $pd['link_status']  ?? 'down';
        }
        // MAC threshold auto-trunk
        if (empty($pd['is_trunk']) && ($pd['mac_count'] ?? 0) >= $threshold) {
            $pd['is_trunk']  = 1;
            $pd['port_type'] = 'trunk';
        }
        unset($pd['_seen_macs']);
        // Flatten vlans array to string
        if (empty($pd['vlan_names']) && !empty($pd['vlans'])) {
            $pd['vlan_names'] = implode(',', $pd['vlans']);
        }
    }
    unset($pd);
}

function _pc_tag_via_trunk(array &$macs, array $ports): void {
    $trunk_names = [];
    foreach ($ports as $pn => $pd) {
        if (!empty($pd['is_trunk']) && in_array($pd['port_type'] ?? '', ['trunk', 'lag'])) {
            $trunk_names[$pn] = true;
        }
    }
    foreach ($macs as &$m) {
        if (isset($trunk_names[$m['port']])) $m['via_trunk'] = 1;
    }
    unset($m);
}

/* ─── Helper ─── */
function _pc_mac_to_std(string $mac): string {
    // 000c29-010bd5 → 00:0C:29:01:0B:D5
    $hex   = str_replace('-', '', $mac);
    $parts = str_split($hex, 2);
    return strtoupper(implode(':', $parts));
}
