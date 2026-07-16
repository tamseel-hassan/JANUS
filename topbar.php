<?php
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
require_once __DIR__ . '/db_config.php';
$_tb_con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$_tb_con) die('DB Error');

// === Original Network Stats ===
$totalDevices = $upDevices = $downDevices = 0;
$totalDevices = mysqli_fetch_assoc(mysqli_query($_tb_con, "SELECT COUNT(*) AS c FROM devices"))['c'] ?? 0;
$upDevices = mysqli_fetch_assoc(mysqli_query(
    $_tb_con,
    "SELECT COUNT(*) AS c
    FROM devices d
    LEFT JOIN (
        SELECT device_id, status,
        ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn
        FROM ping_logs
        WHERE link_id IS NULL
    ) pl ON d.id = pl.device_id AND pl.rn = 1
    WHERE COALESCE(pl.status,'down')='up'"
))['c'] ?? 0;
$downDevices = $totalDevices - $upDevices;
$uptimePct = $totalDevices ? round(($upDevices / $totalDevices) * 100, 1) : 0;

// === SOC/NOC Notifications: Count unread assigned tasks ===
$my_id = $_SESSION['id'];
$notificationCount = mysqli_fetch_assoc(mysqli_query(
    $_tb_con,
    "SELECT COUNT(*) AS c
     FROM incidents
     WHERE assigned_to = $my_id AND unread_by_assignee = 1 AND status != 'closed'"
))['c'] ?? 0;
mysqli_close($_tb_con);

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<style>
/* ---------------------------------------------------------
THEME VARIABLES
--------------------------------------------------------- */
:root {
    --bg:#1b1b2f;
    --card-bg:#222236;
    --text:#fff;
    --text-muted:#aaa;
    --accent:#00c6ff;
    --border:rgba(0,198,255,.15);
    --shadow:rgba(0,0,0,.55);
    --menu-color:#00c6ff;
    --menu-hover:#28a745;
    --topbar-glass:rgba(25,30,40,.7);
    --metric-total: #60a5fa;
    --metric-up: #16a34a;
    --metric-down: #ef4444;
}
[data-theme=light] {
    --bg:#f8fafc;
    --card-bg:#ffffff;
    --text:#2d3748;
    --text-muted:#718096;
    --accent:#3182ce;
    --border:#dbe0e6;
    --shadow:rgba(0,0,0,.08);
    --menu-color:#2b6cb0;
    --menu-hover:#1a8d47;
    --topbar-glass:rgba(255,255,255,.65);
    --metric-total: #2563eb;
    --metric-up: #16a34a;
    --metric-down: #dc2626;
}
/* ---------------------------------------------------------
TOPBAR CONTAINER
--------------------------------------------------------- */
.topbar {
    position: fixed;
    top: 0; left: 260px; right: 0;
    height: 58px;
    transition: left 0.3s ease;
    background: var(--topbar-glass);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1rem;
    z-index: 2000;
    font-family: 'Segoe UI', sans-serif;
    box-shadow: 0 4px 14px var(--shadow);
}
.topbar-left, .topbar-right {
    display: flex;
    align-items: center;
    gap: 1rem;
}
/* Align nav-menu perfectly vertically */
.nav-menu {
    list-style: none;
    display: flex;
    gap: 1rem;
    padding-left: 0;
    margin: 0;
    align-items: center;
}
.nav-menu li {
    display: flex;
    align-items: center;
}
.nav-menu a {
    color: var(--text);
    text-decoration: none;
    font-weight: 500;
    transition: 0.25s;
    line-height: 1;
}
.nav-menu a:hover,
.nav-menu a.active {
    color: var(--accent);
}
/* sidebar toggle handled by sidebar.php */
/* ---------------------------------------------------------
LOGO
--------------------------------------------------------- */
.logo-img {
    height: 34px;
    width: auto;
    border-radius: 6px;
}
/* ---------------------------------------------------------
SEARCH BAR
--------------------------------------------------------- */
.search-form {
    position: relative;
}
.search-form input {
    padding: .4rem .8rem .4rem 2.2rem;
    font-size: .9rem;
    border-radius: 8px;
    width: 220px;
    transition: 0.25s;
    background: rgba(0,0,0,.3);
    border: 1px solid rgba(255,255,255,.15);
    color: var(--text);
}
[data-theme=light] .search-form input {
    background: #f0f4f9;
    border: 1px solid #c9d2de;
}
.search-form input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 8px var(--accent);
}
.search-form button {
    background: none;
    border: none;
    color: var(--text-muted);
    position: absolute;
    left: 9px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
}
/* ---------------------------------------------------------
STATUS METRICS
--------------------------------------------------------- */
.status-item {
    display: flex;
    align-items: center;
    gap: .35rem;
    font-size: .9rem;
    position: relative;
    color: var(--text);
}
.status-value {
    font-weight: 600;
    color: var(--text);
}
.badge {
    position: absolute;
    top: -6px;
    right: -6px;
    font-size: .65rem;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #dc3545;
    color: #fff;
}
/* ---------------------------------------------------------
THEME TOGGLE SWITCH
--------------------------------------------------------- */
.theme-toggle {
    position: relative;
    display: inline-block;
    width: 46px;
    height: 24px;
}
.theme-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: #444;
    transition: .4s;
    border-radius: 34px;
}
.slider:before {
    content: "\f186";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    height: 18px; width: 18px;
    left: 3px; bottom: 3px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffd700;
    font-size: 12px;
    transition: .4s;
}
input:checked + .slider {
    background: var(--accent);
}
input:checked + .slider:before {
    content: "\f185";
    color: #ff7300;
    transform: translateX(20px);
}
/* ---------------------------------------------------------
DROPDOWNS
--------------------------------------------------------- */
.dropdown-menu {
    background: var(--card-bg);
    border: 1px solid var(--border);
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 1000;
    min-width: 160px;
    padding: 0.5rem 0;
    margin: 0;
    font-size: 0.9rem;
    border-radius: 0.375rem;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
.dropdown-menu .dropdown-item {
    color: var(--text);
    padding: 0.375rem 1rem;
    display: block;
    text-decoration: none;
    transition: 0.25s;
}
.dropdown-menu .dropdown-item:hover {
    background: rgba(0,198,255,.15);
    color: var(--accent);
}
/* LIGHT MODE */
[data-theme=light] .dropdown-menu {
    background: #fff;
}
[data-theme=light] .dropdown-item:hover {
    background: #eff3f8;
}
/* =========================================================
NEW CSS BLOCK TO FIX COLORS
========================================================= */
.topbar .text-primary {
    color: var(--metric-total) !important;
}
.topbar .text-success {
    color: var(--metric-up) !important;
}
.topbar .text-danger {
    color: var(--metric-down) !important;
}
/* ---------------------------------------------------------
RESPONSIVE
--------------------------------------------------------- */
@media (max-width: 992px) {
    .search-form input { width: 170px; }
}
@media (max-width: 768px) {
    .nav-menu { display: none; }
}
</style>
</head>
<body>
<nav class="topbar">
    <div class="topbar-left">
        <a href="/home.php">
            <img src="/images/<?= $theme === 'light' ? 'Group 3.svg' : 'Group 2.svg' ?>" class="logo-img" alt="Janus">
        </a>
        <form action="/search.php" method="GET" class="search-form">
            <button type="submit"><i class="fas fa-search"></i></button>
            <input type="text" name="q" placeholder="Search…" autocomplete="off">
        </form>
        <ul class="nav-menu">
            <!-- Security Operations Dropdown -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Incidents</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/soc/report_incident.php">Report Incident</a></li>
                    <li><a class="dropdown-item" href="/soc/my_tasks.php">My Tasks</a></li>
                    <li><a class="dropdown-item" href="/soc/active_incidents.php">Active Incidents</a></li>
                    <li><a class="dropdown-item" href="/soc/incidents_history.php">Incidents History</a></li>
                    <li><a class="dropdown-item" href="/soc/manage_tickets.php">Manage Tickets</a></li>
	                </ul>
            </li>
            <!-- Threat Detection Dropdown -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Threat Detection</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/modules/scanners/vuln_scan.php">Vulnerability scanner</a></li>
                    <li><a class="dropdown-item" href="/malware_analysis.php">Malware Analysis</a></li>
                    <li><a class="dropdown-item" href="/home.php#">Check Ip Reputation</a></li>
                </ul>
            </li>
            <!-- Automation Dropdown -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Remediation & Automation</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/modules/responder/index.php">Playbooks</a></li>
                    <li><a class="dropdown-item" href="/modules/responder/automation.php">Response Automation</a></li>
                </ul>
            </li>
        </ul>
    </div>
    <div class="topbar-right">
        <div class="status-item" title="Network Uptime">
            <i class="fas fa-heartbeat text-primary"></i>
            <span class="status-value"><?= $uptimePct ?>%</span>
        </div>
        <div class="status-item">
            <i class="fas fa-arrow-up text-success"></i>
            <span class="status-value"><?= $upDevices ?></span>
        </div>
        <div class="status-item">
            <i class="fas fa-arrow-down text-danger"></i>
            <span class="status-value"><?= $downDevices ?></span>
        </div>
        <div class="status-item dropdown">
            <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-bell <?= $notificationCount ? 'text-danger' : '' ?>"></i>
                <?php if($notificationCount): ?>
                <span class="badge"><?= $notificationCount ?></span>
                <?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if($notificationCount): ?>
                <li class="dropdown-header">New Assignments</li>
                <li><a class="dropdown-item" href="/soc/my_tasks.php">View all (<?= $notificationCount ?>)</a></li>
                <?php else: ?>
                <li class="dropdown-item text-muted">No new assignments</li>
                <?php endif; ?>
            </ul>
        </div>
        <label class="theme-toggle">
            <input type="checkbox" id="themeSwitch" <?= $theme==='light'?'checked':'' ?>>
            <span class="slider"></span>
        </label>
        <div class="status-item dropdown">
            <a href="#" class="dropdown-toggle user-menu" data-bs-toggle="dropdown">
                <i class="fas fa-user-circle"></i> <?= $_SESSION['name'] ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/profile.php">Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<script>
// Theme toggle
const themeSwitch = document.getElementById('themeSwitch');
if (themeSwitch) {
    themeSwitch.addEventListener('change', () => {
        const newTheme = themeSwitch.checked ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        document.cookie = "theme=" + newTheme + "; path=/; max-age=" + 60*60*24*30;
        
        // Dynamically change logo
        const logo = document.querySelector('.logo-img');
        if (logo) {
            logo.src = '/images/' + (newTheme === 'light' ? 'Group 3.svg' : 'Group 2.svg');
        }
    });
}

// Sync topbar left position with sidebar width
document.addEventListener('DOMContentLoaded', () => {
    const topbar  = document.querySelector('.topbar');
    const sidebar = document.getElementById('sidebar');
    if (!topbar || !sidebar) return;

    const syncTopbar = () => {
        const w = sidebar.classList.contains('collapsed') ? '64px' : '260px';
        topbar.style.left = w;
        topbar.style.transition = 'left 0.3s ease';
    };

    // Apply on load from localStorage
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        topbar.style.left = '64px';
    }

    // Watch sidebar for class changes
    new MutationObserver(syncTopbar).observe(sidebar, {
        attributes: true, attributeFilter: ['class']
    });
});
</script>
</body>
</html>