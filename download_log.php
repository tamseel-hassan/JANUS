<?php
/**
 * download_log.php - Download log files securely
 */
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

$log_base_dir = '/var/log/janus_siem';
$filename = $_GET['file'] ?? '';

// Security: validate filename
if (empty($filename) || !preg_match('/^[\d-]+\.log$/', $filename)) {
    die('Invalid log file specified');
}

$filepath = $log_base_dir . '/' . $filename;

if (!file_exists($filepath)) {
    die('Log file not found');
}

// Set headers for download
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Output file
readfile($filepath);
exit;
