<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * noc/nac_ajax.php – All NAC AJAX endpoints
 */

// Buffer ALL output so PHP warnings/notices never corrupt the JSON response
ob_start();

// Catch any fatal errors that slip through and return them as JSON
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    } else {
        // Flush buffered normal output (should be JSON already set by exit())
        ob_end_flush();
    }
});

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    ob_end_clean();
    exit(json_encode(['error' => 'Unauthorized']));
}

error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', '0');

require_once __DIR__ . '/nac_lib.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) { ob_end_clean(); exit(json_encode(['error' => 'DB Error: ' . $db->connect_error])); }
$db->set_charset('utf8mb4');

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// Polling actions can take many minutes — override the 30s PHP limit.
if (in_array($action, ['poll_switch', 'poll_all', 'debug_poll', 'raw_ssh'], true)) {
    set_time_limit(0);
    ignore_user_abort(true);
}

/* ─── ADD SWITCH ─── */
if ($action === 'add_switch') {
    $hostname    = trim($_POST['hostname']        ?? '');
    $ip          = trim($_POST['ip']              ?? '');
    $model       = trim($_POST['model']           ?? '');
    $user        = trim($_POST['ssh_user']        ?? '');
    $pass        = trim($_POST['ssh_pass']        ?? '');
    $port        = (int)($_POST['ssh_port']       ?? 22);
    $location    = trim($_POST['location']        ?? '');
    $notes       = trim($_POST['notes']           ?? '');
    $plkey       = trim($_POST['platform_key']    ?? 'junos');
    $conn_type   = trim($_POST['connection_type'] ?? 'ssh');
    $enable_pass = trim($_POST['enable_pass']     ?? '');
    $group_id    = (int)($_POST['group_id']       ?? 0);

    // SNMP fields
    $snmp_community = trim($_POST['snmp_community'] ?? '');
    $snmp_version   = (int)($_POST['snmp_version']  ?? 2);
    $snmp_port_num  = (int)($_POST['snmp_port']      ?? 161);

    if ($conn_type === 'telnet' && $port === 22) $port = 23;

    if (!filter_var($ip, FILTER_VALIDATE_IP)) exit(json_encode(['error' => 'Invalid IP']));

    $chk_ip = $db->query("SELECT id, hostname FROM nac_switches WHERE ip='" . $db->real_escape_string($ip) . "' LIMIT 1");
    if ($chk_ip && $row = $chk_ip->fetch_assoc()) {
        $chk_ip->free();
        $db->close();
        exit(json_encode(['error' => "Switch with IP $ip already exists (" . ($row['hostname'] ?: 'unnamed') . ")"]));
    }
    if ($chk_ip) $chk_ip->free();

    if (!in_array($conn_type, ['ssh','telnet','snmp'], true)) $conn_type = 'ssh';

    if ($conn_type !== 'snmp') {
        if (!$ip || !$user || !$pass) exit(json_encode(['error' => 'IP, user and password required']));
    }
    if ($conn_type === 'snmp' && !$snmp_community) {
        exit(json_encode(['error' => 'SNMP community string required']));
    }

    // Auto-add ALL potentially missing columns for older DB schemas (no AFTER clauses — safe on any schema)
    $cols_needed = [
        'hostname'        => "ALTER TABLE nac_switches ADD COLUMN hostname VARCHAR(255) NOT NULL DEFAULT ''",
        'model'           => "ALTER TABLE nac_switches ADD COLUMN model VARCHAR(100) DEFAULT NULL",
        'vendor'          => "ALTER TABLE nac_switches ADD COLUMN vendor VARCHAR(50) DEFAULT NULL",
        'platform_key'    => "ALTER TABLE nac_switches ADD COLUMN platform_key VARCHAR(50) DEFAULT 'ios'",
        'ssh_user'        => "ALTER TABLE nac_switches ADD COLUMN ssh_user VARCHAR(100) DEFAULT NULL",
        'ssh_port'        => "ALTER TABLE nac_switches ADD COLUMN ssh_port INT NOT NULL DEFAULT 22",
        'connection_type' => "ALTER TABLE nac_switches ADD COLUMN connection_type VARCHAR(10) NOT NULL DEFAULT 'ssh'",
        'snmp_community'  => "ALTER TABLE nac_switches ADD COLUMN snmp_community VARCHAR(128) DEFAULT NULL",
        'snmp_version'    => "ALTER TABLE nac_switches ADD COLUMN snmp_version INT NOT NULL DEFAULT 2",
        'snmp_port'       => "ALTER TABLE nac_switches ADD COLUMN snmp_port INT NOT NULL DEFAULT 161",
        'location'        => "ALTER TABLE nac_switches ADD COLUMN location VARCHAR(255) DEFAULT NULL",
        'notes'           => "ALTER TABLE nac_switches ADD COLUMN notes TEXT DEFAULT NULL",
        'enabled'         => "ALTER TABLE nac_switches ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1",
        'poll_status'     => "ALTER TABLE nac_switches ADD COLUMN poll_status VARCHAR(20) DEFAULT 'pending'",
        'poll_error'      => "ALTER TABLE nac_switches ADD COLUMN poll_error TEXT DEFAULT NULL",
        'last_polled'     => "ALTER TABLE nac_switches ADD COLUMN last_polled DATETIME DEFAULT NULL",
        'updated_at'      => "ALTER TABLE nac_switches ADD COLUMN updated_at DATETIME DEFAULT NULL",
        'group_id'        => "ALTER TABLE nac_switches ADD COLUMN group_id INT DEFAULT NULL",
    ];
    foreach ($cols_needed as $col_name => $alter_sql) {
        $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_switches' AND COLUMN_NAME='$col_name'");
        if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
            $db->query($alter_sql);
        }
        $col_chk && $col_chk->free();
    }

    // Ensure legacy 'name' column in nac_switches doesn't block INSERTs if present
    $db->query("ALTER TABLE nac_switches MODIFY COLUMN name VARCHAR(100) DEFAULT NULL");

    if ($conn_type !== 'snmp') {
        nac_save_credential($ip, $user, $pass, $enable_pass);
    }

    $s = $db->prepare(
        "INSERT INTO nac_switches
         (hostname,ip,model,vendor,platform_key,ssh_user,ssh_port,connection_type,
          snmp_community,snmp_version,snmp_port,location,notes,group_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $vendor        = str_contains($plkey,'junos') ? 'juniper' : (str_contains($plkey,'ios') ? 'cisco' : 'other');
    $gid           = $group_id > 0 ? $group_id : null;
    $snmp_comm_val = $conn_type === 'snmp' ? $snmp_community : null;
    $s->bind_param('ssssssissiissi',
        $hostname,$ip,$model,$vendor,$plkey,$user,$port,$conn_type,
        $snmp_comm_val,$snmp_version,$snmp_port_num,$location,$notes,$gid);
    if (!$s->execute()) { $err=$s->error; $s->close(); exit(json_encode(['error'=>$err])); }
    $new_id = $s->insert_id; $s->close();
    $db->close();
    exit(json_encode(['ok'=>true,'id'=>$new_id,'message'=>"Switch $hostname added"]));
}

/* ─── EDIT SWITCH ─── */
if ($action === 'edit_switch') {
    $id          = (int)($_POST['id']              ?? 0);
    $hostname    = trim($_POST['hostname']         ?? '');
    $model       = trim($_POST['model']            ?? '');
    $user        = trim($_POST['ssh_user']         ?? '');
    $pass        = trim($_POST['ssh_pass']         ?? '');
    $location    = trim($_POST['location']         ?? '');
    $notes       = trim($_POST['notes']            ?? '');
    $plkey       = trim($_POST['platform_key']     ?? 'junos');
    $enabled     = (int)($_POST['enabled']         ?? 1);
    $conn_type   = trim($_POST['connection_type']  ?? 'ssh');
    $enable_pass = trim($_POST['enable_pass']      ?? '');
    $group_id    = (int)($_POST['group_id']        ?? 0);

    // SNMP fields
    $snmp_community = trim($_POST['snmp_community'] ?? '');
    $snmp_version   = (int)($_POST['snmp_version']  ?? 2);
    $snmp_port_num  = (int)($_POST['snmp_port']      ?? 161);

    if (!in_array($conn_type, ['ssh','telnet','snmp'], true)) $conn_type = 'ssh';

    $r = $db->query("SELECT ip FROM nac_switches WHERE id=$id");
    if (!$r || $r->num_rows === 0) exit(json_encode(['error' => 'Switch not found']));
    $current_ip = $r->fetch_row()[0];
    $r->free();

    if ($conn_type !== 'snmp') {
        $existing        = nac_get_credential($current_ip);
        $existing_enable = $existing['enable_pass'] ?? '';
        if ($pass !== '') {
            nac_save_credential($current_ip, $user, $pass, $enable_pass !== '' ? $enable_pass : $existing_enable);
        } else {
            if ($existing) {
                nac_save_credential($current_ip, $user, $existing['pass'],
                    $enable_pass !== '' ? $enable_pass : $existing_enable);
            }
        }
    }

    $vendor        = str_contains($plkey,'junos') ? 'juniper' : (str_contains($plkey,'ios') ? 'cisco' : 'other');
    $gid           = $group_id > 0 ? $group_id : null;
    $snmp_comm_val = $conn_type === 'snmp' ? $snmp_community : null;

    $s = $db->prepare(
        "UPDATE nac_switches SET hostname=?,model=?,vendor=?,platform_key=?,ssh_user=?,
         connection_type=?,snmp_community=?,snmp_version=?,snmp_port=?,
         location=?,notes=?,enabled=?,group_id=?,updated_at=NOW() WHERE id=?"
    );
    $s->bind_param('sssssssiiissiii',
        $hostname,$model,$vendor,$plkey,$user,$conn_type,
        $snmp_comm_val,$snmp_version,$snmp_port_num,
        $location,$notes,$enabled,$gid,$id);
    if (!$s->execute()) { $err=$s->error; $s->close(); exit(json_encode(['error'=>$err])); }
    $s->close();
    $db->close();
    exit(json_encode(['ok'=>true,'message'=>"Switch $hostname updated"]));
}

/* ─── DELETE SWITCH ─── */
if ($action === 'delete_switch') {
    $id = (int)($_POST['id'] ?? 0);
    $r = $db->query("SELECT ip FROM nac_switches WHERE id=$id");
    if ($r && $row = $r->fetch_row()) { nac_delete_credential($row[0]); $r->free(); }
    $db->query("DELETE FROM nac_switches WHERE id=$id");
    $db->close();
    exit(json_encode(['ok'=>true]));
}

/* ─── LIST SWITCHES ─── */
if ($action === 'list_switches') {
    // Auto-add all missing columns for older DB schemas
    $cols_needed = [
        'ssh_port'        => "ALTER TABLE nac_switches ADD COLUMN ssh_port INT NOT NULL DEFAULT 22 AFTER ssh_user",
        'snmp_community'  => "ALTER TABLE nac_switches ADD COLUMN snmp_community VARCHAR(128) DEFAULT NULL",
        'snmp_version'    => "ALTER TABLE nac_switches ADD COLUMN snmp_version INT NOT NULL DEFAULT 2",
        'snmp_port'       => "ALTER TABLE nac_switches ADD COLUMN snmp_port INT NOT NULL DEFAULT 161",
        'connection_type' => "ALTER TABLE nac_switches ADD COLUMN connection_type VARCHAR(10) NOT NULL DEFAULT 'ssh'",
        'group_id'        => "ALTER TABLE nac_switches ADD COLUMN group_id INT DEFAULT NULL AFTER id",
    ];
    foreach ($cols_needed as $col_name => $alter_sql) {
        $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_switches' AND COLUMN_NAME='$col_name'");
        if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
            $db->query($alter_sql);
        }
        $col_chk && $col_chk->free();
    }

    _nac_ensure_groups_table($db);

    $r = $db->query(
        "SELECT s.*, p.vendor AS plat_vendor,
         g.name AS group_name, g.color AS group_color,
         (SELECT COUNT(*) FROM nac_mac_events WHERE switch_id=s.id AND is_alarm=1 AND acknowledged=0) AS alarms
         FROM nac_switches s
         LEFT JOIN nac_platforms p ON s.platform_key=p.platform_key
         LEFT JOIN nac_switch_groups g ON s.group_id=g.id
         ORDER BY g.sort_order, g.name, s.hostname"
    );
    $switches = [];
    if ($r) { while ($row = $r->fetch_assoc()) $switches[] = $row; $r->free(); }
    $db->close();
    exit(json_encode($switches));
}

