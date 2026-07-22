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
<link rel="stylesheet" href="../../css/theme.css?v=<?= time() ?>">
<link rel="stylesheet" href="../../css/pages/responder.css?v=<?= time() ?>">
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
        <div class="stat-box cyan-widget">
            <div class="stat-box-content">
                <div>
                    <span class="stat-chip chip-info">Total Playbooks</span>
                    <div class="stat-value"><?= $totalPlaybooks ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-book"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box green-widget">
            <div class="stat-box-content">
                <div>
                    <span class="stat-chip chip-success">Enabled</span>
                    <div class="stat-value"><?= $enabledPlaybooks ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box amber-widget">
            <div class="stat-box-content">
                <div>
                    <span class="stat-chip chip-warning">Executions</span>
                    <div class="stat-value"><?= $totalExecutions ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-history"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box red-widget">
            <div class="stat-box-content">
                <div>
                    <span class="stat-chip chip-danger">Failed</span>
                    <div class="stat-value"><?= $failedExecutions ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box violet-widget">
            <div class="stat-box-content">
                <div>
                    <span class="stat-chip chip-violet">Active Blocks</span>
                    <div class="stat-value"><?= $activeBlocked ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box neutral-widget">
            <div class="stat-box-content">
                <div>
                    <span class="stat-chip chip-neutral">Total Blocks</span>
                    <div class="stat-value"><?= $totalBlocked ?></div>
                </div>
                <div class="stat-icon"><i class="fas fa-list"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card p-4">
            <h5 class="mb-4 text-uppercase fw-bold" style="font-size: 0.9rem; letter-spacing: 0.05em; color: var(--text);">
                <i class="fas fa-bolt me-2" style="color: var(--metric-warning);"></i> Quick Actions
            </h5>
            <div class="d-grid gap-2">
                <a href="index.php" class="btn btn-secondary text-start py-2">
                    <i class="fas fa-book me-2" style="color: var(--accent); width: 24px;"></i> Manage Playbooks
                </a>
                <a href="edit.php" class="btn btn-secondary text-start py-2">
                    <i class="fas fa-plus-circle" style="color: #4ade80; width: 24px;"></i> Create New Playbook
                </a>
                <a href="blocked.php" class="btn btn-secondary text-start py-2">
                    <i class="fas fa-ban" style="color: #f87171; width: 24px;"></i> View Blocked IPs
                </a>
                <a href="?install" class="btn btn-secondary text-start py-2">
                    <i class="fas fa-database" style="color: #fbbf24; width: 24px;"></i> Setup Database Tables
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-4">
            <h5 class="mb-4 text-uppercase fw-bold" style="font-size: 0.9rem; letter-spacing: 0.05em; color: var(--text);">
                <i class="fas fa-history me-2" style="color: var(--metric-warning);"></i> Recent Executions
            </h5>
            <?php if (!$recentExecs || $recentExecs->num_rows === 0): ?>
            <p class="text-muted" style="font-size:0.85rem;">No executions yet</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Playbook</th><th>Status</th><th>Trigger</th><th>Time</th></tr></thead>
                <tbody>
                <?php while ($ex = $recentExecs->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($ex['pb_name'] ?? 'Unknown') ?></td>
                    <td><span style="color:<?= match($ex['status']) { 'completed'=>'#4ade80', 'failed'=>'#f87171', 'running'=>'#29d3ee', default=>'var(--text-muted)' } ?>"><?= $ex['status'] ?></span></td>
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
