<?php
require_once __DIR__ . '/../db_config.php';
// ss/_helpers.php - shared helpers for all report modules
// Included (not executed standalone) by each ss/*.php report.

if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        if ($bytes == 0) return '0 B';
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . ['B','KB','MB','GB','TB'][$i];
    }
}

if (!function_exists('parseMessage')) {
    function parseMessage($msg) {
        $parsed = [];
        if (preg_match_all('/(\w+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
                $parsed[$m[1]] = $value;
            }
        }
        return $parsed;
    }
}

if (!function_exists('getPortName')) {
    function getPortName($port) {
        $port_map = [
            '20' => 'FTP-DATA', '21' => 'FTP', '22' => 'SSH', '23' => 'Telnet',
            '25' => 'SMTP', '53' => 'DNS', '80' => 'HTTP', '110' => 'POP3',
            '143' => 'IMAP', '161' => 'SNMP', '389' => 'LDAP', '443' => 'HTTPS',
            '445' => 'SMB', '500' => 'IKE/IPsec', '636' => 'LDAPS', '993' => 'IMAPS',
            '995' => 'POP3S', '1433' => 'MS-SQL', '1521' => 'Oracle', '3306' => 'MySQL',
            '3389' => 'RDP', '4500' => 'NAT-T/IPsec', '5432' => 'PostgreSQL',
            '5900' => 'VNC', '8080' => 'HTTP-ALT', '8443' => 'HTTPS-ALT'
        ];
        return $port_map[$port] ?? "Port-$port";
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $diff = time() - strtotime($datetime);
        if ($diff < 0) $diff = 0;
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'min ago';
        if ($diff < 86400) return floor($diff / 3600) . 'hr ago';
        return floor($diff / 86400) . 'd ago';
    }
}

// Runs one UNION ALL query across the live + archive syslog tables.
// $select must be a valid column list, $where a fully-formed WHERE clause (already escaped).
if (!function_exists('unionQuery')) {
    function unionQuery($con, $select, $where, $order = '', $limit = 0) {
        $sql = "
            SELECT $select FROM (
                SELECT $select FROM syslog_entries WHERE $where
                UNION ALL
                SELECT $select FROM syslog_entries_archive WHERE $where
            ) AS combined
        ";
        if ($order) $sql .= " ORDER BY $order";
        if ($limit) $sql .= " LIMIT " . (int)$limit;
        return mysqli_query($con, $sql);
    }
}

if (!function_exists('scoreClass')) {
    function scoreClass($score) {
        if ($score >= 70) return 'score-red';
        if ($score >= 45) return 'score-amber';
        return 'score-green';
    }
}

if (!function_exists('renderNoFirewallNotice')) {
    function renderNoFirewallNotice($has_firewall, $firewall_ip) {
        if ($has_firewall) {
            echo '<div class="alert alert-info"><i data-lucide="shield" class="icon-lucide"></i> <strong>Automated Response Available:</strong> Connected to firewall <code>' . htmlspecialchars($firewall_ip) . '</code>.</div>';
        }
    }
}
// Additional helpers required by endpoint reports
function unionQuery($con, $columns, $where, $order = '', $limit = 0) {
    $sql = "SELECT $columns FROM syslog_entries WHERE $where";
    if ($order) $sql .= " ORDER BY $order";
    if ($limit > 0) $sql .= " LIMIT $limit";
    return mysqli_query($con, $sql);
}
function parseMessage($msg) {
    $parsed = [];
    if (preg_match_all('/(\w+)=("([^"]*)"|\'([^\']*)\'|([^ \t]+))/', $msg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $value = $m[3] !== '' ? $m[3] : ($m[4] !== '' ? $m[4] : $m[5]);
            $parsed[$m[1]] = $value;
        }
    }
    return $parsed;
}
function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), $precision) . ' ' . ['B','KB','MB','GB','TB'][$i];
}
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return $diff . 's ago';
    $diff = round($diff / 60);
    if ($diff < 60) return $diff . 'm ago';
    $diff = round($diff / 60);
    if ($diff < 24) return $diff . 'h ago';
    $diff = round($diff / 24);
    return $diff . 'd ago';
}