/* ─── GET SWITCH DETAIL (ports + MACs) ─── */
if ($action === 'switch_detail') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    $ports = [];
    $r = $db->query(
        "SELECT p.*,
         (SELECT COUNT(*) FROM nac_port_macs
          WHERE switch_id=$id
            AND (port_name=p.port_name OR port_name=CONCAT(p.port_name,'.0'))
         ) AS live_macs
         FROM nac_ports p
         WHERE p.switch_id=$id
           AND p.port_name NOT IN ('Router','All-members','Flood','flood','all-members')
           AND p.port_name NOT REGEXP '^[Rr]outer$|^[Aa]ll.members$'
         ORDER BY p.port_name"
    );
    if ($r) { while ($row=$r->fetch_assoc()) $ports[] = $row; $r->free(); }

    $db->query("DELETE FROM nac_ports WHERE switch_id=$id
                AND port_name IN ('Router','All-members','Flood','flood','all-members')");

    $macs = [];
    $r = $db->query(
        "SELECT npm.mac, npm.port_name, npm.vlan_name, npm.mac_type, npm.first_seen, npm.last_seen,
                COALESCE(m.ip,   si.ip)               AS ip,
                COALESCE(m.hostname, si.assigned_to)  AS hostname,
                COALESCE(m.ipam_status, si.status)    AS ipam_status,
                COALESCE(m.oui_vendor, '')             AS oui_vendor,
                COALESCE(p.is_trunk,0) AS via_trunk
         FROM nac_port_macs npm
         LEFT JOIN nac_mac_ip_map m  ON npm.mac = m.mac
         LEFT JOIN janus_ipam si    ON npm.mac = UPPER(REPLACE(si.mac,'-',':'))
         LEFT JOIN nac_ports p       ON npm.switch_id=p.switch_id AND npm.port_name=p.port_name
         WHERE npm.switch_id=$id
         ORDER BY COALESCE(p.is_trunk,0) ASC, npm.port_name, npm.mac"
    );
    if ($r) { 
        while ($row=$r->fetch_assoc()) {
            if (empty($row['oui_vendor']) || $row['oui_vendor'] === 'Unknown') {
                $v = nac_oui_lookup($row['mac']);
                $row['oui_vendor'] = ($v !== 'Unknown') ? $v : '';
            }
            if (!empty($row['ip']) && empty($row['hostname'])) {
                $host = @gethostbyaddr($row['ip']);
                if ($host && $host !== $row['ip']) {
                    $row['hostname'] = $host;
                }
            }
            $macs[] = $row;
        }
        $r->free(); 
    }

    $events = [];
    $r = $db->query(
        "SELECT e.*, ns.hostname AS switch_name
         FROM nac_mac_events e
         LEFT JOIN nac_switches ns ON e.switch_id=ns.id
         WHERE e.switch_id=$id
         ORDER BY e.created_at DESC LIMIT 50"
    );
    if ($r) { while ($row=$r->fetch_assoc()) $events[] = $row; $r->free(); }

    $db->close();
    exit(json_encode(['ports'=>$ports,'macs'=>$macs,'events'=>$events]));
}

