<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

$theme = $_COOKIE['theme'] ?? 'dark';
if ($con->query("SHOW TABLES LIKE 'responder_playbooks'")->num_rows === 0) {
    header('Location: automation.php?install=1');
    exit;
}

// Handle unblock
if (isset($_GET['unblock']) && intval($_GET['unblock']) > 0) {
    $uid = intval($_GET['unblock']);
    $con->query("DELETE FROM responder_blocked_ips WHERE id = $uid");
    header('Location: blocked.php');
    exit;
}

// Handle block from form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block'])) {
    $ip = mysqli_real_escape_string($con, $_POST['ip']);
    $reason = mysqli_real_escape_string($con, $_POST['reason'] ?? 'Manual block');
    $duration = intval($_POST['duration'] ?? 0);
    $expires = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration) : null;

    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $stmt = $con->prepare("INSERT INTO responder_blocked_ips (ip_address, reason, source, expires_at, blocked_by) VALUES (?, ?, 'manual', ?, ?)");
        $uid = $_SESSION['id'];
        $stmt->bind_param('sssi', $ip, $reason, $expires, $uid);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: blocked.php');
    exit;
}

$page = intval($_GET['page'] ?? 1);
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = mysqli_fetch_assoc($con->query("SELECT COUNT(*) as c FROM responder_blocked_ips"))['c'] ?? 0;
$totalPages = max(1, ceil($total / $perPage));
$blocks = $con->query("SELECT b.*, COALESCE(u.username, 'System') as blocked_by_name FROM responder_blocked_ips b LEFT JOIN accounts u ON b.blocked_by = u.id ORDER BY b.created_at DESC LIMIT $perPage OFFSET $offset");
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Janus :: Blocked IPs</title>
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
.form-card { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); padding:20px; margin-bottom:20px; }
.form-control { background:var(--inset); border:1px solid var(--line); color:var(--text-hi); border-radius:var(--radius); font-size:0.88rem; }
.form-control:focus { border-color:var(--cyan); box-shadow:0 0 0 2px rgba(41,211,238,0.15); }
.form-label { font-size:0.8rem; font-weight:600; color:var(--text-mid); text-transform:uppercase; letter-spacing:0.05em; }
.btn-ghost { padding:6px 14px; border-radius:var(--radius); font-size:0.75rem; font-weight:600; letter-spacing:0.03em; text-transform:uppercase; background:transparent; border:1px solid var(--line-active); color:var(--text-mid); transition:all 0.15s; }
.btn-ghost:hover { border-color:var(--cyan); color:var(--cyan); }
.btn-ghost-red:hover { border-color:var(--red); color:var(--red); }
.table { color:var(--text-mid); font-size:0.85rem; }
.table thead { color:var(--text-lo); text-transform:uppercase; font-size:0.7rem; letter-spacing:0.1em; }
.table td, .table th { border-color:var(--line); }
.active-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
@media(max-width:768px) { #main-content { margin-left:0; padding:80px 16px 16px; } }
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">

<div class="page-header">
    <div class="eyebrow"><i class="fas fa-ban"></i> JANUS / RESPONDER</div>
    <h1 class="page-title"><span class="accent">&gt;</span> Blocked IPs</h1>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="form-card">
            <h5 style="font-size:0.9rem;font-weight:700;color:var(--text-hi);margin-bottom:16px;text-transform:uppercase;letter-spacing:0.04em;">
                <i class="fas fa-plus-circle" style="color:var(--red);"></i> Block IP Address
            </h5>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">IP Address</label>
                    <input type="text" name="ip" class="form-control" required placeholder="e.g., 192.168.1.100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g., Malicious activity">
                </div>
                <div class="mb-3">
                    <label class="form-label">Duration (seconds, 0 = permanent)</label>
                    <input type="number" name="duration" class="form-control" value="0" min="0">
                </div>
                <button type="submit" name="block" class="btn-ghost btn-ghost-red" style="width:100%;"><i class="fas fa-ban"></i> Block IP</button>
            </form>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>IP Address</th><th>Reason</th><th>Source</th><th>Blocked By</th><th>Expires</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if ($blocks->num_rows === 0): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-lo);padding:30px;">No blocked IPs</td></tr>
                <?php endif; ?>
                <?php while ($b = $blocks->fetch_assoc()): ?>
                <?php
                    $active = is_null($b['expires_at']) || strtotime($b['expires_at']) > time();
                ?>
                <tr>
                    <td style="font-family:monospace;"><?= htmlspecialchars($b['ip_address']) ?></td>
                    <td><?= htmlspecialchars($b['reason'] ?? '-') ?></td>
                    <td><?= $b['source'] ?></td>
                    <td><?= htmlspecialchars($b['blocked_by_name']) ?></td>
                    <td><?= $b['expires_at'] ? date('M d H:i', strtotime($b['expires_at'])) : 'Permanent' ?></td>
                    <td>
                        <span class="active-dot" style="background:<?= $active ? 'var(--green)' : 'var(--text-lo)' ?>;box-shadow:<?= $active ? '0 0 6px var(--green)' : 'none' ?>"></span>
                        <?= $active ? 'Active' : 'Expired' ?>
                    </td>
                    <td><a href="?unblock=<?= $b['id'] ?>" class="btn-ghost btn-ghost-red" style="padding:2px 10px;font-size:0.7rem;" onclick="return confirm('Unblock this IP?')"><i class="fas fa-check"></i> Unblock</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center" style="gap:4px;">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                    <a href="?page=<?= $p ?>" style="background:<?= $p === $page ? 'var(--cyan)' : 'var(--panel)' ?>;border:1px solid var(--line);color:<?= $p === $page ? '#04232a' : 'var(--text-mid)' ?>;padding:6px 12px;border-radius:var(--radius);text-decoration:none;font-size:0.85rem;"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
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
