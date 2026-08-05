<?php
/**
 * modules/printers/printers_ajax.php
 * Janus Printer Fleet AJAX Backend API
 */
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../auth_check.php';

header('Content-Type: application/json');

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    exit(json_encode(['error' => 'DB Connection failed: ' . $db->connect_error]));
}
$db->set_charset('utf8mb4');

// Auto-migrate janus_printers table
$db->query("CREATE TABLE IF NOT EXISTS janus_printers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL UNIQUE,
    vendor VARCHAR(50) DEFAULT 'HP',
    model VARCHAR(100) DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    snmp_community VARCHAR(100) DEFAULT 'public',
    snmp_version VARCHAR(5) DEFAULT '2c',
    snmp_port INT DEFAULT 161,
    status VARCHAR(20) DEFAULT 'online',
    last_status_msg TEXT DEFAULT NULL,
    toner_level INT DEFAULT 100,
    paper_status VARCHAR(50) DEFAULT 'OK',
    last_polled DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ─── LIST PRINTERS ─── */
if ($action === 'list') {
    $r = $db->query("
        SELECT p.*,
               (SELECT COUNT(*) 
                FROM syslog_entries se 
                LEFT JOIN syslog_sources ss ON se.source_id = ss.id 
                WHERE se.source_ip = p.ip 
                  AND (IFNULL(se.appliance_type, '') != 'firewall' AND IFNULL(ss.appliance_type, '') != 'firewall')
               ) AS total_events,
               (SELECT COUNT(*) 
                FROM syslog_entries se 
                LEFT JOIN syslog_sources ss ON se.source_id = ss.id 
                WHERE se.source_ip = p.ip 
                  AND (IFNULL(se.appliance_type, '') != 'firewall' AND IFNULL(ss.appliance_type, '') != 'firewall')
                  AND (se.severity <= 3 OR se.message LIKE '%jam%' OR se.message LIKE '%error%')
               ) AS alert_count
        FROM janus_printers p
        ORDER BY p.name ASC
    ");
    $printers = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $printers[] = $row;
        }
        $r->free();
    }
    
    // Summary Stats
    $total = count($printers);
    $online = 0; $jams = 0; $low_toner = 0;
    foreach ($printers as $p) {
        if ($p['status'] === 'online') $online++;
        if ($p['status'] === 'paper_jam' || str_contains(strtolower($p['last_status_msg'] ?? ''), 'jam')) $jams++;
        if ((int)$p['toner_level'] < 20 || str_contains(strtolower($p['last_status_msg'] ?? ''), 'toner')) $low_toner++;
    }

    $db->close();
    exit(json_encode([
        'ok' => true,
        'stats' => [
            'total' => $total,
            'online' => $online,
            'jams' => $jams,
            'low_toner' => $low_toner
        ],
        'printers' => $printers
    ]));
}

/* ─── ADD PRINTER ─── */
if ($action === 'add') {
    $name      = trim($_POST['name'] ?? '');
    $ip        = trim($_POST['ip'] ?? '');
    $vendor    = trim($_POST['vendor'] ?? 'HP');
    $model     = trim($_POST['model'] ?? '');
    $location  = trim($_POST['location'] ?? '');
    $community = trim($_POST['snmp_community'] ?? 'public');
    $version   = trim($_POST['snmp_version'] ?? '2c');
    $port      = (int)($_POST['snmp_port'] ?? 161);

    if (!$name || !$ip) {
        exit(json_encode(['error' => 'Printer Name and IP Address are required']));
    }

    $stmt = $db->prepare("INSERT INTO janus_printers (name, ip, vendor, model, location, snmp_community, snmp_version, snmp_port, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'online')");
    $stmt->bind_param('sssssssi', $name, $ip, $vendor, $model, $location, $community, $version, $port);
    
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $db->close();
        exit(json_encode(['error' => 'Failed to add printer: ' . $err]));
    }
    $stmt->close();

    // Register IP in syslog_sources so traps & syslogs link properly
    $esc_ip = $db->real_escape_string($ip);
    $db->query("INSERT INTO syslog_sources (appliance_type, source_ip, is_active, added_by) VALUES ('printer', '$esc_ip', 1, 1) ON DUPLICATE KEY UPDATE appliance_type='printer', is_active=1");

    $db->close();
    exit(json_encode(['ok' => true, 'message' => "Printer '$name' ($ip) added successfully!"]));
}

