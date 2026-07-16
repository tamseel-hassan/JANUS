<?php
// Janus Responder - Webhook Receiver
// External systems can POST alerts here to trigger playbooks
// Endpoint: /modules/responder/webhook.php?key=YOUR_ENDPOINT_KEY

require_once __DIR__ . '/../../db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$endpointKey = $_GET['key'] ?? '';
if (empty($endpointKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing endpoint key']);
    exit;
}

// Find webhook endpoint
$stmt = $con->prepare("SELECT * FROM responder_webhooks WHERE endpoint_key = ? AND enabled = 1");
$stmt->bind_param('s', $endpointKey);
$stmt->execute();
$webhook = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$webhook) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Webhook endpoint not found or disabled']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

// Auto-create incident if configured
$incidentId = null;
if ($webhook['auto_create_incident']) {
    $title = mysqli_real_escape_string($con, $input['title'] ?? 'Webhook Alert');
    $description = mysqli_real_escape_string($con, $input['description'] ?? '');
    $severity = mysqli_real_escape_string($con, $input['severity'] ?? 'medium');
    $sourceIp = mysqli_real_escape_string($con, $input['source_ip'] ?? '');
    $due_days = ['low' => 14, 'medium' => 7, 'high' => 3, 'critical' => 1];
    $due_date = date('Y-m-d H:i:s', strtotime('+' . ($due_days[$severity] ?? 7) . ' days'));

    $istmt = $con->prepare(
        "INSERT INTO incidents (title, description, type, severity, status, due_date, source_ip, reported_by)
         VALUES (?, ?, 'automation', ?, 'open', ?, ?, 0)"
    );
    $istmt->bind_param('sssss', $title, $description, $severity, $due_date, $sourceIp);
    $istmt->execute();
    $incidentId = $istmt->insert_id;
    $istmt->close();

    if ($incidentId) {
        $hstmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, 0, 'status', 'none', 'open')");
        $hstmt->bind_param('i', $incidentId);
        $hstmt->execute();
        $hstmt->close();
    }
}

// Update last triggered
$con->query("UPDATE responder_webhooks SET last_triggered_at = NOW() WHERE id = " . intval($webhook['id']));

// If a playbook is linked, trigger it
$playbookResult = null;
if ($webhook['trigger_playbook_id']) {
    $pid = intval($webhook['trigger_playbook_id']);
    $pstmt = $con->prepare("SELECT id FROM responder_playbooks WHERE id = ? AND enabled = 1");
    $pstmt->bind_param('i', $pid);
    $pstmt->execute();
    if ($pstmt->get_result()->fetch_assoc()) {
        $pstmt->close();

        $estmt = $con->prepare(
            "INSERT INTO responder_executions (playbook_id, incident_id, triggered_by, status, input_data)
             VALUES (?, ?, 'webhook', 'pending', ?)"
        );
        $inputJson = json_encode($input);
        $estmt->bind_param('iis', $pid, $incidentId, $inputJson);
        $estmt->execute();
        $eid = $estmt->insert_id;
        $estmt->close();

        require_once __DIR__ . '/engine.php';
        $engine = new PlaybookEngine();
        $playbookResult = $engine->execute($eid);
    } else {
        $pstmt->close();
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Webhook processed',
    'incident_id' => $incidentId,
    'playbook_result' => $playbookResult,
]);

mysqli_close($con);
