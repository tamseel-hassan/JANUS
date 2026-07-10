<?php
/**
 * Threat Intelligence API Handler
 * Integrates with VirusTotal, AbuseIPDB, and other threat intel sources
 * Handles caching, rate limiting, and unified response format
 */
require_once __DIR__ . '/../db_config.php';

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/threat_check_errors.log');

header('Content-Type: application/json');

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    echo json_encode(['error' => 'Database connection failed: ' . mysqli_connect_error()]);
    exit;
}

// Get API keys from database
function getApiKey($con, $service) {
    $stmt = $con->prepare("SELECT api_key, is_enabled FROM api_config WHERE service_name = ?");
    $stmt->bind_param('s', $service);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['is_enabled'] ? $row['api_key'] : null;
    }
    return null;
}

// Check rate limits
function checkRateLimit($con, $service) {
    $stmt = $con->prepare("SELECT requests_today, daily_limit, last_reset FROM api_config WHERE service_name = ?");
    $stmt->bind_param('s', $service);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $today = date('Y-m-d');
        if ($row['last_reset'] !== $today) {
            // Reset counter for new day
            $con->query("UPDATE api_config SET requests_today = 0, last_reset = '$today' WHERE service_name = '$service'");
            return true;
        }
        return $row['requests_today'] < $row['daily_limit'];
    }
    return false;
}

// Increment request counter
function incrementRequests($con, $service) {
    $con->query("UPDATE api_config SET requests_today = requests_today + 1 WHERE service_name = '$service'");
}

// Cache result
function cacheResult($con, $type, $value, $source, $data, $risk_score, $is_malicious) {
    $value_escaped = mysqli_real_escape_string($con, $value);
    $data_json = mysqli_real_escape_string($con, json_encode($data));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Delete old cache for this observable+source
    $con->query("DELETE FROM threat_intel_cache WHERE observable_type = '$type' AND observable_value = '$value_escaped' AND source = '$source'");
    
    // Insert new cache
    $stmt = $con->prepare("INSERT INTO threat_intel_cache (observable_type, observable_value, source, result_data, risk_score, is_malicious, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssiss', $type, $value, $source, $data_json, $risk_score, $is_malicious, $expires);
    $stmt->execute();
}

/**
 * VirusTotal IP Lookup
 */
function checkVirusTotalIP($ip, $api_key) {
    $url = "https://www.virustotal.com/api/v3/ip_addresses/" . $ip;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-apikey: $api_key",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For SSL issues
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log for debugging
    error_log("VT IP Check - HTTP: $http_code, Error: $curl_error");
    
    if ($curl_error) {
        return [
            'success' => false,
            'message' => "CURL Error: $curl_error"
        ];
    }
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            $stats = $data['data']['attributes']['last_analysis_stats'] ?? [];
            $malicious = $stats['malicious'] ?? 0;
            $suspicious = $stats['suspicious'] ?? 0;
            $total = array_sum($stats);
            
            $risk_score = $total > 0 ? round((($malicious + $suspicious * 0.5) / $total) * 100) : 0;
            
            return [
                'success' => true,
                'risk_score' => $risk_score,
                'is_malicious' => $malicious > 0,
                'details' => [
                    'malicious' => $malicious,
                    'suspicious' => $suspicious,
                    'harmless' => $stats['harmless'] ?? 0,
                    'undetected' => $stats['undetected'] ?? 0,
                    'total_engines' => $total,
                    'reputation' => $data['data']['attributes']['reputation'] ?? 0,
                    'country' => $data['data']['attributes']['country'] ?? 'Unknown'
                ]
            ];
        }
    } elseif ($http_code === 401) {
        return [
            'success' => false,
            'message' => 'Invalid API key (401 Unauthorized)'
        ];
    } elseif ($http_code === 404) {
        return [
            'success' => true,
            'risk_score' => 0,
            'is_malicious' => false,
            'message' => 'IP not found in VirusTotal database'
        ];
    }
    
    return [
        'success' => false,
        'message' => "API returned HTTP $http_code: " . substr($response, 0, 200)
    ];
}

/**
 * VirusTotal Hash Lookup
 */
