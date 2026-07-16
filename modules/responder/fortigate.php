<?php
/**
 * modules/responder/fortigate.php - FortiGate REST API client
 * Extracted from the old responder.php for reuse across responder module
 */
require_once __DIR__ . '/../../db_config.php';

class FortiGateAPI {
    private $ip;
    private $api_key;

    public function __construct(string $ip, string $api_key) {
        $this->ip = $ip;
        $this->api_key = $api_key;
    }

    public function testConnection(): array {
        $url = "https://{$this->ip}/api/v2/monitor/system/status";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->api_key],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            return ['success' => true, 'version' => $data['version'] ?? 'Unknown'];
        }
        return ['success' => false, 'error' => "HTTP $httpCode - " . ($response ?: 'Connection failed')];
    }

    public function blockIP(string $ip, string $reason): array {
        $addressName = "janus_block_" . str_replace('.', '_', $ip) . "_" . time();

        // Create address object
        $result = $this->apiCall('POST', '/api/v2/cmdb/firewall/address', [
            'name' => $addressName,
            'type' => 'ipmask',
            'subnet' => "$ip 255.255.255.255",
            'comment' => $reason,
        ]);

        if (!$result['success']) {
            return $result;
        }

        // Create deny policy
        $policyResult = $this->apiCall('POST', '/api/v2/cmdb/firewall/policy', [
            'name' => "JANUS_Block_" . time(),
            'srcintf' => [['name' => 'any']],
            'dstintf' => [['name' => 'any']],
            'srcaddr' => [['name' => $addressName]],
            'dstaddr' => [['name' => 'all']],
            'action' => 'deny',
            'schedule' => 'always',
            'service' => [['name' => 'ALL']],
            'logtraffic' => 'all',
            'comments' => $reason,
        ]);

        return [
            'success' => true,
            'address_created' => $addressName,
            'policy_created' => $policyResult['success'],
        ];
    }

    public function unblockIP(string $addressName): array {
        return $this->apiCall('DELETE', "/api/v2/cmdb/firewall/address/$addressName");
    }

    private function apiCall(string $method, string $path, array $data = null): array {
        $url = "https://{$this->ip}{$path}";
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
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

    public static function getActiveFirewall(mysqli $con): ?array {
        $result = mysqli_query($con, "SELECT * FROM response_config WHERE is_active = 1 LIMIT 1");
        return $result ? mysqli_fetch_assoc($result) : null;
    }
}
