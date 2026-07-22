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
<link rel="stylesheet" href="../../css/theme.css?v=<?= time() ?>">
<link rel="stylesheet" href="../../css/pages/responder.css?v=<?= time() ?>">
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
            <a href="edit.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Create Playbook</a>
        </div>
    </div>
</div>

<?php if ($playbooks->num_rows === 0): ?>
<div class="empty-state">
    <i class="fas fa-bolt"></i>
    <h4>No Playbooks Yet</h4>
    <p>Create your first automated response workflow</p>
    <a href="edit.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Playbook</a>
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
                <a href="?execute=<?= $pb['id'] ?>" class="btn btn-success btn-sm" title="Execute"><i class="fas fa-play"></i> Run</a>
                <?php endif; ?>
                <a href="edit.php?id=<?= $pb['id'] ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="fas fa-pen"></i></a>
                <a href="index.php?executions=<?= $pb['id'] ?>" class="btn btn-secondary btn-sm" title="Execution Log"><i class="fas fa-list"></i> Log</a>
                <a href="?toggle=<?= $pb['id'] ?>" class="btn btn-secondary btn-sm" title="Toggle">
                    <i class="fas fa-<?= $pb['enabled'] ? 'pause' : 'play' ?>"></i>
                </a>
                <a href="?delete=<?= $pb['id'] ?>" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Delete this playbook?')"><i class="fas fa-trash"></i></a>
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
    <a href="index.php" class="btn btn-secondary btn-sm ms-3"><i class="fas fa-times"></i> Close</a>
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
