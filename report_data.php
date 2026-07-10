<?php
// report_data.php - Thin router (only auth + include sub-file)

session_start();

// INTERNAL TOKEN BYPASS
$internal_token = $_GET['internal_token'] ?? '';
if ($internal_token !== 'janus-secure-internal-2026') {
    if (!isset($_SESSION['loggedin'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Route to correct sub-file
$type = $_GET['type'] ?? 'traffic';

$allowed_types = ['traffic', 'bruteforce', 'config_changes', 'dns_attacks', 'ddos', 'applications', 'availability', 'security'];

if (!in_array($type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid report type']);
    exit;
}

// Include the sub-file directly (no JSON)
$sub_file = __DIR__ . '/ss/' . $type . '.php';
if (file_exists($sub_file)) {
    require $sub_file;
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Report file not found']);
}
?>
