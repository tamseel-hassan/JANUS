<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    echo json_encode(['error' => 'DB Connection Failed']);
    http_response_code(500);
    exit;
}

// Function helper
function db_fetch($sql, $params = []) {
    global $con;
    $stmt = mysqli_prepare($con, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $data;
}

// Topbar Stats
$totalDevices = db_fetch("SELECT COUNT(*) AS c FROM devices")['c'] ?? 0;
$upDevices = db_fetch("SELECT COUNT(*) AS c FROM devices d LEFT JOIN (SELECT device_id, status, ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn FROM ping_logs WHERE link_id IS NULL) pl ON d.id = pl.device_id AND pl.rn = 1 WHERE COALESCE(pl.status,'down')='up'")['c'] ?? 0;
$downDevices = $totalDevices - $upDevices;
$uptimePct = $totalDevices ? round(($upDevices / $totalDevices) * 100, 1) : 0;
$notificationCount = db_fetch("SELECT COUNT(*) AS c FROM incidents WHERE assigned_to = ? AND unread_by_assignee = 1 AND status != 'closed'", [$_SESSION['id']])['c'] ?? 0;

// User Profile
$userProfile = db_fetch("SELECT username FROM accounts WHERE id = ?", [$_SESSION['id']]);

echo json_encode([
    'stats' => [
        'totalDevices' => (int)$totalDevices,
        'upDevices' => (int)$upDevices,
        'downDevices' => (int)$downDevices,
        'uptimePct' => (float)$uptimePct,
    ],
    'notifications' => (int)$notificationCount,
    'user' => [
        'username' => $userProfile['username'] ?? 'User'
    ]
]);
