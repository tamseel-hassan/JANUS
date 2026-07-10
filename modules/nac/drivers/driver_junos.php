<?php
/**
 * drivers/driver_junos.php
 * Juniper JunOS driver — EX2200 / EX3300 / EX4300 (all firmware generations)
 *
 * Handles:
 *   - Classic JunOS 13.x  (EX2200/EX3300 fixed format)
 *   - JunOS 15–17         (EX4300 non-VC, flat table)
 *   - JunOS 18+ VC        (EX4300 Virtual Chassis — {master:0} on every line,
 *                          prompt suffix "-re0" or "-re0>")
 *
 * Commands issued (from nac_platforms table, platform_key = 'junos'):
 *   cmd_mac_table  : show ethernet-switching table
 *   cmd_interfaces : show interfaces terse
 *   cmd_lldp       : show lldp neighbors
 */

/* ═══════════════════════════════════════════════════════════════
   PLATFORM METADATA
   Returned by driver_junos_meta() so nac_lib.php can upsert into
   nac_platforms automatically if the row is missing.
═══════════════════════════════════════════════════════════════ */
function driver_junos_meta(): array {
    return [
        'platform_key'   => 'junos',
        'vendor'         => 'juniper',
        'display_name'   => 'Juniper JunOS (EX2200/EX3300/EX4300)',
        'cmd_mac_table'  => 'show ethernet-switching table',
        'cmd_interfaces' => 'show interfaces terse',
        'cmd_lldp'       => 'show lldp neighbors',
        'cmd_vlans'      => 'show vlans',
        'disable_pager'  => 'set cli screen-length 0',
        'prompt_pattern' => '[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>]',
    ];
}

/* ═══════════════════════════════════════════════════════════════
   MAIN PARSER ENTRY POINT
   Called by nac_lib.php after SSH output is collected.
   Returns ['macs'=>[], 'ports'=>[], 'lldp'=>[], 'vlan_ports'=>[]]
   vlan_ports: ['ge-0/0/7' => ['vlan' => 'internet-green', 'tagged' => false], ...]
═══════════════════════════════════════════════════════════════ */
function driver_junos_parse(string $mac_out, string $ifc_out, string $lldp_out, string $vlans_out = ''): array {
    $macs  = _junos_parse_mac_table($mac_out);
    $ports = _junos_parse_interfaces($ifc_out, $macs);
    $lldp  = _junos_parse_lldp($lldp_out, $ports);

    // Parse show vlans — enriches ports with their configured VLAN membership
    // even when no MACs are currently active on the port
    $vlan_ports = [];
    if ($vlans_out !== '') {
        $vlan_ports = _junos_parse_vlans($vlans_out, $ports);
    }

    // Final trunk / LAG classification
    _junos_classify_trunks($ports);

    // Tag MACs that arrive via a trunk port
    _junos_tag_via_trunk($macs, $ports);

    return [
        'macs'       => array_values($macs),
        'ports'      => array_values($ports),
        'lldp'       => $lldp,
        'vlan_ports' => $vlan_ports,
    ];
}

