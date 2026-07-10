<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * soc/ipam_ajax.php
 *
 * Actions:
 *   load           – full IP inventory with alert enrichment
 *   save           – insert / update IP record
 *   delete         – remove IP record
 *   acknowledge    – ack latest unacked alert for an IP (by janus_ipam.id)
 *   ack_log        – ack a specific log entry (by janus_ipam_log.id)
 *   bulk_ack       – ack all unacknowledged alerts
 *   ping           – live ICMP check for a single IP
 *   subnets_load   – list all subnets
 *   subnet_save    – insert / update a subnet
 *   subnet_delete  – remove a subnet
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    exit(json_encode(['error' => 'Authentication required']));
}

require_once __DIR__ . '/ipam_lib.php';

try {
    $db = ipam_db();
} catch (RuntimeException $e) {
    exit(json_encode(['error' => $e->getMessage()]));
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ══════════════════════════════════════════════════════
   LOAD – IP inventory with latest alert from log
══════════════════════════════════════════════════════ */
if ($action === 'load') {
    $sql = "
        SELECT
            i.id, i.ip, i.mac, i.assigned_to, i.status, i.vlan, i.notes,
            i.first_seen, i.last_seen,
            l.id            AS log_id,
            l.note          AS alert_raw,
            l.acknowledged,
            l.acknowledged_by AS ack_by,
            l.ack_note,
            l.acknowledged_at AS ack_at,
            l.changed_at    AS alert_at
        FROM janus_ipam i
        LEFT JOIN (
            SELECT id, ip, note, acknowledged, acknowledged_by, ack_note,
                   acknowledged_at, changed_at,
                   ROW_NUMBER() OVER (PARTITION BY ip ORDER BY changed_at DESC) rn
            FROM janus_ipam_log
        ) l ON l.ip = i.ip AND l.rn = 1
        ORDER BY
            CASE
                WHEN l.note LIKE '%spoof%'  AND l.acknowledged=0 THEN 0
                WHEN l.note IS NOT NULL     AND l.acknowledged=0 THEN 1
                ELSE 2
            END,
            INET_ATON(i.ip)
    ";
    $res  = $db->query($sql);
    if (!$res) { exit(json_encode(['error' => $db->error])); }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        // Normalise alert_type
        $raw = $row['alert_raw'] ?? '';
        if ($raw) {
            if (stripos($raw,'spoof')    !== false) $row['alert_type'] = 'spoofing';
            elseif (stripos($raw,'MAC')  !== false
                 && stripos($raw,'change') !== false) $row['alert_type'] = 'mac_change';
            elseif (stripos($raw,'offline') !== false) $row['alert_type'] = 'offline';
            elseif (stripos($raw,'online')  !== false) $row['alert_type'] = 'online';
            elseif (stripos($raw,'New device') !== false) $row['alert_type'] = 'new';
            else $row['alert_type'] = 'info';
        } else {
            $row['alert_type'] = null;
        }
        $row['acknowledged'] = (bool)$row['acknowledged'];
        unset($row['alert_raw']);
        $rows[] = $row;
    }
    $res->free();
    $db->close();
    exit(json_encode($rows));
}

/* ══════════════════════════════════════════════════════
   SAVE IP RECORD
══════════════════════════════════════════════════════ */
if ($action === 'save') {
    $id          = intval($_POST['id']          ?? 0);
    $ip          = trim($_POST['ip']            ?? '');
    $mac         = strtoupper(trim($_POST['mac'] ?? ''));
    $assigned_to = trim($_POST['assigned_to']   ?? '');
    $status      = $_POST['status']             ?? 'active';
    $vlan        = trim($_POST['vlan']          ?? '');
    $notes       = trim($_POST['notes']         ?? '');

    header('Content-Type: text/plain');

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        exit('error: Invalid IPv4 address');
    if ($mac !== '' && !preg_match('/^([0-9A-F]{2}[:\-]){5}[0-9A-F]{2}$/', $mac))
        exit('error: Invalid MAC (XX:XX:XX:XX:XX:XX)');
    if (!in_array($status, ['active','inactive','reserved'], true))
        exit('error: Invalid status');

    if ($id > 0) {
        $s = $db->prepare('UPDATE janus_ipam SET ip=?,mac=?,assigned_to=?,status=?,vlan=?,notes=? WHERE id=?');
        if (!$s) exit('error: '.$db->error);
        $s->bind_param('ssssssi', $ip, $mac, $assigned_to, $status, $vlan, $notes, $id);
    } else {
        $chk = $db->prepare('SELECT id FROM janus_ipam WHERE ip=?');
        $chk->bind_param('s',$ip); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) { $chk->close(); exit('error: IP already exists'); }
        $chk->close();
        $s = $db->prepare(
            'INSERT INTO janus_ipam (ip,mac,assigned_to,status,vlan,notes,first_seen,last_seen) VALUES (?,?,?,?,?,?,NOW(),NOW())'
        );
        if (!$s) exit('error: '.$db->error);
        $s->bind_param('ssssss', $ip, $mac, $assigned_to, $status, $vlan, $notes);
    }
    $s->execute();
    $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : 'ok');
}

