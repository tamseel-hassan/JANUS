<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

$theme = $_COOKIE['theme'] ?? 'dark';
$tablesExist = $con->query("SHOW TABLES LIKE 'responder_playbooks'")->num_rows > 0;

if (!$tablesExist) {
    header('Location: automation.php?install=1');
    exit;
}

// Handle delete
if (isset($_GET['delete']) && intval($_GET['delete']) > 0) {
    $did = intval($_GET['delete']);
    $con->query("DELETE FROM responder_executions WHERE playbook_id = $did");
    $con->query("DELETE FROM responder_playbooks WHERE id = $did");
    header('Location: index.php');
    exit;
}

// Handle execute
if (isset($_GET['execute']) && intval($_GET['execute']) > 0) {
    $pid = intval($_GET['execute']);
    $stmt = $con->prepare("SELECT id FROM responder_playbooks WHERE id = ? AND enabled = 1");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $pb = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pb) {
        $estmt = $con->prepare(
            "INSERT INTO responder_executions (playbook_id, triggered_by, trigger_user_id, status, input_data)
             VALUES (?, 'user', ?, 'pending', '{}')"
        );
        $uid = $_SESSION['id'];
        $estmt->bind_param('ii', $pid, $uid);
        $estmt->execute();
        $eid = $estmt->insert_id;
        $estmt->close();

        require_once __DIR__ . '/engine.php';
        $engine = new PlaybookEngine();
        $result = $engine->execute($eid);

        $_SESSION['exec_msg'] = $result['status'] === 'completed' ? 'Playbook executed successfully' : 'Playbook execution failed';
    }
    header('Location: index.php');
    exit;
}

// Toggle enabled
if (isset($_GET['toggle']) && intval($_GET['toggle']) > 0) {
    $tid = intval($_GET['toggle']);
    $con->query("UPDATE responder_playbooks SET enabled = NOT enabled WHERE id = $tid");
    header('Location: index.php');
    exit;
}

