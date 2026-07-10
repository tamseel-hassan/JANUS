<?php
/**
 * Archive syslog_entries - Keep only last 5000 records for blazing fast performance
 * This approach is used by enterprise SIEM tools like Wazuh, FortiSIEM, FortiAnalyzer
 * 
 * Run via cron every 10 minutes
 */
require_once __DIR__ . '/db_config.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    error_log('[' . date('Y-m-d H:i:s') . '] DB Error: ' . mysqli_connect_error());
    exit(1);
}

echo "Starting syslog archive at " . date('Y-m-d H:i:s') . "\n";

// Keep only the most recent 5000 records in live table
$keep_records = 5000;
$batch_size = 2000;

// Start transaction
mysqli_begin_transaction($con);

try {
    // Count total records
    $count_query = "SELECT COUNT(*) as total FROM syslog_entries";
    $result = mysqli_query($con, $count_query);
    $total_records = mysqli_fetch_assoc($result)['total'];
    
    echo "Total records in live table: $total_records\n";
    echo "Target: Keep newest $keep_records records\n";
    
    if ($total_records <= $keep_records) {
        echo "No archiving needed (under threshold)\n";
        mysqli_commit($con);
        mysqli_close($con);
        exit(0);
    }
    
    $records_to_archive = $total_records - $keep_records;
    echo "Records to archive: $records_to_archive\n";
    
    // Get the cutoff ID (keep everything >= this ID)
    $cutoff_query = "SELECT id FROM syslog_entries ORDER BY received_at DESC LIMIT $keep_records, 1";
    $result = mysqli_query($con, $cutoff_query);
    $cutoff_row = mysqli_fetch_assoc($result);
    
    if (!$cutoff_row) {
        echo "Could not determine cutoff ID\n";
        mysqli_commit($con);
        mysqli_close($con);
        exit(0);
    }
    
    $cutoff_id = $cutoff_row['id'];
    echo "Cutoff ID: $cutoff_id (archiving all records with ID < $cutoff_id)\n";
    
    $archived_total = 0;
    $batches = ceil($records_to_archive / $batch_size);
    
    echo "Processing in $batches batches of $batch_size records each\n";
    
    for ($i = 0; $i < $batches; $i++) {
        echo "Batch " . ($i + 1) . " of $batches... ";
        
        // Move batch to archive
        $move_query = "
            INSERT INTO syslog_entries_archive
            (id, source_id, appliance_type, source_ip, message, received_at)
            SELECT id, source_id, appliance_type, source_ip, message, received_at
            FROM syslog_entries
            WHERE id < ?
            LIMIT ?
        ";
        
        $stmt = mysqli_prepare($con, $move_query);
        mysqli_stmt_bind_param($stmt, "ii", $cutoff_id, $batch_size);
        mysqli_stmt_execute($stmt);
        $archived_count = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        
        echo "moved $archived_count, ";
        
        // Delete archived data
        if ($archived_count > 0) {
            $delete_query = "DELETE FROM syslog_entries WHERE id < ? LIMIT ?";
            $stmt = mysqli_prepare($con, $delete_query);
            mysqli_stmt_bind_param($stmt, "ii", $cutoff_id, $batch_size);
            mysqli_stmt_execute($stmt);
            $deleted_count = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            
            echo "deleted $deleted_count\n";
            $archived_total += $archived_count;
        } else {
            echo "done\n";
            break;
        }
        
        usleep(50000); // 0.05 second delay
    }
    
    // Optimize table
    if ($archived_total > 500) {
        echo "Optimizing table...\n";
        mysqli_query($con, "OPTIMIZE TABLE syslog_entries");
    }
    
    mysqli_commit($con);
    echo "\n✅ Archive completed at " . date('Y-m-d H:i:s') . "\n";
    echo "Total archived: " . number_format($archived_total) . " records\n";
    
    // Show stats
    $stats_query = "
        SELECT 
            (SELECT COUNT(*) FROM syslog_entries) as live_count,
            (SELECT COUNT(*) FROM syslog_entries_archive) as archive_count
    ";
    $result = mysqli_query($con, $stats_query);
    $stats = mysqli_fetch_assoc($result);
    
    echo "\n=== Current Statistics ===\n";
    echo "Live records: " . number_format($stats['live_count']) . "\n";
    echo "Archived records: " . number_format($stats['archive_count']) . "\n";
    echo "Total records: " . number_format($stats['live_count'] + $stats['archive_count']) . "\n";
    
} catch (Exception $e) {
    mysqli_rollback($con);
    error_log('[' . date('Y-m-d H:i:s') . '] Archive failed: ' . $e->getMessage());
    echo "❌ Archive failed: " . $e->getMessage() . "\n";
    exit(1);
}

mysqli_close($con);
?>
