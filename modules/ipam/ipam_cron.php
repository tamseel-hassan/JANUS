<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * soc/ipam_cron.php  –  Scheduled network discovery
 *
 * Fixes vs previous version:
 *  - $db connection passed explicitly to insertLog(), never closed early
 *  - Ping fallback for devices with no MAC address
 *  - Scans ALL configured subnets (detected from DB), not just one hardcoded CIDR
 *  - Proper error logging
 *
 * Crontab (every 5 minutes):
 *   *\/5 * * * * /usr/bin/php /var/www/janus/soc/ipam_cron.php >> /var/log/janus_ipam.log 2>&1
 */

define('LOG_FILE',       '/var/log/janus_ipam_cron.log');
define('SCAN_INTERFACE', 'ens160');

/* ── Open DB ── */
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    logMsg('FATAL: DB connection failed – ' . $db->connect_error);
    exit(1);
}

logMsg('──── Cron scan started ────');

/* ── Detect subnets from registered subnets ── */
$subnets = [];
$res = $db->query("SELECT cidr FROM ipam_subnets WHERE scan_enabled = 1 ORDER BY cidr");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $subnets[] = $row['cidr'];
    }
    $res->free();
}

// Fallback if ipam_subnets is empty, try deriving from existing IP data
if (empty($subnets)) {
    $res = $db->query(
        "SELECT DISTINCT
            CONCAT(
                SUBSTRING_INDEX(ip,'.',1),'.',
                SUBSTRING_INDEX(SUBSTRING_INDEX(ip,'.',2),'.',-1),'.',
                SUBSTRING_INDEX(SUBSTRING_INDEX(ip,'.',3),'.',-1),'.0/24'
            ) AS cidr
         FROM shikra_ipam
         ORDER BY cidr"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $subnets[] = $row['cidr'];
        }
        $res->free();
    }
}

// Final fallback if DB is completely empty
if (empty($subnets)) {
    $subnets = ['10.11.1.0/24'];
}

logMsg('Subnets to scan: ' . implode(', ', $subnets));

/* ── Process each subnet ── */
foreach ($subnets as $cidr) {
    logMsg("Scanning $cidr …");
    scanSubnet($db, $cidr);
}

/* ── Done – close DB ── */
$db->close();
logMsg('──── Cron scan complete ────' . PHP_EOL);
exit(0);


