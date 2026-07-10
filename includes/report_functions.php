<?php
/**
 * report_functions.php
 * Shared functions for modular SIEM reporting system
 * Provides common parsing, analysis, and detection utilities
 */

// ============================================
// SYSLOG MESSAGE PARSING
// ============================================

/**
 * Parse FortiGate/generic syslog message into key-value array
 * Handles quoted values, spaces, and special characters
 */
function parseLogMessage($message) {
    $parsed = [];
    
    // Match key=value pairs with optional quotes
    if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $message, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = $m[1];
            $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
            $parsed[$key] = $value;
        }
    }
    
    return $parsed;
}

/**
 * Extract LogID from FortiGate message
 */
function extractLogID($message) {
    if (preg_match('/logid="?(\d+)"?/', $message, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Determine log category based on content
 */
function categorizeLog($message, $parsed = null) {
    if (!$parsed) $parsed = parseLogMessage($message);
    
    // Traffic logs
    if (isset($parsed['type']) && $parsed['type'] === 'traffic') {
        return 'traffic';
    }
    
    // Security/IPS logs
    if (isset($parsed['type']) && ($parsed['type'] === 'ips' || $parsed['subtype'] === 'ips')) {
        return 'security';
    }
    
    // Config changes
    if (strpos($message, 'logid="0100044546"') !== false || 
        strpos($message, 'logid="0100032102"') !== false ||
        strpos($message, 'Configuration') !== false) {
        return 'config';
    }
    
    // Authentication
    if (stripos($message, 'auth') !== false || 
        stripos($message, 'login') !== false) {
        return 'authentication';
    }
    
    // VPN
    if (isset($parsed['subtype']) && $parsed['subtype'] === 'vpn') {
        return 'vpn';
    }
    
    // DNS
    if (isset($parsed['service']) && $parsed['service'] === 'DNS') {
        return 'dns';
    }
    
    return 'other';
}

// ============================================
// CONFIGURATION CHANGE DETECTION
// ============================================

/**
 * Detect if log is a configuration change
 */
function isConfigChange($message) {
    $config_logids = [
        '0100044546', // Attribute configured
        '0100032102', // Configuration changed
        '0100032001', // Admin login
        '0100032003', // Admin logout
        '0100044547', // Object configured
    ];
    
    foreach ($config_logids as $logid) {
        if (strpos($message, "logid=\"$logid\"") !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Parse configuration change details
 * Returns: [type, user, path, attribute, old_value, new_value, description]
 */
function parseConfigChange($message) {
    $parsed = parseLogMessage($message);
    
    $change = [
        'type' => 'unknown',
        'user' => $parsed['user'] ?? 'system',
        'path' => $parsed['cfgpath'] ?? '',
        'attribute' => '',
        'old_value' => '',
        'new_value' => '',
        'description' => $parsed['msg'] ?? '',
        'severity' => 'info',
        'ui' => $parsed['ui'] ?? 'unknown'
    ];
    
    // Parse cfgattr for before/after values
    // Format: "status[enable->disable]" or "port[80->443]"
    if (isset($parsed['cfgattr'])) {
        $cfgattr = $parsed['cfgattr'];
        if (preg_match('/^(.+?)\[(.+?)->(.+?)\]$/', $cfgattr, $m)) {
            $change['attribute'] = trim($m[1]);
            $change['old_value'] = trim($m[2]);
            $change['new_value'] = trim($m[3]);
        } else {
            $change['attribute'] = $cfgattr;
        }
    }
    
    // Determine change type
    if (strpos($change['path'], 'firewall.policy') !== false) {
        $change['type'] = 'firewall_policy';
        $change['severity'] = 'high';
    } elseif (strpos($change['path'], 'user') !== false) {
        $change['type'] = 'user_account';
        $change['severity'] = 'medium';
    } elseif (strpos($change['path'], 'system') !== false) {
        $change['type'] = 'system_config';
        $change['severity'] = 'medium';
    } elseif (strpos($change['path'], 'vpn') !== false) {
        $change['type'] = 'vpn_config';
        $change['severity'] = 'medium';
    } elseif (strpos($change['path'], 'log') !== false) {
        $change['type'] = 'logging_config';
        $change['severity'] = 'low';
    }
    
    // Increase severity for critical attributes
    if ($change['attribute'] === 'status' && $change['new_value'] === 'disable') {
        $change['severity'] = 'high';
    }
    
    return $change;
}

// ============================================
// ATTACK DETECTION - BRUTE FORCE
// ============================================

/**
 * Detect brute force attempts in log entries
 * Returns array of detected attacks
 */
function detectBruteForce($logs, $threshold = 10, $time_window = 120) {
    $attempts_by_ip = [];
    
    foreach ($logs as $log) {
        $parsed = parseLogMessage($log['message']);
        
        // Check if it's a failed login attempt
        if (!isFailedAuth($log['message'], $parsed)) continue;
        
        $source_ip = $parsed['srcip'] ?? $parsed['src'] ?? $parsed['source'] ?? 'Unknown';
        $target_ip = $parsed['dstip'] ?? $parsed['dst'] ?? $parsed['target'] ?? '';
        $timestamp = strtotime($log['received_at']);
        
        if (!isset($attempts_by_ip[$source_ip])) {
            $attempts_by_ip[$source_ip] = [
                'attempts' => [],
                'targets' => [],
                'services' => [],
                'usernames' => []
            ];
        }
        
        $attempts_by_ip[$source_ip]['attempts'][] = $timestamp;
        if ($target_ip) $attempts_by_ip[$source_ip]['targets'][$target_ip] = true;
        if (isset($parsed['service'])) $attempts_by_ip[$source_ip]['services'][$parsed['service']] = true;
        if (isset($parsed['user'])) $attempts_by_ip[$source_ip]['usernames'][$parsed['user']] = true;
    }
    
    // Analyze for brute force patterns
    $attacks = [];
    foreach ($attempts_by_ip as $ip => $data) {
        $attempts = $data['attempts'];
        sort($attempts);
        
        // Look for threshold attempts within time window
        for ($i = 0; $i < count($attempts) - $threshold + 1; $i++) {
            $window_start = $attempts[$i];
            $window_end = $attempts[$i + $threshold - 1];
            
            if (($window_end - $window_start) <= $time_window) {
                $attacks[] = [
                    'source_ip' => $ip,
                    'target_ips' => array_keys($data['targets']),
                    'services' => array_keys($data['services']),
                    'usernames' => array_keys($data['usernames']),
                    'attempt_count' => count(array_filter($attempts, function($t) use ($window_start, $window_end) {
                        return $t >= $window_start && $t <= $window_end;
                    })),
                    'time_window' => round(($window_end - $window_start) / 60, 1) . ' min',
                    'first_attempt' => date('Y-m-d H:i:s', $window_start),
                    'last_attempt' => date('Y-m-d H:i:s', $window_end),
                    'risk_level' => count($attempts) >= 20 ? 'critical' : 'high'
                ];
                break; // Found attack, move to next IP
            }
        }
    }
    
    return $attacks;
}

/**
 * Check if log entry is a failed authentication attempt
 */
function isFailedAuth($message, $parsed = null) {
    if (!$parsed) $parsed = parseLogMessage($message);
    
    // Failed login indicators
    $fail_keywords = [
        'auth', 'fail', 'login', 'failed', 'invalid', 'incorrect', 
        'bad', 'password', 'denied', 'rejected'
    ];
    
    $msg_lower = strtolower($message);
    $fail_count = 0;
    foreach ($fail_keywords as $kw) {
        if (strpos($msg_lower, $kw) !== false) $fail_count++;
    }
    
    // Need at least 2 keywords to consider it a failed auth
    return $fail_count >= 2;
}

// ============================================
// ATTACK DETECTION - DDoS
// ============================================

/**
 * Detect DDoS attacks by analyzing traffic patterns
 */
function detectDDoS($logs, $rules) {
    $traffic_by_flow = [];
    $attacks = [];
    
    foreach ($logs as $log) {
        $parsed = parseLogMessage($log['message']);
        
        if (!isset($parsed['srcip'], $parsed['dstip'])) continue;
        
        $flow_key = $parsed['srcip'] . ':' . ($parsed['dstip'] ?? 'any');
        $timestamp = strtotime($log['received_at']);
        $proto = $parsed['proto'] ?? 'unknown';
        
        if (!isset($traffic_by_flow[$flow_key])) {
            $traffic_by_flow[$flow_key] = [
                'source_ip' => $parsed['srcip'],
                'dest_ip' => $parsed['dstip'],
                'protocol' => $proto,
                'packets' => 0,
                'bytes' => 0,
                'first_seen' => $timestamp,
                'last_seen' => $timestamp,
                'connections' => 0
            ];
        }
        
        $traffic_by_flow[$flow_key]['packets'] += intval($parsed['sentpkt'] ?? 1);
        $traffic_by_flow[$flow_key]['bytes'] += intval($parsed['sentbyte'] ?? 0);
        $traffic_by_flow[$flow_key]['connections']++;
        $traffic_by_flow[$flow_key]['last_seen'] = $timestamp;
    }
    
    // Analyze each flow for DDoS patterns
    foreach ($traffic_by_flow as $flow) {
        $duration = max(1, $flow['last_seen'] - $flow['first_seen']);
        $pps = round($flow['packets'] / $duration);
        $mbps = round(($flow['bytes'] * 8) / $duration / 1000000, 2);
        
        $attack_type = null;
        $severity = 'low';
        
        // SYN Flood detection (TCP, high packet rate, low bandwidth)
        if ($flow['protocol'] == '6' && $pps > 10000 && $mbps < 10) {
            $attack_type = 'syn_flood';
            $severity = 'critical';
        }
        // UDP Flood
        elseif ($flow['protocol'] == '17' && $pps > 50000) {
            $attack_type = 'udp_flood';
            $severity = 'critical';
        }
        // Bandwidth saturation
        elseif ($mbps > 100) {
            $attack_type = 'bandwidth_saturation';
            $severity = 'critical';
        }
        // High connection rate
        elseif ($flow['connections'] > 1000 && $duration < 60) {
            $attack_type = 'connection_flood';
            $severity = 'high';
        }
        
        if ($attack_type) {
            $attacks[] = [
                'attack_type' => $attack_type,
                'source_ip' => $flow['source_ip'],
                'target_ip' => $flow['dest_ip'],
                'protocol' => getProtocolName($flow['protocol']),
                'peak_pps' => $pps,
                'peak_mbps' => $mbps,
                'total_packets' => $flow['packets'],
                'total_bytes' => $flow['bytes'],
                'connections' => $flow['connections'],
                'duration' => $duration,
                'severity' => $severity,
                'start_time' => date('Y-m-d H:i:s', $flow['first_seen']),
                'end_time' => date('Y-m-d H:i:s', $flow['last_seen'])
            ];
        }
    }
    
    return $attacks;
}

// ============================================
// ATTACK DETECTION - DNS
// ============================================

/**
 * Detect DNS-based attacks
 */
function detectDNSAttacks($logs) {
    $dns_by_ip = [];
    $attacks = [];
    
    foreach ($logs as $log) {
        $parsed = parseLogMessage($log['message']);
        
        // Check if DNS traffic
        if (!isset($parsed['service']) || $parsed['service'] !== 'DNS') continue;
        if (!isset($parsed['srcip'])) continue;
        
        $source_ip = $parsed['srcip'];
        $timestamp = strtotime($log['received_at']);
        
        if (!isset($dns_by_ip[$source_ip])) {
            $dns_by_ip[$source_ip] = [
                'queries' => 0,
                'nxdomain' => 0,
                'responses' => [],
                'query_sizes' => [],
                'response_sizes' => [],
                'first_seen' => $timestamp,
                'last_seen' => $timestamp
            ];
        }
        
        $dns_by_ip[$source_ip]['queries']++;
        $dns_by_ip[$source_ip]['last_seen'] = $timestamp;
        
        // Track query/response sizes
        $sent = intval($parsed['sentbyte'] ?? 0);
        $rcvd = intval($parsed['rcvdbyte'] ?? 0);
        
        if ($sent > 0) $dns_by_ip[$source_ip]['query_sizes'][] = $sent;
        if ($rcvd > 0) $dns_by_ip[$source_ip]['response_sizes'][] = $rcvd;
        
        // Detect NXDOMAIN (action denied might indicate failed query)
        if (isset($parsed['action']) && $parsed['action'] === 'deny') {
            $dns_by_ip[$source_ip]['nxdomain']++;
        }
    }
    
    // Analyze for DNS attacks
    foreach ($dns_by_ip as $ip => $data) {
        $duration = max(1, $data['last_seen'] - $data['first_seen']);
        $query_rate = round($data['queries'] / $duration);
        $nxdomain_pct = $data['queries'] > 0 ? round(($data['nxdomain'] / $data['queries']) * 100, 2) : 0;
        
        $attack_type = null;
        $severity = 'low';
        
        // Excessive query rate
        if ($query_rate > 100) {
            $attack_type = 'excessive_queries';
            $severity = 'high';
        }
        // NXDOMAIN flood
        elseif ($nxdomain_pct > 50 && $data['queries'] > 20) {
            $attack_type = 'nxdomain_flood';
            $severity = 'medium';
        }
        // DNS amplification (large responses)
        elseif (!empty($data['response_sizes']) && !empty($data['query_sizes'])) {
            $avg_query = array_sum($data['query_sizes']) / count($data['query_sizes']);
            $avg_response = array_sum($data['response_sizes']) / count($data['response_sizes']);
            $amplification = $avg_query > 0 ? round($avg_response / $avg_query, 2) : 0;
            
            if ($amplification > 10) {
                $attack_type = 'amplification';
                $severity = 'high';
            }
        }
        
        if ($attack_type) {
            $attacks[] = [
                'attack_type' => $attack_type,
                'source_ip' => $ip,
                'query_count' => $data['queries'],
                'query_rate_per_sec' => $query_rate,
                'nxdomain_count' => $data['nxdomain'],
                'nxdomain_percentage' => $nxdomain_pct,
                'duration' => $duration,
                'severity' => $severity,
                'start_time' => date('Y-m-d H:i:s', $data['first_seen']),
                'end_time' => date('Y-m-d H:i:s', $data['last_seen'])
            ];
        }
    }
    
    return $attacks;
}

// ============================================
// MITRE ATT&CK MAPPING
// ============================================

/**
 * Map detected event to MITRE ATT&CK technique
 */
function mapToMITRE($event_type, $details = []) {
    $mappings = [
        'bruteforce' => 'T1110',
        'ddos_syn_flood' => 'T1498.001',
        'ddos_udp_flood' => 'T1498.001',
        'ddos_http_flood' => 'T1498',
        'dns_amplification' => 'T1498.002',
        'dns_tunneling' => 'T1071.004',
        'dns_excessive_queries' => 'T1071.004',
        'config_firewall_policy' => 'T1562.004',
        'config_user_account' => 'T1136',
        'config_system' => 'T1562',
        'port_scan' => 'T1046',
        'ips_exploit' => 'T1190'
    ];
    
    return $mappings[$event_type] ?? null;
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

/**
 * Format bytes to human readable
 */
function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

/**
 * Get protocol name from number
 */
function getProtocolName($proto_num) {
    $protocols = [
        '1' => 'ICMP',
        '6' => 'TCP',
        '17' => 'UDP',
        '47' => 'GRE',
        '50' => 'ESP',
        '51' => 'AH'
    ];
    return $protocols[$proto_num] ?? "Proto-$proto_num";
}

/**
 * Calculate time ago in human readable format
 */
function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/**
 * Get severity color class
 */
function getSeverityClass($severity) {
    $classes = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'secondary',
        'info' => 'light'
    ];
    return $classes[$severity] ?? 'secondary';
}

/**
 * Generate cache key for report data
 */
function generateCacheKey($report_type, $params) {
    ksort($params);
    return $report_type . '_' . md5(json_encode($params));
}

/**
 * Check if cached data is valid
 */
function getCachedReport($con, $cache_key, $max_age_seconds = 300) {
    $stmt = $con->prepare("
        SELECT cache_data, generated_at 
        FROM report_cache 
        WHERE cache_key = ? 
        AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param('s', $cache_key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Update hit count
        $con->query("UPDATE report_cache SET hit_count = hit_count + 1 WHERE cache_key = '$cache_key'");
        return json_decode($row['cache_data'], true);
    }
    
    return null;
}

/**
 * Save report data to cache
 */
function cacheReport($con, $cache_key, $report_type, $data, $params, $ttl_seconds = 300) {
    $cache_data = json_encode($data);
    $params_json = json_encode($params);
    $expires_at = date('Y-m-d H:i:s', time() + $ttl_seconds);
    
    $stmt = $con->prepare("
        INSERT INTO report_cache (report_type, cache_key, cache_data, parameters, expires_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            cache_data = VALUES(cache_data),
            parameters = VALUES(parameters),
            generated_at = NOW(),
            expires_at = VALUES(expires_at),
            hit_count = 0
    ");
    $stmt->bind_param('sssss', $report_type, $cache_key, $cache_data, $params_json, $expires_at);
    return $stmt->execute();
}

/**
 * Build SQL WHERE clause from filters
 */
function buildFilterWhere($filters) {
    $conditions = [];
    $params = [];
    $types = '';
    
    if (!empty($filters['device'])) {
        $conditions[] = "source_ip = ?";
        $params[] = $filters['device'];
        $types .= 's';
    }
    
    if (!empty($filters['srcip'])) {
        $conditions[] = "message LIKE ?";
        $params[] = '%srcip=' . $filters['srcip'] . '%';
        $types .= 's';
    }
    
    if (!empty($filters['dstip'])) {
        $conditions[] = "message LIKE ?";
        $params[] = '%dstip=' . $filters['dstip'] . '%';
        $types .= 's';
    }
    
    if (!empty($filters['severity'])) {
        $conditions[] = "message LIKE ?";
        $params[] = '%severity=' . $filters['severity'] . '%';
        $types .= 's';
    }
    
    return [
        'where' => !empty($conditions) ? ' AND ' . implode(' AND ', $conditions) : '',
        'params' => $params,
        'types' => $types
    ];
}
