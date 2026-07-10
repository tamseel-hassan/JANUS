<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * noc/nac_cron.php – Scheduled NAC poller
 * Crontab: *\/5 * * * * /usr/bin/php /var/www/janus/noc/nac_cron.php
 */
define('CRON_LOG', '/var/log/janus_nac.log');

function clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(CRON_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND|LOCK_EX);
}

require_once __DIR__ . '/nac_lib.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) { clog('FATAL: DB connect failed'); exit(1); }
$db->set_charset('utf8mb4');

clog('══ NAC cron started ══');
$r = $db->query("SELECT * FROM nac_switches WHERE enabled=1 ORDER BY id");
if (!$r || $r->num_rows === 0) { clog('No switches configured'); $db->close(); exit(0); }

while ($sw = $r->fetch_assoc()) {
    clog("Polling {$sw['hostname']} ({$sw['ip']})...");
    $result = nac_poll_switch($db, $sw);

    if ($result['ok']) {
        clog("  ✓ {$result['macs']} MACs, {$result['ports']} ports, " .
             count($result['changes']) . " changes in {$result['duration']}s");
        foreach ($result['changes'] as $c) {
            clog("    [{$c['type']}] " . ($c['mac'] ?? '') . ' ' . ($c['detail'] ?? ''));
        }
    } else {
        clog("  ✗ Error: {$result['error']}");
        $err = $db->real_escape_string($result['error']);
        $db->query("UPDATE nac_switches SET poll_status='error',poll_error='$err' WHERE id={$sw['id']}");
    }
}
$r->free();
clog('══ NAC cron complete ══');
$db->close();
