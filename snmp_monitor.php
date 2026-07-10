<?php
require_once __DIR__ . '/db_config.php';



// snmp_monitor.php - CRON: every 5 minutes
// Fixed version with improved bandwidth calculation

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    error_log('DB Error: ' . mysqli_connect_error());
    exit(1);
}

/* -------------------------------------------------
    1. Ensure tables exist
    ------------------------------------------------- */
mysqli_query($con, "ALTER TABLE snmp_interfaces MODIFY if_descr VARCHAR(512);");
mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS snmp_interfaces (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        if_index INT NOT NULL,
        if_descr VARCHAR(512),
        if_speed BIGINT,
        bytes_in BIGINT,
        bytes_out BIGINT,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dev_if (device_id, if_index)
    ) ENGINE=InnoDB
");

mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS interface_traffic (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        if_index INT NOT NULL,
        if_name VARCHAR(255),
        bytes_in BIGINT,
        bytes_out BIGINT,
        traffic_in_bps BIGINT,
        traffic_out_bps BIGINT,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (device_id, if_index, checked_at)
    ) ENGINE=InnoDB
");

mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS snmp_metrics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL UNIQUE,
        cpu_usage DOUBLE,
        memory_total BIGINT,
        memory_used BIGINT,
        memory_free BIGINT,
        disk_total BIGINT,
        disk_used BIGINT,
        disk_free BIGINT,
        load_1min DOUBLE,
        load_5min DOUBLE,
        load_15min DOUBLE,
        network_in BIGINT DEFAULT 0,
        network_out BIGINT DEFAULT 0,
        processes INT,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

/* -------------------------------------------------
    2. Get SNMP-enabled devices
    ------------------------------------------------- */
