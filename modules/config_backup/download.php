<?php
session_start();
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../auth_check.php';

// Only admin and backup_manager can download
if (!in_array($_SESSION['role'] ?? '', ['admin', 'backup_manager'])) {
    http_response_code(403);
    die('Access denied.');
}

$file = $_GET['file'] ?? '';
$dir  = $_GET['dir']  ?? '';
$upload_dir = realpath(__DIR__ . '/uploads');

// Prevent directory traversal
$path = realpath($dir . '/' . $file);
if (!$path || strpos($path, $upload_dir) !== 0 || !is_file($path)) {
    http_response_code(404);
    die('File not found.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
