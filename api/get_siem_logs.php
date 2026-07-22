<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

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

$log_base_dir = '/var/log/janus_siem';
if (!is_dir($log_base_dir)) {
    @mkdir($log_base_dir, 0755, true);
}

// Fetch active sources
$sources = [];
$res = mysqli_query($con, "
    SELECT ss.*, a.username,
           (SELECT COUNT(*) FROM syslog_entries WHERE source_id = ss.id) as log_count,
           (SELECT MAX(received_at) FROM syslog_entries WHERE source_id = ss.id) as last_log
    FROM syslog_sources ss
    LEFT JOIN accounts a ON ss.added_by = a.id
    ORDER BY ss.is_active DESC, ss.added_at DESC
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $sources[] = $r;
    }
}

// Scan log files from disk
$log_files = [];
if (is_dir($log_base_dir)) {
    $files = scandir($log_base_dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (preg_match('/^(\d+-\d+-\d+-\d+)-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
            $ip = str_replace('-', '.', $m[1]);
            $date = $m[2];
            $filepath = $log_base_dir . '/' . $file;
            $size = filesize($filepath);
            $lines = 0;
            $handle = @fopen($filepath, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    fgets($handle);
                    $lines++;
                }
                fclose($handle);
            }
            $log_files[] = [
                'ip' => $ip,
                'date' => $date,
                'file' => $file,
                'size' => $size,
                'lines' => $lines > 0 ? $lines - 1 : 0
            ];
        }
    }
}

// Filter logs if needed
$filter_ip = $_GET['filter_ip'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';

if ($filter_ip) {
    $log_files = array_filter($log_files, fn($l) => strpos($l['ip'], $filter_ip) !== false);
}
if ($filter_date) {
    $log_files = array_filter($log_files, fn($l) => $l['date'] === $filter_date);
}

// Get retention hours
$retention_hours = 168;
$res_retention = mysqli_query($con, "SELECT `value` FROM system_config WHERE `key` = 'archive_retention_hours'");
if ($res_retention && $row = mysqli_fetch_assoc($res_retention)) {
    $retention_hours = intval($row['value']);
}

// Get archive count
$archive_count = 0;
$archive_oldest = null;
$archive_newest = null;
$check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
if (mysqli_num_rows($check) > 0) {
    $res_archive = mysqli_query($con, "SELECT COUNT(*) as cnt, MIN(received_at) as oldest, MAX(received_at) as newest FROM syslog_entries_archive");
    if ($res_archive && $row = mysqli_fetch_assoc($res_archive)) {
        $archive_count = intval($row['cnt']);
        $archive_oldest = $row['oldest'];
        $archive_newest = $row['newest'];
    }
}

echo json_encode([
    'sources' => $sources,
    'log_files' => array_values($log_files),
    'archive_stats' => [
        'retention_hours' => $retention_hours,
        'count' => $archive_count,
        'oldest' => $archive_oldest,
        'newest' => $archive_newest
    ]
]);