$result = mysqli_query($con, "
    SELECT id, ip, snmp_community, snmp_version, snmp_port
    FROM devices
    WHERE snmp_community IS NOT NULL AND snmp_community != ''
");

if (!$result) {
    error_log("DB Query Error: " . mysqli_error($con));
    exit(1);
}

$deviceCount = mysqli_num_rows($result);
echo "Found $deviceCount SNMP-enabled devices.\n";

while ($row = mysqli_fetch_assoc($result)) {
    $device_id = $row['id'];
    $ip = $row['ip'];
    $community = $row['snmp_community'];
    $version = $row['snmp_version'] ?? '2c';
    $port = $row['snmp_port'] ?? 161;

    echo "\n=== Processing Device ID: $device_id, IP: $ip ===\n";
    
    if (!testSNMPConnection($ip, $community, $port)) {
        echo "FAILED: Cannot connect to $ip via SNMP\n";
        continue;
    }
    
    echo "SUCCESS: SNMP connection to $ip established\n";

    $metrics = fetchSNMPMetrics($ip, $community, $version, $port);
    storeSNMPMetrics($con, $device_id, $metrics);
    
    // Collect interface data FIRST
    echo "\n--- Collecting Interface Data ---\n";
    fetchSNMPInterfaces($ip, $community, $version, $port, $device_id, $con);
    
    // THEN calculate bandwidth (needs previous data)
    echo "\n--- Calculating Interface Bandwidth ---\n";
    calculateInterfaceTraffic($con, $device_id, $ip, $community, $version, $port);
}
mysqli_close($con);
echo "\n=== SNMP monitoring cycle complete ===\n";

/* ==============================================================
    TEST SNMP CONNECTION
    ============================================================== */
function testSNMPConnection($ip, $community, $port) {
    $result = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.1.0', 3000000, 2);
    if ($result === false) {
        error_log("SNMP Test Failed for $ip - Check: community string, firewall, SNMP service");
        return false;
    }
    return true;
}

/* ==============================================================
    HELPER: Decode SNMP Hex-String
    ============================================================== */
function decodeSNMPString($raw) {
    if (empty($raw)) return '';
    
    $clean = preg_replace('/^(STRING:\s*|Hex-STRING:\s*)/i', '', trim($raw));

    if (stripos(trim($raw), 'Hex-STRING') === 0) {
        $hexOnly = preg_replace('/[^0-9A-Fa-f]/', '', $clean);
        $decoded = @hex2bin($hexOnly);
        if ($decoded !== false) {
            return trim(str_replace("\0", '', $decoded));
        }
    }
    return trim($clean, '"');
}

/* ==============================================================
    FETCH CORE METRICS - OS-AWARE
    ============================================================== */
function fetchSNMPMetrics($ip, $community, $version, $port)
{
    $metrics = [
        'cpu_usage' => null, 'memory_total' => null, 'memory_used' => null, 'memory_free' => null,
        'disk_total' => null, 'disk_used' => null, 'disk_free' => null,
        'load_1min' => null, 'load_5min' => null, 'load_15min' => null,
        'processes' => null
    ];
    $timeout = 5000000;
    $retries = 3;

    echo "Collecting SNMP metrics from $ip...\n";

    try {
        $sysDescr = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.1.0', $timeout, $retries);
        if ($sysDescr === false) {
            error_log("SNMP[$ip] Failed to get sysDescr");
            return $metrics;
        }
        
        $desc = decodeSNMPString($sysDescr);
        echo "System Description: $desc\n";
        
        $isWindows = stripos($desc, 'windows') !== false;
        $isLinux = (stripos($desc, 'linux') !== false || stripos($desc, 'unix') !== false);

        $fortiTest = @snmp2_get($ip, $community, '.1.3.6.1.4.1.12356.101.4.1.3.0', $timeout, $retries);
        if ($fortiTest !== false || stripos($desc, 'FortiGate') !== false || stripos($desc, 'FortiOS') !== false) {
            echo "Detected: FORTIGATE\n";
            return fetchFortiGateMetrics($ip, $community, $timeout, $retries, $metrics);
        }

        if ($isWindows) {
            echo "Detected: WINDOWS\n";
        } elseif ($isLinux) {
            echo "Detected: LINUX/UNIX\n";
        } else {
            echo "OS Type: UNKNOWN - Attempting generic collection\n";
        }

        $proc = @snmp2_get($ip, $community, '.1.3.6.1.2.1.25.1.6.0', $timeout, $retries);
        if ($proc !== false) {
            $metrics['processes'] = (int)trim(preg_replace('/^(INTEGER|Gauge32):\s*/i', '', $proc));
            echo "Processes: {$metrics['processes']}\n";
        }

        if ($isWindows) {
            $metrics = fetchWindowsCPU($ip, $community, $timeout, $retries, $metrics);
            $metrics = fetchWindowsStorageFixed($ip, $community, $timeout, $retries, $metrics);
        } else {
            $cpuIdle = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.11.11.0', $timeout, $retries);
            if ($cpuIdle !== false) {
                $cpuIdleValue = (float)trim(preg_replace('/^(INTEGER|Gauge32):\s*/i', '', $cpuIdle));
                $metrics['cpu_usage'] = round(100 - $cpuIdleValue, 1);
                echo "CPU Usage: {$metrics['cpu_usage']}%\n";
            }

            $l1 = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.10.1.3.1', $timeout, $retries);
            $l5 = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.10.1.3.2', $timeout, $retries);
            $l15 = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.10.1.3.3', $timeout, $retries);
            if ($l1 !== false) $metrics['load_1min'] = (float)trim(preg_replace('/^STRING:\s*/i', '', $l1));
            if ($l5 !== false) $metrics['load_5min'] = (float)trim(preg_replace('/^STRING:\s*/i', '', $l5));
            if ($l15 !== false) $metrics['load_15min'] = (float)trim(preg_replace('/^STRING:\s*/i', '', $l15));

            $memTot = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.4.5.0', $timeout, $retries);
            $memFre = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.4.6.0', $timeout, $retries);
            if ($memTot !== false && $memFre !== false) {
                $total = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $memTot)) * 1024;
                $free = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $memFre)) * 1024;
                $metrics['memory_total'] = $total;
                $metrics['memory_used'] = $total - $free;
                $metrics['memory_free'] = $free;
                echo "Memory: " . formatBytes($total) . " total, " . formatBytes($total - $free) . " used\n";
            }

            $diskTot = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.9.1.6.1', $timeout, $retries);
            $diskUsd = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.9.1.8.1', $timeout, $retries);
            if ($diskTot !== false && $diskUsd !== false) {
                $total = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $diskTot)) * 1024;
                $used = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $diskUsd)) * 1024;
                $metrics['disk_total'] = $total;
                $metrics['disk_used'] = $used;
                $metrics['disk_free'] = $total - $used;
                echo "Disk: " . formatBytes($total) . " total, " . formatBytes($used) . " used\n";
            }
        }
    } catch (Exception $e) {
        error_log("SNMP Error [$ip]: " . $e->getMessage());
    }

    return $metrics;
}

