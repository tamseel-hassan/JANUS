<?php
require_once __DIR__ . '/db_config.php';
// reports.php - SNOC Security Operations Dashboard
// VISUAL REDESIGN: Tactical HUD console theme (backend logic unchanged)
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    exit('DB Error: ' . mysqli_connect_error());
}

date_default_timezone_set('Asia/Karachi');

$report_type = $_GET['type'] ?? 'traffic';
$time_range = $_GET['range'] ?? '24h';
$device_filter = $_GET['device'] ?? '';

// Timezone-safe calculations computed by MySQL to align queries with DB server time
$res = mysqli_query($con, "
    SELECT 
        NOW() as end_time,
        DATE_SUB(NOW(), INTERVAL 15 MINUTE) as start_15m,
        DATE_SUB(NOW(), INTERVAL 1 HOUR) as start_1h,
        DATE_SUB(NOW(), INTERVAL 6 HOUR) as start_6h,
        DATE_SUB(NOW(), INTERVAL 24 HOUR) as start_24h,
        DATE_SUB(NOW(), INTERVAL 7 DAY) as start_7d,
        DATE_SUB(NOW(), INTERVAL 30 DAY) as start_30d
");
$row = mysqli_fetch_assoc($res);
$end_time = $row['end_time'];

switch (strtolower($time_range)) {
    case '15m': $start_time = $row['start_15m']; break;
    case '1h':  $start_time = $row['start_1h']; break;
    case '6h':  $start_time = $row['start_6h']; break;
    case '24h': $start_time = $row['start_24h']; break;
    case '7d':  $start_time = $row['start_7d']; break;
    case '30d': $start_time = $row['start_30d']; break;
    default:    $start_time = $row['start_24h'];
}

$devices = [];
$res = mysqli_query($con, "SELECT DISTINCT source_ip FROM syslog_entries ORDER BY source_ip");
while ($r = mysqli_fetch_assoc($res)) {
    $devices[] = $r['source_ip'];
}

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SNOC :: Security Analytics Console</title>
<link href="css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/font-awesome/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* =========================================================
   SNOC TACTICAL CONSOLE — design tokens
   A radar/HUD-inspired console for analysts staring at this
   screen for eight hours a night: dark, high-contrast, dense,
   with amber/cyan signal colors and instrument-panel framing
   instead of generic SaaS gradients.
   ========================================================= */
:root {
    --void:        #090c11;
    --panel:       #10151d;
    --panel-raised:#141b25;
    --inset:       #0a0e14;

    --line:        #1d2733;
    --line-active: #2c3948;

    --amber:       #ffb020;
    --amber-dim:   #8a5f16;
    --cyan:        #29d3ee;
    --cyan-dim:    #146575;
    --red:         #ff4d5e;
    --red-dim:     #7a1f28;
    --green:       #2be8a4;
    --green-dim:   #146346;
    --violet:      #9d8cff;

    --text-hi:     #eef3f7;
    --text-mid:    #93a1b0;
    --text-lo:     #56626f;

    --font-mono: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
    --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

    --radius: 3px;
}

[data-theme="light"] {
    --void:        #eef1f5;
    --panel:       #ffffff;
    --panel-raised:#f6f8fa;
    --inset:       #edf0f4;
    --line:        #d7dde4;
    --line-active: #b9c2cd;
    --text-hi:     #10151d;
    --text-mid:    #4b5768;
    --text-lo:     #8894a3;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body.loggedin {
    background:
        radial-gradient(ellipse 900px 500px at 15% -10%, rgba(41,211,238,0.06), transparent 60%),
        radial-gradient(ellipse 900px 500px at 100% 0%, rgba(255,176,32,0.05), transparent 60%),
        var(--void);
    color: var(--text-hi);
    font-family: var(--font-sans);
    line-height: 1.6;
}

.container-fluid { padding-top: 78px; }

#main-content {
    margin-left: 260px;
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    padding: 24px;
    min-height: 100vh;
}
.sidebar.collapsed ~ #main-content { margin-left: 78px; }

