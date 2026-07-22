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

// Ensure clean tables exist
mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS responder_firewalls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_ip VARCHAR(45) UNIQUE,
        api_key VARCHAR(255),
        username VARCHAR(100),
        is_active TINYINT(1) DEFAULT 1,
        added_by INT,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS responder_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_ip VARCHAR(45),
        action_type VARCHAR(50),
        target_ip VARCHAR(45),
        details TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        executed_at TIMESTAMP NULL
    ) ENGINE=InnoDB
");

// ========== HANDLE GET REQUESTS ==========

$firewalls = [];
$res = mysqli_query($con, "SELECT firewall_ip, username, is_active, added_at FROM responder_firewalls ORDER BY added_at DESC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $firewalls[] = $r;
    }
}

$actions = [];
$res = mysqli_query($con, "
    SELECT ra.id, ra.firewall_ip, ra.action_type, ra.target_ip, ra.status, ra.created_at, a.username 
    FROM responder_actions ra 
    JOIN accounts a ON ra.created_by = a.id 
    ORDER BY ra.created_at DESC 
    LIMIT 20
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $actions[] = $r;
    }
}

echo json_encode([
    'firewalls' => $firewalls,
    'actions' => $actions
]);