/* ════════════════════════════════════════════════════════
   SCAN ONE SUBNET
════════════════════════════════════════════════════════ */
function scanSubnet(mysqli $db, string $cidr): void {
    [$net_addr, $prefix] = explode('/', $cidr);
    $prefix    = (int)$prefix;
    $net_long  = ip2long($net_addr);
    $broadcast = $net_long | ~(~0 << (32 - $prefix));

    /* ── ARP scan ── */
    $cmd    = 'sudo /usr/sbin/arp-scan --interface=' . SCAN_INTERFACE
            . ' --quiet --plain ' . escapeshellarg($cidr) . ' 2>&1';
    $output = shell_exec($cmd);

    $found_ips = [];
    if ($output) {
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (!preg_match('/(\d{1,3}(?:\.\d{1,3}){3})[\s\t]+([0-9a-fA-F:]{17})/', $line, $m)) continue;
            $ipl = ip2long($m[1]);
            if ($ipl < $net_long || $ipl > $broadcast) continue;
            $found_ips[$m[1]] = strtoupper($m[2]);
        }
    }
    logMsg("  ARP found " . count($found_ips) . " device(s)");

    /* ── Ping fallback for no-MAC devices in this subnet ── */
    $nmr = $db->query("SELECT ip FROM janus_ipam WHERE mac IS NULL OR mac = ''");
    if ($nmr) {
        while ($row = $nmr->fetch_assoc()) {
            $ipl = ip2long($row['ip']);
            if ($ipl === false || $ipl < $net_long || $ipl > $broadcast) continue;
            if (isset($found_ips[$row['ip']])) continue;
            $esc = escapeshellarg($row['ip']);
            exec("ping -c 2 -W 1 -q $esc 2>/dev/null", $po, $pret);
            if ($pret === 0) {
                $found_ips[$row['ip']] = ''; // alive but no MAC
                logMsg("  Ping found (no-MAC): {$row['ip']}");
            }
        }
    }

    /* ── Upsert discovered devices ── */
    foreach ($found_ips as $ip => $mac) {
        $s = $db->prepare('SELECT mac, status FROM janus_ipam WHERE ip = ?');
        if (!$s) { logMsg("  prepare error: " . $db->error); continue; }
        $s->bind_param('s', $ip); $s->execute();
        $s->bind_result($cur_mac, $cur_status);
        $exists = (bool)$s->fetch();
        $s->close();

        if ($exists) {
            if ($mac !== '') {
                $upd = $db->prepare("UPDATE janus_ipam SET mac=?,status='active',last_seen=NOW() WHERE ip=?");
                if (!$upd) { logMsg("  prepare error: ".$db->error); continue; }
                $upd->bind_param('ss', $mac, $ip);
            } else {
                $upd = $db->prepare("UPDATE janus_ipam SET status='active',last_seen=NOW() WHERE ip=?");
                if (!$upd) { logMsg("  prepare error: ".$db->error); continue; }
                $upd->bind_param('s', $ip);
            }
            $upd->execute(); $upd->close();

            // Log MAC change
            if ($mac !== '' && $cur_mac && $cur_mac !== $mac) {
                $note = "Cron: MAC changed $cur_mac → $mac";
                insertLog($db, $ip, $cur_mac, $mac, $note);
                logMsg("  MAC change $ip: $cur_mac → $mac");
            }
            // Log came online
            if ($cur_status === 'inactive') {
                insertLog($db, $ip, $cur_mac??'', $mac, 'Cron: Device came back online');
                logMsg("  Online: $ip");
            }
        } else {
            $ins = $db->prepare(
                "INSERT INTO janus_ipam (ip,mac,status,first_seen,last_seen) VALUES (?,?,'active',NOW(),NOW())"
            );
            if (!$ins) { logMsg("  prepare error: ".$db->error); continue; }
            $ins->bind_param('ss', $ip, $mac); $ins->execute(); $ins->close();
            insertLog($db, $ip, '', $mac, 'Cron: New device discovered');
            logMsg("  New device: $ip ($mac)");
        }

        // MAC spoofing check
        if ($mac !== '') {
            $sp = $db->prepare(
                "SELECT ip FROM janus_ipam WHERE mac=? AND ip!=? AND status='active'"
            );
            if (!$sp) continue;
            $sp->bind_param('ss', $mac, $ip); $sp->execute();
            $sp->bind_result($other);
            if ($sp->fetch()) {
                $note = "Cron: MAC spoofing – $mac on $ip, also on $other";
                insertLog($db, $ip, $mac, $mac, $note);
                logMsg("  ⚠ SPOOF: $note");
            }
            $sp->close();
        }
    }

    /* ── Mark offline (scoped to this subnet) ── */
    $all = $db->prepare("SELECT ip, mac FROM janus_ipam WHERE status='active'");
    if (!$all) { logMsg("  prepare error: ".$db->error); return; }
    $all->execute();
    $res = $all->get_result();
    while ($row = $res->fetch_assoc()) {
        $ipl = ip2long($row['ip']);
        if ($ipl < $net_long || $ipl > $broadcast) continue;
        if (!isset($found_ips[$row['ip']])) {
            $upd = $db->prepare("UPDATE janus_ipam SET status='inactive' WHERE ip=?");
            if (!$upd) continue;
            $upd->bind_param('s', $row['ip']); $upd->execute(); $upd->close();
            insertLog($db, $row['ip'], $row['mac']??'', $row['mac']??'', 'Cron: Device went offline');
            logMsg("  Offline: {$row['ip']}");
        }
    }
    $all->close();
}


/* ════════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════════ */
function insertLog(mysqli $db, string $ip, string $old, string $new, string $note): void {
    // Guard: don't attempt on a closed/errored connection
    if ($db->connect_errno) {
        logMsg("insertLog skipped – DB not connected");
        return;
    }
    $s = $db->prepare(
        'INSERT INTO janus_ipam_log (ip, old_mac, new_mac, note, acknowledged) VALUES (?,?,?,?,0)'
    );
    if (!$s) {
        logMsg("insertLog prepare failed: " . $db->error);
        return;
    }
    $s->bind_param('ssss', $ip, $old, $new, $note);
    $s->execute();
    $s->close();
}

function logMsg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}