/* ─── EDIT PRINTER ─── */
if ($action === 'edit') {
    $id        = (int)($_POST['id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $ip        = trim($_POST['ip'] ?? '');
    $vendor    = trim($_POST['vendor'] ?? 'HP');
    $model     = trim($_POST['model'] ?? '');
    $location  = trim($_POST['location'] ?? '');
    $community = trim($_POST['snmp_community'] ?? 'public');
    $version   = trim($_POST['snmp_version'] ?? '2c');
    $port      = (int)($_POST['snmp_port'] ?? 161);

    if (!$id || !$name || !$ip) {
        exit(json_encode(['error' => 'Valid ID, Name and IP required']));
    }

    $stmt = $db->prepare("UPDATE janus_printers SET name=?, ip=?, vendor=?, model=?, location=?, snmp_community=?, snmp_version=?, snmp_port=? WHERE id=?");
    $stmt->bind_param('sssssssii', $name, $ip, $vendor, $model, $location, $community, $version, $port, $id);
    $stmt->execute();
    $stmt->close();

    $esc_ip = $db->real_escape_string($ip);
    $db->query("INSERT INTO syslog_sources (appliance_type, source_ip, is_active, added_by) VALUES ('printer', '$esc_ip', 1, 1) ON DUPLICATE KEY UPDATE appliance_type='printer', is_active=1");

    $db->close();
    exit(json_encode(['ok' => true, 'message' => "Printer '$name' updated successfully!"]));
}

/* ─── DELETE PRINTER ─── */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) exit(json_encode(['error' => 'Printer ID required']));

    $r = $db->query("SELECT ip FROM janus_printers WHERE id=$id");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        $del_ip = $db->real_escape_string($row['ip']);
        $db->query("DELETE FROM syslog_sources WHERE source_ip='$del_ip' AND appliance_type='printer'");
        $r->free();
    }

    $db->query("DELETE FROM janus_printers WHERE id=$id");
    $db->close();
    exit(json_encode(['ok' => true]));
}

/* ─── PRINTER LOGS / TRAPS ─── */
if ($action === 'logs') {
    $ip = trim($_POST['ip'] ?? $_GET['ip'] ?? '');
    if (!$ip) exit(json_encode(['error' => 'Printer IP required']));

    $esc_ip = $db->real_escape_string($ip);

    // Ensure the requested IP is registered as a printer
    $check = $db->query("SELECT id FROM janus_printers WHERE ip='$esc_ip'");
    if (!$check || $check->num_rows === 0) {
        $db->close();
        exit(json_encode(['ok' => false, 'error' => "IP $ip is not registered as a printer."]));
    }
    $check->free();

    $r = $db->query("
        SELECT se.id, se.source_ip, se.facility, se.severity, se.action, se.message, se.raw, se.received_at
        FROM syslog_entries se
        LEFT JOIN syslog_sources ss ON se.source_id = ss.id
        WHERE se.source_ip='$esc_ip'
          AND (IFNULL(se.appliance_type, '') != 'firewall' AND IFNULL(ss.appliance_type, '') != 'firewall')
        ORDER BY se.received_at DESC LIMIT 100
    ");
    $logs = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $logs[] = $row;
        }
        $r->free();
    }
    $db->close();
    exit(json_encode(['ok' => true, 'ip' => $ip, 'logs' => $logs]));
}

/* ─── POLL PRINTER (SNMP Walk / Ping) ─── */
if ($action === 'poll') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) exit(json_encode(['error' => 'Printer ID required']));

    $r = $db->query("SELECT * FROM janus_printers WHERE id=$id");
    if (!$r || $r->num_rows === 0) exit(json_encode(['error' => 'Printer not found']));
    $p = $r->fetch_assoc(); $r->free();

    $ip = $p['ip'];
    $community = $p['snmp_community'];

    // Ping check
    exec("ping -c 1 -w 2 " . escapeshellarg($ip), $out, $res);
    $is_online = ($res === 0);

    $status = $is_online ? 'online' : 'offline';
    $msg = $is_online ? 'Ping OK (Online)' : 'Printer Unreachable (Offline)';
    $toner = $p['toner_level'];

    // Query SNMP if online and extension available
    if ($is_online && function_exists('snmp2_get')) {
        try {
            // SNMP MIB-II Printer OIDs
            // .1.3.6.1.2.1.43.11.1.1.9.1.1 = Toner Level Remaining %
            // .1.3.6.1.2.1.43.16.5.1.2.1.1 = Display status text
            $toner_val = @snmp2_get($ip, $community, '1.3.6.1.2.1.43.11.1.1.9.1.1', 1000000);
            if ($toner_val !== false) {
                $toner = (int)preg_replace('/[^0-9]/', '', $toner_val);
                if ($toner > 100) $toner = 100;
            }

            $disp_val = @snmp2_get($ip, $community, '1.3.6.1.2.1.43.16.5.1.2.1.1', 1000000);
            if ($disp_val !== false) {
                $msg = trim(str_replace('STRING:', '', $disp_val));
                if (str_contains(strtolower($msg), 'jam')) $status = 'paper_jam';
                elseif (str_contains(strtolower($msg), 'toner')) $status = 'toner_low';
            }
        } catch (Throwable $e) {
            // Keep ping status
        }
    }

    $esc_msg = $db->real_escape_string($msg);
    $db->query("UPDATE janus_printers SET status='$status', last_status_msg='$esc_msg', toner_level=$toner, last_polled=NOW() WHERE id=$id");

    $db->close();
    exit(json_encode([
        'ok' => true,
        'status' => $status,
        'msg' => $msg,
        'toner_level' => $toner
    ]));
}

$db->close();
exit(json_encode(['error' => 'Invalid action']));
