<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Error']);
    exit;
}



// GET request: Fetch history
$history = [];
$res = mysqli_query($con, "
    SELECT ah.*, a.username 
    FROM analysis_history ah
    LEFT JOIN accounts a ON ah.user_id = a.id
    WHERE ah.analysis_type = 'threat_intel'
    ORDER BY ah.created_at DESC
    LIMIT 100
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $history[] = $r;
    }
}

echo json_encode(['history' => $history]);
