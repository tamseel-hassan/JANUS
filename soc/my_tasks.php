<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../db_config.php';

// Ensure missing columns
$col_check = mysqli_query($con, "SHOW COLUMNS FROM incidents LIKE 'attachment'");
if (mysqli_num_rows($col_check) == 0) mysqli_query($con, "ALTER TABLE incidents ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER status");
$col_check = mysqli_query($con, "SHOW COLUMNS FROM incidents LIKE 'subcategory'");
if (mysqli_num_rows($col_check) == 0) mysqli_query($con, "ALTER TABLE incidents ADD COLUMN subcategory VARCHAR(100) DEFAULT NULL AFTER type");
$col_check = mysqli_query($con, "SHOW COLUMNS FROM incidents LIKE 'severity'");
if (mysqli_num_rows($col_check) == 0) mysqli_query($con, "ALTER TABLE incidents ADD COLUMN severity ENUM('low','medium','high','critical') DEFAULT 'medium' AFTER type");

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF validation failed.');
    $id = (int)$_POST['id'];
    $current_user = $_SESSION['id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && $_SESSION['role'] === 'admin') {
        foreach (['incident_comments','incident_history','observables','incidents'] as $table) {
            $stmt = $con->prepare("DELETE FROM $table WHERE incident_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute(); $stmt->close();
        }
    } elseif ($action === 'assign') {
        $assigned_to = (int)$_POST['assigned_to'];
        $stmt = $con->prepare("UPDATE incidents SET assigned_to = ?, unread_by_assignee = 1 WHERE id = ?");
        $stmt->bind_param("ii", $assigned_to, $id);
        $stmt->execute(); $stmt->close();
        $stmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'assigned_to', 'none', ?)");
        $stmt->bind_param("iis", $id, $current_user, $_POST['assigned_to']);
        $stmt->execute(); $stmt->close();
    } elseif ($action === 'escalate') {
        $stmt = $con->prepare("UPDATE incidents SET status = 'in_progress' WHERE id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        $stmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'status', 'open', 'in_progress')");
        $stmt->bind_param("ii", $id, $current_user); $stmt->execute(); $stmt->close();
    } elseif ($action === 'change_status') {
        $new_status = $_POST['new_status'];
        if (in_array($new_status, ['open','in_progress','resolved','closed'])) {
            $stmt = $con->prepare("UPDATE incidents SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $id); $stmt->execute(); $stmt->close();
            $stmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'status', '', ?)");
            $stmt->bind_param("iis", $id, $current_user, $new_status); $stmt->execute(); $stmt->close();
        }
    } elseif ($action === 'add_comment') {
        $comment = mysqli_real_escape_string($con, $_POST['comment']);
        $stmt = $con->prepare("INSERT INTO incident_comments (incident_id, user_id, comment) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $id, $current_user, $comment); $stmt->execute(); $stmt->close();
    } elseif ($action === 'add_observable') {
        $obs_type = mysqli_real_escape_string($con, $_POST['obs_type']);
        $obs_value = mysqli_real_escape_string($con, $_POST['obs_value']);
        $obs_desc = mysqli_real_escape_string($con, $_POST['obs_desc']);
        $stmt = $con->prepare("INSERT INTO observables (incident_id, type, value, notes) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $id, $obs_type, $obs_value, $obs_desc); $stmt->execute(); $stmt->close();
    }

    // Mark read
    $stmt = $con->prepare("UPDATE incidents SET unread_by_assignee = 0 WHERE id = ? AND assigned_to = ?");
    $stmt->bind_param("ii", $id, $current_user); $stmt->execute(); $stmt->close();
    header('Location: my_tasks.php'); exit;
}

$my_id = $_SESSION['id'];
$incidents = mysqli_query($con, "
    SELECT i.*, a.username AS reporter, ass.username AS assignee, d.name AS device_name
    FROM incidents i
    JOIN accounts a ON i.reported_by = a.id
    LEFT JOIN accounts ass ON i.assigned_to = ass.id
    LEFT JOIN devices d ON i.device_id = d.id
    WHERE i.assigned_to = $my_id AND i.status != 'closed'
    ORDER BY i.created_at DESC
");
$users = mysqli_query($con, "SELECT id, username FROM accounts ORDER BY username");
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus – My Tasks</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/pages/my_tasks.css">
</head>
<body class="loggedin">
<?php include __DIR__ . '/../topbar.php'; ?>
<?php include __DIR__ . '/../sidebar.php'; ?>
<div id="main-content"><div class="container-fluid">
    <h2 class="mb-4"><i data-lucide="tasks" class="icon-lucide me-2"></i> My Assigned Tasks</h2>
    <?php if (mysqli_num_rows($incidents) == 0): ?>
        <div class="alert alert-info">No assigned tasks.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead class="table-dark"><tr><th>ID</th><th>Title</th><th>Severity</th><th>Status</th><th>Due</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while ($inc = mysqli_fetch_assoc($incidents)): ?>
        <tr class="<?= $inc['unread_by_assignee'] ? 'unread-row' : '' ?>">
            <td>#<?= $inc['id'] ?></td>
            <td><?= htmlspecialchars($inc['title']) ?></td>
            <td><span class="badge bg-<?= ['low'=>'secondary','medium'=>'info','high'=>'warning','critical'=>'danger'][$inc['severity']] ?>"><?= ucfirst($inc['severity']) ?></span></td>
            <td><span class="badge bg-<?= ['open'=>'primary','in_progress'=>'warning','resolved'=>'success','closed'=>'secondary'][$inc['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$inc['status'])) ?></span></td>
            <td><?php
                $due = strtotime($inc['due_date']); $left = ceil(($due - time())/86400);
                if ($left < 0) echo '<span class="text-danger">Overdue</span>';
                elseif ($left <= 1) echo '<span class="text-warning">'.$left.' day</span>';
                else echo '<span class="text-success">'.$left.' days</span>';
            ?></td>
            <td>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?= $inc['id'] ?>" title="View"><i data-lucide="eye" class="icon-lucide"></i></button>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="id" value="<?= $inc['id'] ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="new_status" value="in_progress"><button class="btn btn-primary" title="Start"><i data-lucide="play" class="icon-lucide"></i></button></form>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="id" value="<?= $inc['id'] ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="new_status" value="resolved"><button class="btn btn-success" title="Resolve"><i data-lucide="check" class="icon-lucide"></i></button></form>
                </div>
                <!-- View Modal (simplified, but keep your original detailed modal if needed, just update DB queries inside) -->
                <div class="modal fade" id="viewModal<?= $inc['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-xl"><div class="modal-content">
                        <div class="modal-header"><h5>#<?= $inc['id'] ?> - <?= htmlspecialchars($inc['title']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($inc['description'])) ?></p>
                            <p><strong>Device:</strong> <?= htmlspecialchars($inc['device_name'] ?? 'N/A') ?></p>
                            <p><strong>Reported by:</strong> <?= htmlspecialchars($inc['reporter']) ?> | <strong>Assigned to:</strong> <?= htmlspecialchars($inc['assignee'] ?? 'Unassigned') ?></p>
                            <p><strong>Created:</strong> <?= $inc['created_at'] ?> | <strong>Due:</strong> <?= $inc['due_date'] ?></p>
                            <hr>
                            <h6>Comments</h6>
                            <?php
                            $cres = mysqli_query($con, "SELECT c.*, a.username FROM incident_comments c JOIN accounts a ON c.user_id = a.id WHERE c.incident_id = {$inc['id']} ORDER BY c.created_at DESC");
                            while ($com = mysqli_fetch_assoc($cres)) echo '<div class="mb-2"><strong>'.$com['username'].'</strong> <small>'.$com['created_at'].'</small><br>'.nl2br(htmlspecialchars($com['comment'])).'</div>';
                            ?>
                            <form method="POST"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="id" value="<?= $inc['id'] ?>"><input type="hidden" name="action" value="add_comment"><textarea name="comment" class="form-control mb-2" required></textarea><button class="btn btn-primary btn-sm">Add Comment</button></form>
                        </div>
                    </div></div>
                </div>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div></div>
<script src="/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const s=document.getElementById('sidebar'),m=document.getElementById('main-content');if(s)new MutationObserver(()=>m.style.marginLeft=s.classList.contains('collapsed')?'78px':'250px').observe(s,{attributes:true})});
</script>
</body>
</html>
