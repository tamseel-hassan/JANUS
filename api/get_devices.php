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

// GET request for fetching all devices and links

// GET request for fetching all devices and links
$devices = [];
$res = mysqli_query($con, "SELECT * FROM devices ORDER BY type ASC, name ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $devices[] = $row;
}

$links = [];
$res = mysqli_query($con, "SELECT l.*, df.name AS from_name, dt.name AS to_name FROM links l LEFT JOIN devices df ON l.from_device_id = df.id LEFT JOIN devices dt ON l.to_device_id = dt.id ORDER BY l.name ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $links[] = $row;
}

echo json_encode([
    'devices' => $devices,
    'links' => $links,
    'models' => [
        'server'   => ['Dell PowerEdge', 'HP ProLiant', 'Hypervisor (VMware)', 'Hypervisor (Hyper-V)', 'Virtual Machine', 'Container Host', 'Other'],
        'switch'   => ['Cisco Catalyst 2960', 'Cisco Catalyst 3750', 'Cisco Nexus', 'HP ProCurve 2530', 'HP Aruba 2930F', 'Juniper EX2300', 'Juniper EX4300', 'Other'],
        'firewall' => ['Cisco ASA 5506', 'Cisco ASA 5516', 'Palo Alto PA-220', 'Palo Alto PA-850', 'FortiGate 60E', 'FortiGate 100E', 'FortiGate 200E', 'pfSense', 'OPNsense', 'Other'],
        'router'   => ['Cisco ISR 4331', 'Cisco ISR 4451', 'Cisco ASR 1001', 'Juniper MX204', 'Juniper MX480', 'MikroTik CCR1036', 'MikroTik RB4011', 'Ubiquiti EdgeRouter', 'Other'],
        'iot'      => ['IP Camera', 'Access Point', 'Smart Sensor', 'Environmental Monitor', 'UPS System', 'PDU', 'KVM Switch', 'Other'],
        'wireless' => ['Cisco Aironet', 'Aruba AP-515', 'Ubiquiti UniFi AP', 'Ruckus R650', 'Other'],
        'loadbalancer' => ['F5 BIG-IP', 'Citrix ADC', 'HAProxy', 'NGINX Plus', 'Other'],
        'storage'  => ['NetApp FAS', 'Dell EMC Unity', 'HPE 3PAR', 'Synology NAS', 'QNAP NAS', 'Other'],
        'others'   => ['Other']
    ]
]);
exit;
