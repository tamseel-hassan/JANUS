<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * soc/ipam_lib.php  –  Shared helpers for all IPAM scripts
 *
 * Include this file at the top of ipam_scan.php, ipam_cron.php, ipam_ajax.php
 * Never include it from HTML pages (no session logic here).
 */

/* ════════════════════════════════════════════════════════════════
   DB
════════════════════════════════════════════════════════════════ */
function ipam_db(): mysqli {
    // Use MYSQLI_REPORT_OFF to avoid exceptions from unbuffered-result issues
    // we handle errors manually
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        throw new RuntimeException('DB connect failed: ' . $db->connect_error);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

/* ════════════════════════════════════════════════════════════════
   INTERFACE DETECTION
   Uses `ip route get <first_ip_in_subnet>` to find which NIC
   the kernel would use – no hardcoded interface names.
════════════════════════════════════════════════════════════════ */
function get_interface_for_cidr(string $cidr): string {
    // Auto-detect via 'ip route get' only - no DB connection to avoid sync issues
    [$net] = explode('/', $cidr);
    $parts    = explode('.', $net);
    $parts[3] = (int)$parts[3] + 1;
    $probe    = implode('.', $parts);

    $out = @shell_exec('ip route get ' . escapeshellarg($probe) . ' 2>/dev/null');
    if ($out && preg_match('/\bdev\s+(\S+)/', $out, $m)) {
        return $m[1];
    }
    // Fallback: check known subnet ranges
    $nl = ip2long($net);
    if ($nl !== false) {
        if (($nl & 0xFFFFFF00) === ip2long('172.17.128.0')) return 'enp0s3';
        if (($nl & 0xFF000000) === ip2long('10.0.0.0'))     return 'enp0s3';
        if (($nl & 0xFFFF0000) === ip2long('192.168.0.0'))  return 'enp0s3';
        if (($nl & 0xFFFF0000) === ip2long('172.17.0.0'))   return 'enp0s3';
    }
    return 'enp0s3';
}

/* ════════════════════════════════════════════════════════════════
   SUBNET BOUNDS
════════════════════════════════════════════════════════════════ */
function subnet_bounds(string $cidr): array {
    [$net, $prefix] = explode('/', $cidr);
    $prefix    = (int)$prefix;
    $net_long  = ip2long($net);
    $broadcast = $net_long | ~(~0 << (32 - $prefix));
    return [$net_long, $broadcast, $prefix];
}

function ip_in_subnet(string $ip, int $net_long, int $broadcast): bool {
    $l = ip2long($ip);
    return $l !== false && $l >= $net_long && $l <= $broadcast;
}

/* ════════════════════════════════════════════════════════════════
   LOG INSERT  (safe – checks connection health first)
════════════════════════════════════════════════════════════════ */
function ipam_log_event(mysqli $db, string $ip, string $old_mac, string $new_mac, string $note): void {
    if ($db->connect_errno) return;
    // Flush any pending result set that might cause "commands out of sync"
    while ($db->more_results()) { $db->next_result(); }

    $s = $db->prepare(
        'INSERT INTO janus_ipam_log (ip, old_mac, new_mac, note, acknowledged) VALUES (?,?,?,?,0)'
    );
    if (!$s) return;
    $s->bind_param('ssss', $ip, $old_mac, $new_mac, $note);
    $s->execute();
    $s->close();
}

/* ════════════════════════════════════════════════════════════════
   ARP-SCAN  –  returns [ ip => mac, … ]
   Handles interface auto-detection.
════════════════════════════════════════════════════════════════ */
function run_arp_scan(string $cidr): array {
    [$net_long, $broadcast] = subnet_bounds($cidr);
    $iface   = get_interface_for_cidr($cidr);
    $cmd     = 'sudo /usr/sbin/arp-scan --interface=' . escapeshellarg($iface)
             . ' --quiet --plain ' . escapeshellarg($cidr) . ' 2>&1';
    $output  = shell_exec($cmd);
    $found   = [];

    if (!$output) return $found;

    foreach (explode("\n", trim($output)) as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (!preg_match('/(\d{1,3}(?:\.\d{1,3}){3})[\s\t]+([0-9a-fA-F:]{17})/', $line, $m)) continue;
        $ip  = $m[1];
        $mac = strtoupper($m[2]);
        if (!ip_in_subnet($ip, $net_long, $broadcast)) continue;
        $found[$ip] = $mac;
    }
    return $found;
}

/* ════════════════════════════════════════════════════════════════
   PING CHECK  –  single host
   Returns ['alive'=>bool, 'rtt'=>float|null]
════════════════════════════════════════════════════════════════ */
function ping_host(string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['alive'=>false,'rtt'=>null];
    $esc  = escapeshellarg($ip);
    $out  = [];
    exec("ping -c 2 -W 1 -q $esc 2>/dev/null", $out, $ret);
    $rtt = null;
    if ($ret === 0) {
        foreach (array_reverse($out) as $l) {
            if (preg_match('/rtt.*=\s*[\d.]+\/([\d.]+)\//', $l, $m)) {
                $rtt = round((float)$m[1], 1);
                break;
            }
        }
    }
    return ['alive' => $ret === 0, 'rtt' => $rtt];
}

/* ════════════════════════════════════════════════════════════════
   PROCESS DISCOVERED DEVICES
   Takes $db, $found_ips (ip=>mac), subnet bounds.
   Returns array of change events.
════════════════════════════════════════════════════════════════ */
function process_discovered(mysqli $db, array $found_ips, int $net_long, int $broadcast): array {
    $changes = [];

    foreach ($found_ips as $ip => $mac) {
        // Skip network address (.0) and broadcast (.255) — never real hosts
        $last_octet = (int)explode('.', $ip)[3];
        if ($last_octet === 0 || $last_octet === 255) continue;

        // Fetch current state – use get_result() to avoid bind_result sync issues
        $s = $db->prepare('SELECT mac, status FROM janus_ipam WHERE ip = ?');
        if (!$s) continue;
        $s->bind_param('s', $ip);
        $s->execute();
        $res    = $s->get_result();
        $cur    = $res->fetch_assoc();
        $exists = (bool)$cur;
        $s->close();

        $cur_mac    = $cur['mac']    ?? null;
        $cur_status = $cur['status'] ?? null;

        // MAC spoofing check (only if we have a real MAC)
        if ($mac !== '') {
            // Skip spoofing check if current IP is a network/broadcast address
            $last_octet = (int)explode('.', $ip)[3];
            if ($last_octet !== 0 && $last_octet !== 255) {
                $sp = $db->prepare(
                    "SELECT ip FROM janus_ipam
                      WHERE mac = ? AND ip != ? AND status = 'active'
                        AND SUBSTRING_INDEX(ip,'.',-1) NOT IN ('0','255')
                        AND last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                      LIMIT 1"
                );
                if ($sp) {
                    $sp->bind_param('ss', $mac, $ip);
                    $sp->execute();
                    $sprow = $sp->get_result()->fetch_assoc();
                    $sp->close();
                    if ($sprow) {
                        $note = "MAC spoofing: $mac seen on $ip AND {$sprow['ip']} simultaneously";
                        ipam_log_event($db, $ip, $mac, $mac, $note);
                        $changes[] = ['type'=>'spoofing','ip'=>$ip,'mac'=>$mac,'note'=>$note];
                    }
                }
            }
        }

        if ($exists) {
            // Update
            if ($mac !== '') {
                $u = $db->prepare("UPDATE janus_ipam SET mac=?,status='active',last_seen=NOW() WHERE ip=?");
                if ($u) { $u->bind_param('ss',$mac,$ip); $u->execute(); $u->close(); }
            } else {
                $u = $db->prepare("UPDATE janus_ipam SET status='active',last_seen=NOW() WHERE ip=?");
                if ($u) { $u->bind_param('s',$ip); $u->execute(); $u->close(); }
            }
            // Log MAC change
            if ($mac !== '' && $cur_mac && $cur_mac !== $mac) {
                $note = "MAC changed: $cur_mac → $mac";
                ipam_log_event($db, $ip, $cur_mac, $mac, $note);
                $changes[] = ['type'=>'mac_change','ip'=>$ip,'mac'=>$mac,'old_mac'=>$cur_mac,'note'=>$note];
            }
            // Log came online
            if ($cur_status === 'inactive') {
                ipam_log_event($db, $ip, $cur_mac??'', $mac, 'Device came back online');
// Auto-acknowledge "online" events so they don't trigger alerts
    $db->query("UPDATE janus_ipam_log SET acknowledged=1 WHERE ip='".$db->real_escape_string($ip)."' AND note='Device came back online' ORDER BY changed_at DESC LIMIT 1");
                $changes[] = ['type'=>'online','ip'=>$ip,'mac'=>$mac,'note'=>'Came back online'];
            }
        } else {
            // New device
            $ins = $db->prepare(
                "INSERT INTO janus_ipam (ip,mac,status,first_seen,last_seen) VALUES (?,?,'active',NOW(),NOW())"
            );
            if ($ins) { $ins->bind_param('ss',$ip,$mac); $ins->execute(); $ins->close(); }
            ipam_log_event($db, $ip, '', $mac, 'New device discovered');
            $changes[] = ['type'=>'new','ip'=>$ip,'mac'=>$mac,'note'=>'New device discovered'];
        }
    }

    // Mark offline (scoped to subnet)
    $all = $db->prepare("SELECT ip, mac FROM janus_ipam WHERE status='active'");
    if ($all) {
        $all->execute();
        $rows = [];
        $result = $all->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $all->close();
        foreach ($rows as $row) {
            if (!ip_in_subnet($row['ip'], $net_long, $broadcast)) continue;
            if (!isset($found_ips[$row['ip']])) {
                $upd = $db->prepare("UPDATE janus_ipam SET status='inactive' WHERE ip=?");
                if ($upd) { $upd->bind_param('s',$row['ip']); $upd->execute(); $upd->close(); }
                ipam_log_event($db, $row['ip'], $row['mac']??'', $row['mac']??'', 'Device went offline');
// Auto-acknowledge "offline" events
    $db->query("UPDATE janus_ipam_log SET acknowledged=1 WHERE ip='".$db->real_escape_string($row['ip'])."' AND note='Device went offline' ORDER BY changed_at DESC LIMIT 1");
                $changes[] = ['type'=>'offline','ip'=>$row['ip'],'mac'=>$row['mac']??'','note'=>'Went offline'];
            }
        }
    }

    return $changes;
}
