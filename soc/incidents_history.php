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

$incidents = mysqli_query($con, "
    SELECT i.*, a.username AS reporter, ass.username AS assignee, d.name AS device_name
    FROM incidents i
    JOIN accounts a ON i.reported_by = a.id
    LEFT JOIN accounts ass ON i.assigned_to = ass.id
    LEFT JOIN devices d ON i.device_id = d.id
    ORDER BY i.created_at DESC
");
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus – Incident History</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/pages/incidents_history.css">
</head>
<body class="loggedin">
<?php include __DIR__ . '/../topbar.php'; ?>
<?php include __DIR__ . '/../sidebar.php'; ?>
<div id="main-content"><div class="container-fluid">
    <h2 class="mb-4"><i data-lucide="history" class="icon-lucide me-2"></i> Incident History</h2>
    <input type="text" id="tableSearch" class="form-control mb-3" placeholder="Search..." style="max-width:300px;">
    <?php if (mysqli_num_rows($incidents) == 0): ?>
        <div class="alert alert-info">No incidents found.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover" id="historyTable">
        <thead class="table-dark"><tr><th>ID</th><th>Title</th><th>Severity</th><th>Status</th><th>Created</th><th>Reporter</th><th>Assignee</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while ($inc = mysqli_fetch_assoc($incidents)): ?>
        <tr>
            <td>#<?= $inc['id'] ?></td>
            <td><?= htmlspecialchars($inc['title']) ?></td>
            <td><span class="badge-severity severity-<?= $inc['severity'] ?>"><?= ucfirst($inc['severity']) ?></span></td>
            <td><span class="badge-status status-<?= $inc['status'] ?>"><?= ucfirst(str_replace('_',' ',$inc['status'])) ?></span></td>
            <td><?= date('Y-m-d H:i', strtotime($inc['created_at'])) ?></td>
            <td><?= htmlspecialchars($inc['reporter']) ?></td>
            <td><?= htmlspecialchars($inc['assignee'] ?? 'Unassigned') ?></td>
            <td><button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#viewModal<?= $inc['id'] ?>"><i data-lucide="eye" class="icon-lucide"></i> View</button>
                <div class="modal fade" id="viewModal<?= $inc['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-xl"><div class="modal-content">
                        <div class="modal-header"><h5>#<?= $inc['id'] ?> - <?= htmlspecialchars($inc['title']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <p><?= nl2br(htmlspecialchars($inc['description'])) ?></p>
                            <p><strong>Device:</strong> <?= htmlspecialchars($inc['device_name'] ?? 'N/A') ?></p>
                            <p><strong>Reporter:</strong> <?= htmlspecialchars($inc['reporter']) ?> | <strong>Assignee:</strong> <?= htmlspecialchars($inc['assignee'] ?? 'Unassigned') ?></p>
                            <p><strong>Created:</strong> <?= $inc['created_at'] ?> | <strong>Due:</strong> <?= $inc['due_date'] ?></p>
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
document.addEventListener('DOMContentLoaded',()=>{
    const s=document.getElementById('sidebar'),m=document.getElementById('main-content');
    if(s)new MutationObserver(()=>m.style.marginLeft=s.classList.contains('collapsed')?'78px':'250px').observe(s,{attributes:true});
    const search=document.getElementById('tableSearch'),table=document.getElementById('historyTable');
    if(search&&table)search.addEventListener('keyup',()=>{
        const text=search.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row=>row.style.display=row.textContent.toLowerCase().includes(text)?'':'none');
    });
});
</script>
</body>
</html>
