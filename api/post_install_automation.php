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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// 1. responder_playbooks
$q1 = "CREATE TABLE IF NOT EXISTS responder_playbooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    trigger_type VARCHAR(100),
    conditions JSON,
    actions JSON,
    enabled TINYINT(1) DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB";

// 2. responder_executions
$q2 = "CREATE TABLE IF NOT EXISTS responder_executions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    playbook_id INT,
    triggered_by VARCHAR(255),
    trigger_data JSON,
    status VARCHAR(50) DEFAULT 'running',
    logs JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL
) ENGINE=InnoDB";

// 3. responder_blocked_ips
$q3 = "CREATE TABLE IF NOT EXISTS responder_blocked_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    reason TEXT,
    source_playbook_id INT,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

$success = true;
$errors = [];

if (!mysqli_query($con, $q1)) {
    $success = false;
    $errors[] = "Failed to create responder_playbooks: " . mysqli_error($con);
}

if (!mysqli_query($con, $q2)) {
    $success = false;
    $errors[] = "Failed to create responder_executions: " . mysqli_error($con);
}

if (!mysqli_query($con, $q3)) {
    $success = false;
    $errors[] = "Failed to create responder_blocked_ips: " . mysqli_error($con);
}

if ($success) {
    echo json_encode(['success' => 'Automation database tables installed successfully']);
} else {
    echo json_encode(['error' => 'Installation failed', 'details' => $errors]);
}

mysqli_close($con);