/* ─── POLL SWITCH NOW ─── */
if ($action === 'poll_switch') {
    $id = (int)($_POST['id'] ?? 0);
    $r  = $db->query("SELECT * FROM nac_switches WHERE id=$id");
    if (!$r || $r->num_rows === 0) exit(json_encode(['error'=>'Switch not found']));
    $sw = $r->fetch_assoc(); $r->free();

    $db->query("UPDATE nac_switches SET poll_status='pending' WHERE id=$id");
    $result = nac_poll_switch($db, $sw);

    if (!$result['ok']) {
        $err = $db->real_escape_string($result['error'] ?? 'Unknown error');
        $db->query("UPDATE nac_switches SET poll_status='error',poll_error='$err' WHERE id=$id");
    }

    $db->close();
    exit(json_encode($result));
}

/* ─── POLL ALL SWITCHES ─── */
if ($action === 'poll_all') {
    $r = $db->query("SELECT * FROM nac_switches WHERE enabled=1 ORDER BY id");
    $results = [];
    while ($sw = $r->fetch_assoc()) {
        $result = nac_poll_switch($db, $sw);
        $results[] = ['switch'=>$sw['hostname'],'ip'=>$sw['ip']] + $result;
    }
    $r->free(); $db->close();
    exit(json_encode(['ok'=>true,'results'=>$results]));
}

