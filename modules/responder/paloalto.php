<?php
/**
 * modules/responder/paloalto.php - Palo Alto PAN-OS XML API client
 * Uses PAN-OS XML API for address objects and security policies
 */
require_once __DIR__ . '/../../db_config.php';

class PaloAltoAPI {
    private $ip;
    private $api_key;
    private $vsys;

    public function __construct(string $ip, string $api_key, string $vsys = 'vsys1') {
        $this->ip = $ip;
        $this->api_key = $api_key;
        $this->vsys = $vsys;
    }

    public function testConnection(): array {
        $url = "https://{$this->ip}/api/?type=op&cmd=<show><system><info></info></system></show>&key={$this->api_key}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && strpos($response, '<result>') !== false) {
            $xml = @simplexml_load_string($response);
            if ($xml && $xml->result) {
                $info = $xml->result->system->info;
                return ['success' => true, 'version' => (string)$info->sw_version ?? 'Unknown', 'model' => (string)$info->model ?? 'Unknown'];
            }
        }
        return ['success' => false, 'error' => "HTTP $httpCode - Connection failed"];
    }

    public function blockIP(string $ip, string $reason): array {
        $objName = "janus_block_" . str_replace('.', '_', $ip);

        // Create address object
        $createResult = $this->xmlApiCall('type=config', [
            'action' => 'set',
            'xpath' => "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='{$this->vsys}']/address/entry[@name='{$objName}']",
            'element' => "<entry name='{$objName}'><ip-netmask>{$ip}/32</ip-netmask><description>" . htmlspecialchars($reason) . "</description></entry>",
        ]);

        if (!$createResult['success']) {
            return $createResult;
        }

        // Add to block rule (create rule if it doesn't exist)
        $ruleName = 'Janus_Block_Rule';
        $ruleCheck = $this->xmlApiCall('type=config', [
            'action' => 'get',
            'xpath' => "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='{$this->vsys}']/rulebase/security/rules/entry[@name='{$ruleName}']",
        ]);

        if (!$ruleCheck['success'] || strpos($ruleCheck['response'] ?? '', "<entry name='{$ruleName}'") === false) {
            // Create the block rule
            $this->xmlApiCall('type=config', [
                'action' => 'set',
                'xpath' => "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='{$this->vsys}']/rulebase/security/rules",
                'element' => "<entry name='{$ruleName}'>
                    <from><member>any</member></from>
                    <to><member>any</member></to>
                    <source><member>{$objName}</member></source>
                    <destination><member>any</member></destination>
                    <application><member>any</member></application>
                    <service><member>any</member></service>
                    <action>deny</action>
                    <log-start>yes</log-start>
                    <log-end>yes</log-end>
                    <description>Janus auto-block rule</description>
                </entry>",
            ]);
        } else {
            // Rule exists, add source to it
            $this->xmlApiCall('type=config', [
                'action' => 'set',
                'xpath' => "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='{$this->vsys}']/rulebase/security/rules/entry[@name='{$ruleName}']/source",
                'element' => "<member>{$objName}</member>",
            ]);
        }

        // Commit
        $this->xmlApiCall('type=commit', [], '<commit></commit>');

        return [
            'success' => true,
            'address_created' => $objName,
            'rule_updated' => true,
        ];
    }

    public function unblockIP(string $objName): array {
        // Remove address object
        $delResult = $this->xmlApiCall('type=config', [
            'action' => 'delete',
            'xpath' => "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='{$this->vsys}']/address/entry[@name='{$objName}']",
        ]);

        if ($delResult['success']) {
            $this->xmlApiCall('type=commit', [], '<commit></commit>');
        }

        return $delResult;
    }

    private function xmlApiCall(string $typeParam, array $params = [], ?string $extraXml = null): array {
        $queryParts = [$typeParam];
        foreach ($params as $k => $v) {
            $queryParts[] = urlencode($k) . '=' . urlencode($v);
        }
        $queryParts[] = 'key=' . $this->api_key;
        $url = "https://{$this->ip}/api/?" . implode('&', $queryParts);

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($extraXml !== null && strpos($typeParam, 'commit') !== false) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $extraXml;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/xml'];
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && strpos($response, '<result>success</result>') !== false) {
            return ['success' => true, 'response' => $response];
        }

        $errMsg = '';
        if (strpos($response, '<msg>') !== false) {
            preg_match('/<msg>(.*?)<\/msg>/', $response, $m);
            $errMsg = $m[1] ?? '';
        }

        return ['success' => false, 'error' => "HTTP $httpCode - " . ($errMsg ?: 'Request failed')];
    }
}
