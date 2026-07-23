<?php
/**
 * includes/cache.php - High-performance query caching helper for JANUS
 */

if (!function_exists('cache_ttl_for_range')) {
    /**
     * Get recommended Cache TTL (Time-To-Live) in seconds based on time range filter
     */
    function cache_ttl_for_range(?string $range = '24h'): int {
        switch (strtolower((string)$range)) {
            case '15m':
            case '30m':
            case '1h':
                return 60; // 1 minute for real-time short ranges
            case '6h':
            case '12h':
            case '24h':
                return 300; // 5 minutes for daily views
            case '7d':
                return 900; // 15 minutes for weekly view
            case '30d':
            case '90d':
                return 3600; // 1 hour for monthly/quarterly views
            default:
                return 300;
        }
    }
}

if (!function_exists('get_bucketed_cache_key')) {
    /**
     * Generate a unique bucketed cache key from input parameters
     */
    function get_bucketed_cache_key(...$args): string {
        $flatArgs = array_map(function($arg) {
            return is_scalar($arg) ? (string)$arg : json_encode($arg);
        }, $args);
        return 'janus_cache_' . md5(implode('|', $flatArgs));
    }
}

if (!function_exists('query_cache')) {
    /**
     * Query cache wrapper: returns cached result if valid, else executes callback & caches result
     * 
     * @param string $key Cache key
     * @param int $ttl Time to live in seconds
     * @param callable $callback Function that fetches data from DB
     * @return mixed
     */
    function query_cache(string $key, int $ttl, callable $callback) {
        // If TTL is zero or negative, bypass cache
        if ($ttl <= 0) {
            return $callback();
        }

        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'janus_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.cache';

        // Check if cache exists and is fresh
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
            $content = @file_get_contents($cacheFile);
            if ($content !== false) {
                $data = @unserialize($content);
                if ($data !== false || $content === 'b:0;') {
                    return $data;
                }
            }
        }

        // Execute callback to retrieve fresh data
        $freshData = $callback();

        // Write fresh data to cache file
        @file_put_contents($cacheFile, serialize($freshData), LOCK_EX);

        return $freshData;
    }
}

if (!function_exists('clear_query_cache')) {
    /**
     * Clear all cached files in the temp directory
     */
    function clear_query_cache(): void {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'janus_cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*.cache');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }
}
