<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../db_config.php';

// Auto-add missing columns
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

    if ($action === 'assign') {
        $assigned_to = (int)$_POST['assigned_to'];
        $stmt = $con->prepare("UPDATE incidents SET assigned_to = ?, status = 'in_progress', unread_by_assignee = 1 WHERE id = ?");
        $stmt->bind_param("ii", $assigned_to, $id); $stmt->execute(); $stmt->close();
        $stmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'assigned_to', 'none', ?)");
        $stmt->bind_param("iis", $id, $current_user, $_POST['assigned_to']); $stmt->execute(); $stmt->close();
    } elseif ($action === 'escalate') {
        // No escalated_to column, change status to in_progress and log
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

    header('Location: active_incidents.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

$filter = mysqli_real_escape_string($con, $_GET['filter'] ?? '');
$where = "i.status != 'closed'";
if ($filter) $where .= " AND (i.title LIKE '%$filter%' OR i.description LIKE '%$filter%')";
$incidents = mysqli_query($con, "
    SELECT i.*, a.username AS reporter, ass.username AS assignee, d.name AS device_name
    FROM incidents i
    JOIN accounts a ON i.reported_by = a.id
    LEFT JOIN accounts ass ON i.assigned_to = ass.id
    LEFT JOIN devices d ON i.device_id = d.id
    WHERE $where
    ORDER BY i.due_date ASC
");
$users = mysqli_query($con, "SELECT id, username FROM accounts ORDER BY username");
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus – Active Incidents</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <style>
        #main-content{margin-left:250px;transition:margin-left 0.3s;padding-top:70px}.sidebar.collapsed ~ #main-content{margin-left:78px}
        .modal-content{background:var(--card-bg);color:var(--text)}.modal-header{border-bottom:1px solid var(--border)}.modal-footer{border-top:1px solid var(--border)}
        .badge-severity{padding:4px 8px;border-radius:4px}.severity-low{background:#6c757d;color:#fff}.severity-medium{background:#ffc107;color:#000}.severity-high{background:#fd7e14;color:#fff}.severity-critical{background:#dc3545;color:#fff}
        .badge-status{padding:4px 8px;border-radius:4px}.status-open{background:#0d6efd}.status-in_progress{background:#fd7e14}.status-resolved{background:#198754}.status-closed{background:#6c757d}
    </style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../topbar.php'; ?>
<?php include __DIR__ . '/../sidebar.php'; ?>
<div id="main-content"><div class="container-fluid">
    <h2 class="mb-4"><i class="fas fa-exclamation-triangle me-2"></i> Active/Open Incidents</h2>
    <form method="GET" class="mb-4">
        <div class="input-group">
            <input type="text" name="filter" class="form-control" placeholder="Filter by title or description..." value="<?= htmlspecialchars($filter) ?>">
            <button class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
        </div>
    </form>
    <?php if (mysqli_num_rows($incidents) == 0): ?>
        <div class="alert alert-info">No active incidents found.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead class="table-dark"><tr><th>ID</th><th>Title</th><th>Severity</th><th>Status</th><th>Due</th><th>Reporter</th><th>Assignee</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while ($inc = mysqli_fetch_assoc($incidents)): ?>
        <tr>
            <td>#<?= $inc['id'] ?></td>
            <td><?= htmlspecialchars($inc['title']) ?></td>
            <td><span class="badge-severity severity-<?= $inc['severity'] ?>"><?= ucfirst($inc['severity']) ?></span></td>
            <td><span class="badge-status status-<?= $inc['status'] ?>"><?= ucfirst(str_replace('_',' ',$inc['status'])) ?></span></td>
            <td><?php
                $due = strtotime($inc['due_date']); $left = ceil(($due - time())/86400);
                if ($left < 0) echo '<span class="text-danger">Overdue</span>';
                elseif ($left <= 1) echo '<span class="text-warning">'.$left.' day</span>';
                else echo '<span class="text-success">'.$left.' days</span>';
            ?></td>
            <td><?= htmlspecialchars($inc['reporter']) ?></td>
            <td><?= htmlspecialchars($inc['assignee'] ?? 'Unassigned') ?></td>
            <td>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?= $inc['id'] ?>"><i class="fas fa-eye"></i></button>
                    <!-- Assign dropdown -->
                    <div class="btn-group">
                        <button class="btn btn-warning dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-user-plus"></i></button>
                        <ul class="dropdown-menu">
                            <?php mysqli_data_seek($users,0); while ($u = mysqli_fetch_assoc($users)): ?>
                            <li><form method="POST"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="id" value="<?= $inc['id'] ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="assigned_to" value="<?= $u['id'] ?>"><button class="dropdown-item"><?= htmlspecialchars($u['username']) ?></button></form></li>
                            <?php endwhile; ?>
                        </ul>
                    </div>
                    <!-- Status dropdown -->
                    <div class="btn-group">
                        <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-edit"></i></button>
                        <ul class="dropdown-menu">
                            <?php foreach (['open','in_progress','resolved','closed'] as $st): ?>
                            <li><form method="POST"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="id" value="<?= $inc['id'] ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="new_status" value="<?= $st ?>"><button class="dropdown-item"><?= ucfirst(str_replace('_',' ',$st)) ?></button></form></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <!-- View Modal (simplified) -->
                <div class="modal fade" id="viewModal<?= $inc['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-xl"><div class="modal-content">
                        <div class="modal-header"><h5>#<?= $inc['id'] ?> - <?= htmlspecialchars($inc['title']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <p><?= nl2br(htmlspecialchars($inc['description'])) ?></p>
                            <p><strong>Device:</strong> <?= htmlspecialchars($inc['device_name'] ?? 'N/A') ?></p>
                            <p><strong>Reporter:</strong> <?= htmlspecialchars($inc['reporter']) ?> | <strong>Assignee:</strong> <?= htmlspecialchars($inc['assignee'] ?? 'Unassigned') ?></p>
                            <hr>
                            <h6>Observables</h6>
                            <?php
                            $obs_res = mysqli_query($con, "SELECT * FROM observables WHERE incident_id = {$inc['id']}");
                            while ($obs = mysqli_fetch_assoc($obs_res)) echo '<div>'.htmlspecialchars($obs['type']).': '.htmlspecialchars($obs['value']).' ('.htmlspecialchars($obs['notes']??'').')</div>';
                            ?>
                            <hr>
                            <h6>Add Comment</h6>
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