/* ─── DASHBOARD STATS ─── */
if ($action === 'dashboard_stats') {
    $stats = [];
    $r = $db->query("SELECT COUNT(*) FROM nac_switches WHERE enabled=1");
    $stats['total_switches']  = (int)$r->fetch_row()[0]; $r->free();
    $r = $db->query("SELECT COUNT(*) FROM nac_switches WHERE poll_status='ok'");
    $stats['ok_switches']     = (int)$r->fetch_row()[0]; $r->free();
    $r = $db->query("SELECT COUNT(*) FROM nac_switches WHERE poll_status='error'");
    $stats['error_switches']  = (int)$r->fetch_row()[0]; $r->free();
    $r = $db->query("SELECT COUNT(*) FROM nac_port_macs");
    $stats['total_macs']      = (int)$r->fetch_row()[0]; $r->free();

    // Access MACs: MACs on non-trunk ports
    $r = $db->query(
        "SELECT COUNT(*) FROM nac_port_macs npm
         LEFT JOIN nac_ports p ON p.switch_id=npm.switch_id AND p.port_name=npm.port_name
         WHERE COALESCE(p.is_trunk,0)=0"
    );
    $stats['access_macs'] = (int)$r->fetch_row()[0]; $r->free();

    // Trunk MACs: MACs on trunk/LAG ports
    $r = $db->query(
        "SELECT COUNT(*) FROM nac_port_macs npm
         LEFT JOIN nac_ports p ON p.switch_id=npm.switch_id AND p.port_name=npm.port_name
         WHERE COALESCE(p.is_trunk,0)=1"
    );
    $stats['trunk_macs'] = (int)$r->fetch_row()[0]; $r->free();

    $r = $db->query("SELECT COUNT(*) FROM nac_ports WHERE link_status='up'");
    $stats['ports_up']        = (int)$r->fetch_row()[0]; $r->free();
    $r = $db->query("SELECT COUNT(*) FROM nac_mac_events WHERE is_alarm=1 AND acknowledged=0");
    $stats['unacked_alarms']  = (int)$r->fetch_row()[0]; $r->free();
    $r = $db->query(
        "SELECT e.*, ns.hostname AS switch_name
         FROM nac_mac_events e
         LEFT JOIN nac_switches ns ON e.switch_id=ns.id
         WHERE e.is_alarm=1 AND e.acknowledged=0
         ORDER BY e.created_at DESC LIMIT 10"
    );
    $stats['recent_alarms'] = [];
    if ($r) { while ($row=$r->fetch_assoc()) $stats['recent_alarms'][] = $row; $r->free(); }
    $db->close();
    exit(json_encode($stats));
}

/* ─── SEARCH ─── */
if ($action === 'search') {
    $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
    if (strlen($q) < 2) exit(json_encode([]));
    $results = nac_global_search($db, $q);
    foreach ($results as &$r) {
        if (empty($r['vendor'])) $r['vendor'] = nac_oui_lookup($r['mac'] ?? '');
        if (!empty($r['mac'])) {
            $esc_mac = $db->real_escape_string($r['mac']);
            $access = $db->query(
                "SELECT COUNT(*) FROM nac_port_macs npm
                 LEFT JOIN nac_ports p ON p.switch_id=npm.switch_id AND p.port_name=npm.port_name
                 WHERE npm.mac='$esc_mac' AND COALESCE(p.is_trunk,0)=0"
            );
            $access_count = $access ? (int)$access->fetch_row()[0] : 0;
            $access && $access->free();
            $r['via_trunk'] = ($access_count === 0) ? 1 : 0;
        }
    }
    unset($r);
    $db->close();
    exit(json_encode($results));
}

/* ─── MAC TRACE ─── */
if ($action === 'mac_trace') {
    $mac = trim($_POST['mac'] ?? $_GET['mac'] ?? '');
    if (strlen($mac) < 5) exit(json_encode(['error'=>'Invalid MAC']));
    $chain = nac_mac_trace($db, $mac);
    $db->close();
    exit(json_encode(['chain'=>$chain]));
}

