<?php
require_once __DIR__ . '/../db_config.php';
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Device list
$dl = []; 
$lr = mysqli_query($con, "SELECT id, name, ip FROM devices WHERE snmp_community IS NOT NULL AND snmp_community != '' ORDER BY name");
while ($r = mysqli_fetch_assoc($lr)) {
    $dl[$r['id']] = $r;
}

$sid = $_GET['device'] ?? null;
$all = empty($sid) || !isset($dl[$sid]);
$wh = $all ? "WHERE d.snmp_community IS NOT NULL AND d.snmp_community != ''" : "WHERE d.id = ".(int)$sid;

$dq = "
    SELECT d.id, d.name, d.ip, d.type, d.model,
           COALESCE(pl.status,'down') ping_status,
           sm.cpu_usage, sm.memory_total, sm.memory_used, sm.memory_free,
           sm.disk_total, sm.disk_used, sm.disk_free,
           sm.load_1min, sm.load_5min, sm.load_15min, sm.processes,
           sm.checked_at as last_snmp_check,
           pl.checked_at as last_ping_check
    FROM devices d
    LEFT JOIN (
        SELECT device_id, status, checked_at,
               ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn
        FROM ping_logs WHERE link_id IS NULL
    ) pl ON d.id = pl.device_id AND pl.rn = 1
    LEFT JOIN (
        SELECT device_id, cpu_usage, memory_total, memory_used, memory_free,
               disk_total, disk_used, disk_free, load_1min, load_5min, load_15min,
               processes, checked_at,
               ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn
        FROM snmp_metrics
    ) sm ON d.id = sm.device_id AND sm.rn = 1
    $wh ORDER BY d.name
";

$devs = []; 
$res = mysqli_query($con, $dq);
while ($r = mysqli_fetch_assoc($res)) {
    // Decode Hex-STRING in model
    $s = $r['model'];
    if (strpos($s, 'Hex-STRING:') !== false) {
        preg_match('/Hex-STRING:\s*([0-9A-Fa-f\s]+)/', $s, $m);
        $hex = preg_split('/\s+/', trim($m[1]));
        $decoded = '';
        foreach ($hex as $h) {
            if (ctype_xdigit($h)) $decoded .= chr(hexdec($h));
        }
        $r['model'] = trim($decoded, " \t\n\r\0\x0B\x00") ?: $s;
    }
    
    // cast numbers
    $r['cpu_usage'] = $r['cpu_usage'] !== null ? floatval($r['cpu_usage']) : null;
    $r['memory_total'] = $r['memory_total'] !== null ? floatval($r['memory_total']) : null;
    $r['memory_used'] = $r['memory_used'] !== null ? floatval($r['memory_used']) : null;
    $r['disk_total'] = $r['disk_total'] !== null ? floatval($r['disk_total']) : null;
    $r['disk_used'] = $r['disk_used'] !== null ? floatval($r['disk_used']) : null;
    
    $devs[] = $r;
}

$ifstats = []; 
$ids = array_column($devs, 'id');
if ($ids) {
    $ids = implode(',', array_map('intval', $ids));
    $iq = "
        SELECT it.device_id, it.if_index, it.if_name,
               it.bytes_in, it.bytes_out, it.traffic_in_bps, it.traffic_out_bps,
               it.checked_at, d.name as device_name, d.ip as device_ip,
               si.if_speed, si.if_descr
        FROM interface_traffic it
        JOIN devices d ON it.device_id = d.id
        LEFT JOIN snmp_interfaces si ON it.device_id = si.device_id AND it.if_index = si.if_index
        WHERE it.device_id IN ($ids)
          AND it.checked_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
          AND it.id IN (
              SELECT MAX(id) FROM interface_traffic
              WHERE device_id = it.device_id AND if_index = it.if_index
              GROUP BY device_id, if_index
          )
        ORDER BY it.device_id, it.if_index
    ";
    $ir = mysqli_query($con, $iq);
    while ($r = mysqli_fetch_assoc($ir)) {
        // cast bps
        $r['traffic_in_bps'] = floatval($r['traffic_in_bps']);
        $r['traffic_out_bps'] = floatval($r['traffic_out_bps']);
        $ifstats[$r['device_id']][] = $r;
    }
}

// Append ifstats to devices
foreach ($devs as &$d) {
    $d['interfaces'] = $ifstats[$d['id']] ?? [];
}

// Calculate Totals
$sw = 0; 
$tcpu = 0; 
$tmem = 0; 
$tdisk = 0;
$tin = 0; 
$tout = 0; 
$tif = 0;

foreach ($devs as $d) {
    if ($d['cpu_usage'] !== null) {
        $sw++;
        $tcpu += $d['cpu_usage'];
        if ($d['memory_total'] > 0) $tmem += ($d['memory_used'] / $d['memory_total']) * 100;
        if ($d['disk_total'] > 0)   $tdisk += ($d['disk_used'] / $d['disk_total']) * 100;
    }
    foreach ($d['interfaces'] as $i) {
        $tif++;
        $tin += $i['traffic_in_bps'];
        $tout += $i['traffic_out_bps'];
    }
}

$totals = [
    'devices' => count($devs),
    'monitored_devices' => $sw,
    'interfaces' => $tif,
    'avg_cpu' => $sw > 0 ? round($tcpu / $sw, 1) : 0,
    'avg_mem' => $sw > 0 ? round($tmem / $sw, 1) : 0,
    'avg_disk' => $sw > 0 ? round($tdisk / $sw, 1) : 0,
    'traffic_in_bps' => $tin,
    'traffic_out_bps' => $tout
];

echo json_encode([
    'devices' => $devs,
    'totals' => $totals,
    'all_devices_list' => array_values($dl)
]);
exit;
