<?php
require_once __DIR__ . '/../../db_config.php';
/**
 */

define('NAC_CRED_FILE',       '/etc/janus/switch_credentials.php');
define('NAC_TRUNK_THRESHOLD', 8);    // ports with ≥ this many MACs = trunk
define('NAC_SSH_TIMEOUT',    30);    // seconds per command

// Load vendor driver registry (separate files per platform)
$_nac_driver_dir = __DIR__ . '/drivers/driver_registry.php';
if (file_exists($_nac_driver_dir)) {
    require_once $_nac_driver_dir;
}
unset($_nac_driver_dir);

/* ═══════════════════════════════════════════════════════════════
   CREDENTIAL STORAGE
═══════════════════════════════════════════════════════════════ */
function nac_load_credentials(): array {
    if (!file_exists(NAC_CRED_FILE)) return [];
    $creds = [];
    include NAC_CRED_FILE;   // sets $switch_credentials array
    return $creds['switch_credentials'] ?? [];
}

function nac_save_credential(string $ip, string $user, string $password, string $enable_pass = ''): bool {
    $dir = dirname(NAC_CRED_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $all = nac_load_credentials();
    $all[$ip] = ['user' => $user, 'pass' => $password];
    if ($enable_pass !== '') {
        $all[$ip]['enable_pass'] = $enable_pass;
    } elseif (isset($all[$ip]['enable_pass'])) {
        // preserve existing enable_pass if not updating it
    }

    $php = "<?php\n// Janusswitch credentials – keep outside webroot\n";
    $php .= "\$creds['switch_credentials'] = " . var_export($all, true) . ";\n";
    return file_put_contents(NAC_CRED_FILE, $php, LOCK_EX) !== false;
}

function nac_delete_credential(string $ip): void {
    $all = nac_load_credentials();
    unset($all[$ip]);
    $php = "<?php\n// Janusswitch credentials\n";
    $php .= "\$creds['switch_credentials'] = " . var_export($all, true) . ";\n";
    file_put_contents(NAC_CRED_FILE, $php, LOCK_EX);
}

function nac_get_credential(string $ip): ?array {
    $all = nac_load_credentials();
    return $all[$ip] ?? null;
}

/* ═══════════════════════════════════════════════════════════════
   TCL STRING ESCAPE HELPER
   Escapes a PHP string for safe use inside a Tcl double-quoted
   string (i.e. "..."). This is used to embed passwords and
   commands into expect scripts without breaking the Tcl parser.

   In Tcl double-quoted strings the following chars are special:
     \  → start of escape sequence
     "  → ends the string
     [  → starts a command substitution
     ]  → ends a command substitution
     $  → starts a variable substitution
     {  → (safe inside "" but braces inside $var are tricky)
     }  → (safe inside "")

   We escape: \  "  [  ]  $
   We do NOT need to escape { } inside "" — they are literal.
   After escaping, the string is safe to place inside "..."
   in any Tcl context.
═══════════════════════════════════════════════════════════════ */
function _nac_tcl_escape_dquote(string $s): string {
    // Order matters: escape backslash first so we don't double-escape
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace('"',  '\\"',  $s);
    $s = str_replace('[',  '\\[',  $s);
    $s = str_replace(']',  '\\]',  $s);
    $s = str_replace('$',  '\\$',  $s);
    return $s;
}

/* ═══════════════════════════════════════════════════════════════
   SSH EXECUTION
   Always uses expect — sshpass fails on HP Aruba because it uses
   keyboard-interactive auth, not standard password auth.
   Expect handles all vendors: JunOS, Cisco IOS, HP Aruba 2930.
═══════════════════════════════════════════════════════════════ */
function nac_ssh_command(string $ip, int $port, string $user, string $pass,
                          string $command, int $timeout = NAC_SSH_TIMEOUT,
                          string $plkey = ''): array {
    return nac_ssh_expect($ip, $port, $user, $pass, $command, $timeout, $plkey);
}

/* Run multiple commands in ONE SSH session — much faster than 3 separate connections.
   Returns ['ok'=>bool, 'outputs'=>[cmd=>output, ...], 'raw'=>string] */
function nac_ssh_multi($ip, $port, $user, $pass, array $commands, $timeout, $plkey): array {
    $tmpFile = tempnam(sys_get_temp_dir(), 'nac_mlt_');

    // SAFE password injection into Tcl:
    // We write the password with 'set pw {$pass}' only if the password
    // contains no curly braces. Otherwise we use a temp file strategy.
    // The cleanest universal approach: write password as a hex-encoded Tcl binary,
    // then decode it. BUT simplest reliable: escape for Tcl double-quote context.
    //
    // Tcl double-quote special chars: \ " [ ] $ { }
    // We escape all of them so send "$pw\r" works safely.
    $tclPass = _nac_tcl_escape_dquote($pass);

    // Determine pager disable command
    if (str_contains($plkey, 'junos')) {
        $disable_pager = 'set cli screen-length 0';
    } elseif (in_array($plkey, ['procurve', 'aruba-os', 'aruba'])) {
        $disable_pager = 'no page';
    } elseif (str_contains($plkey, 'ios')) {
        $disable_pager = 'terminal length 0';
    } else {
        $disable_pager = '';
    }
    $tclDisable = _nac_tcl_escape_dquote($disable_pager);

    // Build one expect block per command — no delimiter needed,
    // we split by matching the echoed command lines in PHP afterward.
    //
    // IMPORTANT: The prompt regex MUST match EX4300 Virtual Chassis prompts:
    //   user@switch>           (standard)
    //   user@switch-re0>       (VC — has -re0 suffix before >)
    //   user@switch-re0>       (with trailing space)
    // We use [A-Za-z0-9_\-.@-]+ to allow the hyphen within the hostname part.
    $cmdBlocks = '';
    foreach ($commands as $key => $cmd) {
        $escapedCmd = addslashes($cmd);
        $cmdBlocks .= <<<BLOCK

send "{$escapedCmd}\\r"
while 1 {
    expect {
        -re {\\-+[Mm]ore\\-+}                         { send " " }
        -re {--More--}                                  { send " " }
        -re {-- MORE --}                                { send " " }
        -re {-- More --}                                { send " " }
        -re {---more---}                                { send " " }
        -re {Q to quit}                                 { send " " }
        -re {\\{(?:master|backup|primary|secondary)(?::\\d+)?\\}} { continue }
        -re {[A-Za-z0-9_\\-\\.@]+(?:-re\\d+)?[#>][ \\t]} { break }
        timeout                                         { break }
        eof                                             { break }
    }
}

BLOCK;
    }

    $script = <<<EXPECT_BODY
#!/usr/bin/expect -f
set timeout $timeout

# Password stored in variable — safe for passwords containing special chars
# _nac_tcl_escape_dquote() has escaped all Tcl double-quote metacharacters
set pw "$tclPass"

spawn ssh -p $port \\
    -o StrictHostKeyChecking=no \\
    -o UserKnownHostsFile=/dev/null \\
    -o ConnectTimeout=$timeout \\
    -o PreferredAuthentications=keyboard-interactive,password \\
    -o LogLevel=ERROR \\
    $user@$ip

expect {
    -re {(yes/no|yes/no/\\[fingerprint\\])} { send "yes\\r"; exp_continue }
    -re {[Pp]assword:}                      { send "\$pw\\r" }
    timeout { exit 1 }
    eof     { exit 1 }
}

# Post-login banner loop.
# JunOS Virtual Chassis (EX4300 VC) outputs "{master:0}" on its own line
# BEFORE the prompt. We consume those with exp_continue so they do not
# break prompt detection.
set bc 0
while 1 {
    expect {
        -re {[Pp]ress any key to continue} { after 400; send "\\r"; incr bc; if { \$bc > 5 } { break } }
        -re {[Pp]ress any key}             { after 400; send "\\r"; incr bc; if { \$bc > 5 } { break } }
        -re {[Kk]ey to continue}           { after 400; send "\\r"; incr bc; if { \$bc > 5 } { break } }
        -re {WARNING:}                     { exp_continue }
        -re {[Pp]assword:}                 { send "\$pw\\r" }
        -re {\\{(?:master|backup|primary|secondary)(?::\\d+)?\\}} { exp_continue }
        -re {[A-Za-z0-9_\\-\\.@]+(?:-re\\d+)?[#>][ \\t]} { break }
        timeout                            { break }
        eof                                { exit 1 }
    }
}

# Sync to a clean prompt — retry up to 3 times to flush any residual VC state lines.
set sync 0
while { \$sync < 3 } {
    send "\\r"
    expect {
        -re {\\{(?:master|backup|primary|secondary)(?::\\d+)?\\}} { incr sync; continue }
        -re {[A-Za-z0-9_\\-\\.@]+(?:-re\\d+)?[#>][ \\t]} { set sync 99; break }
        timeout { incr sync }
    }
}

set pgcmd "$tclDisable"
if { \$pgcmd ne "" } {
    send "\$pgcmd\\r"
    while 1 {
        expect {
            -re {\\{(?:master|backup|primary|secondary)(?::\\d+)?\\}} { continue }
            -re {[A-Za-z0-9_\\-\\.@]+(?:-re\\d+)?[#>][ \\t]} { break }
            timeout { break }
        }
    }
}

{$cmdBlocks}

send "exit\\r"
expect {
    -re {[Dd]o you want to log.?out\\s*\\(y[^)]*\\)} { send "y\\r"; expect { eof {} timeout {} } }
    -re {[A-Za-z0-9_\\-\\.@]+>[ \\t]}               { send "exit\\r"; exp_continue }
    eof     {}
    timeout {}
}
EXPECT_BODY;

    file_put_contents($tmpFile, $script);
    chmod($tmpFile, 0700);

    $raw_output = []; $retcode = -1;
    exec('/usr/bin/expect ' . escapeshellarg($tmpFile) . ' 2>&1', $raw_output, $retcode);
    unlink($tmpFile);

    $full_raw = implode("\n", $raw_output);

    // ── Split output by command echo markers ──────────────────────────────────
    // HP Aruba 2930 problem: the switch uses VT100 terminal control and echoes
    // everything on ONE physical \n-terminated line with escape codes embedded:
    //   "<ESC>[33;1HNAC-Sw1# <ESC>[33;10Hshow mac-a<ESC>[33;20Hddress det<ESC>[33;30Hail<CR>"
    // The command text is split character-by-character with cursor-positioning
    // escapes between each chunk. Standard line-based splitting fails entirely.
    //
    // Fix strategy:
    //   1. Strip ALL ANSI/VT100 escape sequences from the raw buffer first
    //   2. Split on BOTH \n and \r (Aruba uses \r between terminal updates)
    //   3. Then do normal command echo detection on the clean lines

    // Step 1: strip escape sequences from entire raw buffer
    $raw_clean = $full_raw;
    $raw_clean = preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $raw_clean);  // CSI: \e[...X
    $raw_clean = preg_replace('/\x1b[()][AB012]/', '', $raw_clean);          // charset
    $raw_clean = preg_replace('/\x1b[=>]/', '', $raw_clean);                 // keypad mode
    $raw_clean = preg_replace('/\x1b[^\[()=>]/', '', $raw_clean);            // other \eX

    // Step 2: split on \n OR \r (normalize both)
    $lines     = preg_split('/\r\n|\r|\n/', $raw_clean);

    $cmdKeys   = array_keys($commands);
    $cmdIndex  = -1;
    $buckets   = [];
    foreach ($cmdKeys as $k) $buckets[$k] = [];

    foreach ($lines as $line) {
        $lt = trim($line);
        if (!$lt) continue;

        // Detect command echo line — now clean of escape codes
        $matched = false;
        foreach ($cmdKeys as $idx => $k) {
            $cmd = $commands[$k];
            if (str_contains($line, $cmd) && preg_match('/[#>]\s/', $line)) {
                $cmdIndex = $idx;
                $matched  = true;
                break;
            }
        }
        if ($matched) continue;

        // Skip pager-disable echo and its response
        if ($disable_pager && str_contains($line, $disable_pager)) continue;
        if (str_contains($lt, 'Screen length set to')) continue;
        if (str_contains($lt, 'Scripting mode')) continue;

        if ($cmdIndex >= 0) {
            $buckets[$cmdKeys[$cmdIndex]][] = $line;
        }
    }

    // ── Clean each bucket ────────────────────────────────────────────────────
    // ANSI codes already stripped above. Just filter noise lines.
    $cleaned = [];
    foreach ($commands as $key => $cmd) {
        $clean = [];
        foreach ($buckets[$key] as $l) {
            $lt = trim($l);
            if (!$lt) continue;
            if (str_starts_with($l, 'spawn '))                                      continue;
            if (preg_match('/^\{(master|backup|primary|secondary)(:\d+)?\}/', $lt)) continue;
            if (str_contains($l, 'Press any key'))                                  continue;
            if (str_contains($l, 'WARNING:'))                                       continue;
            if (str_contains($l, 'hpe.com') || str_contains($l, 'HPE '))           continue;
            if (str_contains($l, 'previous successful login'))                      continue;
            if (str_contains($l, 'Copyright') && str_contains($l, 'Hewlett'))      continue;
            if (str_contains($lt, 'Password:'))                                     continue;
            if (str_contains($lt, 'JUNOS ') && str_contains($lt, 'built '))        continue;
            if (str_contains($lt, 'unknown command'))                               continue;
            if (preg_match('/^([A-Za-z0-9_\-\.]+@)?[A-Za-z0-9_\-\.]+[#>]\s*$/', $lt)) continue;
            $clean[] = $l;
        }
        $cleaned[$key] = implode("\n", $clean);
    }

    return [
        'ok'      => ($retcode === 0),
        'outputs' => $cleaned,
        'raw'     => $full_raw,
        'code'    => $retcode,
    ];
}

/* ═══════════════════════════════════════════════════════════════
   TELNET EXECUTION (for legacy switches — Cisco 2950/2960 etc.)
   Uses expect just like SSH but spawns telnet instead.
   Handles: Username prompt → Password prompt → user/enable mode.
   enable_pass = separate enable secret; falls back to login pass.
═══════════════════════════════════════════════════════════════ */
function nac_telnet_multi(string $ip, int $port, string $user, string $pass,
                           string $enable_pass, array $commands, int $timeout, string $plkey): array {
    $tmpFile   = tempnam(sys_get_temp_dir(), 'nac_tel_');
    $tclUser   = _nac_tcl_escape_dquote($user);
    $tclPass   = _nac_tcl_escape_dquote($pass);
    $tclEnable = _nac_tcl_escape_dquote($enable_pass !== '' ? $enable_pass : $pass);

    // ── Build one expect block per command ───────────────────────────────────
    // FIX: Cisco 2950 IOS 12.1 prompt "CF#" has NO trailing space or tab.
    //      Old regex [#>][ \t] NEVER matched → expect hung for full timeout.
    //      Correct regex: [#>] with NO space requirement.
    $cmdBlocks = '';
    foreach ($commands as $key => $cmd) {
        $escapedCmd = addslashes($cmd);
        $cmdBlocks .= <<<BLOCK

send "{$escapedCmd}\\r"
while 1 {
    expect {
        -re {-+[Mm]ore-+}              { send " " }
        -re {--More--}                  { send " " }
        -re {-- MORE --}                { send " " }
        -re {[A-Za-z0-9_\\-\\.]+[#>]} { break }
        timeout                         { break }
        eof                             { break }
    }
}

BLOCK;
    }

    $telnet_port_arg = ($port !== 23) ? " $port" : '';

    // ── Expect script ────────────────────────────────────────────────────────
    // FIX 1: Login loop uses a while with counters, not a single expect block.
    //        Handles Cisco 2950 IOS 12.1 quirks:
    //          a) "% Username: timeout expired!" — VTY exec-timeout fires during
    //             auth if there's any delay. We catch it and let the switch re-prompt.
    //          b) "% Login invalid" — bad password, allow retry up to pc limit.
    //          c) Prompt arrives with NO space: "CF#" not "CF# "
    //
    // FIX 2: after 400/300 delays before sending credentials — prevents the
    //        switch from receiving chars before it finishes printing the prompt,
    //        which caused the "% Username: timeout expired!" race condition.
    //
    // FIX 3: All prompt regexes use [#>] without trailing [ \t] requirement.
    $script = <<<EXPECT_BODY
#!/usr/bin/expect -f
set timeout $timeout
set user "$tclUser"
set pw   "$tclPass"
set ep   "$tclEnable"
set at_enable 0
set pc 0
set uc 0

spawn telnet $ip$telnet_port_arg

# ── Login loop ────────────────────────────────────────────────────────────────
while {\$uc < 8} {
    expect {
        -re {[Uu]sername:|[Ll]ogin:} {
            after 400
            send "\$user\\r"
            incr uc
        }
        -re {[Pp]assword:} {
            after 300
            send "\$pw\\r"
            incr pc
            if { \$pc > 4 } { exit 1 }
        }
        -re {%[^\r\n]*[Tt]imeout[^\r\n]*|%[^\r\n]*expired[^\r\n]*} {
            # VTY line auth timeout (Cisco 2950 IOS 12.1 quirk) — switch will
            # re-prompt for Username. Wait briefly then let the loop continue.
            after 1200
        }
        -re {%[^\r\n]*[Ll]ogin.invalid} {
            # Wrong password — allow retry
            after 600
        }
        -re {%[^\r\n]*[Aa]ccess.denied}  { exit 1 }
        -re {[Cc]onnection.refused}       { exit 1 }
        -re {[A-Za-z0-9_\\-\\.]+#}       { set at_enable 1; break }
        -re {[A-Za-z0-9_\\-\\.]+>}       { set at_enable 0; break }
        timeout { break }
        eof     { exit 1 }
    }
}

# ── Escalate to enable if in user mode ───────────────────────────────────────
if { !\$at_enable } {
    send "enable\\r"
    set epc 0
    while 1 {
        expect {
            -re {[Pp]assword:}            { after 200; send "\$ep\\r"; incr epc; if {\$epc > 2} { break } }
            -re {[A-Za-z0-9_\\-\\.]+#}   { set at_enable 1; break }
            timeout                        { break }
            eof                            { break }
        }
    }
}

# ── Disable pager ─────────────────────────────────────────────────────────────
send "terminal length 0\\r"
expect {
    -re {[A-Za-z0-9_\\-\\.]+[#>]} { }
    timeout { }
}

$cmdBlocks

send "exit\\r"
expect {
    eof     {}
    timeout {}
}
EXPECT_BODY;

    file_put_contents($tmpFile, $script);
    chmod($tmpFile, 0700);

    $raw_output = []; $retcode = -1;
    exec('/usr/bin/expect ' . escapeshellarg($tmpFile) . ' 2>&1', $raw_output, $retcode);
    unlink($tmpFile);

    $full_raw  = implode("\n", $raw_output);
    $raw_clean = $full_raw;
    $raw_clean = preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $raw_clean);
    $raw_clean = preg_replace('/\x1b[()][AB012]/', '', $raw_clean);
    $raw_clean = preg_replace('/\x1b[=>]/', '',  $raw_clean);

    $lines    = preg_split('/\r\n|\r|\n/', $raw_clean);
    $cmdKeys  = array_keys($commands);
    $cmdIndex = -1;
    $buckets  = [];
    foreach ($cmdKeys as $k) $buckets[$k] = [];

    foreach ($lines as $line) {
        $lt = trim($line);
        if (!$lt) continue;

        // Detect command echo: prompt char followed by the command text
        $matched = false;
        foreach ($cmdKeys as $idx => $k) {
            if (str_contains($line, $commands[$k]) && preg_match('/[#>]/', $line)) {
                $cmdIndex = $idx;
                $matched  = true;
                break;
            }
        }
        if ($matched) continue;

        // Skip login/pager noise
        if (str_contains($lt, 'terminal length'))  continue;
        if ($lt === 'enable')                       continue;
        if (preg_match('/^%/', $lt))               continue;  // all Cisco % messages

        if ($cmdIndex >= 0) $buckets[$cmdKeys[$cmdIndex]][] = $line;
    }

    $cleaned = [];
    foreach ($commands as $key => $cmd) {
        $clean = [];
        foreach ($buckets[$key] as $l) {
            $lt = trim($l);
            if (!$lt)                                                                    continue;
            if (str_starts_with($l, 'spawn '))                                          continue;
            if (str_contains($lt, 'Password:'))                                         continue;
            if (preg_match('/^([A-Za-z0-9_\-\.]+@)?[A-Za-z0-9_\-\.]+[#>]\s*$/', $lt)) continue;
            $clean[] = $l;
        }
        $cleaned[$key] = implode("\n", $clean);
    }

    $has_output = !empty(array_filter(array_map('trim', $cleaned)));
    return [
        'ok'      => ($retcode === 0 || $has_output),
        'outputs' => $cleaned,
        'raw'     => $full_raw,
        'code'    => $retcode,
    ];
}

function nac_ssh_expect(string $ip, int $port, string $user, string $pass,
                         string $cmd, int $timeout, string $plkey = ''): array {
    $tmpFile  = tempnam(sys_get_temp_dir(), 'nac_exp_');
    $tclPass  = _nac_tcl_escape_dquote($pass);
    $tclCmd   = _nac_tcl_escape_dquote($cmd);

    // Determine pager-disable command per vendor (prevents --more-- truncation)
    $disable_pager = '';
    if (str_contains($plkey, 'junos')) {
        $disable_pager = 'set cli screen-length 0';
    } elseif (in_array($plkey, ['procurve', 'aruba-os', 'aruba'])) {
        $disable_pager = 'no page';
    } elseif (str_contains($plkey, 'ios')) {
        $disable_pager = 'terminal length 0';
    } else {
        if (str_contains($cmd, 'ethernet-switching') || str_contains($cmd, 'interfaces terse')
            || str_contains($cmd, 'lldp neighbors') || str_contains($cmd, 'show vlans')) {
            $disable_pager = 'set cli screen-length 0';
        } elseif (str_contains($cmd, 'mac-address') || str_contains($cmd, 'interfaces brief')
                  || str_contains($cmd, 'interfaces status') || str_contains($cmd, 'lldp info')
                  || str_contains($cmd, 'lldp remote') || str_contains($cmd, 'show running')) {
            $disable_pager = 'no page';
        } elseif (str_contains($cmd, 'mac address-table') || str_contains($cmd, 'cdp neighbors')
                  || str_contains($cmd, 'show ip interface')) {
            $disable_pager = 'terminal length 0';
        }
    }
    $tclDisable = _nac_tcl_escape_dquote($disable_pager);

    $script  = "#!/usr/bin/expect -f\n";
    $script .= "set timeout $timeout\n\n";
    // Password in variable — safe for ANY password including quotes, braces, brackets
    $script .= "set pw \"$tclPass\"\n\n";
    $script .= "spawn ssh -p $port \\\n";
    $script .= "    -o StrictHostKeyChecking=no \\\n";
    $script .= "    -o UserKnownHostsFile=/dev/null \\\n";
    $script .= "    -o ConnectTimeout=$timeout \\\n";
    $script .= "    -o PreferredAuthentications=keyboard-interactive,password \\\n";
    $script .= "    -o LogLevel=ERROR \\\n";
    $script .= "    {$user}@{$ip}\n\n";

    $script .= <<<EXPECT_BODY

# ── Step 1: authenticate ─────────────────────────────────────────────────────
expect {
    -re {(yes/no|yes/no/\[fingerprint\])} { send "yes\r"; exp_continue }
    -re {[Pp]assword:}                    { send "\$pw\r" }
    timeout { exit 1 }
    eof     { exit 1 }
}

# ── Step 2: post-login banner loop ───────────────────────────────────────────
# JunOS Virtual Chassis outputs "{master:0}" on its own line before the prompt.
# Consume those with exp_continue so they don't break prompt detection.
set bc 0
while 1 {
    expect {
        -re {[Pp]ress any key to continue} {
            after 400
            send "\r"
            incr bc
            if { \$bc > 5 } { break }
        }
        -re {[Pp]ress any key}    {
            after 400
            send "\r"
            incr bc
            if { \$bc > 5 } { break }
        }
        -re {[Kk]ey to continue}  {
            after 400
            send "\r"
            incr bc
            if { \$bc > 5 } { break }
        }
        -re {WARNING:}            { exp_continue }
        -re {[Pp]assword:}        { send "\$pw\r" }
        -re {\{(?:master|backup|primary|secondary)(?::\d+)?\}} { exp_continue }
        -re {[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>][ \t]} { break }
        timeout                   { break }
        eof                       { exit 1 }
    }
}

# ── Step 3: sync to a clean prompt (retry up to 3× for VC state lines) ────────
set sync 0
while { \$sync < 3 } {
    send "\r"
    expect {
        -re {\{(?:master|backup|primary|secondary)(?::\d+)?\}} { incr sync; continue }
        -re {[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>][ \t]} { set sync 99; break }
        timeout { incr sync }
    }
}

# ── Step 4: disable pager ────────────────────────────────────────────────────
set pgcmd "$tclDisable"
if { \$pgcmd ne "" } {
    send "\$pgcmd\r"
    while 1 {
        expect {
            -re {\{(?:master|backup|primary|secondary)(?::\d+)?\}} { continue }
            -re {[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>][ \t]} { break }
            timeout { break }
        }
    }
}

# ── Step 5: send the actual command ──────────────────────────────────────────
send "$tclCmd\r"

# ── Step 6: collect output, space through any residual pagination ─────────────
while 1 {
    expect {
        -re {\-+[Mm]ore\-+}                           { send " " }
        -re {--More--}                                 { send " " }
        -re {-- MORE --}                               { send " " }
        -re {-- More --}                               { send " " }
        -re {Q to quit}                                { send " " }
        -re {[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>] }     { break }
        -re {[A-Za-z0-9_\-\.@]+(?:-re\d+)?[#>]\t}    { break }
        timeout                                        { break }
        eof                                            { break }
    }
}

# ── Step 7: exit ─────────────────────────────────────────────────────────────
send "exit\r"
expect {
    -re {[Dd]o you want to log.?out\s*\(y[^)]*\)} { send "y\r"; expect { eof {} timeout {} } }
    -re {[A-Za-z0-9_\-\.@]+>[ \t]}                { send "exit\r"; exp_continue }
    eof     {}
    timeout {}
}
EXPECT_BODY;

    file_put_contents($tmpFile, $script);
    chmod($tmpFile, 0700);

    $raw_output = []; $retcode = -1;
    exec('/usr/bin/expect ' . escapeshellarg($tmpFile) . ' 2>&1', $raw_output, $retcode);
    unlink($tmpFile);

    // ── Clean expect noise from output ──
    // Strip: spawn line, command echo, HP banner, JunOS cluster state lines, bare prompts
    $clean      = [];
    $cmd_seen   = false;
    $pg_seen    = false;
    foreach ($raw_output as $l) {
        // Strip all ANSI/VT100 escape sequences (HP Aruba 2930 sends these)
        $l  = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $l);   // CSI sequences e.g. \e[24;1H \e[2K
        $l  = preg_replace('/\x1b[()][AB012]/', '', $l);          // character set designations
        $l  = preg_replace('/\x1b[=>]/', '', $l);                 // keypad application/normal mode
        $lt = trim($l);
        // Always skip spawn line
        if (str_starts_with($l, 'spawn '))                             continue;
        // Skip JunOS cluster/RE state lines: "{master:0}", "{backup:1}", etc.
        if (preg_match('/^\{(master|backup|primary|secondary)(:\d+)?\}/', $lt)) continue;
        // Skip pager-disable command echo (first match only)
        if ($disable_pager && !$pg_seen && str_contains($l, $disable_pager)) {
            $pg_seen = true; continue;
        }
        // Skip the echoed command line (first occurrence)
        if (!$cmd_seen && str_contains($l, $cmd))                      { $cmd_seen = true; continue; }
        // Skip HP banner / login noise lines
        if (str_contains($l, 'Press any key'))                          continue;
        if (str_contains($l, 'hpe.com') || str_contains($l, 'HPE'))    continue;
        if (str_contains($l, 'RESTRICTED RIGHTS'))                      continue;
        if (str_contains($l, 'previous successful login'))              continue;
        if (str_contains($l, 'Software revision'))                      continue;
        if (str_contains($l, 'Copyright') && str_contains($l, 'Hewlett')) continue;
        if (str_contains($l, 'register your products'))                 continue;
        if (str_contains($l, 'up to date about'))                       continue;
        if (str_contains($l, 'feature updates'))                        continue;
        if (str_contains($l, 'product announcements'))                  continue;
        if (str_contains($l, 'Special events'))                         continue;
        if (str_contains($l, 'Confidential computer software'))         continue;
        // Skip bare prompt-only lines: "janus> ", "user@hostname> ", "hostname# "
        if (preg_match('/^([A-Za-z0-9_\-\.]+@)?[A-Za-z0-9_\-\.]+[#>]\s*$/', $lt)) continue;
        $clean[] = $l;
    }

    return [
        'ok'     => ($retcode === 0),
        'output' => implode("\n", $clean),
        'code'   => $retcode,
        'raw'    => implode("\n", $raw_output),   // kept for debug — never shown to user
    ];
}

/* ═══════════════════════════════════════════════════════════════
   PLATFORM DETECTION
═══════════════════════════════════════════════════════════════ */
function nac_detect_platform(mysqli $db, string $model): ?array {
    $model_lc = strtolower($model);
    $res = $db->query("SELECT * FROM nac_platforms ORDER BY id");
    if (!$res) return null;
    while ($row = $res->fetch_assoc()) {
        if (preg_match('/' . $row['model_pattern'] . '/i', $model_lc)) {
            $res->free();
            return $row;
        }
    }
    $res->free();
    return null;
}

/* ═══════════════════════════════════════════════════════════════
   POLL A SWITCH – main entry point
   Returns ['ok'=>bool, 'data'=>[...], 'error'=>string, 'raw_outputs'=>[...]]
   raw_outputs is included so the debug panel can show exactly what
   came back from the switch without having to SSH again.
═══════════════════════════════════════════════════════════════ */
function nac_poll_switch(mysqli $db, array $switch): array {
    // Override PHP's max_execution_time — polling a single switch can take 30-90s
    // (telnet with slow login + 3 commands). poll_all() across 6 switches = 6×90s.
    // request_terminate_timeout in pool.d/www.conf = 0 (unlimited) so this is safe.
    set_time_limit(0);
    $t0     = microtime(true);
    $ip     = $switch['ip'];
    $port   = $switch['ssh_port'] ?? 22;
    $user   = $switch['ssh_user'];
    $plkey  = $switch['platform_key'];
    $conn   = $switch['connection_type'] ?? 'ssh';  // 'ssh' or 'telnet'

    // For telnet, default port is 23 if not overridden
    if ($conn === 'telnet' && (int)$port === 22) {
        $port = 23;
    }

    // Get credentials from secure file (SNMP switches have no CLI credentials)
    $cred = null;
    $pass = '';
    if ($conn !== 'snmp') {
        $cred = nac_get_credential($ip);
        if (!$cred) {
            return ['ok' => false, 'error' => "No credentials stored for $ip"];
        }
        $pass = $cred['pass'];
    }

    // Ensure cmd_vlans column exists — MySQL/MariaDB compatible check via
    // INFORMATION_SCHEMA (works on MySQL 5.6+ and all MariaDB versions).
    // Static flag so the check only runs ONCE per PHP process across all
    // switches in a poll_all — avoids hammering INFORMATION_SCHEMA.
    static $cmd_vlans_checked = false;
    if (!$cmd_vlans_checked) {
        $plat_cols = [
            'platform_key'   => "ALTER TABLE nac_platforms ADD COLUMN platform_key VARCHAR(50) DEFAULT NULL",
            'vendor'         => "ALTER TABLE nac_platforms ADD COLUMN vendor VARCHAR(50) DEFAULT NULL",
            'model_pattern'  => "ALTER TABLE nac_platforms ADD COLUMN model_pattern VARCHAR(255) DEFAULT NULL",
            'cmd_mac_table'  => "ALTER TABLE nac_platforms ADD COLUMN cmd_mac_table TEXT DEFAULT NULL",
            'cmd_interfaces' => "ALTER TABLE nac_platforms ADD COLUMN cmd_interfaces TEXT DEFAULT NULL",
            'cmd_lldp'       => "ALTER TABLE nac_platforms ADD COLUMN cmd_lldp TEXT DEFAULT NULL",
            'cmd_vlans'      => "ALTER TABLE nac_platforms ADD COLUMN cmd_vlans VARCHAR(120) DEFAULT NULL",
        ];
        foreach ($plat_cols as $cname => $sql) {
            $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_platforms' AND COLUMN_NAME='$cname'");
            if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
                $db->query($sql);
            }
            $col_chk && $col_chk->free();
        }

        // Ensure legacy 'name' column in nac_platforms doesn't block INSERTs if present
        $db->query("ALTER TABLE nac_platforms MODIFY COLUMN name VARCHAR(100) DEFAULT NULL");

        // Seed default platform definitions if nac_platforms is empty
        if (function_exists('driver_all_meta')) {
            foreach (driver_all_meta() as $pkey => $meta) {
                $esc_name  = $db->real_escape_string($meta['name'] ?? $pkey);
                $esc_key   = $db->real_escape_string($pkey);
                $esc_v     = $db->real_escape_string($meta['vendor'] ?? '');
                $esc_pat   = $db->real_escape_string($meta['model_pattern'] ?? '');
                $esc_mac   = $db->real_escape_string($meta['cmd_mac_table'] ?? '');
                $esc_ifc   = $db->real_escape_string($meta['cmd_interfaces'] ?? '');
                $esc_lldp  = $db->real_escape_string($meta['cmd_lldp'] ?? '');
                $esc_vlans = $db->real_escape_string($meta['cmd_vlans'] ?? '');

                $chk_row = $db->query("SELECT COUNT(*) FROM nac_platforms WHERE platform_key='$esc_key'");
                if ($chk_row && (int)$chk_row->fetch_row()[0] === 0) {
                    $db->query(
                        "INSERT INTO nac_platforms (name, platform_key, vendor, model_pattern, cmd_mac_table, cmd_interfaces, cmd_lldp, cmd_vlans)
                         VALUES ('$esc_name', '$esc_key', '$esc_v', '$esc_pat', '$esc_mac', '$esc_ifc', '$esc_lldp', '$esc_vlans')"
                    );
                }
                $chk_row && $chk_row->free();
            }
        }

        // Seed 'show vlans' for all JunOS platform rows that don't have it yet
        $db->query(
            "UPDATE nac_platforms SET cmd_vlans='show vlans'
             WHERE platform_key IN ('junos','junos-new')
               AND (cmd_vlans IS NULL OR cmd_vlans='')"
        );
        $cmd_vlans_checked = true;
    }

    // Get platform config from DB
    $plat = $db->query(
        "SELECT * FROM nac_platforms WHERE platform_key = '" .
        $db->real_escape_string($plkey) . "' LIMIT 1"
    );
    if (!$plat || $plat->num_rows === 0) {
        return ['ok' => false, 'error' => "Unknown platform: $plkey"];
    }
    $platform = $plat->fetch_assoc();
    $plat->free();

    // Build command list for SSH/Telnet session.
    // For JunOS we also run "show vlans" to detect VLAN config on down/empty ports.
    $commands = [
        'mac'  => $platform['cmd_mac_table'],
        'ifc'  => $platform['cmd_interfaces'],
        'lldp' => $platform['cmd_lldp'],
    ];
    $has_vlans_cmd = str_contains($plkey, 'junos') && !empty($platform['cmd_vlans']);
    if ($has_vlans_cmd) {
        $commands['vlans'] = $platform['cmd_vlans'];
    }

    // ── SNMP branch — bypass CLI entirely, poll directly via SNMP ────────────
    if ($conn === 'snmp') {
        $snmp_community = $switch['snmp_community'] ?? 'public';
        $snmp_port_num  = (int)($switch['snmp_port'] ?? 161);
        try {
            $parsed = nac_snmp_poll($ip, $snmp_community, $snmp_port_num, 2, $plkey);
        } catch (RuntimeException $e) {
            $duration = round(microtime(true) - $t0, 2);
            $err = $db->real_escape_string('SNMP error: ' . $e->getMessage());
            $db->query("UPDATE nac_switches SET poll_status='error',poll_error='$err',last_polled=NOW() WHERE id={$switch['id']}");
            return ['ok' => false, 'error' => $e->getMessage(), 'duration' => $duration,
                    'raw_outputs' => [], 'raw' => ''];
        }
        $duration = round(microtime(true) - $t0, 2);
        $changes  = nac_persist_poll($db, $switch['id'], $parsed);
        $s = $db->prepare(
            "UPDATE nac_switches SET last_polled=NOW(), poll_status='ok', poll_error=NULL,
             poll_duration=?, mac_count=?, port_count=? WHERE id=?"
        );
        $mc = count($parsed['macs']);
        $pc = count($parsed['ports']);
        $s->bind_param('diii', $duration, $mc, $pc, $switch['id']);
        $s->execute(); $s->close();
        return [
            'ok'          => true,
            'duration'    => $duration,
            'macs'        => $mc,
            'ports'       => $pc,
            'changes'     => $changes,
            'lldp'        => $parsed['lldp'],
            'raw_outputs' => ['mac' => 'SNMP poll — no raw CLI output', 'ifc' => '', 'lldp' => ''],
            'raw'         => '',
        ];
    }
    // ── End SNMP branch ───────────────────────────────────────────────────────

    $r = ($conn === 'telnet')
        ? nac_telnet_multi($ip, $port, $user, $pass, $cred['enable_pass'] ?? '', $commands, NAC_SSH_TIMEOUT, $plkey)
        : nac_ssh_multi($ip, $port, $user, $pass, $commands, NAC_SSH_TIMEOUT, $plkey);

    // Real failure: expect exited non-zero AND no usable output
    if (!$r['ok'] && strlen(trim($r['outputs']['mac'] ?? '')) < 20) {
        $errDetail = trim($r['raw'] ?? '');
        foreach (explode("\n", $errDetail) as $eline) {
            $eline = trim($eline);
            if ($eline && !str_starts_with($eline, 'spawn')) { $errDetail = $eline; break; }
        }
        $duration = round(microtime(true) - $t0, 2);
        $err = $db->real_escape_string(($conn === 'telnet' ? 'Telnet' : 'SSH') . " failed after {$duration}s: $errDetail");
        $db->query("UPDATE nac_switches SET poll_status='error', poll_error='$err', last_polled=NOW() WHERE id={$switch['id']}");
        return [
            'ok'          => false,
            'error'       => ($conn === 'telnet' ? 'Telnet' : 'SSH') . " failed ($ip): $errDetail",
            'duration'    => $duration,
            'raw_outputs' => $r['outputs'] ?? [],
            'raw'         => $r['raw'] ?? '',
        ];
    }

    $mac_out   = $r['outputs']['mac']   ?? '';
    $ifc_out   = $r['outputs']['ifc']   ?? '';
    $lldp_out  = $r['outputs']['lldp']  ?? '';
    $vlans_out = $r['outputs']['vlans'] ?? '';

    // ── Select parser via driver registry ──
    if (function_exists('driver_get_parser')) {
        $parser = driver_get_parser($plkey);
    } else {
        if (str_contains($plkey, 'junos')) {
            $parser = 'parse_junos';
        } elseif (str_contains($plkey, 'ios')) {
            $parser = 'parse_ios';
        } elseif (in_array($plkey, ['procurve', 'aruba-os', 'aruba'])) {
            $parser = 'parse_procurve';
        } else {
            $parser = 'parse_junos';
        }
    }

    // JunOS driver accepts an optional 4th $vlans_out argument
    if ($vlans_out !== '' && str_contains($plkey, 'junos')) {
        $parsed = $parser($mac_out, $ifc_out, $lldp_out, $vlans_out);
    } else {
        $parsed = $parser($mac_out, $ifc_out, $lldp_out);
    }

    $duration = round(microtime(true) - $t0, 2);

    // Persist to DB
    $changes = nac_persist_poll($db, $switch['id'], $parsed);

    // Update switch status
    $s = $db->prepare(
        "UPDATE nac_switches SET last_polled=NOW(), poll_status='ok', poll_error=NULL,
         poll_duration=?, mac_count=?, port_count=? WHERE id=?"
    );
    $mc = count($parsed['macs']);
    $pc = count($parsed['ports']);
    $s->bind_param('diii', $duration, $mc, $pc, $switch['id']);
    $s->execute(); $s->close();

    return [
        'ok'          => true,
        'duration'    => $duration,
        'macs'        => count($parsed['macs']),
        'ports'       => count($parsed['ports']),
        'changes'     => $changes,
        'lldp'        => $parsed['lldp'],
        // Raw outputs stored so debug panel can display them without another SSH session
        'raw_outputs' => [
            'mac'  => $mac_out,
            'ifc'  => $ifc_out,
            'lldp' => $lldp_out,
        ],
        'raw' => $r['raw'] ?? '',
    ];
}

/* ═══════════════════════════════════════════════════════════════
   JUNOS PARSER (EX2200/EX3300/EX4300 – classic format)
═══════════════════════════════════════════════════════════════ */
function parse_junos(string $mac_out, string $ifc_out, string $lldp_out): array {
    $macs  = [];
    $ports = [];
    $lldp  = [];

    // ── Parse MAC table ──
    //
    // JunOS 13.x (EX4300, EX2200 old) – "show ethernet-switching table"
    // Output is grouped per VLAN, each block has its own header:
    //
    //   Ethernet switching table : 50 entries, 50 learned
    //   Routing instance : default-switch
    //       Vlan                MAC                 MAC         Age    Logical
    //       name                address             flags              interface
    //       Prod                00:0c:29:01:0b:d5   D             -   ge-0/0/25.0
    //       Prod                ae:b6:1d:55:dc:02   D             -   ae0.0
    //
    // JunOS 15+ (EX3400, EX4300 newer) – flat table, no per-VLAN blocks
    //
    //   VLAN  MAC address        Flags    Logical interface
    //   vlan5 00:50:56:a6:85:9d  D        ge-0/0/5.0
    //
    // Both formats are handled by one flexible regex that:
    //  1. Allows leading whitespace (trim is applied first)
    //  2. Accepts MAC in colon or dash notation
    //  3. Accepts VLAN name or VLAN ID as the first token
    //  4. Skips header/summary lines via keyword checks
    //
    $skip_keywords = [
        'ethernet switching', 'routing instance', 'vlan name', 'mac address',
        'mac flags', 'logical interface', '---', 'entries', 'learned',
        'static mac', 'persistent', 'statistics', 'non configured',
        'remote pe', 'flags (s', 'flags (d',
    ];

    foreach (explode("\n", $mac_out) as $line) {
        $line = trim($line);
        if (!$line) continue;

        // Skip header / summary lines
        $lower = strtolower($line);
        $skip = false;
        foreach ($skip_keywords as $kw) {
            if (str_contains($lower, $kw)) { $skip = true; break; }
        }
        if ($skip) continue;

        // Match both colon-separated and dash-grouped MAC formats:
        //   "Prod   00:0c:29:01:0b:d5   D   -   ge-0/0/25.0"
        //   "vlan5  00-0c-29-01-0b-d5   D   -   ge-0/0/25.0"
        // Regex: <vlan>  <mac17>  <flags>  <age>  <interface>
        if (!preg_match(
            '/^(\S+)\s+([0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2}[:\-][0-9a-f]{2})\s+(\S+)\s+\S+\s+(\S+)/i',
            $line, $m
        )) continue;

        $vlan      = $m[1];
        $mac       = strtoupper(str_replace('-', ':', $m[2]));
        // $flags  = $m[3];  // D=dynamic, S=static — not used currently
        $raw_iface = $m[4];
        $iface     = preg_replace('/\.\d+$/', '', $raw_iface); // strip .0 unit suffix

        // Skip L3/flood pseudo-interfaces
        $iface_lc = strtolower($iface);
        if (in_array($iface_lc, ['router', 'all-members', 'flood', 'unknown', ''])) continue;
        if (str_starts_with($iface_lc, 'irb')) continue;   // L3 gateway — not a real MAC port

        // Skip header rows that slipped through (vlan col == "vlan" literally)
        if (strtolower($vlan) === 'vlan' || strtolower($vlan) === 'vlan_name'
            || strtolower($vlan) === 'name') continue;

        if (!isset($macs[$mac])) {
            $macs[$mac] = ['mac' => $mac, 'vlan' => $vlan, 'port' => $iface, 'type' => 'D'];
        } else {
            // JunOS 13.x groups MAC table per VLAN block — same MAC can appear under
            // multiple VLANs. Prefer the most specific (non-default) VLAN name.
            // Re-use the driver helper if loaded, otherwise inline the same logic.
            $existing_vlan = $macs[$mac]['vlan'];
            $should_replace = function_exists('_junos_vlan_is_more_specific')
                ? _junos_vlan_is_more_specific($vlan, $existing_vlan)
                : (_nac_inline_vlan_specificity($vlan) > _nac_inline_vlan_specificity($existing_vlan));
            if ($should_replace) {
                $macs[$mac]['vlan'] = $vlan;
            }
        }
        // Count MACs per port (deduplicate same MAC on multiple VLANs)
        if (!isset($ports[$iface])) {
            $ports[$iface] = ['port_name' => $iface, 'mac_count' => 0, 'vlans' => [], '_seen_macs' => []];
        }
        if (!isset($ports[$iface]['_seen_macs'][$mac])) {
            $ports[$iface]['_seen_macs'][$mac] = true;
            $ports[$iface]['mac_count']++;
        }
        if (!in_array($vlan, $ports[$iface]['vlans'])) {
            $ports[$iface]['vlans'][] = $vlan;
        }
    }

    // Clean up internal tracking keys
    foreach ($ports as &$pd) { unset($pd['_seen_macs']); }
    unset($pd);

    // ── Parse interfaces terse ──
    // Handles both EX-2200 and EX-4300 output formats:
    //   ge-0/0/0    up   down              (physical line, no proto)
    //   ge-0/0/0.0  up   down  eth-switch  (sub-unit line, has proto)
    //   ge-0/0/22.0 up   up    aenet --> ae4.0
    //   irb.305     up   up    inet  172.17.130.76/24
    // We match BOTH physical and .0 sub-unit lines, normalizing port name.
    foreach (explode("\n", $ifc_out) as $line) {
        $line = trim($line);
        // Match: ge/xe/et/fe-X/X/X[.N], ae[N][.N], irb.N
        if (!preg_match(
            '/^((?:ge|xe|et|fe)-\d+\/\d+\/\d+(?:\.\d+)?|ae\d+(?:\.\d+)?|irb\.\d+)\s+(up|down)\s+(up|down)\s*(.*)$/i',
            $line, $m
        )) continue;

        $pname_raw = $m[1];
        $admin     = strtolower($m[2]);
        $link      = strtolower($m[3]);
        $proto     = trim($m[4]);

        // Normalize port name: strip .0 sub-unit suffix from ge/ae/xe ports
        // but keep irb.305 as irb.305 (the VLAN number is part of the name)
        if (preg_match('/^irb\./i', $pname_raw)) {
            $pname = $pname_raw;   // irb.305 stays irb.305
        } else {
            $pname = preg_replace('/\.0$/', '', $pname_raw);  // ge-0/0/0.0 → ge-0/0/0
        }

        // Determine port type
        $ptype = 'access';
        if (preg_match('/^ae\d+$/i', $pname))     $ptype = 'lag';
        if (preg_match('/^irb\./i', $pname))       $ptype = 'irb';
        if (str_contains($proto, 'aenet'))          $ptype = 'lag-member';

        if (!isset($ports[$pname])) {
            $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
        }
        $ports[$pname]['admin_status'] = $admin;
        $ports[$pname]['link_status']  = $link;
        // Only update port_type/proto if this line has proto info (sub-unit line)
        // so the .0 line (with aenet/eth-switch) wins over the bare physical line
        if ($proto !== '') {
            $ports[$pname]['port_type'] = $ptype;
            $ports[$pname]['proto']     = $proto;
        } elseif (!isset($ports[$pname]['port_type'])) {
            $ports[$pname]['port_type'] = $ptype;
        }
    }

    // ── Parse interfaces terse – mark LAG members and IRB first ──
    // (already done above in the ifc loop — ptype is set)

    // ── Parse LLDP neighbors ──
    // Store LLDP data but do NOT blindly set is_trunk here.
    // A server with an LLDP-capable NIC is NOT a trunk port.
    foreach (explode("\n", $lldp_out) as $line) {
        $line = trim($line);
        // Format: Local-Iface  Parent-Iface  Chassis-Id  Port-Info  System-Name
        // ge-0/0/10  -  04:d5:90:99:e7:23  (blank)  Master-FW
        // ge-2/0/44  -  00:31:46:39:7d:00  ge-0/0/23.0  HCI-SW2
        if (!preg_match('/^(ge-[\d\/]+|ae\d+)\s+(\S+|-)\s+([0-9a-f:]{17})\s*(.*)/i', $line, $m))
            continue;

        $local_port  = $m[1];
        $parent_ifc  = $m[2];   // '-' = no LAG parent, 'ae4' = LAG member
        $chassis_id  = strtoupper($m[3]);
        $rest        = trim($m[4]);

        // Parse port-info and system-name from rest
        // rest is: "ge-0/0/23.0  HCI-SW2"  or "port1  Master-FW"  or "me0.0  ANALYZER-SWITCH"
        $parts       = preg_split('/\s{2,}/', $rest, 2);
        $remote_port = trim($parts[0] ?? '');
        $sys_name    = trim($parts[1] ?? '');
        // If only one word (no double-space), it might be just the system name
        if ($sys_name === '' && !preg_match('/^[a-z]{2}[\d\/\-]+(\.\d+)?$/i', $remote_port)) {
            $sys_name    = $remote_port;
            $remote_port = '';
        }

        $lldp[] = [
            'local_port'  => $local_port,
            'parent_ifc'  => $parent_ifc,
            'chassis_id'  => $chassis_id,
            'remote_port' => $remote_port,
            'system_name' => $sys_name,
        ];

        // Store LLDP neighbor name on the port for display
        if (isset($ports[$local_port])) {
            $label = $sys_name ?: $chassis_id;
            $ports[$local_port]['lldp_neighbor'] = $label;
        }
    }

    // ── Final trunk detection – correct rules ──
    // A port is TRUNK only if:
    //   1. mac_count >= NAC_TRUNK_THRESHOLD (≥8 MACs = downstream switch)
    //   2. port_type is 'lag' (ae0, ae1 aggregated ports)
    //   3. port_type is 'lag-member' (ge-x/x/x with aenet proto)
    // IRB interfaces are NEVER trunk — they are L3 gateway interfaces.
    // Single devices with LLDP (firewalls, servers with smart NICs) are ACCESS.
    foreach ($ports as $pname => &$pdata) {
        $mc    = $pdata['mac_count'] ?? 0;
        $ptype = $pdata['port_type'] ?? 'access';

        // IRB = always L3 gateway, never trunk, never access alarm
        if (str_starts_with($pname, 'irb') || $ptype === 'irb') {
            $pdata['port_type'] = 'irb';
            $pdata['is_trunk']  = 0;
            continue;
        }

        // LAG aggregate ports (ae0, ae1...) — show as LAG type, suppress MAC alarms
        if ($ptype === 'lag') {
            $pdata['is_trunk'] = 1;  // suppress alarm — LAG carries bundled traffic
            continue;
        }

        // LAG member physical ports (aenet proto) — mark as lag-member, suppress alarms
        if ($ptype === 'lag-member') {
            $pdata['is_trunk'] = 1;
            continue;
        }

        // Access port with 8+ MACs = downstream switch = true trunk
        if ($mc >= NAC_TRUNK_THRESHOLD) {
            $pdata['is_trunk']  = 1;
            $pdata['port_type'] = 'trunk';
            continue;
        }

        // Everything else = access port (including LLDP-capable single devices)
        $pdata['is_trunk']  = 0;
        $pdata['port_type'] = 'access';
    }
    unset($pdata);

    // ── Mark via_trunk on MACs learned through trunk ports ──
    // This lets the UI show "via trunk" instead of misleading direct port
    $trunk_port_names = [];
    foreach ($ports as $pn => $pd) {
        if (!empty($pd['is_trunk']) && ($pd['port_type'] ?? '') === 'trunk') {
            $trunk_port_names[$pn] = true;
        }
    }
    foreach ($macs as &$mac_entry) {
        if (isset($trunk_port_names[$mac_entry['port']])) {
            $mac_entry['via_trunk'] = 1;
        }
    }
    unset($mac_entry);

    return [
        'macs'  => array_values($macs),
        'ports' => array_values($ports),
        'lldp'  => $lldp,
    ];
}

/* ═══════════════════════════════════════════════════════════════
   CISCO IOS PARSER (2950/2960/3560/3750)
═══════════════════════════════════════════════════════════════ */
function parse_ios(string $mac_out, string $ifc_out, string $lldp_out): array {
    $macs  = [];
    $ports = [];
    $lldp  = [];

    // ── Parse MAC table ──
    // Format: vlan  mac_address  type  ports
    // 1    0050.56a6.1cf6  DYNAMIC  Gi0/1
    foreach (explode("\n", $mac_out) as $line) {
        $line = trim($line);
        if (!preg_match('/^(\d+)\s+([0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4})\s+(\S+)\s+(\S+)/i', $line, $m))
            continue;
        $vlan  = 'vlan' . $m[1];
        $mac   = cisco_mac_to_std($m[2]);
        $type  = strtoupper($m[3][0]); // D/S
        $iface = $m[4];

        $macs[$mac] = ['mac' => $mac, 'vlan' => $vlan, 'port' => $iface, 'type' => $type];
        if (!isset($ports[$iface])) {
            $ports[$iface] = ['port_name' => $iface, 'mac_count' => 0, 'vlans' => []];
        }
        $ports[$iface]['mac_count']++;
        if (!in_array($vlan, $ports[$iface]['vlans'])) $ports[$iface]['vlans'][] = $vlan;
    }

    // ── Parse interfaces status ──
    // Port    Name    Status    Vlan    Duplex  Speed
    // Gi0/1           connected   1     a-full  100
    foreach (explode("\n", $ifc_out) as $line) {
        $line = trim($line);
        if (!preg_match('/^(Gi\S+|Fa\S+|Te\S+|Hu\S+|Po\d+)\s+(\S*)\s+(connected|notconnect|disabled|err-disabled)\s+(\S+)/i', $line, $m))
            continue;
        $pname  = $m[1];
        $status = strtolower($m[3]);
        $vlan   = $m[4];
        $admin  = ($status === 'disabled') ? 'down' : 'up';
        $link   = ($status === 'connected') ? 'up' : 'down';

        if (!isset($ports[$pname])) $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
        $ports[$pname]['admin_status'] = $admin;
        $ports[$pname]['link_status']  = $link;
        $ports[$pname]['port_type']    = str_starts_with($pname, 'Po') ? 'lag' : 'access';
        if ($vlan === 'trunk') { $ports[$pname]['is_trunk'] = 1; $ports[$pname]['port_type'] = 'trunk'; }
    }

    // Auto-detect trunks by MAC count
    foreach ($ports as &$p) {
        if (($p['mac_count'] ?? 0) >= NAC_TRUNK_THRESHOLD) $p['is_trunk'] = 1;
    }
    unset($p);

    // ── Parse CDP neighbors ──
    foreach (explode("\n", $lldp_out) as $line) {
        if (preg_match('/^(\S+)\s+(\S+)\s+\d+\s+\S+\s+(\S+)\s+(\S+)/', $line, $m)) {
            $lldp[] = [
                'local_port'  => $m[2],
                'chassis_id'  => '',
                'remote_port' => $m[4],
                'system_name' => $m[1],
            ];
        }
    }

    return ['macs' => array_values($macs), 'ports' => array_values($ports), 'lldp' => $lldp];
}

function cisco_mac_to_std(string $mac): string {
    // Convert 0050.56a6.1cf6 → 00:50:56:A6:1C:F6
    $hex = str_replace('.', '', $mac);
    $parts = str_split($hex, 2);
    return strtoupper(implode(':', $parts));
}

function procurve_mac_to_std(string $mac): string {
    // Convert 000c29-010bd5 → 00:0C:29:01:0B:D5
    $hex = str_replace('-', '', $mac);
    $parts = str_split($hex, 2);
    return strtoupper(implode(':', $parts));
}

/* ═══════════════════════════════════════════════════════════════
   HP PROCURVE / ARUBA 2930 PARSER
   Commands:
     show mac-address            → MAC table
     show interfaces status      → port status (Tagged/Untagged columns)
     show lldp info remote-device → LLDP neighbors
═══════════════════════════════════════════════════════════════ */
function parse_procurve(string $mac_out, string $ifc_out, string $lldp_out): array {
    $macs  = [];
    $ports = [];
    $lldp  = [];

    // ── Parse MAC table ──
    // FORMAT A: "show mac-address" (3-column)
    //   MAC Address       Port   VLAN
    //   000c29-010bd5     27     1
    //
    // FORMAT B: "show mac-address detail" (4-column with Age)
    //   MAC Address       Port                    VLAN  Age (d:h:m:s.ms)
    //   000c29-010bd5     Trk24                   1     0000:00:03:17.32
    //   70e284-0edb1b     8                       111   0000:00:01:58.07
    //
    // The key difference: detail format has the age column (d:h:m:s.ms) at the end.
    // Both formats use the HP compacted MAC: xxxxxx-xxxxxx
    foreach (explode("\n", $mac_out) as $line) {
        $line = trim($line);
        // Skip header / dashes / empty lines
        if ($line === '' || str_starts_with($line, '-') || str_starts_with($line, 'MAC')
            || str_starts_with($line, 'Status') || str_starts_with($line, 'Port')) continue;

        // Match both formats:
        //   xxxxxx-xxxxxx   <port>   <vlan>   [optional age]
        // Port can be: numeric (1-28), Trk24, Trk1, etc.
        if (!preg_match(
            '/^([0-9a-f]{6}-[0-9a-f]{6})\s+(\S+)\s+(\d+)/i',
            $line, $m
        )) continue;

        $mac  = procurve_mac_to_std($m[1]);
        $port = $m[2];   // '27', '8', 'Trk24', etc.
        $vlan = 'vlan' . $m[3];

        // Normalize port: numeric → use as-is, Trk# → trunk group
        $port_normalized = is_numeric($port) ? $port : strtolower($port);

        // First MAC on port wins for tracking (MACs appear multiple times for multiple VLANs)
        if (!isset($macs[$mac])) {
            $macs[$mac] = ['mac' => $mac, 'vlan' => $vlan, 'port' => $port_normalized, 'type' => 'D'];
        }
        if (!isset($ports[$port_normalized])) {
            $ports[$port_normalized] = ['port_name' => $port_normalized, 'mac_count' => 0, 'vlans' => []];
        }
        // Only count each MAC once per port (HP reports same MAC for each VLAN it appears in)
        if (!isset($ports[$port_normalized]['_seen_macs'][$mac])) {
            $ports[$port_normalized]['_seen_macs'][$mac] = true;
            $ports[$port_normalized]['mac_count']++;
        }
        if (!in_array($vlan, $ports[$port_normalized]['vlans'])) {
            $ports[$port_normalized]['vlans'][] = $vlan;
        }
    }

    // ── Parse interfaces (handles BOTH show interfaces brief AND show interfaces status) ──
    //
    // FORMAT A: "show interfaces status" (NAC-Sw1 style)
    //   Port  Name  Status  Config-mode  Speed  Type  Tagged  Untagged
    //   1           Down    Auto         1000   100T  multi   304
    //   28-Trk24                                              (standalone LAG line)
    //
    // FORMAT B: "show interfaces brief" (ServSW-Rack11 style)
    //   Port  Type  | Alert Enabled Status Mode  MDI Flow Bcast
    //   1    100/T  | No   Yes     Down   1000  Auto off  0
    //   23-Trk1 100/T | No Yes    Down   1000  Auto off  0   (LAG member WITH type info)
    //   25*  SFP+SR | No   Yes     Up    10Gig NA   off  0
    //
    // Detection: if ifc_out contains "Intrusion" header → brief format
    //            if ifc_out contains "Tagged" header → status format
    $use_brief_format = str_contains($ifc_out, 'Intrusion') || str_contains($ifc_out, 'Alert');

    foreach (explode("\n", $ifc_out) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '-') || str_starts_with($line, 'Port')
            || str_starts_with($line, 'Status') || str_starts_with($line, 'Max')
            || str_starts_with($line, 'Primary') || str_starts_with($line, 'Manag')
            || str_starts_with($line, 'VLAN') || str_starts_with($line, '*')) {
            continue; // skip headers and legend lines
        }

        if ($use_brief_format) {
            // FORMAT B: "show interfaces brief"
            // "  1   100/1000T  | No  Yes  Down  1000FDx  Auto  off  0"
            // "  23-Trk1  100/1000T | No  Yes  Down  ..."  ← LAG member with status
            // "  25*  SFP+SR | No  Yes  Up  10GigFD  NA  off  0"  ← SFP port (strip *)

            // Match: port[-TrkN]  [*]type | ... Enabled Up/Down ...
            // Port 26 has empty type field: "  26    | No  Yes  Down  ..."
            // so \S* (zero-or-more) instead of \S+ for the type column
            if (!preg_match(
                '/^\s*(\d+)(?:-(\S+))?\*?\s+\S*\s*\|\s*\S+\s+(Yes|No)\s+(Up|Down)/i',
                $line, $m
            )) continue;

            $pname   = $m[1];
            $lag_grp = $m[2] ? strtolower($m[2]) : null; // e.g. 'trk1', 'trk24'
            $enabled = strtolower($m[3]);  // Yes/No
            $status  = strtolower($m[4]);  // Up/Down

            if (!isset($ports[$pname])) $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
            $ports[$pname]['admin_status'] = ($enabled === 'yes') ? 'up' : 'down';
            $ports[$pname]['link_status']  = $status;

            if ($lag_grp) {
                // This port is a LAG member (e.g. "23-Trk1")
                $ports[$pname]['port_type'] = 'lag-member';
                $ports[$pname]['lag_group'] = $lag_grp;
                $ports[$pname]['is_trunk']  = 1;
                // Also ensure the Trk# group entry exists
                if (!isset($ports[$lag_grp])) {
                    $ports[$lag_grp] = ['port_name' => $lag_grp, 'mac_count' => 0, 'vlans' => [],
                                        'port_type' => 'lag', 'is_trunk' => 1,
                                        'admin_status' => 'up', 'link_status' => $status];
                } elseif ($status === 'up') {
                    $ports[$lag_grp]['link_status'] = 'up'; // LAG is up if any member is up
                }
            } else {
                // Regular access port — port type determined later by MAC count
                if (!isset($ports[$pname]['port_type'])) {
                    $ports[$pname]['port_type'] = 'access';
                    $ports[$pname]['is_trunk']  = 0;
                }
            }

        } else {
            // FORMAT A: "show interfaces status"
            // Header: Port  Name  Status  Config-mode  Speed    Type       Tagged  Untagged
            // E.g.:  "  1           Down    Auto    1000FDx  100/1000T  No     222"
            //        "  3           Up      Auto    1000FDx  100/1000T  No     222"
            //        "  24-Trk1    Down    Auto    1000FDx  100/1000T  No     1"   ← LAG WITH cols
            //        "  28-Trk24"  ← standalone LAG member line (no status cols)

            // Standalone LAG member line (just "N-TrkN" with nothing after)
            if (preg_match('/^\s*(\d+)-(\S+)\s*$/', $line, $m)) {
                $pname   = $m[1];
                $lag_grp = strtolower($m[2]);
                if (!isset($ports[$pname])) $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
                $ports[$pname]['port_type']    = 'lag-member';
                $ports[$pname]['lag_group']    = $lag_grp;
                $ports[$pname]['admin_status'] = 'up';
                $ports[$pname]['link_status']  = 'down';
                $ports[$pname]['is_trunk']     = 1;
                continue;
            }

            // LAG member WITH status columns: "N-TrkN  [name]  Up|Down  ..."
            if (preg_match('/^\s*(\d+)-(\S+)\s+\S*\s*(Up|Down)\s+/i', $line, $m)) {
                $pname   = $m[1];
                $lag_grp = strtolower($m[2]);
                $status  = strtolower($m[3]);
                if (!isset($ports[$pname])) $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
                $ports[$pname]['port_type']    = 'lag-member';
                $ports[$pname]['lag_group']    = $lag_grp;
                $ports[$pname]['admin_status'] = 'up';
                $ports[$pname]['link_status']  = $status;
                $ports[$pname]['is_trunk']     = 1;
                if (!isset($ports[$lag_grp])) {
                    $ports[$lag_grp] = ['port_name' => $lag_grp, 'mac_count' => 0, 'vlans' => [],
                                        'port_type' => 'lag', 'is_trunk' => 1,
                                        'admin_status' => 'up', 'link_status' => $status];
                } elseif ($status === 'up') {
                    $ports[$lag_grp]['link_status'] = 'up';
                }
                continue;
            }

            // Normal port line: "  N  [Name]  Up|Down  Config-mode  Speed  Type  Tagged  Untagged"
            // The Name column may be empty (ports jump from number to Status).
            // We allow \S* for the Name field (zero or more non-space chars).
            if (!preg_match(
                '/^\s*(\d+)\s+\S*\s*(Up|Down)\s+\S+\s+\S+\s+\S+\s+(\S+)\s+(\S+)/i',
                $line, $m
            )) continue;

            $pname   = $m[1];
            $status  = strtolower($m[2]);
            $tagged  = strtolower($m[3]);  // 'multi', 'no', or VLAN#

            if (!isset($ports[$pname])) $ports[$pname] = ['port_name' => $pname, 'mac_count' => 0, 'vlans' => []];
            $ports[$pname]['admin_status'] = 'up';
            $ports[$pname]['link_status']  = $status;

            // Tagged='multi' = explicitly trunk; numeric VLAN in Tagged col = tagged membership
            if ($tagged === 'multi' || (is_numeric($tagged) && (int)$tagged > 0)) {
                $ports[$pname]['port_type'] = 'trunk';
                $ports[$pname]['is_trunk']  = 1;
            } else {
                $ports[$pname]['port_type'] = 'access';
                $ports[$pname]['is_trunk']  = 0;
            }
        }
    }

    // ── Finalize Trunk groups (Trk#) and apply MAC threshold ──
    foreach ($ports as $pname => &$pdata) {
        // Ensure all trk# ports are marked as LAG
        if (preg_match('/^trk\d+$/i', $pname)) {
            $pdata['port_type']    = 'lag';
            $pdata['is_trunk']     = 1;
            $pdata['admin_status'] = $pdata['admin_status'] ?? 'up';
            $pdata['link_status']  = $pdata['link_status']  ?? 'down';
        }
        // MAC threshold override: access port with 8+ MACs = downstream switch = trunk
        if (empty($pdata['is_trunk']) && ($pdata['mac_count'] ?? 0) >= NAC_TRUNK_THRESHOLD) {
            $pdata['is_trunk']  = 1;
            $pdata['port_type'] = 'trunk';
        }
        // Clean up internal tracking key
        unset($pdata['_seen_macs']);
        // Set vlan_names string from vlans array
        if (empty($pdata['vlan_names']) && !empty($pdata['vlans'])) {
            $pdata['vlan_names'] = implode(',', $pdata['vlans']);
        }
    }
    unset($pdata);

    // ── Parse LLDP neighbors ──
    // Format:  LocalPort | ChassisId  PortId  PortDescr  SysName
    //          20        | Outside... GigabitEthernet0/5   cisco WS-...
    //          27        | b8d4e7-... 28       28          ServSW-Rack11
    foreach (explode("\n", $lldp_out) as $line) {
        $line = trim($line);
        if (!preg_match('/^(\d+|Trk\S+)\s*\|\s*(\S+)\s+(\S+)\s+(\S*)\s*(.*)/i', $line, $m))
            continue;
        $local_port  = $m[1];
        $chassis_id  = $m[2];
        $remote_port = $m[3];
        $sys_name    = trim(preg_replace('/\s{2,}.*/', '', $m[5]) ?: $m[4]);

        if (strtolower($chassis_id) === 'chassisid') continue; // header

        $lldp[] = [
            'local_port'  => $local_port,
            'chassis_id'  => $chassis_id,
            'remote_port' => $remote_port,
            'system_name' => $sys_name,
        ];

        if (isset($ports[$local_port])) {
            $ports[$local_port]['lldp_neighbor'] = $sys_name ?: $chassis_id;
        }
    }

    // ── Mark via_trunk on MACs ──
    $trunk_names = [];
    foreach ($ports as $pn => $pd) {
        if (!empty($pd['is_trunk']) && in_array($pd['port_type'] ?? '', ['trunk', 'lag'])) {
            $trunk_names[$pn] = true;
        }
    }
    foreach ($macs as &$me) {
        if (isset($trunk_names[$me['port']])) $me['via_trunk'] = 1;
    }
    unset($me);

    return [
        'macs'  => array_values($macs),
        'ports' => array_values($ports),
        'lldp'  => $lldp,
    ];
}

/* ═══════════════════════════════════════════════════════════════
   VLAN NAME NORMALISER
   JunOS can report the same VLAN two ways between polls:
     - by its configured name: "default", "Prod", "management"
     - as a generic "vlan<N>" token (seen in some 13.x formats)
   We keep both representations in a small known map so that
   "default" and "vlan1" are treated as identical, preventing
   spurious VLAN-change alarms.
   Unknown VLAN names are returned as-is (lowercase) so the
   comparison is still case-insensitive.
═══════════════════════════════════════════════════════════════ */
function _nac_normalise_vlan(string $vlan): string {
    // Canonical map: known JunOS VLAN name ↔ its numeric ID string
    // Add more entries here as your network grows.
    static $name_to_num = [
        'default'    => '1',
        'vlan1'      => '1',
        'management' => '1',   // only if your mgmt VLAN really is 1; adjust as needed
    ];
    $lc = strtolower(trim($vlan));
    return $name_to_num[$lc] ?? $lc;
}

/**
 * Inline fallback for VLAN specificity scoring used by parse_junos() when
 * driver_junos.php is not loaded. Mirrors _junos_vlan_specificity() exactly.
 *   0 = "default"        (least specific)
 *   1 = "vlan<N>"        (generic numeric)
 *   2 = any named VLAN   (most specific — e.g. "Prod", "internet-red")
 */
function _nac_inline_vlan_specificity(string $vlan): int {
    $lc = strtolower(trim($vlan));
    if ($lc === 'default') return 0;
    if (preg_match('/^vlan\d+$/i', $lc)) return 1;
    return 2;
}

/* ═══════════════════════════════════════════════════════════════
   PERSIST POLL DATA TO DB
   Returns array of change events
═══════════════════════════════════════════════════════════════ */
function nac_persist_poll(mysqli $db, int $switch_id, array $parsed): array {
    $changes = [];
    $now     = date('Y-m-d H:i:s');

    // ── Sanitize port names from parser output ──────────────────────────────
    // Some older versions of the inline parse_junos() stored port names with
    // a ".0" unit suffix (e.g. "ge-0/0/33.0" instead of "ge-0/0/33").
    // The driver_junos.php strips .0 correctly, but legacy DB rows may still
    // have the old names. We normalize here so joins never miss on a suffix.
    //
    // Step 1: strip .0 from all incoming parsed port names (belt-and-suspenders).
    // Skip irb.N (JunOS L3 sub-interfaces) and bare 'vlan' (EX-2200 L3 interface).
    foreach ($parsed['ports'] as &$p) {
        if (!preg_match('/^irb\./i', $p['port_name']) && !preg_match('/^vlan$/i', $p['port_name'])) {
            $p['port_name'] = preg_replace('/\.0$/', '', $p['port_name']);
        }
    }
    unset($p);

    // Auto-add missing columns for nac_ports
    static $nac_ports_cols_checked = false;
    if (!$nac_ports_cols_checked) {
        $nac_ports_cols = [
            'port_name'    => "ALTER TABLE nac_ports ADD COLUMN port_name VARCHAR(100) DEFAULT NULL",
            'port_type'    => "ALTER TABLE nac_ports ADD COLUMN port_type VARCHAR(50) DEFAULT 'access'",
            'admin_status' => "ALTER TABLE nac_ports ADD COLUMN admin_status VARCHAR(20) DEFAULT 'up'",
            'link_status'  => "ALTER TABLE nac_ports ADD COLUMN link_status VARCHAR(20) DEFAULT 'down'",
            'vlan_names'   => "ALTER TABLE nac_ports ADD COLUMN vlan_names VARCHAR(255) DEFAULT NULL",
            'is_trunk'     => "ALTER TABLE nac_ports ADD COLUMN is_trunk TINYINT(1) DEFAULT 0",
            'mac_count'    => "ALTER TABLE nac_ports ADD COLUMN mac_count INT DEFAULT 0",
            'description'  => "ALTER TABLE nac_ports ADD COLUMN description VARCHAR(255) DEFAULT NULL",
            'last_updated' => "ALTER TABLE nac_ports ADD COLUMN last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($nac_ports_cols as $cname => $sql) {
            $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_ports' AND COLUMN_NAME='$cname'");
            if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
                $db->query($sql);
            }
            $col_chk && $col_chk->free();
        }

        $nac_pm_cols = [
            'port_name'  => "ALTER TABLE nac_port_macs ADD COLUMN port_name VARCHAR(100) DEFAULT NULL",
            'vlan_name'  => "ALTER TABLE nac_port_macs ADD COLUMN vlan_name VARCHAR(100) DEFAULT NULL",
            'mac_type'   => "ALTER TABLE nac_port_macs ADD COLUMN mac_type VARCHAR(20) DEFAULT 'D'",
            'first_seen' => "ALTER TABLE nac_port_macs ADD COLUMN first_seen DATETIME DEFAULT CURRENT_TIMESTAMP",
            'last_seen'  => "ALTER TABLE nac_port_macs ADD COLUMN last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($nac_pm_cols as $cname => $sql) {
            $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_port_macs' AND COLUMN_NAME='$cname'");
            if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
                $db->query($sql);
            }
            $col_chk && $col_chk->free();
        }

        $nac_evt_cols = [
            'event_type'   => "ALTER TABLE nac_mac_events ADD COLUMN event_type VARCHAR(50) DEFAULT NULL",
            'old_port'     => "ALTER TABLE nac_mac_events ADD COLUMN old_port VARCHAR(100) DEFAULT NULL",
            'new_port'     => "ALTER TABLE nac_mac_events ADD COLUMN new_port VARCHAR(100) DEFAULT NULL",
            'old_vlan'     => "ALTER TABLE nac_mac_events ADD COLUMN old_vlan VARCHAR(100) DEFAULT NULL",
            'new_vlan'     => "ALTER TABLE nac_mac_events ADD COLUMN new_vlan VARCHAR(100) DEFAULT NULL",
            'details'      => "ALTER TABLE nac_mac_events ADD COLUMN details TEXT DEFAULT NULL",
            'is_alarm'     => "ALTER TABLE nac_mac_events ADD COLUMN is_alarm TINYINT(1) DEFAULT 0",
            'acknowledged' => "ALTER TABLE nac_mac_events ADD COLUMN acknowledged TINYINT(1) DEFAULT 0",
            'ack_by'       => "ALTER TABLE nac_mac_events ADD COLUMN ack_by VARCHAR(100) DEFAULT NULL",
            'ack_note'     => "ALTER TABLE nac_mac_events ADD COLUMN ack_note TEXT DEFAULT NULL",
            'ack_at'       => "ALTER TABLE nac_mac_events ADD COLUMN ack_at DATETIME DEFAULT NULL",
        ];
        foreach ($nac_evt_cols as $cname => $sql) {
            $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_mac_events' AND COLUMN_NAME='$cname'");
            if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
                $db->query($sql);
            }
            $col_chk && $col_chk->free();
        }

        // Guarantee UNIQUE indexes exist so ON DUPLICATE KEY UPDATE works properly
        $idx_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_ports' AND INDEX_NAME='uq_switch_port'");
        if ($idx_chk && (int)$idx_chk->fetch_row()[0] === 0) {
            $db->query("ALTER TABLE nac_ports ADD UNIQUE KEY uq_switch_port (switch_id, port_name)");
        }
        $idx_chk && $idx_chk->free();

        $idx_chk2 = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_port_macs' AND INDEX_NAME='uq_switch_port_mac'");
        if ($idx_chk2 && (int)$idx_chk2->fetch_row()[0] === 0) {
            $db->query("ALTER TABLE nac_port_macs ADD UNIQUE KEY uq_switch_port_mac (switch_id, port_name, mac)");
        }
        $idx_chk2 && $idx_chk2->free();

        $nac_ports_cols_checked = true;
    }
    foreach ($parsed['macs'] as &$m) {
        if (!preg_match('/^irb\./i', $m['port']) && !preg_match('/^vlan$/i', $m['port'])) {
            $m['port'] = preg_replace('/\.0$/', '', $m['port']);
        }
    }
    unset($m);

    // Step 2: rename any DB rows that still use the .0 suffix for this switch.
    // This is a one-time migration that becomes a no-op once all rows are clean.
    $stale_ports = $db->query(
        "SELECT id, port_name FROM nac_ports
         WHERE switch_id=$switch_id AND port_name REGEXP '\\.0$' AND port_name NOT REGEXP '^irb\\\\.'
         LIMIT 200"
    );
    if ($stale_ports) {
        while ($sp = $stale_ports->fetch_assoc()) {
            $old_name = $sp['port_name'];
            $new_name = preg_replace('/\.0$/', '', $old_name);
            $esc_old  = $db->real_escape_string($old_name);
            $esc_new  = $db->real_escape_string($new_name);
            // Rename the port (ignore if new name already exists — DUPLICATE KEY)
            $db->query("UPDATE IGNORE nac_ports SET port_name='$esc_new' WHERE switch_id=$switch_id AND port_name='$esc_old'");
            $db->query("UPDATE nac_port_macs SET port_name='$esc_new' WHERE switch_id=$switch_id AND port_name='$esc_old'");
            $db->query("UPDATE nac_mac_events SET old_port='$esc_new' WHERE switch_id=$switch_id AND old_port='$esc_old'");
            $db->query("UPDATE nac_mac_events SET new_port='$esc_new' WHERE switch_id=$switch_id AND new_port='$esc_old'");
            // If a duplicate now exists (edge case), remove the stale .0 row
            $db->query("DELETE FROM nac_ports WHERE switch_id=$switch_id AND port_name='$esc_old' LIMIT 1");
        }
        $stale_ports->free();
    }

    // ── Snapshot current port VLAN assignments BEFORE any writes ─────────────
    // The ports upsert below will overwrite vlan_names with data from the current
    // poll's MAC table. For the VLAN-change suppression logic (Layer 2) and the
    // show-vlans baseline comparison we need the PREVIOUS poll's values.
    // Capture them now into memory so the MAC loop and vlan_ports loop can use
    // them reliably regardless of write order.
    $pre_poll_port_vlans = [];
    $ppv_res = $db->query(
        "SELECT port_name, vlan_names FROM nac_ports WHERE switch_id=$switch_id"
    );
    if ($ppv_res) {
        while ($ppv_row = $ppv_res->fetch_assoc()) {
            $pre_poll_port_vlans[$ppv_row['port_name']] = $ppv_row['vlan_names'] ?? '';
        }
        $ppv_res->free();
    }

    // ── Upsert ports ──
    foreach ($parsed['ports'] as $p) {
        $pname  = $p['port_name'];
        $ptype  = $p['port_type']     ?? 'unknown';
        $admin  = $p['admin_status']  ?? 'up';
        $link   = $p['link_status']   ?? 'down';
        $vlans  = implode(',', $p['vlans'] ?? []);
        $trunk  = $p['is_trunk']      ?? 0;
        $mc     = $p['mac_count']     ?? 0;
        $desc   = $p['lldp_neighbor'] ?? null;

        // Check previous state for port up/down events
        $prev = $db->query(
            "SELECT link_status, is_trunk FROM nac_ports
             WHERE switch_id=$switch_id AND port_name='" . $db->real_escape_string($pname) . "'"
        );
        $prev_row = $prev ? $prev->fetch_assoc() : null;
        $prev && $prev->free();

        if ($prev_row) {
            if ($prev_row['link_status'] !== $link) {
                $evt = ($link === 'up') ? 'port_up' : 'port_down';
                $changes[] = ['type' => $evt, 'port' => $pname, 'detail' => "Port $pname went $link"];
                nac_log_event($db, $switch_id, '', $evt, null, $pname, null, null,
                              "Port $pname: link $prev_row[link_status] → $link", 0);
            }
        }

        $s = $db->prepare(
            "INSERT INTO nac_ports (switch_id,port_name,port_type,admin_status,link_status,
             vlan_names,is_trunk,mac_count,description,last_updated)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE port_type=VALUES(port_type),admin_status=VALUES(admin_status),
             link_status=VALUES(link_status),
             vlan_names=CASE
               WHEN VALUES(vlan_names)='' THEN vlan_names
               WHEN vlan_names='' OR vlan_names IS NULL THEN VALUES(vlan_names)
               WHEN VALUES(vlan_names)='default'
                    AND vlan_names NOT IN ('','default') THEN vlan_names
               ELSE VALUES(vlan_names)
             END,
             is_trunk=VALUES(is_trunk),mac_count=VALUES(mac_count),
             description=COALESCE(VALUES(description),description),last_updated=NOW()"
        );
        $s->bind_param('isssssiis', $switch_id,$pname,$ptype,$admin,$link,$vlans,$trunk,$mc,$desc);
        $s->execute(); $s->close();
    }

    // ── Process MACs ──
    // Get current DB state
    $cur_res = $db->query(
        "SELECT mac, port_name, vlan_name FROM nac_port_macs WHERE switch_id=$switch_id"
    );
    $current = [];
    if ($cur_res) {
        while ($r = $cur_res->fetch_assoc()) $current[$r['mac']] = $r;
        $cur_res->free();
    }

    // Get trunk port list (no alarms for these)
    $trunk_res = $db->query(
        "SELECT port_name FROM nac_ports WHERE switch_id=$switch_id AND is_trunk=1"
    );
    $trunk_ports = [];
    if ($trunk_res) {
        while ($r = $trunk_res->fetch_assoc()) $trunk_ports[$r['port_name']] = true;
        $trunk_res->free();
    }

    $seen_macs = [];
    foreach ($parsed['macs'] as $m) {
        $mac   = $m['mac'];
        $port  = $m['port'];
        $vlan  = $m['vlan'];
        $seen_macs[$mac] = true;

        $is_trunk_port = isset($trunk_ports[$port]) ||
                         str_starts_with($port, 'irb') ||
                         str_starts_with($port, 'ae');

        // Get port_id
        $pid_res = $db->query(
            "SELECT id FROM nac_ports WHERE switch_id=$switch_id
             AND port_name='" . $db->real_escape_string($port) . "'"
        );
        $port_id = $pid_res ? ((int)($pid_res->fetch_row()[0] ?? 0)) : 0;
        $pid_res && $pid_res->free();

        if (!isset($current[$mac])) {
            // New MAC
            $alarm = $is_trunk_port ? 0 : 1;
            $changes[] = ['type' => 'new_mac', 'mac' => $mac, 'port' => $port, 'vlan' => $vlan];
            nac_log_event($db, $switch_id, $mac, 'new_mac', null, $port, null, $vlan,
                          "New MAC $mac on $port (VLAN $vlan)", $alarm);
        } else {
            $old = $current[$mac];
            $old_port = $old['port_name'];
            $old_vlan = $old['vlan_name'];

            // MAC moved between ports
            if ($old_port !== $port) {
                $old_is_trunk = isset($trunk_ports[$old_port]);
                $new_is_trunk = $is_trunk_port;
                // Only alarm if moving between access ports
                $alarm = (!$old_is_trunk && !$new_is_trunk) ? 1 : 0;
                $changes[] = ['type'=>'mac_moved','mac'=>$mac,'from'=>$old_port,'to'=>$port];
                nac_log_event($db, $switch_id, $mac, 'mac_moved', $old_port, $port, $old_vlan, $vlan,
                              "MAC $mac moved: $old_port → $port", $alarm);
            }

            // VLAN change — only alarm on access ports.
            // Trunk ports see MACs from many VLANs and the reported VLAN can change
            // between polls without any real change — suppress these entirely.
            //
            // JunOS normalisation strategy (two layers):
            //
            // Layer 1 — name alias map: "default" and "vlan1" both mean VLAN 1.
            //
            // Layer 2 — port ground-truth: On JunOS 13.x (EX4300 VC / EX2200),
            //   the MAC table lists every learned MAC under the "default" routing
            //   instance as well as its real VLAN block. When a MAC has only ONE
            //   occurrence in the output, whichever block the switch prints first
            //   wins — and that is often "default" even for MACs on named VLANs
            //   like "Prod". To prevent spurious alarms we compare the NEW vlan
            //   against the port's configured VLAN from nac_ports.vlan_names. If
            //   the new MAC-table VLAN is "default" and the port's known VLAN is
            //   something else (e.g. "Prod"), we trust the port and suppress the
            //   alarm — the MAC table is being misleading, not the configuration.
            if ($old_vlan && $old_vlan !== $vlan && !$is_trunk_port) {
                $norm_old = _nac_normalise_vlan($old_vlan);
                $norm_new = _nac_normalise_vlan($vlan);

                // Suppress if normalised names match (e.g. default ↔ vlan1)
                $should_alarm = ($norm_old !== $norm_new);

                // Layer 2: if the NEW value is "default" (score=0) and the OLD
                // value is a real named VLAN (score=2), check whether the port's
                // configured VLAN matches the old named VLAN. If so, the MAC table
                // is just reporting under the default routing-instance — suppress.
                if ($should_alarm
                    && _nac_inline_vlan_specificity($vlan) === 0   // new = "default"
                    && _nac_inline_vlan_specificity($old_vlan) === 2 // old = named VLAN
                ) {
                    // Use the pre-poll snapshot — the port upsert above has already
                    // overwritten nac_ports.vlan_names with the current (possibly wrong)
                    // MAC-table value, so we cannot query the DB here.
                    $port_vlan = strtolower(explode(',', $pre_poll_port_vlans[$port] ?? '')[0]);

                    if ($port_vlan === 'default' || $port_vlan === '') {
                        // The port's configured VLAN IS default (confirmed by show vlans
                        // or nac_ports baseline). The old named VLAN stored in
                        // nac_port_macs is stale garbage from the historic flapping bug.
                        // Suppress the alarm and correct $vlan to 'default' so the
                        // MAC upsert below writes the right value.
                        $should_alarm = false;
                        $vlan = 'default';
                    } elseif ($port_vlan === strtolower($old_vlan)) {
                        // Port's known VLAN matches the old named VLAN — the "default"
                        // in the current poll is a JunOS MAC-table artefact. Suppress
                        // and keep the named VLAN.
                        $should_alarm = false;
                        $vlan = $old_vlan;
                    }
                }

                if ($should_alarm) {
                    $changes[] = ['type'=>'vlan_change','mac'=>$mac,'port'=>$port,'old'=>$old_vlan,'new'=>$vlan];
                    nac_log_event($db, $switch_id, $mac, 'vlan_change', $port, $port, $old_vlan, $vlan,
                                  "VLAN change on $port: $old_vlan → $vlan", 1);
                }
            }
        }

        // Upsert MAC — use CASE to prevent stale named VLANs overwriting
        // the correct 'default' (and vice versa) due to MAC table flapping.
        // Rule: if the stored vlan_name is 'default' and the port's known
        // configured VLAN is 'default', keep 'default' even if the current
        // MAC table reports a named VLAN. The $vlan variable was already
        // corrected above by the Layer 2 suppression where applicable.
        $s = $db->prepare(
            "INSERT INTO nac_port_macs (switch_id,port_id,port_name,mac,vlan_name,mac_type,first_seen,last_seen)
             VALUES (?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE port_id=VALUES(port_id),port_name=VALUES(port_name),
             vlan_name=VALUES(vlan_name),last_seen=NOW()"
        );
        $s->bind_param('iissss', $switch_id,$port_id,$port,$mac,$vlan,$m['type']);
        $s->execute(); $s->close();
    }

    // ── Multi-MAC on access port detection ──
    // Count MACs per access port from the current poll
    $port_mac_counts = [];
    foreach ($parsed['macs'] as $m) {
        $p = $m['port'];
        if (!isset($trunk_ports[$p]) && !str_starts_with($p,'irb') && !str_starts_with($p,'ae')) {
            $port_mac_counts[$p] = ($port_mac_counts[$p] ?? 0) + 1;
        }
    }
    foreach ($port_mac_counts as $pname => $cnt) {
        if ($cnt >= 2) {
            // Only alarm if count increased since last poll to avoid repeat alarms
            $esc_p = $db->real_escape_string($pname);
            $prev_cnt = (int)($db->query(
                "SELECT mac_count FROM nac_ports
                 WHERE switch_id=$switch_id AND port_name='$esc_p'"
            )?->fetch_row()[0] ?? 0);
            if ($cnt > $prev_cnt) {
                nac_log_event($db, $switch_id, '', 'multi_mac', null, $pname, null, null,
                    "Access port $pname has $cnt MACs — possible hub or unmanaged switch", 1);
            }
        }
    }

    // MACs no longer seen = lost
    // ── FIX: Do NOT delete MAC rows from nac_port_macs ──────────────────────
    // Deleting a MAC row means the next poll treats the same device as a brand
    // new intruder and fires a new_mac alarm — the core false-positive problem.
    // Instead we keep the row as the stable baseline and just log the loss as
    // an informational (non-alarm) event. The next poll will either:
    //   a) See the MAC back on the same port → no event (normal)
    //   b) See the MAC on a different port   → mac_moved alarm (real event)
    //   c) Not see the MAC again             → row stays, no repeated alarm
    foreach ($current as $mac => $old) {
        if (!isset($seen_macs[$mac])) {
            $is_trunk = isset($trunk_ports[$old['port_name']]);
            if (!$is_trunk) {
                nac_log_event($db, $switch_id, $mac, 'mac_lost', $old['port_name'], null,
                              $old['vlan_name'], null, "MAC $mac no longer seen on {$old['port_name']}", 0);
            }
            // Intentionally NOT deleting the row — see comment above.
        }
    }

    // ── Detect VLAN config changes from "show vlans" output ──────────────────
    // This catches VLAN changes on ports that have NO active MACs (e.g. down
    // ports or ports where the device hasn't sent any frames yet).
    // We compare the configured_vlan from the current poll against what was
    // stored in nac_ports.vlan_names from the previous poll.
    if (!empty($parsed['vlan_ports'])) {
        // Known CLI noise words that must never be treated as VLAN names
        $vlan_noise = ['exit','quit','commit','rollback','configure','show',
                       'set','delete','error','warning','abort','yes','no'];
        foreach ($parsed['vlan_ports'] as $port_name => $vp) {
            // Skip ports that are ONLY in trunk/tagged VLANs with no untagged VLAN
            if (!empty($vp['is_trunk']) && !empty($vp['tagged'])) continue;
            $new_vlan = $vp['vlan'] ?? '';
            if ($new_vlan === '') continue;
            // Reject CLI noise and invalid names
            if (in_array(strtolower($new_vlan), $vlan_noise)) continue;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\.]{0,63}$/', $new_vlan)) continue;

            $esc_port = $db->real_escape_string($port_name);

            // Use the pre-poll snapshot for comparison — avoids reading back
            // a value that was just overwritten by the port upsert above.
            $old_vlan_raw = $pre_poll_port_vlans[$port_name] ?? null;

            if ($old_vlan_raw === null) {
                // Port not in DB before this poll — write baseline and move on
                $esc_new = $db->real_escape_string($new_vlan);
                $db->query(
                    "UPDATE nac_ports SET vlan_names='$esc_new', last_updated=NOW()
                     WHERE switch_id=$switch_id AND port_name='$esc_port'
                       AND (vlan_names IS NULL OR vlan_names='')"
                );
                continue;
            }

            $old_vlan = explode(',', $old_vlan_raw)[0];

            if ($old_vlan === '') {
                // No baseline yet — write it now for next poll
                $esc_new = $db->real_escape_string($new_vlan);
                $db->query(
                    "UPDATE nac_ports SET vlan_names='$esc_new', last_updated=NOW()
                     WHERE switch_id=$switch_id AND port_name='$esc_port'"
                );
                continue;
            }

            // Compare — only fire if genuinely different after normalisation
            if (_nac_normalise_vlan($old_vlan) !== _nac_normalise_vlan($new_vlan)) {
                // Skip if MAC-table loop already raised a vlan_change for this port
                $already = false;
                foreach ($changes as $c) {
                    if (($c['type'] ?? '') === 'vlan_change' && ($c['port'] ?? '') === $port_name) {
                        $already = true; break;
                    }
                }
                if (!$already) {
                    $changes[] = [
                        'type' => 'vlan_config_change',
                        'port' => $port_name,
                        'old'  => $old_vlan,
                        'new'  => $new_vlan,
                    ];
                    nac_log_event($db, $switch_id, '', 'vlan_change', $port_name, $port_name,
                                  $old_vlan, $new_vlan,
                                  "VLAN config changed on $port_name: $old_vlan → $new_vlan (no active MAC)", 1);
                }
            }

            // Always update vlan_names with show-vlans data (authoritative source)
            $esc_new = $db->real_escape_string($new_vlan);
            $db->query(
                "UPDATE nac_ports SET vlan_names='$esc_new', last_updated=NOW()
                 WHERE switch_id=$switch_id AND port_name='$esc_port'"
            );
        }
    }

    // ── One-time heal: fix stale named VLANs in nac_port_macs ────────────────
    // Previous buggy polls may have stored named VLANs (e.g. 'Prod') in
    // nac_port_macs for ports whose actual configured VLAN is 'default'.
    // This corrects those rows so future polls start from a clean baseline.
    // The UPDATE only touches rows where the port's nac_ports.vlan_names is
    // 'default' but nac_port_macs.vlan_name is something else — safe no-op
    // once all rows are correct.
    $db->query(
        "UPDATE nac_port_macs npm
         JOIN nac_ports p ON p.switch_id = npm.switch_id AND p.port_name = npm.port_name
         SET npm.vlan_name = 'default'
         WHERE npm.switch_id = $switch_id
           AND p.vlan_names = 'default'
           AND npm.vlan_name != 'default'
           AND npm.vlan_name != ''"
    );

    // ── Process LLDP → topology links ──
    foreach ($parsed['lldp'] as $neighbor) {
        // Find the remote switch by chassis_id or system_name
        $remote_name = $db->real_escape_string($neighbor['system_name'] ?? '');
        $chassis     = $db->real_escape_string($neighbor['chassis_id'] ?? '');
        if (!$remote_name && !$chassis) continue;

        $find = $db->query(
            "SELECT id FROM nac_switches
             WHERE hostname='$remote_name' OR ip='$chassis' LIMIT 1"
        );
        if (!$find || $find->num_rows === 0) { $find && $find->free(); continue; }
        $remote_id = (int)$find->fetch_row()[0];
        $find->free();

        $local_port  = $db->real_escape_string($neighbor['local_port']);
        $remote_port = $db->real_escape_string($neighbor['remote_port']);
        $s = $db->prepare(
            "INSERT IGNORE INTO nac_topology_links
             (switch_a_id,port_a,switch_b_id,port_b,link_type,discovered_at)
             VALUES (?,?,?,?,'lldp',NOW())"
        );
        $s->bind_param('isis', $switch_id, $local_port, $remote_id, $remote_port);
        $s->execute(); $s->close();
    }

    // ── Rebuild IPAM cross-reference ──
    nac_rebuild_mac_ip_map($db);

    return $changes;
}

function nac_log_event(mysqli $db, int $switch_id, string $mac, string $type,
                        ?string $old_port, ?string $new_port,
                        ?string $old_vlan, ?string $new_vlan,
                        string $details, int $alarm): void {

    // Deduplicate: skip if an identical event already exists (acknowledged or not).
    // This prevents every poll re-firing the same alarm.
    //
    // Suppression rules for alarms (is_alarm=1):
    //   1. An identical open (unacknowledged) alarm exists — always suppress.
    //   2. An identical alarm was acknowledged within the last 30 minutes —
    //      suppress to prevent re-fire on the very next poll after ack.
    //      The 30-minute window means a real re-occurrence (device actually
    //      moved/changed again) will still create a new alarm after half an hour.
    //
    // For non-alarm events (port_up/down, mac_lost): always insert — they are
    // informational log entries and deduplication is not needed.
    if ($alarm) {
        $esc_mac      = $db->real_escape_string($mac);
        $esc_type     = $db->real_escape_string($type);
        $esc_old_port = $db->real_escape_string($old_port ?? '');
        $esc_new_port = $db->real_escape_string($new_port ?? '');
        $esc_old_vlan = $db->real_escape_string($old_vlan ?? '');
        $esc_new_vlan = $db->real_escape_string($new_vlan ?? '');

        // Check for existing open (unacknowledged) identical alarm — skip if found.
        // Also skip if an identical alarm was acknowledged within the last 30 minutes
        // (prevents re-fire on the poll immediately after an operator acks the alarm).
        // Also skip if ANY event for this MAC+port+type was created in the last 5 minutes
        // — this kills burst duplicates caused by parallel poll threads racing to insert
        // the same new_mac event before any of them has committed to the DB.
        $chk = $db->query(
            "SELECT id FROM nac_mac_events
             WHERE switch_id=$switch_id
               AND mac='$esc_mac'
               AND event_type='$esc_type'
               AND COALESCE(new_port,'')='$esc_new_port'
               AND COALESCE(new_vlan,'')='$esc_new_vlan'
               AND (
                     acknowledged=0
                     OR (acknowledged=1 AND ack_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                     OR created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                   )
             LIMIT 1"
        );
        $exists = ($chk && $chk->num_rows > 0);
        $chk && $chk->free();
        if ($exists) return; // Already have this open alarm or it was just acked — don't duplicate
    }

    $s = $db->prepare(
        "INSERT INTO nac_mac_events
         (switch_id,mac,event_type,old_port,new_port,old_vlan,new_vlan,details,is_alarm)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $s->bind_param('issssssis', $switch_id,$mac,$type,$old_port,$new_port,$old_vlan,$new_vlan,$details,$alarm);
    $s->execute(); $s->close();
}

function nac_rebuild_mac_ip_map(mysqli $db): void {
    // Build the map joining IPAM to NAC port data.
    // KEY RULE: when the same MAC appears on multiple switches (e.g. on an
    // access port on ArubaServerSw AND via trunk on the VC), we must pick
    // the ACCESS port entry — that is where the device physically lives.
    //
    // Strategy: use a subquery that ranks nac_port_macs rows per MAC,
    // ordering by is_trunk ASC (access=0 sorts before trunk=1), then by
    // switch_id ASC as tiebreaker. Only the top-ranked row per MAC is used.
    static $nac_map_cols_checked = false;
    if (!$nac_map_cols_checked) {
        $nac_map_cols = [
            'subnet'          => "ALTER TABLE nac_mac_ip_map ADD COLUMN subnet VARCHAR(43) DEFAULT NULL",
            'ipam_status'     => "ALTER TABLE nac_mac_ip_map ADD COLUMN ipam_status VARCHAR(50) DEFAULT NULL",
            'ipam_last_seen'  => "ALTER TABLE nac_mac_ip_map ADD COLUMN ipam_last_seen DATETIME DEFAULT NULL",
            'nac_switch_id'   => "ALTER TABLE nac_mac_ip_map ADD COLUMN nac_switch_id INT DEFAULT NULL",
            'nac_switch_name' => "ALTER TABLE nac_mac_ip_map ADD COLUMN nac_switch_name VARCHAR(255) DEFAULT NULL",
            'nac_port'        => "ALTER TABLE nac_mac_ip_map ADD COLUMN nac_port VARCHAR(100) DEFAULT NULL",
            'nac_vlan'        => "ALTER TABLE nac_mac_ip_map ADD COLUMN nac_vlan VARCHAR(100) DEFAULT NULL",
            'oui_vendor'      => "ALTER TABLE nac_mac_ip_map ADD COLUMN oui_vendor VARCHAR(100) DEFAULT NULL",
            'updated_at'      => "ALTER TABLE nac_mac_ip_map ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($nac_map_cols as $cname => $sql) {
            $col_chk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nac_mac_ip_map' AND COLUMN_NAME='$cname'");
            if ($col_chk && (int)$col_chk->fetch_row()[0] === 0) {
                $db->query($sql);
            }
            $col_chk && $col_chk->free();
        }
        $nac_map_cols_checked = true;
    }

    $db->query("TRUNCATE TABLE nac_mac_ip_map");
    $db->query(
        "INSERT INTO nac_mac_ip_map
            (mac, ip, hostname, subnet, ipam_status, ipam_last_seen,
             nac_switch_id, nac_switch_name, nac_port, nac_vlan, updated_at)
         SELECT
            UPPER(REPLACE(si.mac,'-',':')) AS mac,
            si.ip,
            si.assigned_to,
            CONCAT(
              SUBSTRING_INDEX(si.ip,'.',1),'.',
              SUBSTRING_INDEX(SUBSTRING_INDEX(si.ip,'.',2),'.',-1),'.',
              SUBSTRING_INDEX(SUBSTRING_INDEX(si.ip,'.',3),'.',-1),
              '.0/24') AS subnet,
            si.status,
            si.last_seen,
            best.switch_id,
            ns.hostname,
            best.port_name,
            best.vlan_name,
            NOW()
         FROM janus_ipam si
         -- For each MAC, pick the single best nac_port_macs row:
         -- access port (is_trunk=0) beats trunk (is_trunk=1).
         -- Within the same trunk-status, lower switch_id wins (stable tiebreak).
         LEFT JOIN (
             SELECT npm.mac,
                    npm.switch_id,
                    npm.port_name,
                    npm.vlan_name,
                    COALESCE(p.is_trunk, 0) AS is_trunk
             FROM nac_port_macs npm
             LEFT JOIN nac_ports p
                    ON p.switch_id = npm.switch_id
                   AND p.port_name = npm.port_name
         ) best ON UPPER(REPLACE(si.mac,'-',':')) = best.mac
         LEFT JOIN nac_switches ns ON best.switch_id = ns.id
         WHERE si.mac IS NOT NULL AND si.mac != '' AND si.mac != 'no MAC'
           -- When the same MAC appears on multiple switches, keep only the
           -- access-port row. This WHERE filters out any trunk-port row for
           -- a MAC that ALSO has an access-port row somewhere.
           AND best.is_trunk = (
               SELECT MIN(COALESCE(p2.is_trunk, 0))
               FROM nac_port_macs npm2
               LEFT JOIN nac_ports p2
                      ON p2.switch_id = npm2.switch_id
                     AND p2.port_name = npm2.port_name
               WHERE npm2.mac = UPPER(REPLACE(si.mac,'-',':'))
           )
         ON DUPLICATE KEY UPDATE
            ip=VALUES(ip), hostname=VALUES(hostname), subnet=VALUES(subnet),
            ipam_status=VALUES(ipam_status), ipam_last_seen=VALUES(ipam_last_seen),
            nac_switch_id=VALUES(nac_switch_id), nac_switch_name=VALUES(nac_switch_name),
            nac_port=VALUES(nac_port), nac_vlan=VALUES(nac_vlan), updated_at=NOW()"
    );
}


/* ═══════════════════════════════════════════════════════════════
   DEBUG: RAW SSH TEST
   Runs a single arbitrary command on a switch and returns the
   full raw output plus cleaned output for the debug panel.
═══════════════════════════════════════════════════════════════ */
function nac_ssh_raw_test(string $ip, int $port, string $user, string $pass,
                           string $command, string $plkey = ''): array {
    $t0 = microtime(true);
    $r  = nac_ssh_command($ip, $port, $user, $pass, $command, NAC_SSH_TIMEOUT, $plkey);
    return [
        'ok'       => $r['ok'],
        'output'   => $r['output'] ?? '',
        'raw'      => $r['raw']    ?? '',
        'code'     => $r['code']   ?? -1,
        'duration' => round(microtime(true) - $t0, 2),
        'command'  => $command,
        'ip'       => $ip,
    ];
}

/* ═══════════════════════════════════════════════════════════════
   DEBUG: FULL POLL WITH RAW CAPTURE (read-only, no DB writes)
═══════════════════════════════════════════════════════════════ */
function nac_debug_poll(mysqli $db, array $switch): array {
    $t0    = microtime(true);
    $ip    = $switch['ip'];
    $port  = $switch['ssh_port'] ?? 22;
    $user  = $switch['ssh_user'];
    $plkey = $switch['platform_key'];

    $cred = nac_get_credential($ip);
    if (!$cred) return ['ok' => false, 'error' => 'No credentials stored'];
    $pass = $cred['pass'];

    $plat = $db->query(
        "SELECT * FROM nac_platforms WHERE platform_key='" .
        $db->real_escape_string($plkey) . "' LIMIT 1"
    );
    if (!$plat || $plat->num_rows === 0) {
        return ['ok' => false, 'error' => "Unknown platform: $plkey"];
    }
    $platform = $plat->fetch_assoc(); $plat->free();

    $r = nac_ssh_multi($ip, $port, $user, $pass, [
        'mac'  => $platform['cmd_mac_table'],
        'ifc'  => $platform['cmd_interfaces'],
        'lldp' => $platform['cmd_lldp'],
    ], NAC_SSH_TIMEOUT, $plkey);

    $mac_out  = $r['outputs']['mac']  ?? '';
    $ifc_out  = $r['outputs']['ifc']  ?? '';
    $lldp_out = $r['outputs']['lldp'] ?? '';

    $parser = function_exists('driver_get_parser')
        ? driver_get_parser($plkey)
        : (str_contains($plkey,'junos') ? 'parse_junos'
            : (str_contains($plkey,'ios') ? 'parse_ios' : 'parse_procurve'));

    $parsed   = $parser($mac_out, $ifc_out, $lldp_out);
    $duration = round(microtime(true) - $t0, 2);

    return [
        'ok'          => $r['ok'],
        'duration'    => $duration,
        'platform'    => $plkey,
        'expect_code' => $r['code'] ?? -1,
        'commands'    => [
            'mac'  => $platform['cmd_mac_table'],
            'ifc'  => $platform['cmd_interfaces'],
            'lldp' => $platform['cmd_lldp'],
        ],
        'raw_outputs' => [
            'mac'  => $mac_out,
            'ifc'  => $ifc_out,
            'lldp' => $lldp_out,
        ],
        'raw_full'     => $r['raw'] ?? '',
        'parsed_macs'  => count($parsed['macs']),
        'parsed_ports' => count($parsed['ports']),
        'parsed_lldp'  => count($parsed['lldp']),
        'macs_sample'  => array_slice($parsed['macs'],  0, 20),
        'ports_sample' => array_slice($parsed['ports'], 0, 48),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   OUI LOOKUP (from bundled mini-database)
═══════════════════════════════════════════════════════════════ */
function nac_oui_lookup(string $mac): string {
    static $oui = [
        '00:0C:29' => 'VMware',       '00:50:56' => 'VMware vSphere',
        '00:1C:14' => 'VMware',       '00:05:69' => 'VMware',
        'B4:7A:F1' => 'HP',           '3C:D9:2B' => 'HP',
        '14:18:77' => 'Lenovo',       '34:B3:54' => 'Lenovo',
        'B4:A9:FE' => 'Lenovo',       '98:BE:94' => 'Lenovo',
        '84:39:8F' => 'Fortinet',     '04:D5:90' => 'Fortinet',
        '90:6C:AC' => 'Fortinet',     'BC:24:11' => 'Proxmox/Linux',
        '80:AC:AC' => 'Juniper',      '00:1F:12' => 'Juniper',
        '2C:6B:F5' => 'Juniper',      'F0:1C:2D' => 'Juniper',
        '00:1D:09' => 'Dell',         '44:A8:42' => 'Dell',
        'F8:DB:88' => 'Dell',         'E4:3D:1A' => 'Dell iDRAC',
        '00:90:FA' => 'HP-UX/Agilent','2C:EA:7F' => 'HP',
        '38:68:DD' => 'HP',           '94:40:C9' => 'Supermicro',
        'EC:EB:B8' => 'Supermicro',   '00:60:16' => 'EMC',
        '28:80:23' => 'HP',           '50:EB:1A' => 'SuperMicro',
        '00:10:18' => 'Brocade',      '00:1A:64' => 'Cisco',
        'DC:68:0C' => 'AMD/Supermicro','AE:B6:1D' => 'Random/VM',
        '64:00:6A' => 'Cisco',        '40:5B:7F' => 'Cisco',
        '48:4D:7E' => 'Cisco SFP',    'F4:8E:38' => 'Cisco',
        '6C:A8:49' => 'Yealink',      'C8:1F:66' => 'Yealink',
        'C8:1F:EA' => 'Yealink',      '40:B0:34' => 'Yealink',
        'F0:92:1C' => 'Lenovo',       'DC:DC:E2' => 'Realtek',
        '00:31:46' => 'Supermicro',   '8C:EC:4B' => 'Supermicro',
        '3C:52:A1' => 'Cisco',
        // Additional Vendors & Devices
        '00:15:5D' => 'Microsoft',    '00:08:80' => 'Ruijie',
        '00:17:61' => 'Ruijie',       '00:26:73' => 'Ruijie',
        '04:32:01' => 'Ruijie',       '04:37:01' => 'Ruijie',
        '0C:EA:14' => 'Aruba/HP',     '0C:FA:14' => 'Aruba/HP',
        '0C:LA:14' => 'Aruba/HP',     '10:02:30' => 'Intel',
        'B8:38:61' => 'Intel',        'D4:F5:EF' => 'Apple',
        'F4:D1:08' => 'Apple',        'E8:9A:8F' => 'TP-Link',
        'F8:E4:3B' => 'Ubiquiti',     'D8:50:E6' => 'ASUSTek',
    ];
    $prefix = strtoupper(substr($mac, 0, 8));
    return $oui[$prefix] ?? 'Unknown';
}

/* ═══════════════════════════════════════════════════════════════
   GLOBAL SEARCH
═══════════════════════════════════════════════════════════════ */
/* ═══════════════════════════════════════════════════════════════
   MAC TRACE: Follow topology to find the actual access port
   for a MAC that appears via trunk on a given switch.
   Returns chain: [{'switch','port','vlan','is_access'}...]
═══════════════════════════════════════════════════════════════ */
function nac_mac_trace(mysqli $db, string $mac): array {
    $mac  = strtoupper(str_replace(['-','.',' '], ':', trim($mac)));
    $esc  = $db->real_escape_string($mac);
    $chain = [];
    $visited = []; // prevent infinite loops

    // Find all switch+port combos that know this MAC
    $r = $db->query(
        "SELECT npm.switch_id, npm.port_name, npm.vlan_name,
                ns.hostname, ns.ip,
                COALESCE(p.is_trunk,0) AS is_trunk
         FROM nac_port_macs npm
         JOIN nac_switches ns ON npm.switch_id = ns.id
         LEFT JOIN nac_ports p ON p.switch_id=npm.switch_id AND p.port_name=npm.port_name
         WHERE npm.mac='$esc' OR LOWER(npm.mac)=LOWER('$esc')
         ORDER BY COALESCE(p.is_trunk,0) ASC"
    );
    $hits = [];
    if ($r) { while ($row=$r->fetch_assoc()) $hits[] = $row; $r->free(); }

    foreach ($hits as $h) {
        $chain[] = [
            'switch_id'   => $h['switch_id'],
            'switch_name' => $h['hostname'],
            'switch_ip'   => $h['ip'],
            'port'        => $h['port_name'],
            'vlan'        => $h['vlan_name'],
            'is_trunk'    => (bool)$h['is_trunk'],
            'is_access'   => !(bool)$h['is_trunk'],
        ];
    }

    // If found only on trunk ports, try to follow topology to find the access port
    $access_found = array_filter($chain, fn($c) => $c['is_access']);
    if (empty($access_found) && !empty($chain)) {
        // Walk LLDP topology: for each trunk hit, find connected switch
        foreach ($chain as $hit) {
            $key = $hit['switch_id'] . ':' . $hit['port'];
            if (isset($visited[$key])) continue;
            $visited[$key] = true;

            $sw_id = (int)$hit['switch_id'];
            $esc_port = $db->real_escape_string($hit['port']);

            // Find which switch connects on this trunk port via LLDP
            $topo = $db->query(
                "SELECT switch_b_id AS remote_id, port_b AS remote_port
                 FROM nac_topology_links
                 WHERE switch_a_id=$sw_id AND port_a='$esc_port'
                 UNION
                 SELECT switch_a_id AS remote_id, port_a AS remote_port
                 FROM nac_topology_links
                 WHERE switch_b_id=$sw_id AND port_b='$esc_port'
                 LIMIT 1"
            );
            if (!$topo || $topo->num_rows === 0) { $topo && $topo->free(); continue; }
            $link = $topo->fetch_assoc(); $topo->free();

            $remote_id = (int)$link['remote_id'];
            // Look up MAC on that remote switch
            $r2 = $db->query(
                "SELECT npm.port_name, npm.vlan_name, ns.hostname, ns.ip,
                        COALESCE(p.is_trunk,0) AS is_trunk
                 FROM nac_port_macs npm
                 JOIN nac_switches ns ON npm.switch_id=ns.id
                 LEFT JOIN nac_ports p ON p.switch_id=npm.switch_id AND p.port_name=npm.port_name
                 WHERE npm.switch_id=$remote_id AND npm.mac='$esc'
                 ORDER BY COALESCE(p.is_trunk,0) ASC LIMIT 1"
            );
            if ($r2 && $r2->num_rows > 0) {
                $row2 = $r2->fetch_assoc(); $r2->free();
                $chain[] = [
                    'switch_id'   => $remote_id,
                    'switch_name' => $row2['hostname'],
                    'switch_ip'   => $row2['ip'],
                    'port'        => $row2['port_name'],
                    'vlan'        => $row2['vlan_name'],
                    'is_trunk'    => (bool)$row2['is_trunk'],
                    'is_access'   => !(bool)$row2['is_trunk'],
                    'traced'      => true, // found by topology walk
                ];
            } else { $r2 && $r2->free(); }
        }
    }

    return $chain;
}

function nac_global_search(mysqli $db, string $query): array {
    $q  = $db->real_escape_string(trim($query));
    $results = [];

    // Search by IP or hostname — join directly to janus_ipam then find
    // the best (access-port-preferred) NAC location for that MAC.
    $r = $db->query(
        "SELECT
            UPPER(REPLACE(si.mac,'-',':')) AS mac,
            si.ip, si.assigned_to AS hostname,
            si.status AS ipam_status, si.last_seen AS ipam_last_seen,
            ns.hostname AS nac_switch_name,
            npm.port_name AS nac_port,
            npm.vlan_name AS nac_vlan,
            ns.ip AS switch_ip, ns.model,
            IFNULL(m.oui_vendor,'') AS vendor
         FROM janus_ipam si
         LEFT JOIN nac_mac_ip_map m ON UPPER(REPLACE(si.mac,'-',':')) = m.mac
         LEFT JOIN nac_port_macs npm ON UPPER(REPLACE(si.mac,'-',':')) = npm.mac
             AND COALESCE((SELECT is_trunk FROM nac_ports p
                           WHERE p.switch_id=npm.switch_id
                             AND p.port_name=npm.port_name LIMIT 1), 0) = (
                 SELECT MIN(COALESCE(p2.is_trunk,0))
                 FROM nac_port_macs npm2
                 LEFT JOIN nac_ports p2 ON p2.switch_id=npm2.switch_id
                      AND p2.port_name=npm2.port_name
                 WHERE npm2.mac = UPPER(REPLACE(si.mac,'-',':'))
             )
         LEFT JOIN nac_switches ns ON npm.switch_id = ns.id
         WHERE si.mac IS NOT NULL AND si.mac != ''
           AND (si.ip LIKE '%$q%' OR si.assigned_to LIKE '%$q%')
         LIMIT 20"
    );
    if ($r) { while ($row = $r->fetch_assoc()) $results[] = $row; $r->free(); }

    // Search by MAC
    $macQ = strtoupper(str_replace(['-','.','',' '], [':', ':', '', ''], $q));
    if (preg_match('/[0-9A-F:]{5,}/', $macQ)) {
        $r = $db->query(
            "SELECT m.mac, m.ip, m.hostname, m.subnet, m.ipam_status, m.ipam_last_seen,
                    m.nac_switch_name, m.nac_port, m.nac_vlan,
                    ns.ip AS switch_ip, ns.model,
                    IFNULL(m.oui_vendor,'') AS vendor
             FROM nac_mac_ip_map m
             LEFT JOIN nac_switches ns ON m.nac_switch_id = ns.id
             WHERE m.mac LIKE '%$macQ%'
             LIMIT 20"
        );
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if (!in_array($row, $results)) $results[] = $row;
            }
            $r->free();
        }
    }

    return $results;
}
