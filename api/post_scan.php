<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

set_time_limit(300);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $target = trim($data['target'] ?? '');

    if (empty($target)) {
        echo json_encode(['error' => 'No target provided']);
        exit;
    }

    if (!filter_var($target, FILTER_VALIDATE_IP) && !filter_var($target, FILTER_VALIDATE_DOMAIN)) {
        echo json_encode(['error' => 'Invalid Target Format. Please use a valid IP or Domain.']);
        exit;
    }
    
    $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $check_cmd = $is_windows ? 'where nmap 2>nul' : 'which nmap 2>/dev/null';
    if (!shell_exec($check_cmd)) {
        echo json_encode(['error' => 'Nmap is not installed on this server.']);
        exit;
    }
    
    $start_time = microtime(true);
    $cmd = "nmap -sV --open -T4 -oX - " . escapeshellarg($target);
    $xmlOutput = shell_exec($cmd);
    $duration = microtime(true) - $start_time;

    if ($xmlOutput && strpos($xmlOutput, '<?xml') !== false) {
        try {
            $xml = new SimpleXMLElement($xmlOutput);
            if (!isset($xml->host)) {
                echo json_encode(['status' => 'down', 'message' => 'No host data returned by Nmap. Host might be down or blocking probes.']);
                exit;
            }
            $status = (string)$xml->host->status['state'];
            $addr = (string)$xml->host->address['addr'];
            $hostName = (string)$xml->host->hostnames->hostname['name'] ?? 'Unknown DNS';
            
            if ($status !== 'up') {
                echo json_encode(['status' => 'down', 'message' => 'Host appears DOWN or is blocking ping probes.']);
                exit;
            }
            
            $parsed_ports = [];
            $vuln_count = 0;

            foreach ($xml->host->ports->port as $port) {
                $portID = (string)$port['portid'];
                $protocol = (string)$port['protocol'];
                $state = (string)$port->state['state'];
                $serviceName = (string)$port->service['name'];
                $product = (string)$port->service['product'];
                $version = (string)$port->service['version'];
                
                $risk_level = 'low';
                $desc = 'Standard service';
                
                if (in_array($portID, ['21','23','445','3389'])) {
                    $risk_level = 'critical';
                    $desc = 'High-value target protocol';
                    $vuln_count++;
                } elseif (!empty($version) && (strpos($version, '1.') === 0 || strpos($version, 'old') !== false)) {
                    $risk_level = 'high';
                    $desc = 'Potentially outdated version';
                    $vuln_count++;
                } elseif (in_array($portID, ['80', '443', '8080'])) {
                    $risk_level = 'medium';
                    $desc = 'Web Service - Check for app vulns';
                }
                
                $parsed_ports[] = [
                    'port' => $portID,
                    'protocol' => strtoupper($protocol),
                    'state' => $state,
                    'service' => ucfirst($serviceName),
                    'product' => $product,
                    'version' => $version ?: 'Unknown',
                    'risk_level' => $risk_level,
                    'desc' => $desc
                ];
            }
            
            $result_data = [
                'ports'      => $parsed_ports,
                'os_matches' => [], // Add OS matches if nmap -O is added later
                'vuln_count' => $vuln_count,
                'duration'   => $duration,
                'open_ports' => count($parsed_ports),
            ];

            $result_json = mysqli_real_escape_string($con, json_encode($result_data));
            $t = mysqli_real_escape_string($con, $target);
            mysqli_query($con, "INSERT INTO scanner_records (target_ip, scan_type, result) VALUES ('$t', 'Quick Scan', '$result_json')");

            echo json_encode([
                'status' => 'up',
                'address' => $addr,
                'hostname' => $hostName,
                'ports' => $parsed_ports,
                'raw_xml' => $xmlOutput
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to parse scan results: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'Scan failed or produced invalid output']);
    }
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
