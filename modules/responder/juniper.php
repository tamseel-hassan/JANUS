<?php
/**
 * modules/responder/juniper.php - Juniper SRX API client
 * Uses SSH-based CLI commands via ssh2_connect for address-book management
 */
require_once __DIR__ . '/../../db_config.php';

class JuniperAPI {
    private $ip;
    private $username;
    private $password;
    private $port;

    public function __construct(string $ip, string $username, string $password, int $port = 22) {
        $this->ip = $ip;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
    }

    public function testConnection(): array {
        if (!function_exists('ssh2_connect')) {
            return ['success' => false, 'error' => 'ssh2 extension not installed'];
        }

        $conn = @ssh2_connect($this->ip, $this->port);
        if (!$conn) {
            return ['success' => false, 'error' => 'SSH connection failed'];
        }

        if (!@ssh2_auth_password($conn, $this->username, $this->password)) {
            return ['success' => false, 'error' => 'SSH authentication failed'];
        }

        $stream = @ssh2_exec($conn, 'show version');
        if (!$stream) {
            return ['success' => false, 'error' => 'Command execution failed'];
        }

        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);
        ssh2_disconnect($conn);

        $version = 'Unknown';
        if (preg_match('/JUNOS Software Release \[([^\]]+)\]/', $output, $m) ||
            preg_match('/JUNOS (\S+)/', $output, $m)) {
            $version = $m[1];
        }

        return ['success' => true, 'version' => $version];
    }

    public function blockIP(string $ip, string $reason): array {
        if (!function_exists('ssh2_connect')) {
            return ['success' => false, 'error' => 'ssh2 extension not installed'];
        }

        $conn = @ssh2_connect($this->ip, $this->port);
        if (!$conn) {
            return ['success' => false, 'error' => 'SSH connection failed'];
        }

        if (!@ssh2_auth_password($conn, $this->username, $this->password)) {
            return ['success' => false, 'error' => 'SSH authentication failed'];
        }

        $addrName = "janus_block_" . str_replace('.', '_', $ip);

        // Build candidate configuration commands
        $cmds = [
            "configure",
            "set security address-book global address {$addrName} {$ip}/32",
            "set security address-book global address-set janus-block-set address {$addrName}",
            "set security policies from-zone untrust to-zone untrust policy janus-block-{$addrName} match source-address {$addrName}",
            "set security policies from-zone untrust to-zone untrust policy janus-block-{$addrName} match destination-address any",
            "set security policies from-zone untrust to-zone untrust policy janus-block-{$addrName} match application any",
            "set security policies from-zone untrust to-zone untrust policy janus-block-{$addrName} then deny",
            "set security policies from-zone untrust to-zone untrust policy janus-block-{$addrName} then log-init",
            "set security policies from-zone untrust to-zone untrust policy janus-block-{$addrName} description \"Janus Block: {$reason}\"",
            "commit and-quit",
        ];

        $output = $this->runCommands($conn, $cmds);
        ssh2_disconnect($conn);

        if (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) {
            return ['success' => false, 'error' => 'Configuration failed: ' . substr($output, 0, 500)];
        }

        return [
            'success' => true,
            'address_created' => $addrName,
            'commands_output' => substr($output, 0, 500),
        ];
    }

    public function unblockIP(string $addrName): array {
        if (!function_exists('ssh2_connect')) {
            return ['success' => false, 'error' => 'ssh2 extension not installed'];
        }

        $conn = @ssh2_connect($this->ip, $this->port);
        if (!$conn) {
            return ['success' => false, 'error' => 'SSH connection failed'];
        }

        if (!@ssh2_auth_password($conn, $this->username, $this->password)) {
            return ['success' => false, 'error' => 'SSH authentication failed'];
        }

        $cmds = [
            "configure",
            "delete security address-book global address {$addrName}",
            "delete security address-book global address-set janus-block-set address {$addrName}",
            "delete security policies from-zone untrust to-zone untrust policy janus-block-{$addrName}",
            "commit and-quit",
        ];

        $output = $this->runCommands($conn, $cmds);
        ssh2_disconnect($conn);

        if (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) {
            return ['success' => false, 'error' => 'Delete failed: ' . substr($output, 0, 500)];
        }

        return ['success' => true];
    }

    private function runCommands($conn, array $cmds): string {
        $command = implode("\n", $cmds);
        $stream = @ssh2_exec($conn, $command);
        if (!$stream) return 'Failed to execute commands';

        stream_set_blocking($stream, true);
        stream_set_timeout($stream, 30);
        $output = stream_get_contents($stream);
        fclose($stream);
        return $output;
    }
}
