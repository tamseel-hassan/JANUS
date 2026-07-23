<?php
/**
 * Janus Query Result Cache
 * Simple file-based cache for expensive analytics queries.
 *
 * Usage:
 *   $rows = query_cache("unique_key_{$start}_{$end}", 120, function() use ($con, $query) {
 *       $result = mysqli_query($con, $query);
 *       $rows = [];
 *       while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
 *       return $rows;
 *   });
 */

function query_cache(string $key, int $ttl_seconds, callable $fn): mixed
{
    // Caching globally disabled: always query database directly
    return $fn();
}

/**
 * Get TTL in seconds based on time range string.
 * Longer ranges = longer cache time since data changes less frequently.
 */
function cache_ttl_for_range(string $range): int
{
    return match ($range) {
        '15m'   => 30,
        '1h'    => 60,
        '6h'    => 120,
        '24h'   => 300,
        '7d'    => 600,
        '30d'   => 900,
        default => 120,
    };
}

/**
 * Clear all cached files (call after data import or manual refresh).
 */
function clear_query_cache(): void
{
    $cache_dir = '/tmp/janus_cache/';
    if (is_dir($cache_dir)) {
        foreach (glob($cache_dir . '*.cache') as $file) {
            @unlink($file);
        }
    }
}

/**
 * Generate a bucketed cache key where start/end timestamps are rounded to intervals
 * based on the active range. This allows cache hits when reloading pages.
 */
function get_bucketed_cache_key(string $prefix, string $start, string $end, string $device, string $range): string
{
    $interval = match (strtolower($range)) {
        '15m'   => 15,    // 15 seconds
        '1h'    => 60,    // 1 minute
        '6h'    => 300,   // 5 minutes
        '24h', '1d' => 600,   // 10 minutes
        '7d'    => 1800,  // 30 minutes
        '30d'   => 7200,  // 2 hours
        default => 300,
    };

    $start_ts = strtotime($start);
    $end_ts = strtotime($end);

    // Default to time() if parsing fails
    if (!$start_ts) $start_ts = time() - 86400;
    if (!$end_ts)   $end_ts = time();

    $rounded_start = $start_ts - ($start_ts % $interval);
    $rounded_end = $end_ts - ($end_ts % $interval);

    return "{$prefix}_{$rounded_start}_{$rounded_end}_{$device}";
}