/* ─── ACK EVENT ─── */
if ($action === 'ack_event') {
    $id   = (int)($_POST['id'] ?? 0);
    $note = $db->real_escape_string(trim($_POST['note'] ?? ''));
    $by   = $db->real_escape_string($_SESSION['name'] ?? 'unknown');
    $db->query(
        "UPDATE nac_mac_events SET acknowledged=1, ack_by='$by', ack_note='$note', ack_at=NOW()
         WHERE id=$id"
    );
    $db->close();
    exit(json_encode(['ok'=>true]));
}

/* ─── ACK ALL ALARMS FOR SWITCH ─── */
if ($action === 'ack_all_switch') {
    $id = (int)($_POST['switch_id'] ?? 0);
    $by = $db->real_escape_string($_SESSION['name'] ?? 'unknown');
    $db->query(
        "UPDATE nac_mac_events SET acknowledged=1,ack_by='$by',ack_at=NOW()
         WHERE switch_id=$id AND is_alarm=1 AND acknowledged=0"
    );
    $n = $db->affected_rows;
    $db->close();
    exit(json_encode(['ok'=>true,'count'=>$n]));
}

/* ─── GET PLATFORMS ─── */
if ($action === 'get_platforms') {
    $col_chk = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'nac_platforms'
           AND COLUMN_NAME  = 'cmd_vlans'"
    );
    $col_exists = $col_chk && ((int)$col_chk->fetch_row()[0] > 0);
    $col_chk && $col_chk->free();
    if (!$col_exists) {
        $db->query("ALTER TABLE nac_platforms ADD COLUMN cmd_vlans VARCHAR(120) DEFAULT NULL");
    }
    if (function_exists('driver_all_meta')) {
        foreach (driver_all_meta() as $plkey => $meta) {
            $esc_key   = $db->real_escape_string($plkey);
            $esc_vlans = $db->real_escape_string($meta['cmd_vlans'] ?? '');
            $db->query(
                "UPDATE nac_platforms SET cmd_vlans='$esc_vlans'
                 WHERE platform_key='$esc_key' AND (cmd_vlans IS NULL OR cmd_vlans='')"
            );
        }
    }
    $r = $db->query("SELECT * FROM nac_platforms ORDER BY vendor, platform_key");
    $platforms = [];
    if ($r) { while ($row=$r->fetch_assoc()) $platforms[] = $row; $r->free(); }
    $db->close();
    exit(json_encode($platforms));
}

/* ─── MAC HISTORY ─── */
if ($action === 'mac_history') {
    $mac = $db->real_escape_string(strtoupper(trim($_POST['mac'] ?? '')));
    $r   = $db->query(
        "SELECT e.*, ns.hostname AS switch_name
         FROM nac_mac_events e
         LEFT JOIN nac_switches ns ON e.switch_id=ns.id
         WHERE e.mac='$mac'
         ORDER BY e.created_at DESC LIMIT 100"
    );
    $events = [];
    if ($r) { while ($row=$r->fetch_assoc()) $events[] = $row; $r->free(); }
    $db->close();
    exit(json_encode($events));
}

/* ─── TOPOLOGY DATA ─── */
if ($action === 'topology') {
    $switches = [];
    $r = $db->query("SELECT * FROM nac_switches WHERE enabled=1");
    if ($r) { while ($row=$r->fetch_assoc()) $switches[$row['id']] = $row; $r->free(); }
    $links = [];
    $r = $db->query(
        "SELECT l.*, sa.hostname AS name_a, sa.ip AS ip_a,
                sb.hostname AS name_b, sb.ip AS ip_b
         FROM nac_topology_links l
         JOIN nac_switches sa ON l.switch_a_id=sa.id
         JOIN nac_switches sb ON l.switch_b_id=sb.id"
    );
    if ($r) { while ($row=$r->fetch_assoc()) $links[] = $row; $r->free(); }
    $db->close();
    exit(json_encode(['switches'=>array_values($switches),'links'=>$links]));
}

/* ─── DEBUG POLL ─── */
if ($action === 'debug_poll') {
    $id = (int)($_POST['id'] ?? 0);
    $r  = $db->query("SELECT * FROM nac_switches WHERE id=$id");
    if (!$r || $r->num_rows === 0) exit(json_encode(['error'=>'Switch not found']));
    $sw = $r->fetch_assoc(); $r->free();

    $diag = [];
    $diag['switch']  = ['hostname'=>$sw['hostname'],'ip'=>$sw['ip'],
                         'platform'=>$sw['platform_key'],'conn'=>$sw['connection_type']];
    $diag['cred_ok'] = (bool)nac_get_credential($sw['ip']);

    if (($sw['connection_type'] ?? 'ssh') === 'snmp') {
        if (function_exists('nac_snmp_test')) {
            $diag['snmp_test'] = nac_snmp_test(
                $sw['ip'],
                $sw['snmp_community'] ?? 'public',
                (int)($sw['snmp_port'] ?? 161)
            );
        } else {
            $diag['snmp_test'] = ['ok'=>false,'error'=>'SNMP driver not loaded'];
        }
    }

    $driver_dir = __DIR__ . '/drivers/';
    $diag['driver_files'] = is_dir($driver_dir)
        ? array_map('basename', glob($driver_dir . 'driver_*.php'))
        : [];

    $cron_log = '/var/log/janus_nac.log';
    if (file_exists($cron_log) && is_readable($cron_log)) {
        $lines = file($cron_log);
        $diag['cron_log_tail'] = implode('', array_slice($lines, -30));
    } else {
        $diag['cron_log_tail'] = '(log not found at ' . $cron_log . ')';
    }

    $db->close();
    exit(json_encode(['ok' => true, 'diag' => $diag]));
}

