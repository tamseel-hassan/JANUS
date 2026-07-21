<?php
/**
 * api/get_ipam.php - Next.js endpoint for IPAM read operations
 * Replaces legacy ipam_ajax.php 'load' and 'subnets_load'
 */
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../modules/ipam/ipam_lib.php';

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

try {
    $db = ipam_db();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? 'load';

if ($action === 'load') {
    // 1. Fetch IPs with their latest alert
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
    
    $res = $db->query($sql);
    if (!$res) { 
        http_response_code(500);
        echo json_encode(['error' => $db->error]); 
        exit; 
    }
    
    $ips = [];
    while ($row = $res->fetch_assoc()) {
        $raw = $row['alert_raw'] ?? '';
        if ($raw) {
            if (stripos($raw,'spoof') !== false) $row['alert_type'] = 'spoofing';
            elseif (stripos($raw,'MAC') !== false && stripos($raw,'change') !== false) $row['alert_type'] = 'mac_change';
            elseif (stripos($raw,'offline') !== false) $row['alert_type'] = 'offline';
            elseif (stripos($raw,'online') !== false) $row['alert_type'] = 'online';
            elseif (stripos($raw,'New device') !== false) $row['alert_type'] = 'new';
            else $row['alert_type'] = 'info';
        } else {
            $row['alert_type'] = null;
        }
        $row['acknowledged'] = (bool)$row['acknowledged'];
        unset($row['alert_raw']);
        $ips[] = $row;
    }
    $res->free();

    // 2. Fetch Subnets
    $subnets = [];
    $s_res = $db->query("SELECT * FROM ipam_subnets ORDER BY id");
    if ($s_res) {
        while ($s_row = $s_res->fetch_assoc()) {
            $subnets[] = $s_row;
        }
        $s_res->free();
    }

    // Auto-detect interfaces and count devices (from legacy logic)
    $all_devices = [];
    $all_res = $db->query("SELECT ip, status FROM janus_ipam");
    if ($all_res) {
        while ($row = $all_res->fetch_assoc()) {
            $all_devices[] = $row;
        }
        $all_res->free();
    }

    foreach ($subnets as &$s) {
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

        // Auto-detect interface via routing table
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

    // 3. Fetch NAT mappings
    $mappings = [];
    $m_res = $db->query("SELECT * FROM ipam_nat_map ORDER BY id DESC");
    if ($m_res) {
        while ($m_row = $m_res->fetch_assoc()) {
            $mappings[] = $m_row;
        }
        $m_res->free();
    }

    echo json_encode([
        'ips' => $ips,
        'subnets' => $subnets,
        'mappings' => $mappings
    ]);
    
} elseif ($action === 'ip_history') {
    $ip = trim($_GET['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Invalid IP']);
        exit;
    }
    $stmt = $db->prepare("SELECT id, ip, old_mac, new_mac, note, acknowledged, acknowledged_by, ack_note, acknowledged_at, changed_at FROM janus_ipam_log WHERE ip = ? ORDER BY changed_at DESC LIMIT 200");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    echo json_encode($rows);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}

$db->close();
