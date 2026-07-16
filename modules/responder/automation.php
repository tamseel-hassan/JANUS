<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

$theme = $_COOKIE['theme'] ?? 'dark';
$installSuccess = isset($_GET['installed']);

// Check if tables exist
$tablesExist = $con->query("SHOW TABLES LIKE 'responder_playbooks'")->num_rows > 0;
$needsInstall = !$tablesExist && !isset($_GET['install']);

// Stats — only query if tables exist
$totalPlaybooks = 0; $enabledPlaybooks = 0; $totalExecutions = 0;
$failedExecutions = 0; $totalBlocked = 0; $activeBlocked = 0;
$recentExecs = null;

if ($tablesExist) {
    $totalPlaybooks = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_playbooks"))['c'] ?? 0);
    $enabledPlaybooks = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_playbooks WHERE enabled = 1"))['c'] ?? 0);
    $totalExecutions = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_executions"))['c'] ?? 0);
    $failedExecutions = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_executions WHERE status = 'failed'"))['c'] ?? 0);
    $totalBlocked = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_blocked_ips"))['c'] ?? 0);
    $activeBlocked = intval(mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_blocked_ips WHERE expires_at IS NULL OR expires_at > NOW()"))['c'] ?? 0);
    $recentExecs = $con->query("SELECT pe.*, p.name as pb_name FROM responder_executions pe LEFT JOIN responder_playbooks p ON pe.playbook_id = p.id ORDER BY pe.created_at DESC LIMIT 10");
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Janus :: Response Automation</title>
<link href="../../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="../../css/theme.css">
<style>
:root {
    --void:#090c11; --panel:#10151d; --panel-raised:#141b25; --inset:#0a0e14;
    --line:#1d2733; --line-active:#2c3948; --amber:#ffb020; --cyan:#29d3ee;
    --red:#ff4d5e; --green:#2be8a4; --violet:#9d8cff;
    --text-hi:#eef3f7; --text-mid:#93a1b0; --text-lo:#56626f;
    --radius:3px;
}
[data-theme="light"] {
    --void:#eef1f5; --panel:#fff; --panel-raised:#f6f8fa; --inset:#edf0f4;
    --line:#d7dde4; --line-active:#b9c2cd; --text-hi:#10151d; --text-mid:#4b5768; --text-lo:#8894a3;
}
body.loggedin { background:var(--void); color:var(--text-hi); font-family:'Segoe UI',sans-serif; }
#main-content { margin-left:250px; padding:80px 25px 25px; min-height:100vh; transition:margin-left 0.3s; }
.sidebar.collapsed ~ #main-content { margin-left:80px; }
.page-title { font-size:1.6rem; font-weight:700; }
.page-title .accent { color:var(--amber); }
.eyebrow { font-size:0.72rem; font-weight:600; letter-spacing:0.25em; color:var(--cyan); text-transform:uppercase; margin-bottom:10px; }
.stat-box { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); padding:20px 22px; transition:all 0.2s; height:100%; position:relative; }
.stat-box:hover { border-color:var(--line-active); transform:translateY(-2px); }
.stat-icon { font-size:1.5rem; margin-bottom:12px; }
.stat-value { font-size:1.85rem; font-weight:700; color:var(--text-hi); margin-bottom:4px; }
.stat-label { font-size:0.7rem; color:var(--text-lo); text-transform:uppercase; letter-spacing:0.1em; font-weight:600; }
.table { color:var(--text-mid); font-size:0.85rem; }
.table thead { color:var(--text-lo); text-transform:uppercase; font-size:0.7rem; letter-spacing:0.1em; }
.table td, .table th { border-color:var(--line); }
.stat-chip { display:inline-block; padding:5px 13px; border-radius:3px; font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; margin-top:14px; }
.chip-info { background:var(--cyan); color:#04232a; }
.chip-success { background:var(--green); color:#04231a; }
.chip-danger { background:var(--red); color:#2b0a0e; }
.chip-warning { background:var(--amber); color:#1a1204; }
.chip-violet { background:var(--violet); color:#14103a; }
.chip-neutral { background:var(--line-active); color:var(--text-hi); }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.stat-box { animation:fadeIn 0.35s ease-out; }
@media(max-width:768px) { #main-content { margin-left:0; padding:80px 16px 16px; } }
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">

<div class="page-header">
    <div class="eyebrow"><i class="fas fa-bolt"></i> JANUS / RESPONDER</div>
    <h1 class="page-title"><span class="accent">&gt;</span> Response Automation</h1>
    <p style="color:var(--text-mid);font-size:0.9rem;">Orchestration and automated response dashboard</p>
</div>

<?php if ($needsInstall): ?>
<div style="background:var(--panel);border:1px solid var(--amber);border-radius:var(--radius);padding:24px;margin-bottom:20px;text-align:center;">
    <i class="fas fa-database" style="font-size:2rem;color:var(--amber);margin-bottom:12px;"></i>
    <h4 style="color:var(--text-hi);">Database Tables Not Found</h4>
    <p style="color:var(--text-mid);">The responder module needs database tables. Click the button below to set them up.</p>
    <a href="install.php" class="btn-ghost mt-2" style="border-color:var(--amber);color:var(--amber);"><i class="fas fa-database"></i> Install Database Tables</a>
</div>
<?php elseif ($installSuccess): ?>
<div style="background:var(--panel);border:1px solid var(--green);border-radius:var(--radius);padding:16px;margin-bottom:20px;text-align:center;">
    <i class="fas fa-check-circle" style="color:var(--green);margin-right:8px;"></i>
    <span style="color:var(--text-hi);">Database tables installed successfully!</span>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon" style="color:var(--cyan);"><i class="fas fa-book"></i></div>
            <div class="stat-value"><?= $totalPlaybooks ?></div>
            <span class="stat-chip chip-info">Total Playbooks</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon" style="color:var(--green);"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value"><?= $enabledPlaybooks ?></div>
            <span class="stat-chip chip-success">Enabled</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon" style="color:var(--amber);"><i class="fas fa-history"></i></div>
            <div class="stat-value"><?= $totalExecutions ?></div>
            <span class="stat-chip chip-warning">Executions</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon" style="color:var(--red);"><i class="fas fa-ban"></i></div>
            <div class="stat-value"><?= $failedExecutions ?></div>
            <span class="stat-chip chip-danger">Failed</span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon" style="color:var(--violet);"><i class="fas fa-ban"></i></div>
            <div class="stat-value"><?= $activeBlocked ?></div>
            <span class="stat-chip chip-violet">Active Blocks</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon" style="color:var(--text-mid);"><i class="fas fa-list"></i></div>
            <div class="stat-value"><?= $totalBlocked ?></div>
            <span class="stat-chip chip-neutral">Total Blocks</span>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div style="background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;">
            <h5 style="font-size:0.9rem;font-weight:700;color:var(--text-hi);margin-bottom:16px;text-transform:uppercase;letter-spacing:0.04em;">
                <i class="fas fa-bolt" style="color:var(--amber);"></i> Quick Actions
            </h5>
            <div class="d-grid gap-2">
                <a href="index.php" class="btn btn-outline-light btn-sm text-start" style="border-color:var(--line);color:var(--text-hi);">
                    <i class="fas fa-book" style="color:var(--cyan);width:24px;"></i> Manage Playbooks
                </a>
                <a href="edit.php" class="btn btn-outline-light btn-sm text-start" style="border-color:var(--line);color:var(--text-hi);">
                    <i class="fas fa-plus-circle" style="color:var(--green);width:24px;"></i> Create New Playbook
                </a>
                <a href="blocked.php" class="btn btn-outline-light btn-sm text-start" style="border-color:var(--line);color:var(--text-hi);">
                    <i class="fas fa-ban" style="color:var(--red);width:24px;"></i> View Blocked IPs
                </a>
                <a href="?install" class="btn btn-outline-light btn-sm text-start" style="border-color:var(--line);color:var(--text-hi);">
                    <i class="fas fa-database" style="color:var(--amber);width:24px;"></i> Setup Database Tables
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div style="background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;">
            <h5 style="font-size:0.9rem;font-weight:700;color:var(--text-hi);margin-bottom:16px;text-transform:uppercase;letter-spacing:0.04em;">
                <i class="fas fa-history" style="color:var(--amber);"></i> Recent Executions
            </h5>
            <?php if ($recentExecs->num_rows === 0): ?>
            <p style="color:var(--text-lo);font-size:0.85rem;">No executions yet</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Playbook</th><th>Status</th><th>Trigger</th><th>Time</th></tr></thead>
                <tbody>
                <?php while ($ex = $recentExecs->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($ex['pb_name'] ?? 'Unknown') ?></td>
                    <td><span style="color:<?= match($ex['status']) { 'completed'=>'var(--green)', 'failed'=>'var(--red)', 'running'=>'var(--cyan)', default=>'var(--text-lo)' } ?>"><?= $ex['status'] ?></span></td>
                    <td><?= $ex['triggered_by'] ?></td>
                    <td><?= date('M d H:i', strtotime($ex['created_at'])) ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>
</div>

<script src="../../js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('sidebar'), c = document.getElementById('main-content');
    function adj() { c.style.marginLeft = s && s.classList.contains('collapsed') ? '80px' : '250px'; }
    adj(); if (s) new MutationObserver(adj).observe(s, { attributes: true, attributeFilter: ['class'] });
});
</script>
</body>
</html>
<?php mysqli_close($con); ?>