/* ─── RAW SSH COMMAND ─── */
if ($action === 'raw_ssh') {
    $id  = (int)($_POST['id'] ?? 0);
    $cmd = trim($_POST['command'] ?? '');
    if (!$cmd) exit(json_encode(['error' => 'No command provided']));

    $r = $db->query("SELECT * FROM nac_switches WHERE id=$id");
    if (!$r || $r->num_rows === 0) exit(json_encode(['error' => 'Switch not found']));
    $sw = $r->fetch_assoc(); $r->free();

    $cred = nac_get_credential($sw['ip']);
    if (!$cred) exit(json_encode(['error' => 'No credentials stored for ' . $sw['ip']]));

    $result = nac_ssh_raw_test($sw['ip'], (int)$sw['ssh_port'], $sw['ssh_user'],
                                $cred['pass'], $cmd, $sw['platform_key']);
    if (isset($result['raw']) && strlen($result['raw']) > 16384) {
        $result['raw'] = substr($result['raw'], 0, 16384) . "\n[truncated]";
    }
    $db->close();
    exit(json_encode($result));
}

/* ─── SERVER DIAGNOSTICS ─── */
if ($action === 'server_diag') {
    $diag = [];
    $diag['php_version']       = PHP_VERSION;
    $diag['php_sapi']          = PHP_SAPI;
    $diag['disable_functions'] = ini_get('disable_functions') ?: '(none)';

    $expect_path = trim(shell_exec('which expect 2>/dev/null') ?: '');
    $expect_ver  = trim(shell_exec('expect -v 2>&1') ?: '');
    $diag['expect_path']    = $expect_path ?: 'NOT FOUND';
    $diag['expect_version'] = $expect_ver  ?: 'NOT FOUND';

    $tmp = tempnam(sys_get_temp_dir(), 'nac_diag_');
    $diag['tmp_writeable'] = $tmp ? 'YES' : 'NO';
    if ($tmp) @unlink($tmp);

    $diag['cred_file_exists']   = file_exists(NAC_CRED_FILE)   ? 'YES' : 'NO';
    $diag['cred_file_readable'] = is_readable(NAC_CRED_FILE)   ? 'YES' : 'NO';

    $r = $db->query("SELECT id,hostname,ip,platform_key,poll_status,poll_error,last_polled FROM nac_switches");
    $diag['switches'] = [];
    if ($r) { while ($row=$r->fetch_assoc()) $diag['switches'][] = $row; $r->free(); }

    $r = $db->query("SELECT platform_key,cmd_mac_table,cmd_interfaces,cmd_lldp FROM nac_platforms");
    $diag['platforms'] = [];
    if ($r) { while ($row=$r->fetch_assoc()) $diag['platforms'][] = $row; $r->free(); }

    $driver_dir = __DIR__ . '/drivers/';
    $diag['driver_files'] = is_dir($driver_dir)
        ? array_map('basename', glob($driver_dir . 'driver_*.php'))
        : [];

    $cron_log = '/var/log/janus_nac.log';
    if (file_exists($cron_log) && is_readable($cron_log)) {
        $lines = file($cron_log);
        $diag['cron_log_tail'] = implode('', array_slice($lines, -30));
    } else {
        $diag['cron_log_tail'] = '(log not found at ' . $cron_log . ')';
    }

    $db->close();
    exit(json_encode(['ok' => true, 'diag' => $diag]));
}

/* ─── SNMP TEST ─── */
if ($action === 'snmp_test') {
    $ip         = trim($_POST['ip']         ?? '');
    $community  = trim($_POST['community']  ?? 'public');
    $snmp_port  = (int)($_POST['snmp_port'] ?? 161);
    if (!$ip) exit(json_encode(['error' => 'IP required']));
    if (!function_exists('nac_snmp_test')) {
        exit(json_encode(['error' => 'SNMP driver not loaded — check driver_registry.php includes driver_snmp.php']));
    }
    $db->close();
    exit(json_encode(nac_snmp_test($ip, $community, $snmp_port)));
}

