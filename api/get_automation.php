<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth_check.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$tablesExist = $con->query("SHOW TABLES LIKE 'responder_playbooks'")->num_rows > 0;
$needsInstall = !$tablesExist;

$totalPlaybooks = 0;
$enabledPlaybooks = 0;
$totalExecutions = 0;
$failedExecutions = 0;
$totalBlocked = 0;
$activeBlocked = 0;
$recentExecs = [];

if ($tablesExist) {
    $totalPlaybooks = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_playbooks"))['c'] ?? 0);
    $enabledPlaybooks = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_playbooks WHERE enabled = 1"))['c'] ?? 0);
    
    if ($con->query("SHOW TABLES LIKE 'responder_executions'")->num_rows > 0) {
        $totalExecutions = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_executions"))['c'] ?? 0);
        $failedExecutions = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_executions WHERE status = 'failed'"))['c'] ?? 0);
        
        $res = $con->query("SELECT pe.*, p.name as pb_name FROM responder_executions pe LEFT JOIN responder_playbooks p ON pe.playbook_id = p.id ORDER BY pe.created_at DESC LIMIT 10");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $recentExecs[] = $row;
            }
        }
    }

    if ($con->query("SHOW TABLES LIKE 'responder_blocked_ips'")->num_rows > 0) {
        $totalBlocked = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_blocked_ips"))['c'] ?? 0);
        $activeBlocked = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_blocked_ips WHERE expires_at IS NULL OR expires_at > NOW()"))['c'] ?? 0);
    }
}

echo json_encode([
    'needsInstall' => $needsInstall,
    'stats' => [
        'totalPlaybooks' => $totalPlaybooks,
        'enabledPlaybooks' => $enabledPlaybooks,
        'totalExecutions' => $totalExecutions,
        'failedExecutions' => $failedExecutions,
        'totalBlocked' => $totalBlocked,
        'activeBlocked' => $activeBlocked,
    ],
    'recentExecutions' => $recentExecs
]);

mysqli_close($con);
