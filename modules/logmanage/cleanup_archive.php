<?php
/**
 * cleanup_archive.php - Automated Archive Cleanup Script (Janus)
 * Uses retention policy from system_config table
 * Run via cron: 0 2 * * * /usr/bin/php /var/www/janus/modules/logmanage/cleanup_archive.php
 */
require_once __DIR__ . '/../../db_config.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    error_log('[' . date('Y-m-d H:i:s') . '] Archive cleanup - DB Error: ' . mysqli_connect_error());
    exit(1);
}

// Load retention setting from system_config table
$retention_hours = 168; // Default fallback
$result = mysqli_query($con, "SELECT `value` FROM system_config WHERE `key` = 'archive_retention_hours' LIMIT 1");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $retention_hours = intval($row['value']);
}

echo "===================================================================\n";
echo "Archive Cleanup Started: " . date('Y-m-d H:i:s') . "\n";
echo "Retention Policy: Keep logs newer than $retention_hours hours (" . round($retention_hours/24, 1) . " days)\n";
echo "===================================================================\n\n";

// Calculate cutoff timestamp
$cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_hours} hours"));
echo "Cutoff timestamp: $cutoff\n";
echo "Deleting all archive records older than this timestamp...\n\n";

// Check if archive table exists
$check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
if (mysqli_num_rows($check) === 0) {
    echo "❌ Archive table 'syslog_entries_archive' does not exist.\n";
    echo "Nothing to cleanup. Exiting.\n";
    mysqli_close($con);
    exit(0);
}

// Get count before deletion
$count_stmt = mysqli_prepare($con, "SELECT COUNT(*) FROM syslog_entries_archive WHERE received_at < ?");
mysqli_stmt_bind_param($count_stmt, 's', $cutoff);
mysqli_stmt_execute($count_stmt);
mysqli_stmt_bind_result($count_stmt, $count_before);
mysqli_stmt_fetch($count_stmt);
mysqli_stmt_close($count_stmt);

echo "Records to delete: " . number_format($count_before) . "\n";

if ($count_before === 0) {
    echo "✓ No old records found. Nothing to cleanup.\n";

    // Show current stats
    $result = mysqli_query($con, "SELECT COUNT(*) as cnt, MIN(received_at) as oldest, MAX(received_at) as newest FROM syslog_entries_archive");
    if ($stats = mysqli_fetch_assoc($result)) {
        echo "\nCurrent Archive Stats:\n";
        echo "  Total records: " . number_format($stats['cnt']) . "\n";
        echo "  Oldest record: " . ($stats['oldest'] ?? 'N/A') . "\n";
        echo "  Newest record: " . ($stats['newest'] ?? 'N/A') . "\n";
    }

    mysqli_close($con);
    exit(0);
}

// Begin transaction
mysqli_begin_transaction($con);

try {
    // Delete in batches
    $batch_size = 5000;
    $total_deleted = 0;
    $batches = 0;

    echo "\nDeleting in batches of $batch_size...\n";

    do {
        $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries_archive WHERE received_at < ? LIMIT ?");
        mysqli_stmt_bind_param($stmt, 'si', $cutoff, $batch_size);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        $total_deleted += $deleted;
        $batches++;

        if ($deleted > 0) {
            echo "Batch $batches: Deleted $deleted records (Total: " . number_format($total_deleted) . ")\n";
        }

        // Small delay to avoid DB overload
        usleep(100000); // 0.1 seconds

    } while ($deleted > 0);

    // Optimize table if significant deletions
    if ($total_deleted > 1000) {
        echo "\nOptimizing table...\n";
        mysqli_query($con, "OPTIMIZE TABLE syslog_entries_archive");
        echo "Table optimized.\n";
    }

    mysqli_commit($con);

    // Get final stats
    $result = mysqli_query($con, "
        SELECT
            COUNT(*) as remaining,
            MIN(received_at) as oldest,
            MAX(received_at) as newest
        FROM syslog_entries_archive
    ");
    $stats = mysqli_fetch_assoc($result);

    echo "\n===================================================================\n";
    echo "✓ Cleanup completed successfully at " . date('Y-m-d H:i:s') . "\n";
    echo "===================================================================\n";
    echo "Records deleted: " . number_format($total_deleted) . "\n";
    echo "Batches processed: $batches\n";
    echo "\nCurrent Archive Stats:\n";
    echo "  Remaining records: " . number_format($stats['remaining']) . "\n";
    echo "  Oldest record: " . ($stats['oldest'] ?? 'N/A') . "\n";
    echo "  Newest record: " . ($stats['newest'] ?? 'N/A') . "\n";
    echo "===================================================================\n";

} catch (Exception $e) {
    mysqli_rollback($con);
    error_log('[' . date('Y-m-d H:i:s') . '] Archive cleanup failed: ' . $e->getMessage());
    echo "❌ Cleanup failed: " . $e->getMessage() . "\n";
    mysqli_close($con);
    exit(1);
}

mysqli_close($con);
exit(0);