/* ─── MAC IPAM ALERTS ─── */
if ($action === 'mac_ipam_alerts') {
    $mac = strtoupper(str_replace(['-','.',' '], ':', trim($_POST['mac'] ?? '')));
    $esc = $db->real_escape_string($mac);

    $ipam_row = null;
    $r = $db->query(
        "SELECT * FROM janus_ipam
         WHERE UPPER(REPLACE(REPLACE(mac,'-',':'),'.',':')) = '$esc'
         LIMIT 1"
    );
    if ($r && $row = $r->fetch_assoc()) { $ipam_row = $row; $r->free(); }

    $alerts = [];
    if ($ipam_row) {
        $esc_ip = $db->real_escape_string($ipam_row['ip']);
        $r2 = $db->query(
            "SELECT *,
              CASE
                WHEN note LIKE '%spoof%'        THEN 'spoofing'
                WHEN note LIKE '%MAC%' AND note LIKE '%change%' THEN 'mac_change'
                WHEN note LIKE '%offline%'      THEN 'offline'
                WHEN note LIKE '%online%'       THEN 'online'
                WHEN note LIKE '%New device%'   THEN 'new'
                ELSE 'info'
              END AS alert_type
             FROM janus_ipam_log
             WHERE ip = '$esc_ip'
             ORDER BY changed_at DESC LIMIT 50"
        );
        if ($r2) { while ($row = $r2->fetch_assoc()) $alerts[] = $row; $r2->free(); }
    }

    $db->close();
    exit(json_encode(['ipam' => $ipam_row, 'alerts' => $alerts]));
}

/* ─── NAT/VIP MAP — LIST ─── */
if ($action === 'nat_list') {
    $r = $db->query("SELECT * FROM ipam_nat_map ORDER BY vip, nat_type");
    $rows = [];
    if ($r) { while ($row=$r->fetch_assoc()) $rows[] = $row; $r->free(); }
    $db->close();
    exit(json_encode($rows));
}

/* ─── NAT/VIP MAP — SAVE ─── */
if ($action === 'nat_save') {
    $id      = (int)($_POST['id'] ?? 0);
    $vip     = $db->real_escape_string(trim($_POST['vip']         ?? ''));
    $real_ip = $db->real_escape_string(trim($_POST['real_ip']     ?? ''));
    $nat_type= $db->real_escape_string(trim($_POST['nat_type']    ?? 'dnat'));
    $desc    = $db->real_escape_string(trim($_POST['description'] ?? ''));
    if (!$vip || !$real_ip) exit(json_encode(['error'=>'vip and real_ip required']));
    if ($id > 0) {
        $db->query("UPDATE ipam_nat_map SET vip='$vip',real_ip='$real_ip',nat_type='$nat_type',description='$desc',updated_at=NOW() WHERE id=$id");
    } else {
        $db->query("INSERT INTO ipam_nat_map (vip,real_ip,nat_type,description,created_at,updated_at) VALUES ('$vip','$real_ip','$nat_type','$desc',NOW(),NOW())");
        $id = $db->insert_id;
    }
    $db->close();
    exit(json_encode(['ok'=>true,'id'=>$id]));
}

/* ─── NAT/VIP MAP — DELETE ─── */
if ($action === 'nat_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) $db->query("DELETE FROM ipam_nat_map WHERE id=$id");
    $db->close();
    exit(json_encode(['ok'=>true]));
}

/* ─────────────────────────────────────────────────────────────
   GROUP MANAGEMENT
───────────────────────────────────────────────────────────── */

