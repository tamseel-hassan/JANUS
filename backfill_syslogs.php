<?php
// backfill_syslogs.php
// Performance-optimized backfill script for Janus syslog database
ini_set('memory_limit', '1024M');
set_time_limit(0);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/log_parsers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die("DB connection failed: " . mysqli_connect_error() . "\n");
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

echo "Clearing daily and hourly rollup tables...\n";
mysqli_query($con, "TRUNCATE TABLE syslog_traffic_daily");
mysqli_query($con, "TRUNCATE TABLE syslog_traffic_hourly");

$tables = ['syslog_entries', 'syslog_entries_archive'];

foreach ($tables as $table) {
    echo "--------------------------------------------------\n";
    echo "Processing table: $table\n";
    
    $cnt_res = mysqli_query($con, "SELECT COUNT(*) AS c FROM $table");
    $total = mysqli_fetch_assoc($cnt_res)['c'];
    echo "Total rows to backfill: $total\n";
    
    if ($total == 0) continue;

    // Prepare statement once for performance
    $up_stmt = mysqli_prepare($con, "
        UPDATE $table SET 
            src_ip = ?, dst_ip = ?, dst_port = ?, app = ?, service = ?, action = ?, 
            sent_bytes = ?, rcvd_bytes = ?, duration = ?,
            is_auth_failure = ?, is_remote_access = ?, is_malware = ?, is_notable = ?
        WHERE id = ?
    ");

    $rd_stmt = mysqli_prepare($con, "
        INSERT INTO syslog_traffic_daily (
            log_date, source_ip, destination_ip, app, service, action, flow_count, total_sent, total_rcvd
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 1, ?, ?
        ) ON DUPLICATE KEY UPDATE 
            flow_count = flow_count + 1,
            total_sent = total_sent + VALUES(total_sent),
            total_rcvd = total_rcvd + VALUES(total_rcvd)
    ");

    $rh_stmt = mysqli_prepare($con, "
        INSERT INTO syslog_traffic_hourly (
            log_hour, source_ip, flow_count, total_sent, total_rcvd
        ) VALUES (
            ?, ?, 1, ?, ?
        ) ON DUPLICATE KEY UPDATE 
            flow_count = flow_count + 1,
            total_sent = total_sent + VALUES(total_sent),
            total_rcvd = total_rcvd + VALUES(total_rcvd)
    ");

    if (!$up_stmt || !$rd_stmt || !$rh_stmt) {
        die("Failed to prepare database statements.\n");
    }

    // Bind parameters to variables by reference
    $src_ip = null; $dst_ip = null; $dst_port = null; $app = null; $service = null; $action = null;
    $sent_bytes = 0; $rcvd_bytes = 0; $duration = 0; 
    $f_auth = 0; $f_remote = 0; $f_malware = 0; $f_notable = 0;
    $row_id = 0;
    mysqli_stmt_bind_param($up_stmt, 'ssisssiiiiiiii', $src_ip, $dst_ip, $dst_port, $app, $service, $action, $sent_bytes, $rcvd_bytes, $duration, $f_auth, $f_remote, $f_malware, $f_notable, $row_id);

    $log_date = null; $rd_src = null; $rd_dst = null; $rd_app = null; $rd_svc = null; $rd_act = null; $rd_sent = 0; $rd_rcvd = 0;
    mysqli_stmt_bind_param($rd_stmt, 'ssssssii', $log_date, $rd_src, $rd_dst, $rd_app, $rd_svc, $rd_act, $rd_sent, $rd_rcvd);

    $log_hour = null; $rh_src = null; $rh_sent = 0; $rh_rcvd = 0;
    mysqli_stmt_bind_param($rh_stmt, 'ssii', $log_hour, $rh_src, $rh_sent, $rh_rcvd);

    $chunk_size = 10000;
    $processed = 0;
    $last_id = 0;

    // Turn off autocommit for batch transactions (speeds up write by 10x)
    mysqli_autocommit($con, false);

    while ($processed < $total) {
        $res = mysqli_query($con, "SELECT id, message, received_at FROM $table WHERE id > $last_id ORDER BY id ASC LIMIT $chunk_size");
        if (!$res || mysqli_num_rows($res) === 0) break;
        
        while ($row = mysqli_fetch_assoc($res)) {
            $last_id = $row['id'];
            $p = parseSyslogMessage($row['message']);
            $flags = classify_syslog_flags($row['message']);
            
            // Assign to reference variables and execute
            $src_ip = $p['src_ip'];
            $dst_ip = $p['dst_ip'];
            $dst_port = $p['dst_port'];
            $app = $p['app'];
            $service = $p['service'];
            $action = $p['action'];
            $sent_bytes = $p['sent_bytes'];
            $rcvd_bytes = $p['rcvd_bytes'];
            $duration = $p['duration'];
            $f_auth = $flags[0];
            $f_remote = $flags[1];
            $f_malware = $flags[2];
            $f_notable = $flags[3];
            $row_id = $last_id;
            mysqli_stmt_execute($up_stmt);
            
            // Add to daily/hourly rollups if traffic log
            if ($p['src_ip'] !== null && $p['dst_ip'] !== null) {
                $log_date = date('Y-m-d', strtotime($row['received_at']));
                $log_hour = date('Y-m-d H:00:00', strtotime($row['received_at']));
                
                $rd_src = $p['src_ip'];
                $rd_dst = $p['dst_ip'];
                $rd_app = $p['app'] ?: 'Unknown';
                $rd_svc = $p['service'] ?: 'Unknown';
                $rd_act = $p['action'] ?: 'other';
                $rd_sent = $p['sent_bytes'];
                $rd_rcvd = $p['rcvd_bytes'];
                mysqli_stmt_execute($rd_stmt);
                
                $rh_src = $p['src_ip'];
                $rh_sent = $p['sent_bytes'];
                $rh_rcvd = $p['rcvd_bytes'];
                mysqli_stmt_execute($rh_stmt);
            }
            
            $processed++;
        }
        
        // Commit chunk
        mysqli_commit($con);
        echo "Processed $processed / $total rows...\n";
    }

    // Clean up statements
    mysqli_stmt_close($up_stmt);
    mysqli_stmt_close($rd_stmt);
    mysqli_stmt_close($rh_stmt);
    
    mysqli_autocommit($con, true);
}

echo "--------------------------------------------------\n";
echo "Backfill completed successfully!\n";
mysqli_close($con);