/* ─────────────────────────────────────────────────────────────
   INTERNAL: MAC TABLE PARSER
   Handles three output variants:

   Variant A – JunOS 13.x grouped per-VLAN blocks (EX2200/EX3300):
     Ethernet switching table : 50 entries, 50 learned
     Routing instance : default-switch
         Vlan         MAC                  MAC    Age   Logical
         name         address              flags         interface
         Prod         00:0c:29:01:0b:d5    D       -    ge-0/0/25.0

   Variant B – JunOS 15–17 flat table (EX4300 non-VC):
     VLAN  MAC address        Flags    Logical interface
     vlan5 00:50:56:a6:85:9d  D        ge-0/0/5.0

   Variant C – JunOS 18+ VC (EX4300 Virtual Chassis) — identical data
     but every line is prefixed with the RE state tag:
     {master:0}        ← standalone flush line (skip)
     Prod   00:0c:…    D   -   ge-0/0/25.0    ← data line (normal)
     Sometimes: "       Prod   00:0c:…" with leading RE noise on same line:
     {master:0}Prod   00:0c:… (should not happen but handled defensively)
─────────────────────────────────────────────────────────────── */
function _junos_parse_mac_table(string $raw): array {
    $macs = [];

    $skip_keywords = [
        'ethernet switching', 'routing instance', 'vlan name', 'mac address',
        'mac flags', 'logical interface', '---', 'entries', 'learned',
        'static mac', 'persistent', 'statistics', 'non configured',
        'remote pe', 'flags (s', 'flags (d', 'total mac', 'routing-instance',
    ];

    foreach (explode("\n", $raw) as $line) {
        // Strip Virtual-Chassis RE state prefix: {master:0}, {backup:1}, etc.
        // These appear either as standalone lines OR prepended to data lines.
        $line = preg_replace('/^\s*\{(?:master|backup|primary|secondary)(?::\d+)?\}\s*/i', '', $line);
        $line = trim($line);
        if (!$line) continue;

        // Skip header / summary lines
        $lower = strtolower($line);
        $skip  = false;
        foreach ($skip_keywords as $kw) {
            if (str_contains($lower, $kw)) { $skip = true; break; }
        }
        if ($skip) continue;

        // Universal regex — matches both colon-separated and dash-grouped MACs,
        // with optional age column (Variant A has it, Variant B does not):
        //   <vlan>  <mac17>  <flags>  [age]  <interface>
        // We make <age> optional by using \S+\s+ zero-or-more times before iface.
        if (!preg_match(
            '/^(\S+)\s+([0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2})\s+(\S+)\s+(?:\S+\s+)?(\S+)/i',
            $line, $m
        )) continue;

        $vlan      = $m[1];
        $mac       = strtoupper(str_replace('-', ':', $m[2]));
        // $flags  = $m[3];
        $raw_iface = $m[4];

        // Strip .0 unit suffix from ge/ae/xe but not irb
        $iface = preg_match('/^irb\./i', $raw_iface)
            ? $raw_iface
            : preg_replace('/\.0$/', '', $raw_iface);

        // Skip L3/flood pseudo-interfaces
        $iface_lc = strtolower($iface);
        if (in_array($iface_lc, ['router', 'all-members', 'flood', 'unknown', ''])) continue;
        if (str_starts_with($iface_lc, 'irb')) continue;

        // Skip literal header tokens that slip through
        if (in_array(strtolower($vlan), ['vlan', 'vlan_name', 'name', 'vlan-name'])) continue;

        if (!isset($macs[$mac])) {
            $macs[$mac] = ['mac' => $mac, 'vlan' => $vlan, 'port' => $iface, 'type' => 'D'];
        } else {
            // JunOS 13.x groups the MAC table per VLAN block, so the same MAC can
            // appear under multiple VLAN names (e.g. first under "default", then
            // under "Prod" for the same port). We prefer the most specific name:
            // any non-default named VLAN beats "default" / "vlan1" / "vlan<N>".
            // This prevents the VLAN name from flapping between polls based purely
            // on which block the switch prints first.
            $existing_vlan = $macs[$mac]['vlan'];
            if (_junos_vlan_is_more_specific($vlan, $existing_vlan)) {
                $macs[$mac]['vlan'] = $vlan;
                // Also update port if this entry is on the same or a more specific iface
                if ($iface === $macs[$mac]['port'] || !str_starts_with($macs[$mac]['port'], 'ge-')) {
                    $macs[$mac]['port'] = $iface;
                }
            }
        }
    }

    return $macs;
}

/**
 * Returns true if $candidate VLAN name is "more specific" than $existing.
 * Specificity rules (highest wins):
 *   1. Named VLAN that is NOT default/vlan1/vlanN  → most specific
 *   2. Named VLAN that IS "default" or "vlan1"     → least specific
 *   3. Generic "vlan<N>" token                     → least specific
 *
 * This ensures that if the same MAC appears under both "default" and "Prod"
 * in different VLAN blocks of the MAC table, we keep "Prod".
 */
function _junos_vlan_is_more_specific(string $candidate, string $existing): bool {
    $c_score = _junos_vlan_specificity($candidate);
    $e_score = _junos_vlan_specificity($existing);
    return $c_score > $e_score;
}

function _junos_vlan_specificity(string $vlan): int {
    $lc = strtolower(trim($vlan));
    // Generic numeric-only VLAN token (e.g. "vlan1", "vlan305") — low specificity
    if (preg_match('/^vlan\d+$/i', $lc)) return 1;
    // The "default" VLAN — lowest specificity
    if ($lc === 'default') return 0;
    // Any other named VLAN (e.g. "Prod", "internet-red", "technical") — high specificity
    return 2;
}