function _nac_ensure_groups_table(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS nac_switch_groups (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(80)  NOT NULL,
        color      VARCHAR(10)  NOT NULL DEFAULT '#58a6ff',
        notes      TEXT,
        sort_order INT          NOT NULL DEFAULT 0,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_switches' AND COLUMN_NAME='group_id'");
    if ($chk && (int)$chk->fetch_row()[0] === 0) {
        $db->query("ALTER TABLE nac_switches ADD COLUMN group_id INT DEFAULT NULL AFTER id");
    }
    $chk && $chk->free();
}

/* ─── GROUP LIST ─── */
if ($action === 'group_list') {
    _nac_ensure_groups_table($db);
    $r = $db->query(
        "SELECT g.*, COUNT(s.id) AS switch_count
         FROM nac_switch_groups g
         LEFT JOIN nac_switches s ON s.group_id = g.id
         GROUP BY g.id ORDER BY g.sort_order, g.name"
    );
    $groups = [];
    if ($r) { while ($row = $r->fetch_assoc()) $groups[] = $row; $r->free(); }
    $db->close();
    exit(json_encode($groups));
}

/* ─── GROUP SAVE ─── */
if ($action === 'group_save') {
    _nac_ensure_groups_table($db);
    $id    = (int)($_POST['id']         ?? 0);
    $name  = trim($_POST['name']        ?? '');
    $color = trim($_POST['color']       ?? '#58a6ff');
    $notes = trim($_POST['notes']       ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if (!$name) exit(json_encode(['error' => 'Group name required']));
    if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) $color = '#58a6ff';
    if ($id > 0) {
        $s = $db->prepare("UPDATE nac_switch_groups SET name=?,color=?,notes=?,sort_order=? WHERE id=?");
        $s->bind_param('sssii', $name, $color, $notes, $sort, $id);
    } else {
        $s = $db->prepare("INSERT INTO nac_switch_groups (name,color,notes,sort_order) VALUES (?,?,?,?)");
        $s->bind_param('sssi', $name, $color, $notes, $sort);
    }
    $s->execute(); $err=$s->error; $new_id=$db->insert_id; $s->close(); $db->close();
    exit($err ? json_encode(['error'=>$err]) : json_encode(['ok'=>true,'id'=>$id?:$new_id]));
}

/* ─── GROUP DELETE ─── */
if ($action === 'group_delete') {
    _nac_ensure_groups_table($db);
    $id = (int)($_POST['id'] ?? 0);
    $db->query("UPDATE nac_switches SET group_id=NULL WHERE group_id=$id");
    $db->query("DELETE FROM nac_switch_groups WHERE id=$id");
    $db->close();
    exit(json_encode(['ok'=>true]));
}

/* ─── GROUP ASSIGN ─── */
if ($action === 'group_assign') {
    _nac_ensure_groups_table($db);
    $switch_id = (int)($_POST['switch_id'] ?? 0);
    $group_id  = (int)($_POST['group_id']  ?? 0);
    if ($group_id > 0) {
        $db->query("UPDATE nac_switches SET group_id=$group_id WHERE id=$switch_id");
    } else {
        $db->query("UPDATE nac_switches SET group_id=NULL WHERE id=$switch_id");
    }
    $db->close();
    exit(json_encode(['ok'=>true]));
}

/* ─── POLL GROUP ─── */
if ($action === 'poll_group') {
    set_time_limit(0); ignore_user_abort(true);
    $group_id = (int)($_POST['group_id'] ?? 0);
    if (!$group_id) exit(json_encode(['error'=>'No group_id']));
    $r = $db->query("SELECT * FROM nac_switches WHERE enabled=1 AND group_id=$group_id ORDER BY id");
    $results = [];
    if ($r) {
        while ($sw = $r->fetch_assoc()) {
            $result = nac_poll_switch($db, $sw);
            $results[] = ['switch'=>$sw['hostname'],'ip'=>$sw['ip']] + $result;
        }
        $r->free();
    }
    $db->close();
    exit(json_encode(['ok'=>true,'results'=>$results]));
}

/* ─── PORT BANDWIDTH ─── */
if ($action === 'port_bw') {
    $switch_id = (int)($_POST['switch_id'] ?? 0);
    $port_name = trim($_POST['port_name'] ?? '');
    if (!$switch_id || !$port_name) exit(json_encode(['error' => 'switch_id and port_name required']));

    // Check if nac_port_bw table exists
    $tbl = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_port_bw'");
    if (!$tbl || (int)$tbl->fetch_row()[0] === 0) {
        $db->close();
        exit(json_encode(['ready' => false, 'message' => 'BW poller not yet run — nac_port_bw table missing']));
    }
    $tbl->free();

    // Fetch the two most recent snapshots for this switch+port
    $s = $db->prepare(
        "SELECT in_octets, out_octets, in_errors, out_errors, speed_bps, polled_at
         FROM nac_port_bw
         WHERE switch_id=? AND port_name=?
         ORDER BY polled_at DESC LIMIT 2"
    );
    $s->bind_param('is', $switch_id, $port_name);
    $s->execute();
    $result = $s->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $s->close();
    $db->close();

    if (count($rows) < 2) {
        exit(json_encode(['ready' => false,
            'message' => count($rows) === 0
                ? 'No data yet — SNMP poller has not run for this port'
                : 'Only 1 sample — waiting for second poll (runs every 2 min)']));
    }

    $now  = $rows[0];
    $prev = $rows[1];

    // Seconds elapsed between polls
    $elapsed = max(1, strtotime($now['polled_at']) - strtotime($prev['polled_at']));

    // 64-bit counter wrap-around safe delta
    $delta_in  = ($now['in_octets']  >= $prev['in_octets'])
        ? ($now['in_octets']  - $prev['in_octets'])
        : (PHP_INT_MAX - $prev['in_octets'] + $now['in_octets']);  // wrapped
    $delta_out = ($now['out_octets'] >= $prev['out_octets'])
        ? ($now['out_octets'] - $prev['out_octets'])
        : (PHP_INT_MAX - $prev['out_octets'] + $now['out_octets']);

    $in_bps  = ($delta_in  * 8) / $elapsed;
    $out_bps = ($delta_out * 8) / $elapsed;
    $speed   = (int)$now['speed_bps'];

    // Utilisation (only if speed known)
    $util_in = $util_out = null;
    if ($speed > 0) {
        $util_in  = round(($in_bps  / $speed) * 100, 1);
        $util_out = round(($out_bps / $speed) * 100, 1);
    }

    // Human-readable helper
    $fmt = function(float $bps): string {
        if ($bps >= 1e9)  return round($bps / 1e9,  2) . ' Gbps';
        if ($bps >= 1e6)  return round($bps / 1e6,  2) . ' Mbps';
        if ($bps >= 1e3)  return round($bps / 1e3,  1) . ' Kbps';
        return round($bps) . ' bps';
    };

    exit(json_encode([
        'ready'       => true,
        'in_bps'      => round($in_bps),
        'out_bps'     => round($out_bps),
        'in_human'    => $fmt($in_bps),
        'out_human'   => $fmt($out_bps),
        'speed_bps'   => $speed,
        'speed_human' => $speed > 0 ? $fmt($speed) : null,
        'util_in'     => $util_in,
        'util_out'    => $util_out,
        'in_errors'   => (int)$now['in_errors'],
        'out_errors'  => (int)$now['out_errors'],
        'polled_at'   => $now['polled_at'],
        'elapsed_sec' => $elapsed,
    ]));
}

$db->close();
exit(json_encode(['error' => 'Unknown action: ' . $action]));
