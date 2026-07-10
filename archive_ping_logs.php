<?php
/**
 * Archive ping logs older than 12 hours
 * Run via cron once per hour (see crontab: 0 * * * *)
 *
 * WHY 12 HOURS (changed from 73):
 * avalibility.php's report queries already UNION ALL across
 * ping_logs + ping_logs_archive, so lowering this cutoff does NOT
 * lose any data from your 24h/7d/30d reports - it just moves rows
 * into the archive table sooner, keeping the "hot" ping_logs table
 * small. This matters because topbar.php runs a window-function
 * query (ROW_NUMBER() OVER PARTITION BY device_id) against
 * ping_logs on EVERY page load, including the post-login redirect -
 * a smaller hot table means that query stays fast even after the
 * app has been running for days without manual intervention.
 *
 * At ~300 devices checked every 2 minutes, expect roughly 9,000
 * new rows/hour, so the hot table should now hover around
 * 100k-110k rows at its peak (right before each hourly archive run)
 * instead of growing unbounded toward 400k+.
 */

require_once __DIR__ . '/db_config.php';

echo "Starting ping logs archive at " . date('Y-m-d H:i:s') . "\n";

// Archive data older than 12 hours
$cutoff_time = date('Y-m-d H:i:s', strtotime('-12 hours'));

// Start transaction for data safety
mysqli_begin_transaction($con);

try {
    // 1. Move old data to archive
    echo "Moving data older than: $cutoff_time\n";

    $move_query = "
        INSERT INTO ping_logs_archive 
        (id, device_id, link_id, status, rtt_avg, packets_sent, packets_received, packet_loss_pct, checked_at)
        SELECT 
            id, device_id, link_id, status, rtt_avg, packets_sent, packets_received, packet_loss_pct, checked_at
        FROM ping_logs 
        WHERE checked_at < ?
    ";
    
    $stmt = mysqli_prepare($con, $move_query);
    mysqli_stmt_bind_param($stmt, "s", $cutoff_time);
    mysqli_stmt_execute($stmt);
    $archived_count = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    
    echo "Moved $archived_count records to archive\n";
    
    // 2. Delete archived data from main table
    if ($archived_count > 0) {
        $delete_query = "DELETE FROM ping_logs WHERE checked_at < ?";
        $stmt = mysqli_prepare($con, $delete_query);
        mysqli_stmt_bind_param($stmt, "s", $cutoff_time);
        mysqli_stmt_execute($stmt);
        $deleted_count = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        
        echo "Deleted $deleted_count records from main table\n";
        
        // 3. Optimize table to reclaim space
        // NOTE: OPTIMIZE TABLE rewrites the entire table and takes an
        // exclusive lock for the duration. On a table with 100k+ rows
        // this can take a noticeable amount of time/IO. Since this now
        // runs every hour (vs every 44 min before) on a much smaller
        // delta each time, the lock duration per run should be short -
        // but if you ever notice ping_monitor.php's cron overlapping
        // with this lock and failing to write, consider removing this
        // OPTIMIZE step or running it on a separate daily schedule
        // instead of every single hourly archive run.
        mysqli_query($con, "OPTIMIZE TABLE ping_logs");
        echo "Optimized ping_logs table\n";
    } else {
        echo "No records to archive\n";
    }
    
    // Commit transaction
    mysqli_commit($con);
    echo "Archive completed successfully at " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    // Rollback on error
    mysqli_rollback($con);
    error_log('[' . date('Y-m-d H:i:s') . '] Archive failed: ' . $e->getMessage());
    echo "Archive failed: " . $e->getMessage() . "\n";
    exit(1);
}

mysqli_close($con);
?>
