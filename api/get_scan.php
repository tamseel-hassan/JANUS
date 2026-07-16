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
    
    if (!shell_exec('which nmap')) {
        echo json_encode(['error' => 'Nmap is not installed on this server.']);
        exit;
    }
    
    $cmd = "nmap -sV --open -T4 -oX - " . escapeshellarg($target);
    $xmlOutput = shell_exec($cmd);

    if ($xmlOutput && strpos($xmlOutput, '<?xml') !== false) {
        try {
            $xml = new SimpleXMLElement($xmlOutput);
            $status = (string)$xml->host->status['state'];
            $addr = (string)$xml->host->address['addr'];
            $hostName = (string)$xml->host->hostnames->hostname['name'] ?? 'Unknown DNS';
            
            if ($status !== 'up') {
                echo json_encode(['status' => 'down', 'message' => 'Host appears DOWN or is blocking ping probes.']);
                exit;
            }
            
            $parsed_ports = [];
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
                } elseif (!empty($version) && (strpos($version, '1.') === 0 || strpos($version, 'old') !== false)) {
                    $risk_level = 'high';
                    $desc = 'Potentially outdated version';
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
