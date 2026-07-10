<?php
// ping_monitor.php - Cron: every 2 min - OPTIMIZED VERSION (stores 1 record per check)
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/ping_functions.php';

// Set timezone if not already set
date_default_timezone_set('Asia/Karachi');

echo "[" . date('Y-m-d H:i:s') . "] Starting ping cycle...\n";

// Ping devices (ping twice, store once)
$device_count = 0;
$device_up = 0;
$device_down = 0;

$result = mysqli_query($con, "SELECT id, ip FROM devices WHERE ip IS NOT NULL AND ip != ''");
while ($row = mysqli_fetch_assoc($result)) {
    $device_id = $row['id'];
    $ip = $row['ip'];

    $ping = pingHost($ip);
    $status   = $ping['status'];
    $rtt_avg  = $ping['rtt_avg'];
    $sent     = $ping['sent'];
    $received = $ping['received'];
    $loss_pct = $ping['loss_pct'];

    $device_count++;
    if ($status === 'up') { $device_up++; } else { $device_down++; }

    // ALWAYS update cache, even if we skip logging
    updateDeviceStatusCache($con, $device_id, $status, $rtt_avg);

    // Check if we should log this status (avoid logging "down" too frequently)
    $should_log = shouldLogStatus($con, $status, $device_id, null);

    if ($status === 'down') {
        if ($should_log) {
            echo "  Device $ip: DOWN (cache updated, logging)\n";
        } else {
            echo "  Device $ip: DOWN (cache updated, skipping log - logged within last 10 min)\n";
        }
    } else {
        echo "  Device $ip: UP (cache updated, RTT: " . ($rtt_avg ?? 'N/A') . " ms)\n";
    }

    if ($should_log) {
        logPing($con, $device_id, null, $status, $rtt_avg, $sent, $received, $loss_pct);
    }
}

// Ping links (same logic, no cache update — maps.php reads link status straight from ping_logs)
$link_count = 0;
$link_up = 0;
$link_down = 0;

$link_result = mysqli_query($con, "SELECT id, ip FROM links WHERE ip IS NOT NULL AND ip != ''");
while ($row = mysqli_fetch_assoc($link_result)) {
    $link_id = $row['id'];
    $ip = $row['ip'];

    $ping = pingHost($ip);
    $status   = $ping['status'];
    $rtt_avg  = $ping['rtt_avg'];
    $sent     = $ping['sent'];
    $received = $ping['received'];
    $loss_pct = $ping['loss_pct'];

    $link_count++;
    if ($status === 'up') { $link_up++; } else { $link_down++; }

    $should_log = shouldLogStatus($con, $status, null, $link_id);

    if ($status === 'down') {
        echo $should_log ? "  Link $ip: DOWN (logging)\n" : "  Link $ip: DOWN (skipping log)\n";
    } else {
        echo "  Link $ip: UP (RTT: " . ($rtt_avg ?? 'N/A') . " ms)\n";
    }

    if ($should_log) {
        logPing($con, null, $link_id, $status, $rtt_avg, $sent, $received, $loss_pct);
    }
}

mysqli_close($con);

echo "[" . date('Y-m-d H:i:s') . "] Ping cycle complete.\n";
echo "  Devices: $device_up up, $device_down down (total: $device_count)\n";
echo "  Links: $link_up up, $link_down down (total: $link_count)\n";
?>