/* ---------- HUD PANEL: shared corner-bracket frame ---------- */
.hud-panel {
    position: relative;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
}
.hud-panel::before, .hud-panel::after,
.hud-panel .hb-tl, .hud-panel .hb-br { content: none; }
.hud-panel::before, .hud-panel::after {
    content: '';
    position: absolute;
    width: 9px; height: 9px;
    pointer-events: none;
}
.hud-panel::before {
    top: -1px; left: -1px;
    border-top: 2px solid var(--cyan);
    border-left: 2px solid var(--cyan);
}
.hud-panel::after {
    bottom: -1px; right: -1px;
    border-bottom: 2px solid var(--cyan);
    border-right: 2px solid var(--cyan);
}

/* ---------- EYEBROW / PAGE HEADER ---------- */
.page-header { margin-bottom: 28px; }

.eyebrow {
    font-family: var(--font-mono);
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.25em;
    color: var(--cyan);
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.eyebrow .led {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 6px 1px var(--green);
    animation: pulse-led 1.8s ease-in-out infinite;
}
@keyframes pulse-led {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.35; }
}

.page-title {
    font-family: var(--font-mono);
    font-size: 1.9rem;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--text-hi);
    margin-bottom: 6px;
}
.page-title .accent { color: var(--amber); }

.page-subtitle {
    color: var(--text-mid);
    font-size: 0.9rem;
    font-family: var(--font-sans);
}

