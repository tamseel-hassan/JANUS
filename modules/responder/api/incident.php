<?php
/**
 * modules/responder/api/incident.php - AJAX endpoint: Create incident from reports
 * POST: title, severity, source_ip, description, type
 * Returns JSON
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../db_config.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

$title = trim($_POST['title'] ?? '');
$severity = trim($_POST['severity'] ?? 'medium');
$source_ip = trim($_POST['source_ip'] ?? '');
$description = trim($_POST['description'] ?? '');
$type = trim($_POST['type'] ?? 'siem_detection');

if (empty($title)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Title is required']);
    exit;
}

if (!in_array($severity, ['low', 'medium', 'high', 'critical'])) {
    $severity = 'medium';
}

$reported_by = intval($_SESSION['id'] ?? 0);
$due_days = ['low' => 14, 'medium' => 7, 'high' => 3, 'critical' => 1];
$due_date = date('Y-m-d H:i:s', strtotime('+' . ($due_days[$severity] ?? 7) . ' days'));

$stmt = $con->prepare(
    "INSERT INTO incidents (title, description, type, severity, reported_by, due_date, status, source_ip)
     VALUES (?, ?, ?, ?, ?, ?, 'open', ?)"
);
$stmt->bind_param('ssssiss', $title, $description, $type, $severity, $reported_by, $due_date, $source_ip);
$stmt->execute();

if ($stmt->error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to create incident: ' . $stmt->error]);
    exit;
}

$incident_id = $stmt->insert_id;
$stmt->close();

// Add initial history entry
if ($incident_id) {
    $hstmt = $con->prepare(
        "INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value)
         VALUES (?, ?, 'status', 'none', 'open')"
    );
    $hstmt->bind_param('ii', $incident_id, $reported_by);
    $hstmt->execute();
    $hstmt->close();
}

echo json_encode([
    'ok' => true,
    'incident_id' => $incident_id,
    'title' => $title,
    'severity' => $severity,
    'due_date' => $due_date,
    'message' => "Incident #$incident_id created successfully"
]);
