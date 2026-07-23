<?php
/**
 * Enhanced Log Processor with Daily Rotation
 * Processes incoming syslog entries and rotates logs daily per IP
 * Format: 172-17-128-1-2025-11-27.log
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/log_parsers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    error_log('DB Error: ' . mysqli_connect_error());
    exit('DB Error: ' . mysqli_connect_error());
}

// Log storage directory
$log_base_dir = '/var/log/janus_siem';
if (!is_dir($log_base_dir)) {
    mkdir($log_base_dir, 0755, true);
}

/**
 * Write log to daily rotated file
 */
function writeToLogFile($ip, $timestamp, $appliance_type, $message) {
    global $log_base_dir;
    
    $ip_formatted = str_replace('.', '-', $ip);
    $date = date('Y-m-d', strtotime($timestamp));
    $filename = "{$ip_formatted}-{$date}.log";
    $filepath = $log_base_dir . '/' . $filename;
    
    $log_entry = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s', strtotime($timestamp)),
        strtoupper($appliance_type),
        $ip,
        $message
    );
    
    file_put_contents($filepath, $log_entry, FILE_APPEND | LOCK_EX);
    chmod($filepath, 0644);
}

/**
 * Clean old log files (optional retention policy)
 */
function cleanOldLogs($retention_days = 90) {
    global $log_base_dir;
    
    $cutoff = time() - ($retention_days * 86400);
    $files = glob($log_base_dir . '/*.log');
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            error_log("Deleted old log file: " . basename($file));
        }
    }
}

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

// Prepared rollup statements
$rollup_daily_stmt = $con->prepare("
    INSERT INTO syslog_traffic_daily (
        log_date, source_ip, destination_ip, app, service, action, flow_count, total_sent, total_rcvd
    ) VALUES (
        ?, ?, ?, ?, ?, ?, 1, ?, ?
    ) ON DUPLICATE KEY UPDATE 
        flow_count = flow_count + 1,
        total_sent = total_sent + VALUES(total_sent),
        total_rcvd = total_rcvd + VALUES(total_rcvd)
");

$rollup_hourly_stmt = $con->prepare("
    INSERT INTO syslog_traffic_hourly (
        log_hour, source_ip, flow_count, total_sent, total_rcvd
    ) VALUES (
        ?, ?, 1, ?, ?
    ) ON DUPLICATE KEY UPDATE 
        flow_count = flow_count + 1,
        total_sent = total_sent + VALUES(total_sent),
        total_rcvd = total_rcvd + VALUES(total_rcvd)
");

// Read from stdin (syslog pipe) or process existing DB entries
$input = fopen('php://stdin', 'r');
stream_set_blocking($input, false);

while (true) {
    $line = fgets($input);
    
    if ($line !== false) {
        $line = trim($line);
        if (empty($line)) continue;

        if (preg_match('/<(\d+)>(.+?) (.+?) (.+?): (.+)/', $line, $matches)) {
            $priority = $matches[1];
            $timestamp = $matches[2];
            $source_ip = $matches[3];
            $tag = $matches[4];
            $message = $matches[5];
            
            $stmt = $con->prepare("SELECT id, appliance_type, is_active FROM syslog_sources WHERE source_ip = ?");
            $stmt->bind_param('s', $source_ip);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                if ($row['is_active']) {
                    $source_id = $row['id'];
                    $appliance_type = $row['appliance_type'];
                    $full_message = $tag . ': ' . $message;
                    
                    // Parse details
                    $p = parseSyslogMessage($full_message);
                    $flags = classify_syslog_flags($full_message);

                    // Insert into database
                    $stmt2 = $con->prepare("
                        INSERT INTO syslog_entries (
                            source_id, appliance_type, source_ip, message,
                            src_ip, dst_ip, dst_port, app, service, action, sent_bytes, rcvd_bytes, duration,
                            is_auth_failure, is_remote_access, is_malware, is_notable
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt2->bind_param(
                        'isssssisssiiiiiii', 
                        $source_id, $appliance_type, $source_ip, $full_message,
                        $p['src_ip'], $p['dst_ip'], $p['dst_port'], $p['app'], $p['service'], $p['action'], 
                        $p['sent_bytes'], $p['rcvd_bytes'], $p['duration'],
                        $flags[0], $flags[1], $flags[2], $flags[3]
                    );
                    $stmt2->execute();
                    $stmt2->close();
                    
                    // Write to daily rotated log file
                    writeToLogFile($source_ip, date('Y-m-d H:i:s'), $appliance_type, $full_message);
                    
                    // Update rollups if traffic log
                    if ($p['src_ip'] !== null && $p['dst_ip'] !== null) {
                        $log_date = date('Y-m-d');
                        $log_hour = date('Y-m-d H:00:00');
                        
                        $app = $p['app'] ?: 'Unknown';
                        $svc = $p['service'] ?: 'Unknown';
                        $act = $p['action'] ?: 'other';

                        if ($rollup_daily_stmt) {
                            $rollup_daily_stmt->bind_param(
                                'ssssssii',
                                $log_date, $p['src_ip'], $p['dst_ip'], $app, $svc, $act,
                                $p['sent_bytes'], $p['rcvd_bytes']
                            );
                            $rollup_daily_stmt->execute();
                        }

                        if ($rollup_hourly_stmt) {
                            $rollup_hourly_stmt->bind_param(
                                'ssii',
                                $log_hour, $p['src_ip'],
                                $p['sent_bytes'], $p['rcvd_bytes']
                            );
                            $rollup_hourly_stmt->execute();
                        }
                    }
                    
                    error_log("Processed log from {$source_ip} ({$appliance_type})");
                } else {
                    error_log("Blocked log from inactive source: {$source_ip}");
                }
            } else {
                error_log("Rejected log from unregistered source: {$source_ip}");
            }
            
            $stmt->close();
        }
    }
    
    // Clean old logs once per day (check every 1000 iterations)
    // Sleep briefly if stdin was empty to prevent 100% CPU lock
    if ($line === false) {
        usleep(50000); // 50ms sleep
    }
}

if ($rollup_daily_stmt) $rollup_daily_stmt->close();
if ($rollup_hourly_stmt) $rollup_hourly_stmt->close();
mysqli_close($con);
fclose($input);
exit(0);