/* ==============================================================
    FORTIGATE METRICS
    ============================================================== */
function fetchFortiGateMetrics($ip, $community, $timeout, $retries, $metrics) {
    $cpuVal = @snmp2_get($ip, $community, '.1.3.6.1.4.1.12356.101.4.1.3.0', $timeout, $retries);
    if ($cpuVal !== false) {
        $metrics['cpu_usage'] = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $cpuVal));
        echo "CPU: {$metrics['cpu_usage']}%\n";
    }

    $memVal = @snmp2_get($ip, $community, '.1.3.6.1.4.1.12356.101.4.1.4.0', $timeout, $retries);
    if ($memVal !== false) {
        $memPct = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $memVal));
        $metrics['memory_total'] = 100;
        $metrics['memory_used'] = $memPct;
        $metrics['memory_free'] = 100 - $memPct;
        echo "Memory: {$memPct}% used\n";
    }

    $sessVal = @snmp2_get($ip, $community, '.1.3.6.1.4.1.12356.101.4.1.8.0', $timeout, $retries);
    if ($sessVal !== false) {
        $metrics['processes'] = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $sessVal));
        echo "Sessions: {$metrics['processes']}\n";
    }

    return $metrics;
}

/* ==============================================================
    WINDOWS CPU DETECTION
    ============================================================== */
function fetchWindowsCPU($ip, $community, $timeout, $retries, $metrics) {
    echo "Collecting Windows CPU...\n";

    $cpuWalk = @snmp2_walk($ip, $community, '.1.3.6.1.2.1.25.3.3.1.2', $timeout, $retries);
    if ($cpuWalk && is_array($cpuWalk) && count($cpuWalk) > 0) {
        $sum = 0;
        $coreCount = 0;

        foreach ($cpuWalk as $v) {
            $cleanValue = trim(preg_replace('/^(INTEGER|Gauge32):\s*/i', '', $v));
            if (is_numeric($cleanValue)) {
                $sum += (float)$cleanValue;
                $coreCount++;
            }
        }

        if ($coreCount > 0) {
            $metrics['cpu_usage'] = round($sum / $coreCount, 1);
            echo "CPU: {$metrics['cpu_usage']}% (avg of $coreCount cores)\n";
            return $metrics;
        }
    }

    $cpuIdle = @snmp2_get($ip, $community, '.1.3.6.1.4.1.2021.11.11.0', $timeout, $retries);
    if ($cpuIdle !== false) {
        $cpuIdleValue = (float)trim(preg_replace('/^(INTEGER|Gauge32):\s*/i', '', $cpuIdle));
        $metrics['cpu_usage'] = round(100 - $cpuIdleValue, 1);
        echo "CPU: {$metrics['cpu_usage']}% (UCD-SNMP method)\n";
    }

    return $metrics;
}

/* ==============================================================
    WINDOWS STORAGE
    ============================================================== */
