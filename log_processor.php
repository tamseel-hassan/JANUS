<?php
/**
 * Enhanced Log Processor with Daily Rotation
 * Processes incoming syslog entries and rotates logs daily per IP
 * Format: 172-17-128-1-2025-11-27.log
 */
require_once __DIR__ . '/db_config.php';

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
    
    // Format: 172-17-128-1-2025-11-27.log
    $ip_formatted = str_replace('.', '-', $ip);
    $date = date('Y-m-d', strtotime($timestamp));
    $filename = "{$ip_formatted}-{$date}.log";
    $filepath = $log_base_dir . '/' . $filename;
    
    // Format log entry
    $log_entry = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s', strtotime($timestamp)),
        strtoupper($appliance_type),
        $ip,
        $message
    );
    
    // Append to file
    file_put_contents($filepath, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Set permissions
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

// Read from stdin (syslog pipe) or process existing DB entries
$input = fopen('php://stdin', 'r');
stream_set_blocking($input, false); // Non-blocking mode

while (true) {
    $line = fgets($input);
    
    if ($line !== false) {
        $line = trim($line);
        if (empty($line)) continue;

        // Parse syslog format: <priority>timestamp hostname tag: message
        // Example: <134>Nov 27 22:00:01 192.168.1.1 firewall: Connection established
        if (preg_match('/<(\d+)>(.+?) (.+?) (.+?): (.+)/', $line, $matches)) {
            $priority = $matches[1];
            $timestamp = $matches[2];
            $source_ip = $matches[3];
            $tag = $matches[4];
            $message = $matches[5];
            
            // Check if source is allowed and active
            $stmt = $con->prepare("SELECT id, appliance_type, is_active FROM syslog_sources WHERE source_ip = ?");
            $stmt->bind_param('s', $source_ip);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Check if source is active
                if ($row['is_active']) {
                    $source_id = $row['id'];
                    $appliance_type = $row['appliance_type'];
                    $full_message = $tag . ': ' . $message;
                    
                    // Insert into database
                    $stmt2 = $con->prepare("INSERT INTO syslog_entries (source_id, appliance_type, source_ip, message) VALUES (?, ?, ?, ?)");
                    $stmt2->bind_param('isss', $source_id, $appliance_type, $source_ip, $full_message);
                    $stmt2->execute();
                    $stmt2->close();
                    
                    // Write to daily rotated log file
                    writeToLogFile($source_ip, date('Y-m-d H:i:s'), $appliance_type, $full_message);
                    
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
    static $iteration = 0;
    if (++$iteration % 1000 == 0) {
        cleanOldLogs(90); // Keep logs for 90 days
    }
    
    // Small sleep to prevent CPU spinning
    usleep(100000); // 0.1 second
}

fclose($input);
mysqli_close($con);
