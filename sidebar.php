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
<div id="sidebar" class="sidebar glassy">
    <div class="sidebar-header">
        <button id="toggle-btn" class="toggle-btn" title="Collapse / Expand">☰</button>
        <h3 class="sidebar-title">Janus: NOC &amp; SOC</h3>
    </div>

    <ul class="sidebar-menu">

        <?php if (_sb_can('home.php')): ?>
        <li><a href="/home.php"><span>🏠</span> <span class="label">Dashboard</span></a></li>
        <?php endif; ?>

        <?php if ($is_admin || $is_analyst): ?>

        <?php if (_sb_can('manage.php')): ?>
        <li><a href="/manage.php"><span>🖧</span> <span class="label">Inventory</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('monitor.php')): ?>
        <li><a href="/monitor.php"><span>🖥️</span> <span class="label">Monitoring</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('maps.php')): ?>
        <li><a href="/maps.php"><span>🗺️</span> <span class="label">Topology Map</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('resources.php')): ?>
        <li><a href="/resources.php"><span>⚙️</span> <span class="label">Performance Metrics</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('reports.php') || _sb_can('avalibility.php')): ?>
        <li class="has-submenu">
            <a href="#" class="submenu-toggle"><span>📊</span> <span class="label">Reports &amp; Analytics</span></a>
            <ul class="submenu" style="display:none;">
                <?php if (_sb_can('reports.php')): ?>
                <li><a href="/reports.php"><span>📊</span> <span class="label">Reports &amp; Analytics</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('avalibility.php')): ?>
                <li><a href="/avalibility.php"><span>📈</span> <span class="label">Availability Reports</span></a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if (_sb_can('fflow.php') || _sb_can('ips_logs.php') || _sb_can('vuln_scan.php')): ?>
        <li class="has-submenu">
            <a href="#" class="submenu-toggle"><span>🔍</span> <span class="label">Traffic &amp; Security</span></a>
            <ul class="submenu" style="display:none;">
                <?php if (_sb_can('fflow.php')): ?>
                <li><a href="/fflow.php"><span>📡</span> <span class="label">Traffic Analyzer</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('ips_logs.php')): ?>
                <li><a href="/ips_logs.php"><span>🛡️</span> <span class="label">IPS Logs</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('vuln_scan.php')): ?>
                <li><a href="/modules/scanners/vuln_scan.php"><span>🔎</span> <span class="label">Vulnerability Scanner</span></a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if (_sb_can('nac.php') || _sb_can('ipam.php')): ?>
        <li class="has-submenu">
            <a href="#" class="submenu-toggle"><span>🌐</span> <span class="label">NOC Tools</span></a>
            <ul class="submenu" style="display:none;">
                <?php if (_sb_can('ipam.php')): ?>
                <li><a href="/modules/ipam/ipam.php"><span>🌐</span> <span class="label">IP Address Management</span></a></li>
                <?php endif; ?>
                <?php if (_sb_can('nac.php')): ?>
                <li><a href="/modules/nac/nac.php"><span>🔌</span> <span class="label">Layer 2 Monitoring</span></a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if (_sb_can('config_backup.php')): ?>
        <li><a href="/modules/config_backup/"><span>💾</span> <span class="label">Appliance Configs</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('compliance.php')): ?>
        <li><a href="/compliance.php"><span>📋</span> <span class="label">Policy &amp; Compliance</span></a></li>
        <?php endif; ?>

        <?php if (_sb_can('logmanage.php')): ?>
        <li><a href="/modules/logmanage/"><span>🗂️</span> <span class="label">Log Management</span></a></li>
        <?php endif; ?>

        <?php endif; // end admin/analyst block ?>

        <?php if (_sb_can('users.php')): ?>
        <li><a href="/users.php"><span>👥</span> <span class="label">User Management</span></a></li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-footer">
        <div class="user-info">
            <span class="user-icon">👤</span>
            <div class="label">
                <div style="font-size:.85rem;font-weight:600"><?= htmlspecialchars($current_username) ?></div>
                <div style="font-size:.72rem;opacity:.6;text-transform:capitalize"><?= htmlspecialchars($current_role) ?></div>
            </div>
        </div>
        <a href="/logout.php" class="logout-btn label" title="Logout">⏻</a>
    </div>
</div>

