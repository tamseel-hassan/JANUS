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

<!-- Favicon & Metadata Main Page Icon (Direct & Dynamic Head Injection) -->
<link rel="icon" type="image/svg+xml" href="/images/Group 2.svg">
<link rel="shortcut icon" href="/images/Group 2.svg">
<script>
(function() {
    try {
        var head = document.head || document.getElementsByTagName('head')[0];
        if (head) {
            var existingIcons = head.querySelectorAll("link[rel*='icon']");
            existingIcons.forEach(function(el) { el.remove(); });
            
            var icon = document.createElement('link');
            icon.rel = 'icon';
            icon.type = 'image/svg+xml';
            icon.href = '/images/Group 2.svg?v=<?= time() ?>';
            head.appendChild(icon);
            
            var shortcut = document.createElement('link');
            shortcut.rel = 'shortcut icon';
            shortcut.href = '/images/Group 2.svg?v=<?= time() ?>';
            head.appendChild(shortcut);
        }
    } catch(e) {}
})();
</script>
<!-- Lato Font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="/css/topbar.css?v=<?= time() ?>">
<link rel="stylesheet" href="/css/theme.css?v=<?= time() ?>">

<nav class="topbar">
    <div class="topbar-left">
        <form action="/search.php" method="GET" class="search-form">
            <button type="submit" class="search-btn"><i data-lucide="search" class="icon-lucide"></i></button>
            <input type="text" name="q" placeholder="Search…" autocomplete="off">
        </form>
        <ul class="nav-menu">
            <!-- Security Operations Dropdown -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Incidents</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/soc/report_incident.php"><i data-lucide="file-plus" class="icon-lucide me-2"></i>Report Incident</a></li>
                    <li><a class="dropdown-item" href="/soc/my_tasks.php"><i data-lucide="check-square" class="icon-lucide me-2"></i>My Tasks</a></li>
                    <li><a class="dropdown-item" href="/soc/active_incidents.php"><i data-lucide="alert-triangle" class="icon-lucide me-2"></i>Active Incidents</a></li>
                    <li><a class="dropdown-item" href="/soc/incidents_history.php"><i data-lucide="history" class="icon-lucide me-2"></i>Incidents History</a></li>
                    <li><a class="dropdown-item" href="/soc/manage_tickets.php"><i data-lucide="ticket" class="icon-lucide me-2"></i>Manage Tickets</a></li>
                </ul>
            </li>
            <!-- Threat Detection Dropdown -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Threat Detection</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/modules/scanners/vuln_scan.php"><i data-lucide="shield-alert" class="icon-lucide me-2"></i>Vulnerability Scanner</a></li>
                    <li><a class="dropdown-item" href="/malware_analysis.php"><i data-lucide="bug" class="icon-lucide me-2"></i>Malware Analysis</a></li>
                    <li><a class="dropdown-item" href="/threat_intel.php"><i data-lucide="globe" class="icon-lucide me-2"></i>Check IP Reputation</a></li>
                </ul>
            </li>
            <!-- Automation Dropdown -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Remediation & Automation</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/modules/responder/index.php"><i data-lucide="book-open" class="icon-lucide me-2"></i>Playbooks</a></li>
                    <li><a class="dropdown-item" href="/modules/responder/automation.php"><i data-lucide="zap" class="icon-lucide me-2"></i>Response Automation</a></li>
                </ul>
            </li>
        </ul>
    </div>
    <div class="topbar-right">
        <div class="status-item" title="Network Uptime">
            <i data-lucide="activity" class="icon-lucide text-primary"></i>
            <span class="status-value"><?= $uptimePct ?>%</span>
        </div>
        <div class="status-item" title="Online Devices">
            <i data-lucide="arrow-up" class="icon-lucide text-success"></i>
            <span class="status-value"><?= $upDevices ?></span>
        </div>
        <div class="status-item" title="Offline Devices">
            <i data-lucide="arrow-down" class="icon-lucide text-danger"></i>
            <span class="status-value"><?= $downDevices ?></span>
        </div>
        <div class="status-item dropdown" title="Notifications">
            <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" style="color: inherit; text-decoration: none;">
                <i data-lucide="bell" class="icon-lucide <?= $notificationCount ? 'text-danger' : '' ?>"></i>
                <?php if($notificationCount): ?>
                <span class="badge"><?= $notificationCount ?></span>
                <?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if($notificationCount): ?>
                <li class="dropdown-header">New Assignments</li>
                <li><a class="dropdown-item" href="/soc/my_tasks.php"><i data-lucide="inbox" class="icon-lucide me-2"></i>View all (<?= $notificationCount ?>)</a></li>
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
                <i data-lucide="circle-user" class="icon-lucide"></i> <?= $_SESSION['name'] ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/profile.php"><i data-lucide="user" class="icon-lucide me-2"></i>Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="/logout.php"><i data-lucide="log-out" class="icon-lucide me-2 text-danger"></i>Logout</a></li>
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
    lucide.createIcons();
    
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