function fetchWindowsStorageFixed($ip, $community, $timeout, $retries, $metrics) {
    echo "Collecting Windows storage...\n";

    $descWalk = @snmp2_real_walk($ip, $community, '.1.3.6.1.2.1.25.2.3.1.3', $timeout, $retries);

    if ($descWalk) {
        foreach ($descWalk as $oid => $descRaw) {
            preg_match('/\.(\d+)$/', $oid, $m);
            $index = $m[1];
            $desc = decodeSNMPString($descRaw);

            $units = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.4.$index", $timeout, $retries);
            $size = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.5.$index", $timeout, $retries);
            $used = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.6.$index", $timeout, $retries);

            if ($units !== false && $size !== false && $used !== false) {
                $units = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $units));
                $size = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $size));
                $used = (int)trim(preg_replace('/^INTEGER:\s*/i', '', $used));

                $totalBytes = $size * $units;
                $usedBytes = $used * $units;

                if (stripos($desc, 'Physical Memory') !== false && $totalBytes > 0 && !$metrics['memory_total']) {
                    $metrics['memory_total'] = $totalBytes;
                    $metrics['memory_used'] = $usedBytes;
                    $metrics['memory_free'] = $totalBytes - $usedBytes;
                    echo "Memory: " . formatBytes($totalBytes) . " total\n";
                }

                if ((stripos($desc, 'C:\\') !== false || stripos($desc, 'C:') !== false) && $totalBytes > 0 && !$metrics['disk_total']) {
                    $metrics['disk_total'] = $totalBytes;
                    $metrics['disk_used'] = $usedBytes;
                    $metrics['disk_free'] = $totalBytes - $usedBytes;
                    echo "Disk C: " . formatBytes($totalBytes) . " total\n";
                }
            }
        }
    }

    return $metrics;
}

/* ==============================================================
    FETCH INTERFACES - Store current counters
    ============================================================== */
