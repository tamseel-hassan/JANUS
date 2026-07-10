<?php
// log_receiver.php
// Usage: run by rsyslog's omprog: rsyslog will pipe syslog lines into stdin of this script.

set_time_limit(0);
ini_set('memory_limit', '256M');
date_default_timezone_set('UTC');

// DB connect
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    // cannot log to DB; just exit
    file_put_contents('/var/log/janus-log_receiver.err', date('c') . " - DB connect failed: " . mysqli_connect_error() . PHP_EOL, FILE_APPEND);
    exit(1);
}

// Prepared insert statement (we reuse)
$insert_stmt = mysqli_prepare($con, "INSERT INTO syslog_entries (source_id, appliance_type, source_ip, message) VALUES (?, ?, ?, ?)");
if (!$insert_stmt) {
    file_put_contents('/var/log/janus-log_receiver.err', date('c') . " - Prepare insert failed: " . mysqli_error($con) . PHP_EOL, FILE_APPEND);
    exit(1);
}

// read stdin line by line
$stdin = fopen('php://stdin', 'r');
if (!$stdin) exit(0);

while (!feof($stdin)) {
    $line = fgets($stdin);
    if ($line === false) break;
    $line = trim($line);
    if ($line === '') continue;

    // Attempt to extract source IP:
    // Common syslog formats contain the host either as the hostname just after PRI/time or via "<PRI>timestamp host ... : msg"
    $source_ip = null;

    // 1) Try to match IPv4 in line
    if (preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', $line, $m)) {
        $source_ip = $m[1];
    } else {
        // 2) Some syslog lines may contain src=1.2.3.4 or from=<ip>
        if (preg_match('/src=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $line, $m2)) $source_ip = $m2[1];
        else if (preg_match('/from\[?([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\]?/', $line, $m3)) $source_ip = $m3[1];
    }

    // If still not found, fallback: try to parse hostname and resolve (may be slow) - skip for performance
    if (!$source_ip) {
        // skip lines without IP to avoid filling DB
        continue;
    }

    // Validate ip
    if (!filter_var($source_ip, FILTER_VALIDATE_IP)) continue;

    // Find matching allowed source (active)
    $sstmt = mysqli_prepare($con, "SELECT id, appliance_type FROM syslog_sources WHERE source_ip = ? AND is_active = 1 LIMIT 1");
    if (!$sstmt) continue;
    mysqli_stmt_bind_param($sstmt, 's', $source_ip);
    mysqli_stmt_execute($sstmt);
    mysqli_stmt_bind_result($sstmt, $sid, $sappliance);
    $found = mysqli_stmt_fetch($sstmt);
    mysqli_stmt_close($sstmt);

    if (!$found) {
        // not allowed OR blocked -> ignore
        continue;
    }

    // Insert into syslog_entries
    $appl = $sappliance ?: 'other';
    $msg = $line;
    mysqli_stmt_bind_param($insert_stmt, 'isss', $sid, $appl, $source_ip, $msg);
    $ok = @mysqli_stmt_execute($insert_stmt);
    if (!$ok) {
        // log error to file (non-blocking)
        file_put_contents('/var/log/janus-log_receiver.err', date('c') . " - insert failed: " . mysqli_error($con) . " | line: " . $line . PHP_EOL, FILE_APPEND);
    }
}

// cleanup
mysqli_stmt_close($insert_stmt);
mysqli_close($con);
fclose($stdin);
exit(0);