/* ══════════════════════════════════════════════════════
   DELETE IP RECORD
══════════════════════════════════════════════════════ */
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    header('Content-Type: text/plain');
    if ($id <= 0) exit('error: Invalid ID');
    $s = $db->prepare('DELETE FROM janus_ipam WHERE id=?');
    $s->bind_param('i',$id); $s->execute();
    $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : 'ok');
}

/* ══════════════════════════════════════════════════════
   ACKNOWLEDGE – by janus_ipam.id
══════════════════════════════════════════════════════ */
if ($action === 'acknowledge') {
    $id     = intval($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');
    $ack_by = $_SESSION['name'] ?? 'unknown';
    header('Content-Type: text/plain');
    if ($id <= 0) exit('error: Invalid ID');

    // Get IP for this record
    $s = $db->prepare('SELECT ip FROM janus_ipam WHERE id=?');
    $s->bind_param('i',$id); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if (!$row) exit('error: Record not found');

    $ip = $row['ip'];
    $s  = $db->prepare(
        'UPDATE janus_ipam_log SET acknowledged=1,acknowledged_by=?,ack_note=?,acknowledged_at=NOW()
         WHERE ip=? AND acknowledged=0 ORDER BY changed_at DESC LIMIT 1'
    );
    $s->bind_param('sss',$ack_by,$note,$ip); $s->execute();
    $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : 'ok');
}

/* ══════════════════════════════════════════════════════
   ACK_LOG – by janus_ipam_log.id
══════════════════════════════════════════════════════ */
if ($action === 'ack_log') {
    $log_id = intval($_POST['log_id'] ?? 0);
    $note   = trim($_POST['note']    ?? '');
    $ack_by = $_SESSION['name']      ?? 'unknown';
    header('Content-Type: text/plain');
    if ($log_id <= 0) exit('error: Invalid log ID');
    $s = $db->prepare(
        'UPDATE janus_ipam_log SET acknowledged=1,acknowledged_by=?,ack_note=?,acknowledged_at=NOW() WHERE id=?'
    );
    $s->bind_param('ssi',$ack_by,$note,$log_id); $s->execute();
    $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : 'ok');
}

/* ══════════════════════════════════════════════════════
   BULK_ACK
══════════════════════════════════════════════════════ */
if ($action === 'bulk_ack') {
    $note   = trim($_POST['note'] ?? 'Bulk acknowledged');
    $ack_by = $_SESSION['name']   ?? 'unknown';
    header('Content-Type: text/plain');
    $s = $db->prepare(
        'UPDATE janus_ipam_log SET acknowledged=1,acknowledged_by=?,ack_note=?,acknowledged_at=NOW() WHERE acknowledged=0'
    );
    $s->bind_param('ss',$ack_by,$note); $s->execute();
    $n = $s->affected_rows; $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : "ok: $n alert(s) acknowledged");
}

/* ══════════════════════════════════════════════════════
   PING
══════════════════════════════════════════════════════ */
if ($action === 'ping') {
    $ip = trim($_POST['ip'] ?? '');
    $db->close();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        exit(json_encode(['alive'=>false,'error'=>'Invalid IP']));
    }
    $r = ping_host($ip);
    exit(json_encode(['alive'=>$r['alive'],'rtt'=>$r['rtt'],'ip'=>$ip]));
}