/* ---------- BUTTONS ---------- */
.btn {
    padding: 9px 18px;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 0.82rem;
    font-family: var(--font-mono);
    letter-spacing: 0.03em;
    text-transform: uppercase;
    transition: all 0.15s;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-primary {
    background: var(--amber);
    color: #1a1204;
    border-color: var(--amber);
}
.btn-primary:hover { background: #ffc659; transform: translateY(-1px); color: #1a1204; }

.btn-success { background: var(--green); color: #04231a; border-color: var(--green); }
.btn-success:hover { color: #04231a; filter: brightness(1.1); }

.btn-outline-primary {
    background: transparent;
    border: 1px solid var(--line-active);
    color: var(--text-mid);
}
.btn-outline-primary:hover { border-color: var(--cyan); color: var(--cyan); background: rgba(41,211,238,0.06); }

.btn-danger { background: var(--red); color: #2b0a0e; border-color: var(--red); }
.btn-danger:hover { filter: brightness(1.1); color: #2b0a0e; }
.btn-outline-warning { background: transparent; border: 1px solid var(--amber-dim); color: var(--amber); }
.btn-outline-warning:hover { background: rgba(255,176,32,0.08); }
.btn-warning { background: var(--amber); color: #1a1204; }
.btn-sm { padding: 6px 12px; font-size: 0.72rem; }

/* ---------- FILTERS PANEL ---------- */
.filters-panel {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 20px 22px;
    margin-bottom: 22px;
}

.form-label {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-lo);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.form-label i { color: var(--cyan); }

.form-select, .form-control {
    background: var(--inset);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    color: var(--text-hi);
    padding: 9px 12px;
    font-size: 0.85rem;
    font-family: var(--font-mono);
    transition: all 0.15s;
}
.form-select:focus, .form-control:focus {
    outline: none;
    border-color: var(--cyan);
    box-shadow: 0 0 0 3px rgba(41,211,238,0.12);
    background: var(--panel-raised);
}

.period-readout {
    padding: 9px 14px;
    background: var(--inset);
    border-radius: var(--radius);
    border: 1px solid var(--line);
    font-family: var(--font-mono);
    font-size: 0.85rem;
    color: var(--text-hi);
}
.period-readout .arrow { color: var(--amber); margin: 0 10px; }

/* ---------- TABS ---------- */
.report-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 26px; }

.tab-category {
    width: 100%;
    font-family: var(--font-mono);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.18em;
    color: var(--text-lo);
    margin: 18px 0 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.tab-category i { color: var(--amber); font-size: 0.75rem; }
.tab-category::after { content: ''; flex: 1; height: 1px; background: var(--line); }

.report-tab {
    padding: 9px 16px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-left: 2px solid var(--line);
    border-radius: 2px;
    cursor: pointer;
    transition: all 0.15s;
    color: var(--text-mid);
    text-decoration: none;
    font-family: var(--font-mono);
    font-weight: 500;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.report-tab:hover {
    border-left-color: var(--cyan);
    color: var(--text-hi);
    background: var(--panel-raised);
}
.report-tab.active {
    background: var(--panel-raised);
    color: var(--amber);
    border-left-color: var(--amber);
    border-color: var(--line-active);
}
.report-tab i { font-size: 0.85rem; }

/* ---------- STAT BOXES ---------- */
.stat-box {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 20px 22px;
    transition: all 0.2s;
    position: relative;
}
.stat-box::before, .stat-box::after {
    content: '';
    position: absolute;
    width: 9px; height: 9px;
    pointer-events: none;
}
.stat-box::before { top: -1px; left: -1px; border-top: 2px solid var(--cyan); border-left: 2px solid var(--cyan); }
.stat-box::after { bottom: -1px; right: -1px; border-bottom: 2px solid var(--cyan); border-right: 2px solid var(--cyan); }
.stat-box:hover { border-color: var(--line-active); transform: translateY(-2px); }

.stat-icon { font-size: 1.5rem; margin-bottom: 12px; opacity: 0.85; }
.text-danger .stat-icon, .stat-icon.text-danger { color: var(--red) !important; }
.text-warning .stat-icon, .stat-icon.text-warning { color: var(--amber) !important; }
.text-primary .stat-icon, .stat-icon.text-primary { color: var(--cyan) !important; }
.text-info .stat-icon, .stat-icon.text-info { color: var(--cyan) !important; }
.text-success .stat-icon, .stat-icon.text-success { color: var(--green) !important; }

.stat-value {
    font-family: var(--font-mono);
    font-size: 1.85rem;
    font-weight: 700;
    color: var(--text-hi);
    margin-bottom: 4px;
    letter-spacing: -0.01em;
}
.stat-label {
    font-family: var(--font-mono);
    font-size: 0.7rem;
    color: var(--text-lo);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 600;
}

/* ---------- STAT CHIP (FortiSIEM-style colored label pill) ---------- */
.stat-chip {
    display: inline-block;
    padding: 5px 13px;
    border-radius: 3px;
    font-family: var(--font-mono);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-top: 14px;
}
.chip-danger  { background: var(--red);   color: #2b0a0e; }
.chip-warning { background: var(--amber); color: #1a1204; }
.chip-info    { background: var(--cyan);  color: #04232a; }
.chip-success { background: var(--green); color: #04231a; }
.chip-neutral { background: var(--line-active); color: var(--text-hi); }

/* ---------- SEVERITY PILL ---------- */
.sev-pill {
    display: inline-block;
    padding: 5px 14px;
    border-radius: 14px;
    font-family: var(--font-mono);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    white-space: nowrap;
}
.sev-critical { background: var(--red); color: #2b0a0e; }
.sev-high     { background: #ff8a3d; color: #2a1103; }
.sev-medium   { background: var(--amber); color: #1a1204; }
.sev-low      { background: var(--green); color: #04231a; }

/* ---------- RISK SCORE BADGE ---------- */
.score-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px; height: 26px;
    border-radius: 50%;
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 0.7rem;
    margin-right: 8px;
    flex-shrink: 0;
}
.score-red   { background: var(--red);   color: #2b0a0e; }
.score-amber { background: var(--amber); color: #1a1204; }
.score-green { background: var(--green); color: #04231a; }

/* ---------- DASHBOARD SWITCHER STRIP ---------- */
.dash-strip {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 14px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 0;
}
.dash-tab {
    font-family: var(--font-mono);
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-mid);
    padding: 10px 4px;
    margin-right: 22px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: all 0.15s;
}
.dash-tab:hover { color: var(--text-hi); }
.dash-tab.active { color: var(--text-hi); border-bottom-color: var(--amber); }
.dash-tab .fa-chevron-down { font-size: 0.65rem; margin-left: 6px; color: var(--text-lo); }
.dash-add {
    margin-left: auto;
    width: 28px; height: 28px;
    border-radius: 4px;
    border: 1px dashed var(--line-active);
    background: none;
    color: var(--text-lo);
    cursor: pointer;
    transition: all 0.15s;
}
.dash-add:hover { border-color: var(--cyan); color: var(--cyan); }

/* ---------- TIME RANGE PILLS ---------- */
.range-strip {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.range-pill-group {
    display: inline-flex;
    border: 1px solid var(--line);
    border-radius: var(--radius);
    overflow: hidden;
}
.range-pill {
    font-family: var(--font-mono);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-mid);
    background: var(--inset);
    border: none;
    border-right: 1px solid var(--line);
    padding: 8px 14px;
    cursor: pointer;
    transition: all 0.15s;
}
.range-pill:last-child { border-right: none; }
.range-pill:hover { background: var(--panel-raised); color: var(--text-hi); }
.range-pill.active { background: var(--cyan); color: #04232a; }
.refresh-btn {
    width: 34px; height: 34px;
    border-radius: var(--radius);
    border: 1px solid var(--line);
    background: var(--inset);
    color: var(--text-mid);
    cursor: pointer;
    transition: all 0.2s;
}
.refresh-btn:hover { color: var(--cyan); border-color: var(--cyan); }
.refresh-btn.spinning i { animation: spin 0.6s linear infinite; }

/* ---------- CATEGORY TABS (dashboard groups) ---------- */
.category-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.category-tab {
    font-family: var(--font-mono);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-mid);
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 9px 15px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.15s;
}
.category-tab i { color: var(--text-lo); font-size: 0.8rem; }
.category-tab:hover { border-color: var(--line-active); color: var(--text-hi); }
.category-tab.active { background: var(--panel-raised); border-color: var(--amber); color: var(--amber); }
.category-tab.active i { color: var(--amber); }

/* ---------- REPORT CHIPS (widgets within a category) ---------- */
.report-chips {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    padding: 12px 14px;
    background: var(--inset);
    border: 1px solid var(--line);
    border-radius: var(--radius);
}
.report-chip {
    font-family: var(--font-mono);
    font-size: 0.76rem;
    font-weight: 500;
    color: var(--text-mid);
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 7px 15px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.15s;
}
.report-chip:hover { color: var(--text-hi); border-color: var(--line-active); }
.report-chip.active { background: var(--cyan); color: #04232a; border-color: var(--cyan); font-weight: 700; }

/* ---------- REPORT CARDS ---------- */
.report-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 22px;
    margin-bottom: 22px;
    position: relative;
}
.report-card::before, .report-card::after {
    content: '';
    position: absolute;
    width: 9px; height: 9px;
    pointer-events: none;
}
.report-card::before { top: -1px; left: -1px; border-top: 2px solid var(--line-active); border-left: 2px solid var(--line-active); }
.report-card::after { bottom: -1px; right: -1px; border-bottom: 2px solid var(--line-active); border-right: 2px solid var(--line-active); }

.report-card h5 {
    font-family: var(--font-mono);
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 18px;
    color: var(--text-hi);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--line);
}
.report-card h5 i { color: var(--amber); }

/* ---------- TABLES ---------- */
.table { color: var(--text-hi); font-size: 0.85rem; font-family: var(--font-sans); }
.table thead th {
    background: var(--inset);
    border-bottom: 1px solid var(--line-active);
    color: var(--text-lo);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.68rem;
    letter-spacing: 0.08em;
    font-family: var(--font-mono);
    padding: 10px 12px;
}
.table tbody tr { border-bottom: 1px solid var(--line); transition: all 0.15s; }
.table tbody tr:hover { background: var(--panel-raised); }
.table tbody td { padding: 11px 12px; vertical-align: middle; }
.table code {
    font-family: var(--font-mono);
    color: #a9c4d4;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.07);
    padding: 2px 6px;
    border-radius: 2px;
    font-size: 0.82rem;
}

/* ---------- BADGES ---------- */
.badge {
    padding: 4px 10px;
    border-radius: 2px;
    font-weight: 700;
    font-size: 0.68rem;
    font-family: var(--font-mono);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-left: 2px solid transparent;
}
.bg-success { background: rgba(43,232,164,0.12) !important; color: var(--green) !important; border-left-color: var(--green); }
.bg-danger  { background: rgba(255,77,94,0.12) !important; color: var(--red) !important; border-left-color: var(--red); }
.bg-warning { background: rgba(255,176,32,0.12) !important; color: var(--amber) !important; border-left-color: var(--amber); }
.bg-info    { background: rgba(41,211,238,0.12) !important; color: var(--cyan) !important; border-left-color: var(--cyan); }
.bg-primary { background: rgba(41,211,238,0.12) !important; color: var(--cyan) !important; border-left-color: var(--cyan); }
.bg-secondary { background: rgba(147,161,176,0.12) !important; color: var(--text-mid) !important; border-left-color: var(--text-lo); }

/* ---------- MODAL ---------- */
.modal-content {
    background: var(--panel);
    border: 1px solid var(--line-active);
    border-radius: 6px;
}
.modal-header {
    border-bottom: 1px solid var(--line);
    padding: 20px 24px;
    background: var(--panel-raised);
    border-radius: 6px 6px 0 0;
}
.modal-title {
    color: var(--text-hi);
    font-weight: 700;
    font-size: 1.05rem;
    font-family: var(--font-mono);
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-title i { color: var(--amber); }
.modal-body { padding: 22px 24px; max-height: 70vh; overflow-y: auto; }
.modal-footer { border-top: 1px solid var(--line); padding: 16px 24px; }
.btn-close { filter: invert(1) grayscale(1) brightness(1.6); opacity: 0.6; }
.btn-close:hover { opacity: 1; }

/* ---------- LOADING SPINNER ---------- */
.loading-spinner {
    width: 42px; height: 42px;
    border: 2px solid var(--line);
    border-top-color: var(--cyan);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ---------- ALERTS ---------- */
.alert { border-radius: var(--radius); padding: 14px 18px; border: 1px solid; border-left-width: 3px; font-size: 0.85rem; font-family: var(--font-sans); }
.alert-success { background: rgba(43,232,164,0.06); border-color: var(--green-dim); border-left-color: var(--green); color: var(--green); }
.alert-danger  { background: rgba(255,77,94,0.06); border-color: var(--red-dim); border-left-color: var(--red); color: var(--red); }
.alert-warning { background: rgba(255,176,32,0.06); border-color: var(--amber-dim); border-left-color: var(--amber); color: var(--amber); }
.alert-info    { background: rgba(41,211,238,0.06); border-color: var(--cyan-dim); border-left-color: var(--cyan); color: var(--cyan); }

/* ---------- CLICKABLE IPs ---------- */
.clickable-ip {
    color: var(--cyan);
    font-family: var(--font-mono);
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.clickable-ip:hover { color: var(--amber); text-decoration: none; }
.clickable-ip i { font-size: 0.75em; opacity: 0.7; }

/* ---------- CHART CONTAINER ---------- */
.chart-container { position: relative; height: 280px; width: 100%; }
.chart-container canvas { max-height: 280px !important; }

/* ---------- SCROLLBAR ---------- */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--inset); }
::-webkit-scrollbar-thumb { background: var(--line-active); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--cyan); }

/* ---------- RESPONSIVE ---------- */
@media (max-width: 768px) {
    #main-content { margin-left: 0; padding: 16px; }
    .page-title { font-size: 1.4rem; }
    .report-tabs { flex-direction: column; }
}

/* ---------- ENTRY ANIMATION ---------- */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
.report-card, .stat-box { animation: fadeIn 0.35s ease-out; }

@media (prefers-reduced-motion: reduce) {
    .report-card, .stat-box, .eyebrow .led { animation: none; }
}
</style>
</head>
<body class="loggedin">

<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">

    <!-- HEADER -->
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="eyebrow"><span class="led"></span> SNOC / SECURITY OPERATIONS &mdash; LIVE FEED</div>
                <h1 class="page-title"><span class="accent">&gt;</span> Security Analytics Console</h1>
                <p class="page-subtitle">Network intelligence, threat detection and compliance telemetry across all monitored assets</p>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-success" onclick="exportReport()">
                    <i class="fas fa-file-export"></i> Export
                </button>
                <button class="btn btn-outline-primary" onclick="scheduledReports()">
                    <i class="fas fa-clock"></i> Schedule
                </button>
                <button class="btn btn-primary" onclick="refreshReport()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- DASHBOARD SWITCHER (saved-view style strip, single live dashboard today) -->
    <div class="dash-strip">
        <button class="dash-tab active" type="button">SNOC Security Dashboard <i class="fas fa-chevron-down"></i></button>
        <button class="dash-add" type="button" title="Custom dashboards are on the roadmap" onclick="alert('Custom saved dashboards aren\'t wired up yet — this build always shows the live SNOC view. Ping your admin if you want multi-dashboard support added.')">
            <i class="fas fa-plus"></i>
        </button>
    </div>

    <!-- TIME RANGE + DEVICE FILTER -->
    <div class="filters-panel">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label"><i class="far fa-clock"></i> Time Range</label>
                <div class="range-strip">
                    <div class="range-pill-group" id="rangePillGroup">
                        <button type="button" class="range-pill" data-range="15m">15M</button>
                        <button type="button" class="range-pill" data-range="1h">1H</button>
                        <button type="button" class="range-pill" data-range="6h">6H</button>
                        <button type="button" class="range-pill" data-range="24h">1D</button>
                        <button type="button" class="range-pill" data-range="7d">7D</button>
                        <button type="button" class="range-pill" data-range="30d">30D</button>
                    </div>
                    <button type="button" class="refresh-btn" id="refreshBtn" onclick="refreshReport()" title="Refresh now">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <span class="period-readout" style="font-size:0.78rem;">
                        <?= date('M d, H:i', strtotime($start_time)) ?> <span class="arrow">&rarr;</span> <?= date('M d, H:i', strtotime($end_time)) ?>
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="fas fa-server"></i> Device Filter</label>
                <select name="device" id="deviceFilter" class="form-select" onchange="applyFilters()">
                    <option value="">All Devices</option>
                    <?php foreach($devices as $dev): ?>
                        <option value="<?= htmlspecialchars($dev) ?>" <?= $device_filter==$dev?'selected':'' ?>>
                            <?= htmlspecialchars($dev) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <span style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-lo); text-transform:uppercase; letter-spacing:0.08em;">
                    <i class="fas fa-circle" style="font-size:6px; color:var(--green); margin-right:5px;"></i>Manual Refresh
                </span>
            </div>
        </div>
    </div>

    <!-- CATEGORY TABS (dashboard groups) -->
    <div class="category-tabs" id="categoryTabs"></div>

    <!-- REPORT CHIPS (widgets within active category) -->
    <div class="report-chips" id="reportChips"></div>

    <!-- CONTENT -->
    <div id="reportContent">
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted" style="font-family: var(--font-mono); font-size:0.8rem; letter-spacing:0.05em;">LOADING ANALYTICS&hellip;</p>
        </div>
    </div>

</div>
</div>

<!-- DRILL-DOWN MODAL -->
<div class="modal fade" id="drillDownModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="drillDownModalLabel"><i class="fas fa-search-plus"></i> Detailed Analysis</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="drillDownContent">
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Loading details...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
        <button type="button" class="btn btn-primary" onclick="exportDrillDown()"><i class="fas fa-download"></i> Export</button>
      </div>
    </div>
  </div>
</div>

<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/chart.js"></script>
<script>
// GLOBAL VARIABLES
let currentType = '<?= $report_type ?>';
let currentRange = '<?= $time_range ?>';
let currentDevice = '<?= $device_filter ?>';
const startTime = '<?= $start_time ?>';
const endTime = '<?= $end_time ?>';

// Chart.js global HUD defaults
if (window.Chart) {
    Chart.defaults.color = '#8592a3';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
    Chart.defaults.font.family = "'JetBrains Mono', monospace";
    Chart.defaults.font.size = 11;
}

// CRITICAL: Chart instances registry to prevent duplicates
const chartInstances = {};

function destroyChart(chartId) {
    if (chartInstances[chartId]) {
        chartInstances[chartId].destroy();
        delete chartInstances[chartId];
    }
}

function createChart(canvasId, config) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (canvas) {
        chartInstances[canvasId] = new Chart(canvas, config);
    }
}

// Count-up animation for stat readouts
function animateStatValues(container) {
    (container || document).querySelectorAll('.stat-value[data-raw]').forEach(el => {
        const raw = parseFloat(el.getAttribute('data-raw'));
        if (isNaN(raw)) return;
        const suffix = el.getAttribute('data-suffix') || '';
        const duration = 600;
        const start = performance.now();
        function step(now) {
            const p = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(raw * eased).toLocaleString() + suffix;
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}

// -----------------------------------------------------------------
// REPORT CATALOG — drives the two-tier nav (category tabs -> chips)
// -----------------------------------------------------------------
const REPORT_CATALOG = {
    network: { label: 'Network Analysis', icon: 'fa-network-wired', reports: [
        { type: 'traffic', label: 'Traffic Analysis', icon: 'fa-exchange-alt' },
        { type: 'applications', label: 'Applications', icon: 'fa-layer-group' },
        { type: 'bandwidth', label: 'Bandwidth', icon: 'fa-chart-area' },
    ]},
    security: { label: 'Security & Threats', icon: 'fa-shield-virus', reports: [
        { type: 'security_analysis', label: 'MITRE ATT&CK', icon: 'fa-shield-alt' },
        { type: 'threat_hunting', label: 'Threat Hunting', icon: 'fa-crosshairs' },
        { type: 'bruteforce', label: 'Brute Force', icon: 'fa-user-lock' },
        { type: 'anomaly', label: 'Anomaly Detection', icon: 'fa-exclamation-triangle' },
    ]},
    access: { label: 'Access & Authentication', icon: 'fa-user-shield', reports: [
        { type: 'remote_access', label: 'Remote Access', icon: 'fa-door-open' },
        { type: 'user_activity', label: 'User Activity', icon: 'fa-users' },
    ]},
    attacks: { label: 'Attack Detection', icon: 'fa-bomb', reports: [
        { type: 'dns_attacks', label: 'DNS Attacks', icon: 'fa-server' },
        { type: 'ddos', label: 'DDoS', icon: 'fa-radiation' },
        { type: 'malware', label: 'Malware', icon: 'fa-bug' },
    ]},
    config: { label: 'Configuration & Compliance', icon: 'fa-cog', reports: [
        { type: 'config_changes', label: 'Config Changes', icon: 'fa-wrench' },
        { type: 'compliance', label: 'Compliance', icon: 'fa-clipboard-check' },
    ]},
    assets: { label: 'Asset & Inventory', icon: 'fa-sitemap', reports: [
        { type: 'asset_discovery', label: 'Asset Discovery', icon: 'fa-radar' },
        { type: 'vulnerability', label: 'Vulnerabilities', icon: 'fa-shield-alt' },
        { type: 'endpoint_activity', label: 'Endpoint Activity', icon: 'fa-desktop' },
    ]},
};

function categoryOf(type) {
    for (const key in REPORT_CATALOG) {
        if (REPORT_CATALOG[key].reports.some(r => r.type === type)) return key;
    }
    return 'network';
}

let currentCategory = categoryOf(currentType);

function renderCategoryTabs() {
    const el = document.getElementById('categoryTabs');
    el.innerHTML = Object.keys(REPORT_CATALOG).map(key => {
        const cat = REPORT_CATALOG[key];
        return `<button type="button" class="category-tab ${key === currentCategory ? 'active' : ''}" data-cat="${key}">
                    <i class="fas ${cat.icon}"></i> ${cat.label}
                </button>`;
    }).join('');
    el.querySelectorAll('.category-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            currentCategory = btn.getAttribute('data-cat');
            renderCategoryTabs();
            const firstReport = REPORT_CATALOG[currentCategory].reports[0];
            selectReport(firstReport.type);
        });
    });
}

function renderReportChips() {
    const el = document.getElementById('reportChips');
    el.innerHTML = REPORT_CATALOG[currentCategory].reports.map(r => {
        return `<button type="button" class="report-chip ${r.type === currentType ? 'active' : ''}" data-type="${r.type}">
                    <i class="fas ${r.icon}"></i> ${r.label}
                </button>`;
    }).join('');
    el.querySelectorAll('.report-chip').forEach(btn => {
        btn.addEventListener('click', () => selectReport(btn.getAttribute('data-type')));
    });
}

function selectReport(type) {
    currentType = type;
    currentCategory = categoryOf(type);
    renderCategoryTabs();
    renderReportChips();
    loadReportContent(currentType);
    const url = new URL(window.location);
    url.searchParams.set('type', currentType);
    window.history.pushState({}, '', url);
}

function renderRangePills() {
    document.querySelectorAll('.range-pill').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-range') === currentRange);
        btn.addEventListener('click', () => {
            currentRange = btn.getAttribute('data-range');
            applyFilters();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Auto-refresh every 5 minutes
    setInterval(() => {
        const btn = document.getElementById('refreshBtn');
        if (btn) btn.classList.add('spinning');
        loadReportContent(currentType);
        setTimeout(() => { if (btn) btn.classList.remove('spinning'); }, 800);
    }, 5 * 60 * 1000);
    renderCategoryTabs();
    renderReportChips();
    renderRangePills();
    loadReportContent(currentType);
    // Auto-refresh the active report every 5 minutes (matches the agent check-in cadence)
    setInterval(() => {
        const btn = document.getElementById('refreshBtn');
        if (btn) btn.classList.add('spinning');
        loadReportContent(currentType);
        setTimeout(() => { if (btn) btn.classList.remove('spinning'); }, 800);
    }, 5 * 60 * 1000);
});

function applyFilters() {
    const device = document.getElementById('deviceFilter').value;
    const url = new URL(window.location);
    url.searchParams.set('type', currentType);
    url.searchParams.set('range', currentRange);
    url.searchParams.set('device', device);
    window.location.href = url.toString();
}

async function loadReportContent(type, extraParams = '') {
    const contentDiv = document.getElementById('reportContent');
    contentDiv.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted" style="font-family: var(--font-mono); font-size:0.8rem; letter-spacing:0.05em;">LOADING ${type.replace(/_/g, ' ').toUpperCase()}&hellip;</p>
        </div>
    `;

    Object.keys(chartInstances).forEach(chartId => destroyChart(chartId));

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            range: currentRange
        });

        if (extraParams) {
            const extra = new URLSearchParams(extraParams);
            extra.forEach((value, key) => params.append(key, value));
        }

        const url = `ss/${type}.php?${params.toString()}`;
        console.log('Loading:', url);

        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`Server error: ${response.status}`);
        }

        const html = await response.text();
        contentDiv.innerHTML = html;
        animateStatValues(contentDiv);

        const scripts = contentDiv.querySelectorAll('script');
        scripts.forEach(script => {
            const newScript = document.createElement('script');
            newScript.textContent = script.textContent;
            document.head.appendChild(newScript);
            document.head.removeChild(newScript);
        });

    } catch (error) {
        console.error('Load error:', error);
        contentDiv.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Error loading report:</strong> ${error.message}
            </div>
        `;
    }
}

async function drillDownIP(ip) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('drillDownModal'));
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i class="fas fa-network-wired"></i> IP Analysis: ${ip}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Analyzing ${ip}...</p>
        </div>
    `;

    modal.show();
    Object.keys(chartInstances).filter(id => id.startsWith('modal-')).forEach(destroyChart);

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            drilldown_ip: ip
        });

        const response = await fetch(`ss/ip_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);

        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });

    } catch (error) {
        console.error('Drill-down error:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Error loading IP details: ${error.message}
            </div>
        `;
    }
}

async function drillDownTechnique(techniqueId, techniqueName) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('drillDownModal'));
    const modalTitle = document.getElementById('drillDownModalLabel');
    const modalBody = document.getElementById('drillDownContent');

    modalTitle.innerHTML = `<i class="fas fa-shield-virus"></i> ${techniqueId}: ${techniqueName}`;
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="loading-spinner mx-auto"></div>
            <p class="mt-3 text-muted">Loading technique details...</p>
        </div>
    `;

    modal.show();
    Object.keys(chartInstances).filter(id => id.startsWith('modal-')).forEach(destroyChart);

    try {
        const params = new URLSearchParams({
            start: startTime,
            end: endTime,
            device: currentDevice,
            technique: techniqueId
        });

        const response = await fetch(`ss/mitre_drilldown.php?${params.toString()}`);
        if (!response.ok) throw new Error(`Failed: ${response.status}`);

        const html = await response.text();
        modalBody.innerHTML = html;

        const scripts = modalBody.querySelectorAll('script');
        scripts.forEach(script => {
            try { eval(script.textContent); } catch (e) { console.error('Script error:', e); }
        });

    } catch (error) {
        console.error('Drill-down error:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Error: ${error.message}
            </div>
        `;
    }
}

function refreshReport() { loadReportContent(currentType); }
function exportReport() { window.print(); }

function exportDrillDown() {
    const content = document.getElementById('drillDownContent');
    const title = document.getElementById('drillDownModalLabel').textContent;
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>' + title + '</title>');
    printWindow.document.write('<style>body{font-family:Arial;padding:20px;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ddd;padding:8px;}</style>');
    printWindow.document.write('</head><body><h2>' + title + '</h2>');
    printWindow.document.write(content.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

function scheduledReports() {
    alert('Scheduled Reports:\n\nConfigure automated report generation and delivery.\n\nContact your administrator to enable this feature.');
}

document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey) {
        if (e.key === 'r') { e.preventDefault(); refreshReport(); }
        else if (e.key === 'p') { e.preventDefault(); exportReport(); }
    }
});

window.drillDownIP = drillDownIP;
window.drillDownTechnique = drillDownTechnique;
window.loadReportContent = loadReportContent;
window.createChart = createChart;
window.destroyChart = destroyChart;
window.animateStatValues = animateStatValues;

// Safety net: force-clean any stuck backdrop / body scroll-lock after this
// shared modal closes, no matter which function opened it.
document.getElementById('drillDownModal').addEventListener('hidden.bs.modal', () => {
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
});
</script>
</body>
</html>
