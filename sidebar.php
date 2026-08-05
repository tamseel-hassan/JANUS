<?php
/**
 * sidebar.php – Janus SOC/NOC Sidebar
 *
 * Role visibility:
 *   admin    → sees everything
 *   analyst  → sees all except User Management
 *
 * Uses nac_can_access() from auth_check.php when available,
 * falls back to role-based checks if included standalone.
 */

$current_username = $_SESSION['name'] ?? '';
$current_role     = $_SESSION['role'] ?? 'analyst';

$is_admin    = ($current_role === 'admin');
$is_analyst  = ($current_role === 'analyst');

// Helper: check page access — uses auth_check helper if loaded, else role check
function _sb_can(string $page): bool {
    if (function_exists('nac_can_access')) return nac_can_access($page);
    // fallback
    $role = $_SESSION['role'] ?? 'analyst';
    $map  = [
        'home.php'=>['admin','analyst'], 'manage.php'=>['admin','analyst'],
        'monitor.php'=>['admin','analyst'], 'maps.php'=>['admin','analyst'],
        'resources.php'=>['admin','analyst'], 'reports.php'=>['admin','analyst'],
        'avalibility.php'=>['admin','analyst'],
        'fflow.php'=>['admin','analyst'], 'ips_logs.php'=>['admin','analyst'],
        'vuln_scan.php'=>['admin','analyst'],
        'nac.php'=>['admin','analyst'], 'ipam.php'=>['admin','analyst'],
        'config_backup.php'=>['admin','analyst','backup_manager'], 'compliance.php'=>['admin','analyst'],
        'logmanage.php'=>['admin','analyst'],
        'users.php'=>['admin'],
    ];
    return in_array($role, $map[$page] ?? ['admin','analyst'], true);
}
?>
<link rel="stylesheet" href="/css/sidebar.css">

