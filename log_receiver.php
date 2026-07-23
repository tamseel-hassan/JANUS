<?php
// log_receiver.php
// Usage: run by rsyslog's omprog: rsyslog will pipe syslog lines into stdin of this script.

set_time_limit(0);
ini_set('memory_limit', '256M');
date_default_timezone_set('UTC');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/log_parsers.php';

// DB connect
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    file_put_contents('/var/log/janus-log_receiver.err', date('c') . " - DB connect failed: " . mysqli_connect_error() . PHP_EOL, FILE_APPEND);
    exit(1);
}

// Prepared insert statement (we reuse)
$insert_stmt = mysqli_prepare($con, "
    INSERT INTO syslog_entries (
        source_id, appliance_type, source_ip, message,
        src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, duration,
        is_auth_failure, is_remote_access, is_malware, is_notable
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$insert_stmt) {
    file_put_contents('/var/log/janus-log_receiver.err', date('c') . " - Prepare insert failed: " . mysqli_error($con) . PHP_EOL, FILE_APPEND);
    exit(1);
}

// Prepared rollup statements
$rollup_daily_stmt = mysqli_prepare($con, "
    INSERT INTO syslog_traffic_daily (
        log_date, source_ip, destination_ip, app, service, action, flow_count, total_sent, total_rcvd
    ) VALUES (
        ?, ?, ?, ?, ?, ?, 1, ?, ?
    ) ON DUPLICATE KEY UPDATE 
        flow_count = flow_count + 1,
        total_sent = total_sent + VALUES(total_sent),
        total_rcvd = total_rcvd + VALUES(total_rcvd)
");

$rollup_hourly_stmt = mysqli_prepare($con, "
    INSERT INTO syslog_traffic_hourly (
        log_hour, source_ip, flow_count, total_sent, total_rcvd
    ) VALUES (
        ?, ?, 1, ?, ?
    ) ON DUPLICATE KEY UPDATE 
        flow_count = flow_count + 1,
        total_sent = total_sent + VALUES(total_sent),
        total_rcvd = total_rcvd + VALUES(total_rcvd)
");

function parseSyslogMessage($msg) {
    $parsed = [
        'src_ip' => null, 'dst_ip' => null, 'dst_port' => null,
        'app' => null, 'service' => null, 'action' => null,
        'sent_bytes' => 0, 'rcvd_bytes' => 0, 'duration' => 0
    ];

    $keys = [
        'srcip' => 'src_ip', 'dstip' => 'dst_ip', 'dstport' => 'dst_port',
        'app' => 'app', 'service' => 'service', 'action' => 'action',
        'sentbyte' => 'sent_bytes', 'rcvdbyte' => 'rcvd_bytes', 'duration' => 'duration'
    ];

    foreach ($keys as $k => $field) {
        $pos = strpos($msg, $k . '=');
        if ($pos !== false) {
            $start = $pos + strlen($k) + 1;
            if ($start < strlen($msg)) {
                if ($msg[$start] === '"') {
                    $end = strpos($msg, '"', $start + 1);
                    $val = ($end !== false) ? substr($msg, $start + 1, $end - $start - 1) : substr($msg, $start + 1);
                } elseif ($msg[$start] === "'") {
                    $end = strpos($msg, "'", $start + 1);
                    $val = ($end !== false) ? substr($msg, $start + 1, $end - $start - 1) : substr($msg, $start + 1);
                } else {
                    $end = strpos($msg, ' ', $start);
                    $val = ($end !== false) ? substr($msg, $start, $end - $start) : substr($msg, $start);
                }
                
                if ($field === 'dst_port' || $field === 'sent_bytes' || $field === 'rcvd_bytes' || $field === 'duration') {
                    $parsed[$field] = max(0, intval($val));
                } else {
                    $parsed[$field] = $val;
                }
            }
        }
    }
    return $parsed;
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
    $source_ip = null;

    if (preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', $line, $m)) {
        $source_ip = $m[1];
    } else {
        if (preg_match('/src=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $line, $m2)) $source_ip = $m2[1];
        else if (preg_match('/from\[?([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\]?/', $line, $m3)) $source_ip = $m3[1];
    }

    if (!$source_ip) {
        continue;
    }

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
        continue;
    }

    // Parse syslog parameters
    $p = parseSyslogMessage($line);
    $flags = classify_syslog_flags($line);

    // Insert into syslog_entries
    $appl = $sappliance ?: 'other';
    $msg = $line;
    mysqli_stmt_bind_param(
        $insert_stmt, 
        'isssssisssiiiiiii', 
        $sid, $appl, $source_ip, $msg,
        $p['src_ip'], $p['dst_ip'], $p['dst_port'], $p['app'], $p['service'], $p['action'], 
        $p['sent_bytes'], $p['rcvd_bytes'], $p['duration'],
        $flags[0], $flags[1], $flags[2], $flags[3]
    );
    $ok = @mysqli_stmt_execute($insert_stmt);
    if (!$ok) {
        file_put_contents('/var/log/janus-log_receiver.err', date('c') . " - insert failed: " . mysqli_error($con) . " | line: " . $line . PHP_EOL, FILE_APPEND);
        continue;
    }

    // Update rollups if traffic data is present
    if ($p['src_ip'] !== null && $p['dst_ip'] !== null) {
        $log_date = date('Y-m-d');
        $log_hour = date('Y-m-d H:00:00');
        
        $app = $p['app'] ?: 'Unknown';
        $svc = $p['service'] ?: 'Unknown';
        $act = $p['action'] ?: 'other';

        // 1. Daily Rollup
        if ($rollup_daily_stmt) {
            mysqli_stmt_bind_param(
                $rollup_daily_stmt,
                'ssssssii',
                $log_date, $p['src_ip'], $p['dst_ip'], $app, $svc, $act,
                $p['sent_bytes'], $p['rcvd_bytes']
            );
            @mysqli_stmt_execute($rollup_daily_stmt);
        }

        // 2. Hourly Rollup
        if ($rollup_hourly_stmt) {
            mysqli_stmt_bind_param(
                $rollup_hourly_stmt,
                'ssii',
                $log_hour, $p['src_ip'],
                $p['sent_bytes'], $p['rcvd_bytes']
            );
            @mysqli_stmt_execute($rollup_hourly_stmt);
        }
    }
}

// cleanup
mysqli_stmt_close($insert_stmt);
if ($rollup_daily_stmt) mysqli_stmt_close($rollup_daily_stmt);
if ($rollup_hourly_stmt) mysqli_stmt_close($rollup_hourly_stmt);
mysqli_close($con);
fclose($stdin);
exit(0);
