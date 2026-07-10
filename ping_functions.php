<?php
/**
 * ping_functions.php
 * ---------------------------------------------------------------
 * Shared ICMP ping + parsing + cache/log logic.
 *
 * WHY THIS FILE EXISTS:
 * Previously, all the "ping a host, parse the output, decide
 * up/down, update device_status_cache, log to ping_logs" logic
 * lived only inside ping_monitor.php (the cron script). When we
 * added check_now.php (the on-demand "Check Now" button for
 * monitor.php), it needed the EXACT same logic — otherwise the
 * cron sweep and the manual check could disagree on what counts
 * as "up", how RTT is parsed, etc., and they'd quietly drift
 * apart over time as one got edited and the other didn't.
 *
 * So this file is now the single source of truth for:
 *   - pingHost()                  -> runs the actual ping, parses output
 *   - logPing()                   -> writes one row to ping_logs
 *   - updateDeviceStatusCache()   -> upserts device_status_cache
 *   - getLastDownTime()           -> used for down-logging throttle
 *
 * Both ping_monitor.php (cron, sweeps everything every 2 min) and
 * check_now.php (on-demand, single device) require_once this file.
 * ---------------------------------------------------------------
 */

/**
 * Ping a single host twice and parse the result.
 *
 * @param string $ip
 * @return array{status:string, rtt_avg:?float, sent:int, received:int, loss_pct:float}
 */
function pingHost(string $ip): array {
    $output = [];
    $return_var = 0;
    // Same flags as the cron sweep: -c 2 (2 packets), -W 2 (2s timeout), -q (quiet)
    exec("ping -c 2 -W 2 -q " . escapeshellarg($ip) . " 2>&1", $output, $return_var);

    $status   = 'down';
    $rtt_avg  = null;
    $sent     = 2;
    $received = 0;
    $loss_pct = 100.00;

    if ($return_var === 0 && count($output) >= 4) {
        $last_line   = end($output);
        $second_last = $output[count($output) - 2] ?? '';

        if (preg_match('/(\d+) packets transmitted, (\d+) received, ([\d.]+)% packet loss/', $second_last, $matches)) {
            $sent     = (int)$matches[1];
            $received = (int)$matches[2];
            $loss_pct = (float)$matches[3];

            if ($received >= 1) {
                $status = 'up';
                if (preg_match('/rtt min\/avg\/max\/mdev = [\d.]+\/([\d.]+)\/[\d.]+\/[\d.]+ ms/', $last_line, $rtt_matches)) {
                    $rtt_avg = (float)$rtt_matches[1];
                }
            }
        }
    }

    return [
        'status'   => $status,
        'rtt_avg'  => $rtt_avg,
        'sent'     => $sent,
        'received' => $received,
        'loss_pct' => $loss_pct,
    ];
}

/**
 * Log a single ping result to database (ping_logs table).
 */
function logPing($con, ?int $device_id, ?int $link_id, string $status, ?float $rtt_avg, int $sent, int $received, float $loss_pct): void {
    $stmt = $con->prepare("INSERT INTO ping_logs (device_id, link_id, status, rtt_avg, packets_sent, packets_received, packet_loss_pct) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisdiid', $device_id, $link_id, $status, $rtt_avg, $sent, $received, $loss_pct);
    $stmt->execute();
    $stmt->close();
}

/**
 * Update device_status_cache table - call this every time a device is pinged,
 * whether by the cron sweep or by an on-demand Check Now.
 */
function updateDeviceStatusCache($con, int $device_id, string $status, ?float $rtt_avg): void {
    // First, get current status from cache
    $stmt = $con->prepare("SELECT status FROM device_status_cache WHERE device_id = ?");
    $stmt->bind_param('i', $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_row = $result->fetch_assoc();
    $stmt->close();

    $now = date('Y-m-d H:i:s');

    if ($current_row) {
        $current_status = $current_row['status'];

        if ($current_status != $status) {
            // Status changed - update last_status_change
            $stmt = $con->prepare("
                UPDATE device_status_cache
                SET status = ?,
                    rtt_avg = ?,
                    checked_at = ?,
                    last_status_change = ?,
                    last_up = IF(? = 'up', ?, last_up),
                    last_down = IF(? = 'down', ?, last_down)
                WHERE device_id = ?
            ");
            $stmt->bind_param('sdssssssi',
                $status,
                $rtt_avg,
                $now,
                $now,
                $status, $now,
                $status, $now,
                $device_id
            );
        } else {
            // Status unchanged - just update check time and RTT
            $stmt = $con->prepare("
                UPDATE device_status_cache
                SET rtt_avg = ?,
                    checked_at = ?
                WHERE device_id = ?
            ");
            $stmt->bind_param('dsi', $rtt_avg, $now, $device_id);
        }
    } else {
        // Device not in cache - insert new record
        $stmt = $con->prepare("
            INSERT INTO device_status_cache
            (device_id, status, rtt_avg, checked_at, last_status_change, last_up, last_down)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $last_up   = ($status == 'up')   ? $now : null;
        $last_down = ($status == 'down') ? $now : null;

        $stmt->bind_param('isdssss',
            $device_id,
            $status,
            $rtt_avg,
            $now,
            $now,
            $last_up,
            $last_down
        );
    }

    $stmt->execute();
    $stmt->close();
}

/**
 * Get last time a down status was logged for device/link.
 * Returns datetime string or a very old date if none found.
 */
function getLastDownTime($con, ?int $device_id, ?int $link_id): string {
    $query = "SELECT checked_at FROM ping_logs WHERE status = 'down' ";

    if ($device_id !== null) {
        $query .= "AND device_id = ? AND link_id IS NULL ";
    } elseif ($link_id !== null) {
        $query .= "AND link_id = ? ";
    } else {
        return '2000-01-01 00:00:00';
    }

    $query .= "ORDER BY checked_at DESC LIMIT 1";

    $stmt = mysqli_prepare($con, $query);

    if ($device_id !== null) {
        mysqli_stmt_bind_param($stmt, "i", $device_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $link_id);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $row ? $row['checked_at'] : '2000-01-01 00:00:00';
}

/**
 * Decide whether a "down" result should actually be written to ping_logs,
 * based on the same 10-minute throttle the cron sweep uses (so a flapping
 * or chronically-down device doesn't flood the table). "Up" results are
 * always logged immediately, same as before.
 */
function shouldLogStatus($con, string $status, ?int $device_id, ?int $link_id): bool {
    if ($status !== 'down') return true;
    $last_down = getLastDownTime($con, $device_id, $link_id);
    $ten_minutes_ago = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    return $last_down <= $ten_minutes_ago;
}
