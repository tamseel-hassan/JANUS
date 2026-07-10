<?php
/**
 * auth_check.php — Janus Central Access Control
 * ─────────────────────────────────────────────────
 * Include this at the TOP of every protected page:
 *
 *   require_once __DIR__ . '/auth_check.php';
 *
 * ROLES
 * ──────
 *   admin    → full access to everything
 *   analyst  → SOC/NOC analysis, no operations metrics, no user management
 *   operator → operations metrics only
 */

require_once __DIR__ . '/db_config.php';

// ── 1. Session guard ──────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: /index.html');
    exit;
}

// ── 2. Disabled account guard ─────────────────────────────────────────────────
// Cache result for 5 minutes to avoid per-request queries
$_now = time();
if (!isset($_SESSION['_ac_checked']) || ($_now - $_SESSION['_ac_checked']) > 300) {
    $_ac_con = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($_ac_con) {
        $_ac_stmt = $_ac_con->prepare('SELECT is_active FROM accounts WHERE id = ? LIMIT 1');
        if ($_ac_stmt) {
            $_ac_stmt->bind_param('i', $_SESSION['id']);
            $_ac_stmt->execute();
            $_ac_stmt->bind_result($_ac_active);
            $_ac_stmt->fetch();
            $_ac_stmt->close();
            // is_active = 1 means enabled, 0 means disabled
            $_SESSION['_ac_disabled'] = ($_ac_active == 0);
        }
        mysqli_close($_ac_con);
        $_SESSION['_ac_checked'] = $_now;
    }
}
if (!empty($_SESSION['_ac_disabled'])) {
    session_destroy();
    header('Location: /index.html?reason=disabled');
    exit;
}

// ── 3. Page permission map ────────────────────────────────────────────────────
$PAGE_PERMISSIONS = [
    'home.php'              => ['admin', 'analyst'],
    'manage.php'            => ['admin', 'analyst'],
    'monitor.php'           => ['admin', 'analyst'],
    'maps.php'              => ['admin', 'analyst'],
    'resources.php'         => ['admin', 'analyst'],
    'reports.php'           => ['admin', 'analyst'],
    'profile.php'           => ['admin', 'analyst', 'operator'],
    'users.php'             => ['admin'],
    'config_backup.php'     => ['admin', 'backup_manager'],
    'search.php'            => ['admin', 'analyst', 'operator'],
    'ips_logs.php'          => ['admin', 'analyst'],
    'siem_logs.php'         => ['admin', 'analyst'],
    'logmanage.php'         => ['admin', 'analyst'],
    'threat_intel.php'      => ['admin', 'analyst'],
    'malware_analysis.php'  => ['admin', 'analyst'],
    'vuln_scan.php'         => ['admin', 'analyst'],
    'compliance.php'        => ['admin', 'analyst'],
    'trafficanalyzer.php'   => ['admin', 'analyst'],
    'config_backup.php'     => ['admin', 'analyst'],
    'snmp_monitor.php'      => ['admin', 'analyst'],
    'responder.php'         => ['admin', 'analyst'],
    'scan.php'              => ['admin', 'analyst'],
    'avalibility.php'       => ['admin', 'analyst'],
];

$DEFAULT_ALLOW_ROLES = ['admin', 'analyst'];

// ── 4. Enforce access ─────────────────────────────────────────────────────────
$_current_page  = basename($_SERVER['PHP_SELF']);
$_current_role  = $_SESSION['role'] ?? 'analyst';
$_allowed_roles = $PAGE_PERMISSIONS[$_current_page] ?? $DEFAULT_ALLOW_ROLES;

if (!in_array($_current_role, $_allowed_roles, true)) {
    $reason = urlencode("Your role '{$_current_role}' cannot access '{$_current_page}'.");
    header("Location: /access_denied.php?reason={$reason}");
    exit;
}

// ── 5. Helper function ────────────────────────────────────────────────────────
function nac_can_access(string $page): bool {
    global $PAGE_PERMISSIONS, $DEFAULT_ALLOW_ROLES;
    $role    = $_SESSION['role'] ?? 'analyst';
    $allowed = $PAGE_PERMISSIONS[$page] ?? $DEFAULT_ALLOW_ROLES;
    return in_array($role, $allowed, true);
}