/* ══════════════════════════════════════════════════════
   SUBNETS_LOAD
══════════════════════════════════════════════════════ */
if ($action === 'subnets_load') {
    // Fetch all subnets first
    $res  = $db->query("SELECT * FROM ipam_subnets ORDER BY id");
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $res && $res->free();

    // Fetch ALL devices once (not per-subnet to avoid DB sync issues)
    $all_devices = [];
    $all_res = $db->query("SELECT ip, status FROM janus_ipam");
    if ($all_res) {
        while ($row = $all_res->fetch_assoc()) {
            $all_devices[] = $row;
        }
        $all_res->free();
    }

    // Auto-detect interfaces via shell (no DB needed)
    foreach ($rows as &$s) {
        [$nl, $br] = subnet_bounds($s['cidr']);
        $tc = 0; $ac = 0;
        foreach ($all_devices as $d) {
            $il = ip2long($d['ip']);
            if ($il !== false && $il >= $nl && $il <= $br) {
                $tc++;
                if ($d['status'] === 'active') $ac++;
            }
        }
        $s['device_count']   = $tc;
        $s['active_count']   = $ac;

        // Auto-detect interface via routing table (no DB call)
        $iface = $s['interface'] ?? '';
        if (!$iface) {
            [$net] = explode('/', $s['cidr']);
            $parts = explode('.', $net);
            $parts[3] = (int)$parts[3] + 1;
            $probe = implode('.', $parts);
            $out = @shell_exec('ip route get ' . escapeshellarg($probe) . ' 2>/dev/null');
            if ($out && preg_match('/\bdev\s+(\S+)/', $out, $m)) {
                $iface = $m[1];
            } else {
                $iface = 'ens160';
            }
        }
        $s['interface_auto'] = $iface;
    }
    unset($s);

    $db->close();
    exit(json_encode($rows));
}

/* ══════════════════════════════════════════════════════
   SUBNET_SAVE
══════════════════════════════════════════════════════ */
if ($action === 'subnet_save') {
    $id          = intval($_POST['id']          ?? 0);
    $cidr        = trim($_POST['cidr']          ?? '');
    $label       = trim($_POST['label']         ?? '');
    $vlan_id     = trim($_POST['vlan_id']       ?? '');
    $description = trim($_POST['description']   ?? '');
    $interface   = trim($_POST['interface']     ?? '');
    $scan_enabled = intval($_POST['scan_enabled'] ?? 1);

    header('Content-Type: text/plain');
    if (!preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $cidr))
        exit('error: Invalid CIDR');

    if ($id > 0) {
        $s = $db->prepare(
            'UPDATE ipam_subnets SET cidr=?,label=?,vlan_id=?,description=?,interface=?,scan_enabled=? WHERE id=?'
        );
        $s->bind_param('sssssii',$cidr,$label,$vlan_id,$description,$interface,$scan_enabled,$id);
    } else {
        $s = $db->prepare(
            'INSERT INTO ipam_subnets (cidr,label,vlan_id,description,interface,scan_enabled) VALUES (?,?,?,?,?,?)'
        );
        $s->bind_param('sssssi',$cidr,$label,$vlan_id,$description,$interface,$scan_enabled);
    }
    $s->execute();
    $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : 'ok');
}

/* ══════════════════════════════════════════════════════
   SUBNET_DELETE
══════════════════════════════════════════════════════ */
if ($action === 'subnet_delete') {
    $id = intval($_POST['id'] ?? 0);
    header('Content-Type: text/plain');
    if ($id <= 0) exit('error: Invalid ID');
    $s = $db->prepare('DELETE FROM ipam_subnets WHERE id=?');
    $s->bind_param('i',$id); $s->execute();
    $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : 'ok');
}


/* ══════════════════════════════════════════════════════
   IP_HISTORY – full log for one IP
══════════════════════════════════════════════════════ */
if ($action === 'ip_history') {
    $ip = trim($_POST['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        exit(json_encode(['error' => 'Invalid IP']));
    }
    $s = $db->prepare(
        'SELECT id, ip, old_mac, new_mac, note, acknowledged,
                acknowledged_by, ack_note, acknowledged_at, changed_at
         FROM janus_ipam_log
         WHERE ip = ?
         ORDER BY changed_at DESC
         LIMIT 200'
    );
    $s->bind_param('s', $ip);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    $db->close();
    exit(json_encode($rows));
}

/* ══════════════════════════════════════════════════════
   BULK_ACK_IP – ack all unacked alerts for one IP
══════════════════════════════════════════════════════ */
if ($action === 'bulk_ack_ip') {
    $ip     = trim($_POST['ip']   ?? '');
    $note   = trim($_POST['note'] ?? 'Bulk acknowledged');
    $ack_by = $_SESSION['name']   ?? 'unknown';
    header('Content-Type: text/plain');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        exit('error: Invalid IP');
    }
    $s = $db->prepare(
        'UPDATE janus_ipam_log SET acknowledged=1,acknowledged_by=?,ack_note=?,acknowledged_at=NOW()
         WHERE ip=? AND acknowledged=0'
    );
    $s->bind_param('sss', $ack_by, $note, $ip);
    $s->execute();
    $n = $s->affected_rows; $err = $s->error; $s->close(); $db->close();
    exit($err ? 'error: '.$err : "ok: $n alert(s) acknowledged for $ip");
}

$db->close();
exit(json_encode(['error' => 'Unknown action: '.$action]));