<div id="sidebar" class="sidebar glassy">
    <div class="sidebar-header">
        <a href="/home.php" class="sidebar-title">
            <img src="/images/Group 2.svg" class="logo-img" alt="Janus">
        </a>
        <button id="toggle-btn" class="toggle-btn" title="Collapse / Expand">
            <i data-lucide="menu" class="icon-lucide"></i>
        </button>
    </div>

    <ul class="sidebar-menu">

        <?php if (_sb_can('home.php')): ?>
        <li><a href="/home.php"><i data-lucide="layout-dashboard" class="icon-lucide"></i> <span class="label">Dashboard</span></a></li>
        <?php endif; ?>

        <?php if ($is_admin || $is_analyst): ?>

        <?php if (_sb_can('manage.php')): ?>
        <li><a href="/manage.php"><i data-lucide="router" class="icon-lucide"></i> <span class="label">Inventory</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('monitor.php')): ?>
        <li><a href="/monitor.php"><i data-lucide="monitor" class="icon-lucide"></i> <span class="label">Monitoring</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('maps.php')): ?>
        <li><a href="/maps.php"><i data-lucide="map-pin" class="icon-lucide"></i> <span class="label">Topology Map</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('resources.php')): ?>
        <li><a href="/resources.php"><i data-lucide="bar-chart-2" class="icon-lucide"></i> <span class="label">Performance Metrics</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('reports.php') || _sb_can('avalibility.php')): ?>
        <li class="has-submenu">
            <a href="#" class="submenu-toggle"><i data-lucide="file-bar-chart-2" class="icon-lucide"></i> <span class="label">Reports &amp; Analytics</span></a>
            <ul class="submenu" style="display:none;">
                <?php if (_sb_can('reports.php')): ?>
                <li><a href="/reports.php"><i data-lucide="file-bar-chart-2" class="icon-lucide"></i> <span class="label">Reports &amp; Analytics</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('avalibility.php')): ?>
                <li><a href="/avalibility.php"><i data-lucide="line-chart" class="icon-lucide"></i> <span class="label">Availability Reports</span></a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if (_sb_can('fflow.php') || _sb_can('ips_logs.php') || _sb_can('vuln_scan.php')): ?>
        <li class="has-submenu">
            <a href="#" class="submenu-toggle"><i data-lucide="shield-check" class="icon-lucide"></i> <span class="label">Traffic &amp; Security</span></a>
            <ul class="submenu" style="display:none;">
                <?php if (_sb_can('fflow.php')): ?>
                <li><a href="/fflow.php"><i data-lucide="radio-tower" class="icon-lucide"></i> <span class="label">Traffic Analyzer</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('ips_logs.php')): ?>
                <li><a href="/ips_logs.php"><i data-lucide="bug" class="icon-lucide"></i> <span class="label">IPS Logs</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('vuln_scan.php')): ?>
                <li><a href="/modules/scanners/vuln_scan.php"><i data-lucide="bug" class="icon-lucide"></i> <span class="label">Vulnerability Scanner</span></a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if (_sb_can('nac.php') || _sb_can('ipam.php') || _sb_can('printers.php')): ?>
        <li class="has-submenu">
            <a href="#" class="submenu-toggle"><i data-lucide="wrench" class="icon-lucide"></i> <span class="label">NOC Tools</span></a>
            <ul class="submenu" style="display:none;">
                <?php if (_sb_can('ipam.php')): ?>
                <li><a href="/modules/ipam/ipam.php"><i data-lucide="globe" class="icon-lucide"></i> <span class="label">IP Address Management</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('nac.php')): ?>
                <li><a href="/modules/nac/nac.php"><i data-lucide="plug" class="icon-lucide"></i> <span class="label">Layer 2 Monitoring</span></a></li>
                <?php endif; ?>
                <li><a href="/modules/printers/printers.php"><i data-lucide="printer" class="icon-lucide"></i> <span class="label">Printer Fleet</span></a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if (_sb_can('config_backup.php')): ?>
        <li><a href="/modules/config_backup/"><i data-lucide="save" class="icon-lucide"></i> <span class="label">Appliance Configs</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('compliance.php')): ?>
        <li><a href="/compliance.php"><i data-lucide="check-square" class="icon-lucide"></i> <span class="label">Policy &amp; Compliance</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('logmanage.php')): ?>
        <li><a href="/modules/logmanage/"><i data-lucide="file-text" class="icon-lucide"></i> <span class="label">Log Management</span></a></li>
        <?php endif; ?>

        <?php endif; // end admin/analyst block ?>

        <?php if (_sb_can('users.php')): ?>
        <li><a href="/users.php"><i data-lucide="user-cog" class="icon-lucide"></i> <span class="label">User Management</span></a></li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-footer">
        <div class="user-info">
            <i data-lucide="user" class="icon-lucide user-icon"></i>
            <div class="label">
                <div style="font-size:.85rem;font-weight:600"><?= htmlspecialchars($current_username) ?></div>
                <div style="font-size:.72rem;opacity:.6;text-transform:capitalize"><?= htmlspecialchars($current_role) ?></div>
            </div>
        </div>
        <a href="/logout.php" class="logout-btn label" title="Logout">⏻</a>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const sidebar     = document.getElementById("sidebar");
    const mainContent = document.getElementById("main-content");

    const applyWidth = (collapsed) => {
        const w = collapsed ? '64px' : '260px';
        if (mainContent) {
            mainContent.style.marginLeft = w;
            mainContent.style.transition = 'margin-left 0.3s ease';
        }
        const tb = document.querySelector('.topbar');
        if (tb) { tb.style.left = w; tb.style.transition = 'left 0.3s ease'; }
    };

    document.querySelectorAll(".toggle-btn").forEach(btn =>
        btn.addEventListener("click", () => {
            sidebar.classList.toggle("collapsed");
            const c = sidebar.classList.contains("collapsed");
            localStorage.setItem("sidebarCollapsed", c);
            applyWidth(c);
        })
    );

    const collapsed = localStorage.getItem("sidebarCollapsed") === "true";
    if (collapsed) sidebar.classList.add("collapsed");
    applyWidth(collapsed);

    document.querySelectorAll('.submenu-toggle').forEach(toggle => {
        toggle.addEventListener('click', e => {
            e.preventDefault();
            const li = toggle.closest('.has-submenu');
            document.querySelectorAll('.has-submenu.open').forEach(x => {
                if (x !== li) { x.classList.remove('open'); x.querySelector('.submenu').style.display='none'; }
            });
            li.classList.toggle('open');
            li.querySelector('.submenu').style.display = li.classList.contains('open') ? 'block' : 'none';
        });
    });

    // Highlight active page — also auto-open parent submenu
    document.querySelectorAll(".sidebar-menu a").forEach(link => {
        const href = link.getAttribute("href");
        if (!href) return;
        const path = location.pathname;
        if (href === path || href === path.replace(/^\//, '') ||
            path.endsWith(href.replace(/^\//, ''))) {
            link.classList.add("active");
            const sub = link.closest('.submenu');
            if (sub) {
                const parent = sub.closest('.has-submenu');
                if (parent) { parent.classList.add('open'); sub.style.display='block'; }
            }
        }
    });
});
</script>
