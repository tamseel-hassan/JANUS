<?php
// layout/topbar.php - Pure HTML version (CSS is now in topbar.css)
$totalDevices = db_fetch("SELECT COUNT(*) AS c FROM devices")['c'] ?? 0;
$upDevices = db_fetch("SELECT COUNT(*) AS c FROM devices d LEFT JOIN (SELECT device_id, status, ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn FROM ping_logs WHERE link_id IS NULL) pl ON d.id = pl.device_id AND pl.rn = 1 WHERE COALESCE(pl.status,'down')='up'")['c'] ?? 0;
$downDevices = $totalDevices - $upDevices;
$uptimePct = $totalDevices ? round(($upDevices / $totalDevices) * 100, 1) : 0;
$notificationCount = db_fetch("SELECT COUNT(*) AS c FROM incidents WHERE assigned_to = ? AND unread_by_assignee = 1 AND status != 'closed'", [$_SESSION['id']])['c'] ?? 0;
?>

<nav class="topbar">
    <div class="topbar-left">
        <button class="toggle-btn" id="sidebarToggle">
            <i data-lucide="menu" class="icon-lucide"></i>
        </button>
        <a href="/home.php">
            <img src="/icon.jpg" class="logo-img" alt="Janus">
        </a>
        <form action="/search.php" method="GET" class="search-form">
            <button type="submit"><i data-lucide="search" class="icon-lucide"></i></button>
            <input type="text" name="q" placeholder="Search…" autocomplete="off">
        </form>
        <ul class="nav-menu">
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Incidents</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/report_incident.php">Report Incident</a></li>
                    <li><a class="dropdown-item" href="/my_tasks.php">My Tasks <?= $notificationCount ? "<span class='badge bg-danger ms-2'>$notificationCount</span>" : '' ?></a></li>
                    <li><a class="dropdown-item" href="/active_incidents.php">Active Incidents</a></li>
                    <li><a class="dropdown-item" href="/incidents_history.php">Incidents History</a></li>
                    <li><a class="dropdown-item" href="/manage_tickets.php">Manage Tickets</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Threat Detection</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/vuln_scan.php">Vulnerability Scanner</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Remediation & Automation</a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="/home.php#">Playbooks</a></li>
                    <li><a class="dropdown-item" href="/home.php#">Response Automation</a></li>
                </ul>
            </li>
        </ul>
    </div>
    <div class="topbar-right">
        <div class="status-item" title="Network Uptime">
            <i data-lucide="activity" class="icon-lucide text-primary"></i>
            <span class="status-value"><?= $uptimePct ?>%</span>
        </div>
        <div class="status-item">
            <i data-lucide="arrow-up" class="icon-lucide text-success"></i>
            <span class="status-value"><?= $upDevices ?></span>
        </div>
        <div class="status-item">
            <i data-lucide="arrow-down" class="icon-lucide text-danger"></i>
            <span class="status-value"><?= $downDevices ?></span>
        </div>
        <div class="status-item dropdown">
            <a href="#" data-bs-toggle="dropdown">
                <i data-lucide="bell" class="icon-lucide <?= $notificationCount ? 'text-danger' : '' ?>"></i>
                <?php if($notificationCount): ?><span class="badge"><?= $notificationCount ?></span><?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/my_tasks.php">View all (<?= $notificationCount ?>)</a></li>
            </ul>
        </div>
        <label class="theme-toggle">
            <input type="checkbox" id="themeSwitch" <?= $theme === 'light' ? 'checked' : '' ?>>
            <span class="slider"></span>
        </label>
        <div class="status-item dropdown">
            <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">
                <i data-lucide="circle-user" class="icon-lucide"></i> <?= htmlspecialchars($_SESSION['name']) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/profile.php">Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