<style>
:root {
    --sb-bg-dark:     rgba(15,23,42,0.92);
    --sb-border-dark: rgba(255,255,255,0.07);
    --sb-text-dark:   #e2e8f0;
    --sb-hover-dark:  rgba(59,130,246,0.15);
    --sb-bg-light:    #ffffff;
    --sb-border-light:#e5e7eb;
    --sb-text-light:  #1e293b;
    --sb-hover-light: #f1f5f9;
    --sb-accent:      #3b82f6;
    --sb-accent-glow: rgba(59,130,246,0.35);
}
.sidebar {
    width:260px; position:fixed; top:0; bottom:0; left:0;
    overflow-y:auto; overflow-x:hidden;
    backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px);
    transition:width 0.3s ease; z-index:1050;
    display:flex; flex-direction:column;
}
[data-theme="dark"]  .sidebar { background:var(--sb-bg-dark);  border-right:1px solid var(--sb-border-dark);  color:var(--sb-text-dark); }
[data-theme="light"] .sidebar { background:var(--sb-bg-light); border-right:1px solid var(--sb-border-light); color:var(--sb-text-light); }
.sidebar-header { display:flex; justify-content:space-between; align-items:center; padding:16px 18px 14px; flex-shrink:0; }
[data-theme="dark"]  .sidebar-header { border-bottom:1px solid var(--sb-border-dark); }
[data-theme="light"] .sidebar-header { border-bottom:1px solid var(--sb-border-light); }
.sidebar-title { font-size:1.05rem; font-weight:700; letter-spacing:.5px; background:linear-gradient(135deg,#3b82f6,#06b6d4); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; margin:0; white-space:nowrap; }
.toggle-btn { background:transparent; border:none; font-size:20px; cursor:pointer; transition:transform 0.3s,color 0.2s; padding:2px 4px; color:inherit; flex-shrink:0; }
.toggle-btn:hover { color:var(--sb-accent); transform:rotate(90deg); }
.sidebar-menu { margin:6px 0; padding:0; list-style:none; flex:1; }
.sidebar-menu li { margin:2px 8px; }
.sidebar-menu a { text-decoration:none; padding:10px 14px; display:flex; align-items:center; gap:12px; border-left:3px solid transparent; border-radius:8px; transition:all 0.2s; font-size:14px; white-space:nowrap; }
[data-theme="dark"]  .sidebar-menu a { color:var(--sb-text-dark); }
[data-theme="light"] .sidebar-menu a { color:var(--sb-text-light); }
[data-theme="dark"]  .sidebar-menu a:hover { background:var(--sb-hover-dark);  border-left-color:var(--sb-accent); color:#fff; }
[data-theme="light"] .sidebar-menu a:hover { background:var(--sb-hover-light); border-left-color:var(--sb-accent); color:#1e293b; }
[data-theme="dark"]  .sidebar-menu a.active { background:rgba(59,130,246,.22); border-left-color:var(--sb-accent); color:#fff; box-shadow:0 0 14px var(--sb-accent-glow); }
[data-theme="light"] .sidebar-menu a.active { background:#dbeafe; border-left-color:var(--sb-accent); color:#1e3a8a; }
.submenu { list-style:none; padding:2px 0 4px; margin:0; }
[data-theme="dark"]  .submenu { background:rgba(0,0,0,.25); border-radius:0 0 8px 8px; }
[data-theme="light"] .submenu { background:rgba(0,0,0,.03); border-radius:0 0 8px 8px; }
.submenu li { margin:1px 0; }
.submenu li a { padding-left:44px; font-size:13px; border-radius:6px; }
.has-submenu > a.submenu-toggle { cursor:pointer; }
.has-submenu .submenu-toggle::after { content:'▾'; margin-left:auto; transition:transform 0.25s; font-size:.75rem; opacity:.6; }
.has-submenu.open .submenu-toggle::after { transform:rotate(180deg); }
.sidebar-footer { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; flex-shrink:0; gap:8px; }
[data-theme="dark"]  .sidebar-footer { border-top:1px solid var(--sb-border-dark); }
[data-theme="light"] .sidebar-footer { border-top:1px solid var(--sb-border-light); }
.user-info { display:flex; align-items:center; gap:8px; overflow:hidden; min-width:0; }
.user-icon { font-size:1.3rem; flex-shrink:0; }
.logout-btn { text-decoration:none; font-size:1.2rem; opacity:.55; transition:opacity .2s,transform .2s; flex-shrink:0; color:inherit; }
.logout-btn:hover { opacity:1; transform:scale(1.15); color:#ef4444; }
.sidebar.collapsed { width:64px; }
.sidebar.collapsed .label,
.sidebar.collapsed .sidebar-title { display:none; }
.sidebar.collapsed .sidebar-menu a { justify-content:center; padding:10px; }
.sidebar.collapsed .submenu { display:none !important; }
.sidebar.collapsed .submenu-toggle::after { display:none; }
.sidebar.collapsed .sidebar-footer { justify-content:center; }
.sidebar.collapsed .logout-btn { display:none; }
.sidebar::-webkit-scrollbar { width:4px; }
.sidebar::-webkit-scrollbar-thumb { background:rgba(59,130,246,.3); border-radius:4px; }
</style>

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