/* ─────────────────────────────────────────────────────────────
   INTERNAL: INTERFACES TERSE PARSER
   Builds port array from "show interfaces terse" output.

   Line formats:
     ge-0/0/0           up   down              (physical, no proto)
     ge-0/0/0.0         up   down  eth-switch  (sub-unit, has proto)
     ge-0/0/22.0        up   up    aenet --> ae4.0  (LAG member)
     ae4.0              up   up    aenet             (aggregate)
     irb.305            up   up    inet  172.17.130.76/24
   VC-specific extra lines:
     ge-1/0/0           up   up              (member RE ports — still valid)
─────────────────────────────────────────────────────────────── */
function _junos_parse_interfaces(string $raw, array &$macs): array {
    $ports = [];

    // Pre-populate ports from MAC table so every MAC has a port entry
    foreach ($macs as $m) {
        $iface = $m['port'];
        if (!isset($ports[$iface])) {
            $ports[$iface] = ['port_name' => $iface, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
        }
        if (!isset($ports[$iface]['_seen_macs'][$m['mac']])) {
            $ports[$iface]['_seen_macs'][$m['mac']] = true;
            $ports[$iface]['mac_count']++;
        }
        if (!in_array($m['vlan'], $ports[$iface]['vlans'])) {
            $ports[$iface]['vlans'][] = $m['vlan'];
        }
    }

    foreach (explode("\n", $raw) as $line) {
        // Strip VC RE prefix
        $line = preg_replace('/^\s*\{(?:master|backup|primary|secondary)(?::\d+)?\}\s*/i', '', $line);
        $line = trim($line);

        // Match ge/xe/et/fe (incl. VC slot ge-1/0/0), ae, irb with optional .N sub-unit
        if (!preg_match(
            '/^((?:ge|xe|et|fe)-\d+\/\d+\/\d+(?:\.\d+)?|ae\d+(?:\.\d+)?|irb\.\d+)\s+(up|down)\s+(up|down)\s*(.*)/i',
            $line, $m
        )) continue;

        $pname_raw = $m[1];
        $admin     = strtolower($m[2]);
        $link      = strtolower($m[3]);
        $proto     = trim($m[4]);

        // Normalise name: strip .0 except from irb
        $pname = preg_match('/^irb\./i', $pname_raw)
            ? $pname_raw
            : preg_replace('/\.0$/', '', $pname_raw);

        // Determine type
        $ptype = 'access';
        if (preg_match('/^ae\d+$/i', $pname))  $ptype = 'lag';
        if (preg_match('/^irb\./i',  $pname))  $ptype = 'irb';
        if (str_contains($proto, 'aenet'))       $ptype = 'lag-member';

        if (!isset($ports[$pname])) {
            $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
        }
        $ports[$pname]['admin_status'] = $admin;
        $ports[$pname]['link_status']  = $link;

        // Sub-unit line (has proto) wins over bare physical line for type/proto
        if ($proto !== '') {
            $ports[$pname]['port_type'] = $ptype;
            $ports[$pname]['proto']     = $proto;
        } elseif (!isset($ports[$pname]['port_type'])) {
            $ports[$pname]['port_type'] = $ptype;
        }
    }

    return $ports;
}

/* ─────────────────────────────────────────────────────────────
   INTERNAL: LLDP NEIGHBORS PARSER
   Format: Local-Iface  Parent-Iface  Chassis-Id  Port-Info  System-Name
     ge-0/0/10  -  04:d5:90:99:e7:23  (blank)  Master-FW
     ge-2/0/44  -  00:31:46:39:7d:00  ge-0/0/23.0  HCI-SW2
─────────────────────────────────────────────────────────────── */
function _junos_parse_lldp(string $raw, array &$ports): array {
    $lldp = [];

    foreach (explode("\n", $raw) as $line) {
        $line = preg_replace('/^\s*\{(?:master|backup|primary|secondary)(?::\d+)?\}\s*/i', '', $line);
        $line = trim($line);

        // ge-X/X/X or ae# plus optional VC slot (ge-1/0/X)
        if (!preg_match('/^((?:ge|xe|et)-[\d\/]+|ae\d+)\s+(\S+|-)\s+([0-9a-f:]{17})\s*(.*)/i', $line, $m))
            continue;

        $local_port = $m[1];
        $parent_ifc = $m[2];
        $chassis_id = strtoupper($m[3]);
        $rest       = trim($m[4]);

        $parts       = preg_split('/\s{2,}/', $rest, 2);
        $remote_port = trim($parts[0] ?? '');
        $sys_name    = trim($parts[1] ?? '');
        if ($sys_name === '' && !preg_match('/^[a-z]{2}[\d\/\-]+(\.\d+)?$/i', $remote_port)) {
            $sys_name    = $remote_port;
            $remote_port = '';
        }

        $lldp[] = [
            'local_port'  => $local_port,
            'parent_ifc'  => $parent_ifc,
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

/* ─────────────────────────────────────────────────────────────
   INTERNAL: TRUNK / LAG CLASSIFICATION
   Rules:
   1. IRB interfaces → always 'irb', never trunk
   2. ae# (LAG aggregate) → 'lag', is_trunk=1
   3. ge/xe/et with aenet proto → 'lag-member', is_trunk=1
   4. Any access port with ≥ NAC_TRUNK_THRESHOLD MACs → 'trunk', is_trunk=1
   5. All others → 'access', is_trunk=0
─────────────────────────────────────────────────────────────── */
function _junos_classify_trunks(array &$ports): void {
    $threshold = defined('NAC_TRUNK_THRESHOLD') ? NAC_TRUNK_THRESHOLD : 8;

    foreach ($ports as $pname => &$pd) {
        $ptype = $pd['port_type'] ?? 'access';
        $mc    = $pd['mac_count'] ?? 0;

        unset($pd['_seen_macs']);

        if ($ptype === 'irb' || str_starts_with($pname, 'irb')) {
            $pd['port_type'] = 'irb';
            $pd['is_trunk']  = 0;
            continue;
        }
        if ($ptype === 'lag') {
            $pd['is_trunk'] = 1;
            continue;
        }
        if ($ptype === 'lag-member') {
            $pd['is_trunk'] = 1;
            continue;
        }
        if ($mc >= $threshold) {
            $pd['port_type'] = 'trunk';
            $pd['is_trunk']  = 1;
            continue;
        }
        $pd['port_type'] = 'access';
        $pd['is_trunk']  = 0;
    }
    unset($pd);
}

/* ─────────────────────────────────────────────────────────────
   INTERNAL: TAG VIA-TRUNK MACs
   Tag any MAC whose port has is_trunk=1 (covers trunk, lag,
   and lag-member port types — not just explicit 'trunk' type).
─────────────────────────────────────────────────────────────── */
function _junos_tag_via_trunk(array &$macs, array $ports): void {
    $trunk_ports = [];
    foreach ($ports as $pn => $pd) {
        if (!empty($pd['is_trunk'])) {
            $trunk_ports[$pn] = true;
        }
    }
    foreach ($macs as &$m) {
        if (isset($trunk_ports[$m['port']])) {
            $m['via_trunk'] = 1;
        }
    }
    unset($m);
}

/* ─────────────────────────────────────────────────────────────
   INTERNAL: SHOW VLANS PARSER
   Parses "show vlans" output to build a port → VLAN membership map.
   This lets us detect VLAN config changes even on ports with no
   active MACs (e.g. a down port whose VLAN was changed in config).

   JunOS 12.x / 13.x format (EX2200/EX4300):
     Name           Tag     Interfaces
     Vlan305        305
                            ge-0/0/23.0*
     default        1
                            ge-0/0/3.0, ge-0/0/4.0, ... ge-0/0/23.0*
     internet-red   222
                            ge-0/0/1.0, ge-0/0/2.0*, ge-0/0/23.0*

   Rules:
     - Interface with trailing * = tagged (trunk member)
     - Interface without * = untagged (access member)
     - A port on multiple VLANs (one untagged + one or more tagged) is a trunk
     - We record the *untagged* VLAN as the port's access VLAN for comparison

   Returns: ['ge-0/0/7' => ['vlan'=>'internet-green','tagged'=>false,'all_vlans'=>['internet-green']], ...]
─────────────────────────────────────────────────────────────── */
function _junos_parse_vlans(string $raw, array &$ports): array {
    $vlan_ports   = [];
    $current_vlan = null;

    // Words that must never be treated as VLAN names — CLI noise that can
    // appear in the SSH output around the show vlans output.
    static $noise_words = [
        'exit', 'quit', 'commit', 'rollback', 'configure', 'run',
        'show', 'set', 'delete', 'deactivate', 'activate', 'request',
        'error', 'warning', 'abort', 'yes', 'no', 'interfaces',
        'routing', 'instance', 'switch', 'complete', 'succeeded',
    ];

    foreach (explode("\n", $raw) as $line) {
        // Strip VC RE prefix
        $line = preg_replace('/^\s*\{(?:master|backup|primary|secondary)(?::\d+)?\}\s*/i', '', $line);

        $lt = trim($line);
        if (!$lt) continue;

        // Skip header and separator lines
        if (preg_match('/^Name\s+Tag/i', $lt)) continue;
        if (preg_match('/^-{3,}/', $lt))       continue;

        // Skip prompt lines: "user@host>" or "host#"
        if (preg_match('/[#>]\s*$/', $lt))     continue;

        // ── VLAN name line detection ──────────────────────────────────────────
        // A valid VLAN line MUST have a numeric tag ID as the second token.
        // Format: "<name>   <tag>"  e.g. "default   1" or "internet-red   222"
        // We require the tag to be present — lines without a tag are ambiguous
        // and too risky to accept (they match noise words like "exit").
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_\-\.]*)\s+(\d+)\s*$/', $lt, $m)) {
            $vlan_name = $m[1];
            // Reject noise words even if they somehow have a numeric suffix
            if (in_array(strtolower($vlan_name), $noise_words)) continue;
            $current_vlan = $vlan_name;
            continue;
        }

        // ── Interface list line ───────────────────────────────────────────────
        // Must have leading whitespace (indented under the VLAN block)
        // and $current_vlan must already be set.
        if ($current_vlan === null)          continue;
        if (!preg_match('/^\s+/', $line))    continue;

        // Extract interface tokens — format: "ge-0/0/3.0, ge-0/0/4.0, ge-0/0/23.0*"
        $tokens = preg_split('/[\s,]+/', $lt);
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if (!$tok) continue;

            $is_tagged = str_ends_with($tok, '*');
            $iface_raw = rtrim($tok, '*');

            // Normalise: strip .0 suffix (keep irb.N as-is)
            $iface = preg_match('/^irb\./i', $iface_raw)
                ? $iface_raw
                : preg_replace('/\.0$/', '', $iface_raw);

            // Must look like a real interface name — reject anything else
            if (!preg_match('/^(ge|xe|et|fe|ae)-\d+\/\d+\/\d+$/i', $iface)
                && !preg_match('/^irb\.\d+$/i', $iface)) {
                continue;
            }

            if (!isset($vlan_ports[$iface])) {
                $vlan_ports[$iface] = [
                    'vlan'      => $current_vlan,
                    'tagged'    => $is_tagged,
                    'all_vlans' => [$current_vlan],
                ];
            } else {
                $vlan_ports[$iface]['all_vlans'][] = $current_vlan;
                // Prefer untagged VLAN as the access VLAN
                if (!$is_tagged && $vlan_ports[$iface]['tagged']) {
                    $vlan_ports[$iface]['vlan']   = $current_vlan;
                    $vlan_ports[$iface]['tagged'] = false;
                }
                if (count($vlan_ports[$iface]['all_vlans']) > 1) {
                    $vlan_ports[$iface]['is_trunk'] = true;
                }
            }

            // Enrich ports array with configured VLAN for UI display
            if (isset($ports[$iface]) && !$is_tagged) {
                $ports[$iface]['configured_vlan'] = $current_vlan;
                if (count($vlan_ports[$iface]['all_vlans'] ?? []) > 1) {
                    $ports[$iface]['is_trunk']  = 1;
                    $ports[$iface]['port_type'] = 'trunk';
                }
            }
        }
    }

    return $vlan_ports;
}

/* ═══════════════════════════════════════════════════════════════
   EXPECT SCRIPT CUSTOMISATION FOR JUNOS VC
   Returns the prompt regex for the SSH expect engine in nac_lib.php.
   VC switches show "{master:0}" on the line BEFORE the prompt —
   we just need the prompt pattern to also match the -re0 suffix.
═══════════════════════════════════════════════════════════════ */
function driver_junos_prompt_regex(): string {
    // Matches:
    //   user@host>
    //   user@host-re0>
    //   host#
    //   host-re0#
    return '[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>]\s';
}