$playbooks = $con->query("SELECT p.*, COALESCE(u.username, 'System') as created_by_name FROM responder_playbooks p LEFT JOIN accounts u ON p.created_by = u.id ORDER BY p.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Janus :: Playbooks</title>
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
body.loggedin {
    background:var(--void); color:var(--text-hi);
    font-family:'Segoe UI',sans-serif;
}
#main-content { margin-left:250px; padding:80px 25px 25px; min-height:100vh; transition:margin-left 0.3s; }
.sidebar.collapsed ~ #main-content { margin-left:80px; }
.page-title { font-size:1.6rem; font-weight:700; letter-spacing:-0.01em; }
.page-title .accent { color:var(--amber); }
.eyebrow { font-size:0.72rem; font-weight:600; letter-spacing:0.25em; color:var(--cyan); text-transform:uppercase; margin-bottom:10px; }
.pb-card {
    background:var(--panel); border:1px solid var(--line); border-radius:var(--radius);
    padding:20px 22px; margin-bottom:16px; transition:all 0.2s;
}
.pb-card:hover { border-color:var(--line-active); }
.pb-card .name { font-size:1.05rem; font-weight:600; color:var(--text-hi); }
.pb-card .desc { color:var(--text-mid); font-size:0.88rem; }
.badge-trigger { font-size:0.68rem; padding:3px 10px; border-radius:3px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; }
.bg-cyan { background:var(--cyan); color:#04232a; }
.bg-amber { background:var(--amber); color:#1a1204; }
.bg-green { background:var(--green); color:#04231a; }
.bg-violet { background:var(--violet); color:#14103a; }
.bg-red { background:var(--red); color:#2b0a0e; }
.bg-line { background:var(--line-active); color:var(--text-hi); }
.btn-ghost {
    padding:6px 14px; border-radius:var(--radius); font-size:0.75rem; font-weight:600;
    letter-spacing:0.03em; text-transform:uppercase; background:transparent;
    border:1px solid var(--line-active); color:var(--text-mid); transition:all 0.15s;
}
.btn-ghost:hover { border-color:var(--cyan); color:var(--cyan); }
.btn-ghost-red:hover { border-color:var(--red); color:var(--red); }
.btn-ghost-green:hover { border-color:var(--green); color:var(--green); }
.stat-mini { font-size:0.75rem; color:var(--text-lo); }
.stat-mini strong { color:var(--text-mid); }
.empty-state { text-align:center; padding:60px 20px; color:var(--text-lo); }
.empty-state i { font-size:3rem; margin-bottom:16px; opacity:0.4; }
.empty-state h4 { color:var(--text-mid); }
.toast { position:fixed; top:80px; right:25px; z-index:9999;
    padding:12px 20px; border-radius:var(--radius);
    font-size:0.85rem; font-weight:500; animation:fadeIn 0.3s;
}
.toast-success { background:var(--green); color:#04231a; }
.toast-fail { background:var(--red); color:#2b0a0e; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
@media(max-width:768px) { #main-content { margin-left:0; padding:80px 16px 16px; } }
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">

<?php if (isset($_SESSION['exec_msg'])): ?>
<div class="toast toast-<?= str_contains($_SESSION['exec_msg'], 'fail') ? 'fail' : 'success' ?>">
    <i class="fas fa-<?= str_contains($_SESSION['exec_msg'], 'fail') ? 'times' : 'check' ?>-circle"></i>
    <?= htmlspecialchars($_SESSION['exec_msg']) ?>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;margin-left:12px;cursor:pointer">&times;</button>
</div>
<?php unset($_SESSION['exec_msg']); ?>
<?php endif; ?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="eyebrow"><i class="fas fa-bolt"></i> JANUS / RESPONDER &mdash; AUTOMATION</div>
            <h1 class="page-title"><span class="accent">&gt;</span> Playbooks</h1>
            <p class="text-muted" style="color:var(--text-mid);font-size:0.9rem;">Automated response workflows for security incidents</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="edit.php" class="btn-ghost"><i class="fas fa-plus"></i> Create Playbook</a>
        </div>
    </div>
</div>

<?php if ($playbooks->num_rows === 0): ?>
<div class="empty-state">
    <i class="fas fa-bolt"></i>
    <h4>No Playbooks Yet</h4>
    <p>Create your first automated response workflow</p>
    <a href="edit.php" class="btn-ghost mt-3"><i class="fas fa-plus"></i> Create Playbook</a>
</div>
<?php else: ?>
<div class="row">
    <?php while ($pb = $playbooks->fetch_assoc()): ?>
    <?php
        $actions = json_decode($pb['actions'] ?? '[]', true);
        $triggers = ['manual' => 'bg-cyan', 'incident_created' => 'bg-amber', 'ip_blocked' => 'bg-red', 'webhook' => 'bg-violet', 'scheduled' => 'bg-green'];
        $triggerColor = $triggers[$pb['trigger_type']] ?? 'bg-line';
        $lastRun = $pb['last_executed_at'] ? date('M d, H:i', strtotime($pb['last_executed_at'])) : 'Never';
    ?>
    <div class="col-lg-6">
        <div class="pb-card">
            <div class="d-flex align-items-start justify-content-between mb-2">
                <div>
                    <span class="name"><?= htmlspecialchars($pb['name']) ?></span>
                    <span class="badge-trigger <?= $triggerColor ?> ms-2"><?= str_replace('_', ' ', $pb['trigger_type']) ?></span>
                    <span class="badge-trigger bg-line ms-1"><?= count($actions) ?> action<?= count($actions) !== 1 ? 's' : '' ?></span>
                </div>
                <span style="width:10px;height:10px;border-radius:50%;background:<?= $pb['enabled'] ? 'var(--green)' : 'var(--text-lo)' ?>;flex-shrink:0;box-shadow:<?= $pb['enabled'] ? '0 0 6px var(--green)' : 'none' ?>"></span>
            </div>
            <div class="desc mb-2"><?= htmlspecialchars($pb['description'] ?: 'No description') ?></div>
            <div class="stat-mini mb-3">
                <strong><?= $pb['execution_count'] ?></strong> runs &middot;
                Last: <strong><?= $lastRun ?></strong> &middot;
                Priority: <strong><?= $pb['priority'] ?></strong> &middot;
                By: <strong><?= htmlspecialchars($pb['created_by_name']) ?></strong>
            </div>
            <div class="d-flex gap-2">
                <?php if ($pb['enabled']): ?>
                <a href="?execute=<?= $pb['id'] ?>" class="btn-ghost btn-ghost-green" title="Execute"><i class="fas fa-play"></i> Run</a>
                <?php endif; ?>
                <a href="edit.php?id=<?= $pb['id'] ?>" class="btn-ghost" title="Edit"><i class="fas fa-pen"></i></a>
                <a href="index.php?executions=<?= $pb['id'] ?>" class="btn-ghost" title="Execution Log"><i class="fas fa-list"></i> Log</a>
                <a href="?toggle=<?= $pb['id'] ?>" class="btn-ghost" title="Toggle">
                    <i class="fas fa-<?= $pb['enabled'] ? 'pause' : 'play' ?>"></i>
                </a>
                <a href="?delete=<?= $pb['id'] ?>" class="btn-ghost btn-ghost-red" title="Delete" onclick="return confirm('Delete this playbook?')"><i class="fas fa-trash"></i></a>
            </div>
        </div>
    </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['executions'])): ?>
<?php
$eid = intval($_GET['executions']);
$execs = $con->query("SELECT pe.*, COALESCE(u.username, 'System') as trigger_user FROM responder_executions pe LEFT JOIN accounts u ON pe.trigger_user_id = u.id WHERE pe.playbook_id = $eid ORDER BY pe.created_at DESC LIMIT 20");
$pbname = $con->query("SELECT name FROM responder_playbooks WHERE id = $eid")->fetch_assoc()['name'] ?? 'Playbook';
?>
<hr style="border-color:var(--line);margin:30px 0 16px;">
<h5 style="color:var(--text-mid);font-size:0.9rem;margin-bottom:16px;">
    <i class="fas fa-history"></i> Recent Executions: <?= htmlspecialchars($pbname) ?>
    <a href="index.php" class="btn-ghost ms-3" style="font-size:0.7rem;"><i class="fas fa-times"></i> Close</a>
</h5>
<div class="table-responsive">
<table class="table" style="color:var(--text-mid);font-size:0.85rem;">
    <thead style="color:var(--text-lo);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.1em;">
        <tr><th>ID</th><th>Status</th><th>Triggered</th><th>By</th><th>Steps</th><th>Started</th><th>Completed</th><th>Error</th></tr>
    </thead>
    <tbody>
        <?php while ($ex = $execs->fetch_assoc()): ?>
        <tr>
            <td>#<?= $ex['id'] ?></td>
            <td>
                <span style="color:<?= match($ex['status']) { 'completed'=>'var(--green)', 'failed'=>'var(--red)', 'running'=>'var(--cyan)', default=>'var(--text-lo)' } ?>">
                    <?= $ex['status'] ?>
                </span>
            </td>
            <td><?= $ex['triggered_by'] ?></td>
            <td><?= htmlspecialchars($ex['trigger_user'] ?? 'System') ?></td>
            <td><?= intval($ex['current_step']) ?>/<?= intval($ex['total_steps']) ?></td>
            <td><?= $ex['started_at'] ? date('M d H:i:s', strtotime($ex['started_at'])) : '-' ?></td>
            <td><?= $ex['completed_at'] ? date('M d H:i:s', strtotime($ex['completed_at'])) : '-' ?></td>
            <td style="color:var(--red);max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($ex['error_message'] ?? '') ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div>
</div>

<script src="../../js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('sidebar'), c = document.getElementById('main-content');
    function adj() { c.style.marginLeft = s && s.classList.contains('collapsed') ? '80px' : '250px'; }
    adj(); if (s) new MutationObserver(adj).observe(s, { attributes: true, attributeFilter: ['class'] });
    setTimeout(function() { document.querySelectorAll('.toast').forEach(function(t) { t.style.opacity = '0'; t.style.transition = 'opacity 0.5s'; setTimeout(function() { t.remove(); }, 500); }); }, 5000);
});
</script>
</body>
</html>
<?php mysqli_close($con); ?>