function fetchSNMPInterfaces($ip, $community, $version, $port, $device_id, $con)
{
    $timeout = 5000000;
    $retries = 3;

    $fortiTest = @snmp2_get($ip, $community, '.1.3.6.1.4.1.12356.101.4.1.3.0', $timeout, $retries);
    $sysDescr = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.1.0', $timeout, $retries);
    $isFortiGate = ($fortiTest !== false || ($sysDescr && stripos($sysDescr, 'FortiGate') !== false));

    $ifOid = $isFortiGate ? '.1.3.6.1.2.1.31.1.1.1.1' : '.1.3.6.1.2.1.2.2.1.2';

    echo "Fetching interface list...\n";
    $descWalk = @snmp2_real_walk($ip, $community, $ifOid, $timeout, $retries);
    
    if (!$descWalk) {
        echo "WARNING: No interfaces found or walk failed\n";
        return;
    }

    $interfaceCount = count($descWalk);
    echo "Found $interfaceCount interfaces\n";

    $stmt = $con->prepare("
        INSERT INTO snmp_interfaces
        (device_id, if_index, if_descr, if_speed, bytes_in, bytes_out)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            if_descr = VALUES(if_descr),
            if_speed = VALUES(if_speed),
            bytes_in = VALUES(bytes_in),
            bytes_out = VALUES(bytes_out),
            checked_at = CURRENT_TIMESTAMP
    ");

    foreach ($descWalk as $oid => $descRaw) {
        preg_match('/\.(\d+)$/', $oid, $m);
        $ifIndex = (int)$m[1];
        $desc = substr(decodeSNMPString($descRaw), 0, 512);

        $speed = $in = $out = null;
        $speedVal = @snmp2_get($ip, $community, ".1.3.6.1.2.1.2.2.1.5.$ifIndex", $timeout, $retries);
        $inVal = @snmp2_get($ip, $community, ".1.3.6.1.2.1.2.2.1.10.$ifIndex", $timeout, $retries);
        $outVal = @snmp2_get($ip, $community, ".1.3.6.1.2.1.2.2.1.16.$ifIndex", $timeout, $retries);

        if ($speedVal !== false) $speed = (int)trim(preg_replace('/^Gauge32:\s*/i', '', $speedVal));
        if ($inVal !== false) $in = (int)trim(preg_replace('/^Counter32:\s*/i', '', $inVal));
        if ($outVal !== false) $out = (int)trim(preg_replace('/^Counter32:\s*/i', '', $outVal));

        $stmt->bind_param('iisiii', $device_id, $ifIndex, $desc, $speed, $in, $out);
        $stmt->execute();
        
        echo "  Interface $ifIndex: $desc (In: " . formatBytes($in) . ", Out: " . formatBytes($out) . ")\n";
    }
    $stmt->close();
}

/* ==============================================================
    CALCULATE INTERFACE TRAFFIC - Calculate bandwidth from deltas
    ============================================================== */
function calculateInterfaceTraffic($con, $device_id, $ip, $community, $version, $port) {
    $timeout = 5000000;
    $retries = 3;

    $fortiTest = @snmp2_get($ip, $community, '.1.3.6.1.4.1.12356.101.4.1.3.0', $timeout, $retries);
    $sysDescr = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.1.0', $timeout, $retries);
    $isFortiGate = ($fortiTest !== false || ($sysDescr && stripos($sysDescr, 'FortiGate') !== false));

    $ifOid = $isFortiGate ? '.1.3.6.1.2.1.31.1.1.1.1' : '.1.3.6.1.2.1.2.2.1.2';

    $descWalk = @snmp2_real_walk($ip, $community, $ifOid, $timeout, $retries);
    if (!$descWalk) {
        echo "WARNING: Cannot fetch interfaces for bandwidth calculation\n";
        return;
    }

    // Get previous traffic data - Use the most recent entry that's at least 2 minutes old
    $prevTraffic = [];
    $result = mysqli_query($con, "
        SELECT 
            it1.if_index, 
            it1.bytes_in as prev_bytes_in, 
            it1.bytes_out as prev_bytes_out, 
            UNIX_TIMESTAMP(it1.checked_at) as prev_timestamp
        FROM interface_traffic it1
        INNER JOIN (
            SELECT if_index, MAX(checked_at) as max_checked
            FROM interface_traffic
            WHERE device_id = $device_id 
              AND checked_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
              AND checked_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            GROUP BY if_index
        ) it2 ON it1.if_index = it2.if_index AND it1.checked_at = it2.max_checked
        WHERE it1.device_id = $device_id
    ");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $prevTraffic[$row['if_index']] = $row;
            echo "    Found previous data for interface {$row['if_index']} from " . date('H:i:s', $row['prev_timestamp']) . "\n";
        }
    }

    $prevCount = count($prevTraffic);
    echo "Found $prevCount interfaces with previous data for bandwidth calculation\n";

    if ($prevCount == 0) {
        echo "INFO: First run - no previous data. Bandwidth will be available after next run.\n";
    }

    $stmt = $con->prepare("
        INSERT INTO interface_traffic
        (device_id, if_index, if_name, bytes_in, bytes_out, traffic_in_bps, traffic_out_bps)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $bandwidthCount = 0;

    foreach ($descWalk as $oid => $descRaw) {
        preg_match('/\.(\d+)$/', $oid, $m);
        $ifIndex = (int)$m[1];
        $ifName = substr(decodeSNMPString($descRaw), 0, 255);

        // Skip loopback
        if (stripos($ifName, 'loopback') !== false || stripos($ifName, 'lo') === 0 || $ifName == '') {
            continue;
        }

        $inVal = @snmp2_get($ip, $community, ".1.3.6.1.2.1.2.2.1.10.$ifIndex", $timeout, $retries);
        $outVal = @snmp2_get($ip, $community, ".1.3.6.1.2.1.2.2.1.16.$ifIndex", $timeout, $retries);

        if ($inVal !== false && $outVal !== false) {
            $bytesIn = (int)trim(preg_replace('/^Counter32:\s*/i', '', $inVal));
            $bytesOut = (int)trim(preg_replace('/^Counter32:\s*/i', '', $outVal));

            $trafficInBps = 0;
            $trafficOutBps = 0;

            // Calculate bandwidth if we have previous data
            if (isset($prevTraffic[$ifIndex])) {
                $prev = $prevTraffic[$ifIndex];
                $timeDiff = time() - $prev['prev_timestamp'];

                if ($timeDiff > 0 && $timeDiff < 600) { // Only if less than 10 minutes
                    $max32 = 4294967295;
                    
                    $bytesInDiff = $bytesIn - $prev['prev_bytes_in'];
                    if ($bytesInDiff < 0) $bytesInDiff += $max32 + 1;

                    $bytesOutDiff = $bytesOut - $prev['prev_bytes_out'];
                    if ($bytesOutDiff < 0) $bytesOutDiff += $max32 + 1;

                    $trafficInBps = ($bytesInDiff * 8) / $timeDiff;
                    $trafficOutBps = ($bytesOutDiff * 8) / $timeDiff;
                    
                    $bandwidthCount++;
                    echo "  $ifName: IN=" . formatBits($trafficInBps) . ", OUT=" . formatBits($trafficOutBps) . " (delta: {$timeDiff}s)\n";
                }
            }

            $trafficInBps_rounded = round($trafficInBps);
            $trafficOutBps_rounded = round($trafficOutBps);

            $stmt->bind_param('iisiiii', $device_id, $ifIndex, $ifName, $bytesIn, $bytesOut, 
                             $trafficInBps_rounded, $trafficOutBps_rounded);
            $stmt->execute();
        }
    }
    $stmt->close();
    
    if ($bandwidthCount > 0) {
        echo "Calculated bandwidth for $bandwidthCount active interfaces\n";
    }
}

/* ==============================================================
    STORE METRICS
    ============================================================== */
function storeSNMPMetrics($con, $device_id, $metrics)
{
    $cpu = $metrics['cpu_usage'] ?? 0;
    $mem_total = $metrics['memory_total'] ?? 0;
    $mem_used = $metrics['memory_used'] ?? 0;
    $mem_free = $metrics['memory_free'] ?? 0;
    $disk_total = $metrics['disk_total'];
    $disk_used = $metrics['disk_used'];
    $disk_free = $metrics['disk_free'];
    $load_1min = $metrics['load_1min'];
    $load_5min = $metrics['load_5min'];
    $load_15min = $metrics['load_15min'];
    $processes = $metrics['processes'] ?? 0;
    $network_in = $metrics['network_in'] ?? 0;
    $network_out = $metrics['network_out'] ?? 0;

    $stmt = $con->prepare("
        INSERT INTO snmp_metrics
        (device_id, cpu_usage, memory_total, memory_used, memory_free,
         disk_total, disk_used, disk_free, load_1min, load_5min, load_15min,
         network_in, network_out, processes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cpu_usage = VALUES(cpu_usage),
            memory_total = VALUES(memory_total),
            memory_used = VALUES(memory_used),
            memory_free = VALUES(memory_free),
            disk_total = VALUES(disk_total),
            disk_used = VALUES(disk_used),
            disk_free = VALUES(disk_free),
            load_1min = VALUES(load_1min),
            load_5min = VALUES(load_5min),
            load_15min = VALUES(load_15min),
            network_in = VALUES(network_in),
            network_out = VALUES(network_out),
            processes = VALUES(processes),
            checked_at = CURRENT_TIMESTAMP
    ");

    $stmt->bind_param(
        'iddddddddiddii',
        $device_id, $cpu, $mem_total, $mem_used, $mem_free,
        $disk_total, $disk_used, $disk_free,
        $load_1min, $load_5min, $load_15min,
        $network_in, $network_out, $processes
    );
    $stmt->execute();
    $stmt->close();
    
    echo "Metrics stored successfully\n";
}

// Helper functions
function formatBits($bits) {
    if ($bits < 1000) return round($bits, 1) . ' bps';
    if ($bits < 1000000) return round($bits/1000, 1) . ' Kbps';
    if ($bits < 1000000000) return round($bits/1000000, 1) . ' Mbps';
    return round($bits/1000000000, 1) . ' Gbps';
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes/1048576, 1) . ' MB';
    return round($bytes/1073741824, 1) . ' GB';
}
?>
