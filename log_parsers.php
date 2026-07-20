<?php
/**
 * log_parsers.php - Vendor-agnostic syslog normalization layer
 * -----------------------------------------------------------------
 * Include this once near the top of any report page:
 *   require_once __DIR__ . '/log_parsers.php';
 */
if (!function_exists('detect_log_vendor')) {
    function detect_log_vendor(string $msg): string
    {
        $msg = trim($msg);

        if (preg_match('/\blogid="?\d{10}"?/', $msg) || preg_match('/\bdevid="?FG/', $msg)
            || (preg_match('/\bsrcip=/', $msg) && preg_match('/\btype="?\w+"?/', $msg) && preg_match('/\bsubtype=/', $msg))) {
            return 'fortigate';
        }
        if (preg_match('/%(ASA|FTD)-\d-\d{6}/', $msg)) {
            return 'cisco_asa';
        }
        if (preg_match('/%[A-Z0-9_]+-\d-[A-Z0-9_]+:/', $msg)) {
            return 'cisco_ios';
        }
        if (preg_match('/^[^,]*,[^,]*,[^,]*,(TRAFFIC|THREAT|SYSTEM|CONFIG|HIPMATCH|GLOBALPROTECT|USERID|CORRELATION)\b/', $msg)) {
            return 'paloalto';
        }
        if (preg_match('/^\d+,,,\d+,[\w.]+,match,(block|pass|reject)\b/i', $msg)) {
            return 'pfsense';
        }
        if (strpos($msg, 'product=') !== false && strpos($msg, 'action=') !== false) {
            return 'checkpoint';
        }
        if (strpos($msg, 'CEF:') === 0) {
            return 'cef';
        }
        if (preg_match('/\w+=("[^"]*"|\S+)/', $msg)) {
            return 'generic_kv';
        }
        return 'unknown';
    }
}
if (!function_exists('_parse_kv')) {
    function _parse_kv(string $msg): array
    {
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
if (!function_exists('_blank_entry')) {
    function _blank_entry(string $vendor, string $raw, string $source_ip): array
    {
        return [
            'vendor'      => $vendor,
            'category'    => 'other',
            'action'      => 'unknown',
            'src_ip'      => null, 'src_port' => null,
            'dst_ip'      => null, 'dst_port' => null,
            'protocol'    => null,
            'app'         => null,
            'service'     => null,
            'policy'      => null,
            'bytes_sent'  => 0,
            'bytes_recv'  => 0,
            'devname'     => $source_ip,
            'severity'    => null,
            'raw'         => $raw,
            'parsed'      => false,
        ];
    }
}
if (!function_exists('_normalize_fortigate')) {
    function _normalize_fortigate(string $msg, string $source_ip): array
    {
        $p = _parse_kv($msg);
        $e = _blank_entry('fortigate', $msg, $source_ip);
        if (!isset($p['srcip'], $p['dstip'])) return $e;
        $type = $p['type'] ?? '';
        $e['category']   = $type === 'traffic' ? 'traffic' : ($type === 'ips' || $type === 'app-ctrl' ? 'threat' : ($type === 'vpn' ? 'vpn' : 'other'));
        $e['action']     = strtolower($p['action'] ?? 'unknown');
        $e['src_ip']     = $p['srcip'];
        $e['src_port']   = $p['srcport'] ?? null;
        $e['dst_ip']     = $p['dstip'];
        $e['dst_port']   = $p['dstport'] ?? null;
        $e['protocol']   = $p['proto'] ?? null;
        $e['app']        = $p['app'] ?? ($p['appcat'] ?? null);
        $e['service']    = $p['service'] ?? null;
        $e['policy']     = $p['policyid'] ?? null;
        $e['bytes_sent'] = intval($p['sentbyte'] ?? 0);
        $e['bytes_recv'] = intval($p['rcvdbyte'] ?? 0);
        $e['devname']    = $p['devname'] ?? $source_ip;
        $e['severity']   = $p['level'] ?? $p['severity'] ?? null;
        $e['parsed']     = true;
        return $e;
    }
}
if (!function_exists('_normalize_cisco_asa')) {
    function _normalize_cisco_asa(string $msg, string $source_ip): array
    {
        $e = _blank_entry('cisco_asa', $msg, $source_ip);
        if (preg_match('/%ASA-\d-106023:\s+Deny\s+(\w+)\s+src\s+[\w.-]+:([\d.]+)\/(\d+)\s+dst\s+[\w.-]+:([\d.]+)\/(\d+)/i', $msg, $m)) {
            $e['category'] = 'traffic'; $e['action'] = 'deny'; $e['protocol'] = strtoupper($m[1]);
            $e['src_ip'] = $m[2]; $e['src_port'] = $m[3]; $e['dst_ip'] = $m[4]; $e['dst_port'] = $m[5];
            $e['parsed'] = true; return $e;
        }
        if (preg_match('/%ASA-\d-106100:\s+access-list\s+(\S+)\s+(permitted|denied)\s+(\w+)\s+[\w.-]+\/([\d.]+)\((\d+)\)\s*->\s*[\w.-]+\/([\d.]+)\((\d+)\)/i', $msg, $m)) {
            $e['category'] = 'traffic'; $e['action'] = $m[2] === 'permitted' ? 'accept' : 'deny'; $e['policy'] = $m[1];
            $e['protocol'] = strtoupper($m[3]); $e['src_ip'] = $m[4]; $e['src_port'] = $m[5]; $e['dst_ip'] = $m[6]; $e['dst_port'] = $m[7];
            $e['parsed'] = true; return $e;
        }
        if (preg_match('/%ASA-\d-30201[3-6]:\s+(Built|Teardown)\s+(?:inbound|outbound)?\s*(TCP|UDP)\s+connection.*?for\s+[\w.-]+:([\d.]+)\/(\d+).*?to\s+[\w.-]+:([\d.]+)\/(\d+)/i', $msg, $m)) {
            $e['category'] = 'traffic'; $e['action'] = strtolower($m[1]) === 'built' ? 'accept' : 'close';
            $e['protocol'] = $m[2]; $e['src_ip'] = $m[3]; $e['src_port'] = $m[4]; $e['dst_ip'] = $m[5]; $e['dst_port'] = $m[6];
            $e['parsed'] = true; return $e;
        }
        if (preg_match('/%ASA-\d-\d{6}:.*(authentication|login)\s+(failed|rejected|denied)/i', $msg)) {
            $e['category'] = 'auth'; $e['action'] = 'deny'; $e['parsed'] = true; return $e;
        }
        $e['category'] = 'system'; return $e;
    }
}
if (!function_exists('_normalize_paloalto')) {
    function _normalize_paloalto(string $msg, string $source_ip): array
    {
        $e = _blank_entry('paloalto', $msg, $source_ip);
        $f = str_getcsv(trim($msg));
        if (!isset($f[3])) return $e;
        $e['category'] = strtolower($f[3]) === 'traffic' ? 'traffic' : (strtolower($f[3]) === 'threat' ? 'threat' : 'other');
        $e['src_ip'] = $f[7] ?? null; $e['dst_ip'] = $f[8] ?? null; $e['app'] = $f[13] ?? null;
        $e['src_port'] = $f[24] ?? null; $e['dst_port'] = $f[25] ?? null;
        $e['protocol'] = strtoupper($f[29] ?? '');
        $action = strtolower($f[30] ?? ''); $e['action'] = $action === 'allow' ? 'accept' : ($action ?: 'unknown');
        $e['bytes_sent'] = intval($f[32] ?? 0); $e['bytes_recv'] = intval($f[33] ?? 0);
        $e['devname'] = $f[1] ?? $source_ip;
        $e['parsed'] = ($e['src_ip'] !== null && $e['dst_ip'] !== null);
        return $e;
    }
}
if (!function_exists('_normalize_pfsense')) {
    function _normalize_pfsense(string $msg, string $source_ip): array
    {
        $e = _blank_entry('pfsense', $msg, $source_ip);
        $f = str_getcsv(trim($msg));
        if (count($f) < 19) return $e;
        $e['category'] = 'traffic'; $e['action'] = strtolower($f[6]) === 'pass' ? 'accept' : 'deny';
        $e['protocol'] = strtoupper($f[16] ?? ''); $e['src_ip'] = $f[18] ?? null; $e['dst_ip'] = $f[19] ?? null;
        $e['src_port'] = $f[20] ?? null; $e['dst_port'] = $f[21] ?? null;
        $e['devname'] = $source_ip; $e['parsed'] = ($e['src_ip'] !== null && $e['dst_ip'] !== null);
        return $e;
    }
}
if (!function_exists('_normalize_checkpoint')) {
    function _normalize_checkpoint(string $msg, string $source_ip): array
    {
        $p = _parse_kv($msg);
        $e = _blank_entry('checkpoint', $msg, $source_ip);
        if (!isset($p['src'], $p['dst'])) return $e;
        $e['category'] = 'traffic'; $e['action'] = strtolower($p['action'] ?? 'unknown');
        $e['src_ip'] = $p['src']; $e['dst_ip'] = $p['dst']; $e['src_port'] = $p['s_port'] ?? null;
        $e['dst_port'] = $p['service'] ?? null; $e['protocol'] = $p['proto'] ?? null;
        $e['bytes_sent'] = intval($p['sent_bytes'] ?? 0); $e['bytes_recv'] = intval($p['rcv_bytes'] ?? 0);
        $e['devname'] = $p['origin'] ?? $source_ip; $e['parsed'] = true;
        return $e;
    }
}
if (!function_exists('_normalize_generic_kv')) {
    function _normalize_generic_kv(string $msg, string $source_ip): array
    {
        $p = _parse_kv($msg);
        $e = _blank_entry('generic', $msg, $source_ip);
        $src = $p['srcip'] ?? $p['src_ip'] ?? $p['src'] ?? null;
        $dst = $p['dstip'] ?? $p['dst_ip'] ?? $p['dst'] ?? null;
        if ($src === null || $dst === null) return $e;
        $e['category'] = 'traffic'; $e['action'] = strtolower($p['action'] ?? 'unknown');
        $e['src_ip'] = $src; $e['dst_ip'] = $dst; $e['src_port'] = $p['srcport'] ?? $p['src_port'] ?? null;
        $e['dst_port'] = $p['dstport'] ?? $p['dst_port'] ?? null; $e['protocol'] = $p['proto'] ?? $p['protocol'] ?? null;
        $e['devname'] = $p['devname'] ?? $p['host'] ?? $source_ip; $e['parsed'] = true;
        return $e;
    }
}
if (!function_exists('normalize_log_entry')) {
    function normalize_log_entry(string $message, string $source_ip = ''): array
    {
        $vendor = detect_log_vendor($message);
        switch ($vendor) {
            case 'fortigate':   return _normalize_fortigate($message, $source_ip);
            case 'cisco_asa':   return _normalize_cisco_asa($message, $source_ip);
            case 'paloalto':    return _normalize_paloalto($message, $source_ip);
            case 'pfsense':     return _normalize_pfsense($message, $source_ip);
            case 'checkpoint':  return _normalize_checkpoint($message, $source_ip);
            case 'generic_kv':  return _normalize_generic_kv($message, $source_ip);
            default:            return _blank_entry($vendor, $message, $source_ip);
        }
    }
}
if (!function_exists('traffic_signature_sql')) {
    function traffic_signature_sql(string $col = 'message'): string
    {
        return "(
            $col LIKE '%type=traffic%' OR $col LIKE '%type=\"traffic\"%' OR $col LIKE '%subtype=forward%'
            OR $col LIKE '%\\%ASA-%-106023%' OR $col LIKE '%\\%ASA-%-106100%' OR $col LIKE '%\\%ASA-%-3020%'
            OR $col LIKE '%TRAFFIC,%'
            OR $col REGEXP '^[0-9]+,,,[0-9]+,[^,]+,match,(pass|block)'
            OR $col LIKE '%src=%' AND $col LIKE '%dst=%'
            OR $col LIKE '%srcip=%' AND $col LIKE '%dstip=%'
        )";
    }
}
