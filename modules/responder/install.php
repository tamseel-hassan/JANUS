<?php
session_start();
require_once __DIR__ . '/../../db_config.php';

$messages = [];

// Run schema
$schema = file_get_contents(__DIR__ . '/schema.sql');
$statements = explode(';', $schema);
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (!empty($stmt)) {
        if (mysqli_query($con, $stmt)) {
            $messages[] = 'OK: ' . substr($stmt, 0, 60) . '...';
        } else {
            $messages[] = 'SKIP: ' . mysqli_error($con) . ' (' . substr($stmt, 0, 60) . '...)';
        }
    }
}

// Insert default webhook endpoints
$check = mysqli_query($con, "SELECT id FROM responder_webhooks WHERE endpoint_key = 'generic-alert'");
if (mysqli_num_rows($check) === 0) {
    mysqli_query($con, "INSERT INTO responder_webhooks (name, endpoint_key, auto_create_incident, enabled, created_by) VALUES ('Generic Alert', 'generic-alert', 1, 1, " . intval($_SESSION['id'] ?? 0) . ")");
    $messages[] = 'Created default webhook: generic-alert';
}

header('Location: automation.php?installed=1');
exit;