function checkVirusTotalHash($hash, $api_key) {
    $url = "https://www.virustotal.com/api/v3/files/" . $hash;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-apikey: $api_key",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log for debugging
    error_log("VT Hash Check - HTTP: $http_code, Error: $curl_error");
    
    if ($curl_error) {
        return [
            'success' => false,
            'message' => "CURL Error: $curl_error"
        ];
    }
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            $stats = $data['data']['attributes']['last_analysis_stats'] ?? [];
            $malicious = $stats['malicious'] ?? 0;
            $suspicious = $stats['suspicious'] ?? 0;
            $total = array_sum($stats);
            
            $risk_score = $total > 0 ? round((($malicious + $suspicious * 0.5) / $total) * 100) : 0;
            
            // Get threat names
            $threat_names = [];
            $results = $data['data']['attributes']['last_analysis_results'] ?? [];
            foreach ($results as $engine => $result) {
                if ($result['category'] === 'malicious' && !empty($result['result'])) {
                    $threat_names[] = $result['result'];
                }
            }
            
            return [
                'success' => true,
                'risk_score' => $risk_score,
                'is_malicious' => $malicious > 0,
                'details' => [
                    'detected_engines' => $malicious,
                    'suspicious_engines' => $suspicious,
                    'total_engines' => $total,
                    'threat_names' => implode(', ', array_unique(array_slice($threat_names, 0, 10))),
                    'file_type' => $data['data']['attributes']['type_description'] ?? 'Unknown',
                    'size' => $data['data']['attributes']['size'] ?? 0,
                    'first_seen' => isset($data['data']['attributes']['first_submission_date']) ? date('Y-m-d', $data['data']['attributes']['first_submission_date']) : 'Unknown'
                ]
            ];
        }
    } elseif ($http_code === 404) {
        return [
            'success' => true,
            'risk_score' => 0,
            'is_malicious' => false,
            'message' => 'Hash not found in VirusTotal database (no previous submissions)'
        ];
    } elseif ($http_code === 401) {
        return [
            'success' => false,
            'message' => 'Invalid API key (401 Unauthorized)'
        ];
    }
    
    return [
        'success' => false,
        'message' => "API returned HTTP $http_code: " . substr($response, 0, 200)
    ];
}

/**
 * AbuseIPDB Lookup
 */
function checkAbuseIPDB($ip, $api_key) {
    $url = "https://api.abuseipdb.com/api/v2/check";
    $url .= "?ipAddress=" . urlencode($ip) . "&maxAgeInDays=90&verbose";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Key: $api_key",
        "Accept: application/json"
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            $abuse_score = $data['data']['abuseConfidenceScore'];
            $is_malicious = $abuse_score > 25;
            
            return [
                'success' => true,
                'risk_score' => $abuse_score,
                'is_malicious' => $is_malicious,
                'details' => [
                    'abuse_score' => $abuse_score,
                    'total_reports' => $data['data']['totalReports'],
                    'distinct_users' => $data['data']['numDistinctUsers'],
                    'country' => $data['data']['countryCode'] ?? 'Unknown',
                    'isp' => $data['data']['isp'] ?? 'Unknown',
                    'is_whitelisted' => $data['data']['isWhitelisted'] ?? false
                ]
            ];
        }
    }
    
    return [
        'success' => false,
        'message' => 'API returned HTTP ' . $http_code
    ];
}

/**
 * Simple reputation check (free, no API key)
 */
function checkSimpleReputation($type, $value) {
    // This is a mock function - in production, you'd use other free services
    // or build your own reputation database
    
    if ($type === 'ip') {
        // Check if private IP
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return [
                'success' => true,
                'risk_score' => 0,
                'is_malicious' => false,
                'details' => [
                    'status' => 'Private/Reserved IP',
                    'note' => 'This is a private or reserved IP address'
                ]
            ];
        }
    }
    
    // For demo purposes, return neutral
    return [
        'success' => true,
        'risk_score' => 0,
        'is_malicious' => false,
        'details' => [
            'status' => 'No reputation data available',
            'note' => 'Configure API keys in settings for full analysis'
        ]
    ];
}

// Main handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $value = trim($_POST['value'] ?? '');
    
    if (empty($type) || empty($value)) {
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }
    
    $results = [];
    
    // Check VirusTotal
    if ($type === 'ip' || $type === 'hash') {
        $vt_key = getApiKey($con, 'virustotal');
        if ($vt_key && checkRateLimit($con, 'virustotal')) {
            $vt_result = $type === 'ip' ? checkVirusTotalIP($value, $vt_key) : checkVirusTotalHash($value, $vt_key);
            if ($vt_result['success']) {
                incrementRequests($con, 'virustotal');
                cacheResult($con, $type, $value, 'virustotal', $vt_result, $vt_result['risk_score'], $vt_result['is_malicious']);
                $results['virustotal'] = $vt_result;
            }
        } else {
            // Use simple check as fallback
            $simple = checkSimpleReputation($type, $value);
            $results['virustotal'] = $simple;
            $results['virustotal']['message'] = 'VirusTotal API not configured or rate limited';
        }
    }
    
    // Check AbuseIPDB for IPs
    if ($type === 'ip') {
        $abuse_key = getApiKey($con, 'abuseipdb');
        if ($abuse_key && checkRateLimit($con, 'abuseipdb')) {
            $abuse_result = checkAbuseIPDB($value, $abuse_key);
            if ($abuse_result['success']) {
                incrementRequests($con, 'abuseipdb');
                cacheResult($con, $type, $value, 'abuseipdb', $abuse_result, $abuse_result['risk_score'], $abuse_result['is_malicious']);
                $results['abuseipdb'] = $abuse_result;
            }
        } else {
            $results['abuseipdb'] = [
                'success' => false,
                'message' => 'AbuseIPDB API not configured or rate limited'
            ];
        }
    }
    
    // If no results, provide basic check
    if (empty($results)) {
        $results['basic'] = checkSimpleReputation($type, $value);
    }
    
    echo json_encode($results);
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
