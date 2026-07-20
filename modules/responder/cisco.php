<?php
/**
 * modules/responder/cisco.php - Cisco ASA REST API client
 * Uses ASA REST API v10.x+ (/api/objects/)
 */
require_once __DIR__ . '/../../db_config.php';

class CiscoASAAPI {
    private $ip;
    private $username;
    private $password;
    private $port;
    private $token;

    public function __construct(string $ip, string $username, string $password, int $port = 443) {
        $this->ip = $ip;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
    }

    private function auth(): bool {
        if ($this->token) return true;
        $url = "https://{$this->ip}:{$this->port}/api/auth/tokens";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['username' => $this->username, 'password' => $this->password]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headers = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        curl_close($ch);

        if ($httpCode === 201 || $httpCode === 200) {
            $data = json_decode($response, true);
            $this->token = $data['token'] ?? null;
            return $this->token !== null;
        }
        return false;
    }

    public function testConnection(): array {
        if (!$this->auth()) {
            return ['success' => false, 'error' => 'Authentication failed'];
        }
        $url = "https://{$this->ip}:{$this->port}/api/monitoring/deviceplatform";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return ['success' => true, 'version' => $data['softwareVersion'] ?? 'Unknown', 'model' => $data['model'] ?? 'Unknown'];
        }
        return ['success' => false, 'error' => "HTTP $httpCode"];
    }

    public function blockIP(string $ip, string $reason): array {
        if (!$this->auth()) {
            return ['success' => false, 'error' => 'Authentication failed'];
        }

        $networkObj = "janus_block_" . str_replace('.', '_', $ip);

        // Create network object
        $result = $this->apiCall('POST', '/api/objects/networkobjects/' . urlencode($networkObj), [
            'kind' => 'object#NetworkObject',
            'value' => $ip,
            'host' => $ip,
            'description' => $reason,
        ]);

        if (!$result['success']) {
            return $result;
        }

        // Create access rule to deny traffic from this object
        $ruleResult = $this->apiCall('POST', '/api/objects/accessrules', [
            'kind' => 'object#ACLRule',
            'sourceAddress' => [['kind' => 'object#NetworkObject', '$ref' => '/api/objects/networkobjects/' . urlencode($networkObj)]],
            'destinationAddress' => [['kind' => 'object#NetworkObject', '$ref' => '/api/objects/networkobjects/hostany']],
            'service' => [['kind' => 'object#NetworkServiceObject', '$ref' => '/api/objects/networkservices/any']],
            'action' => 'deny',
            'enabled' => true,
            'logEvents' => 'all',
            'description' => "Janus Block: $reason",
            'position' => '1',
        ]);

        return [
            'success' => true,
            'address_created' => $networkObj,
            'rule_created' => $ruleResult['success'],
        ];
    }

    public function unblockIP(string $networkObj): array {
        if (!$this->auth()) {
            return ['success' => false, 'error' => 'Authentication failed'];
        }

        // Remove network object
        $delNet = $this->apiCall('DELETE', '/api/objects/networkobjects/' . urlencode($networkObj));

        return $delNet;
    }

    private function apiCall(string $method, string $path, array $data = null): array {
        $url = "https://{$this->ip}:{$this->port}{$path}";
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'response' => json_decode($response, true)];
        }
        return ['success' => false, 'error' => "HTTP $httpCode - " . ($response ?: 'Request failed')];
    }
}
