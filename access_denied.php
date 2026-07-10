<?php
session_start();
if (!isset($_SESSION['loggedin'])) { header('Location: /index.html'); exit; }
$theme  = $_COOKIE['theme'] ?? 'dark';
$reason = htmlspecialchars($_GET['reason'] ?? 'You do not have permission to access that page.');
$role   = htmlspecialchars($_SESSION['role'] ?? 'unknown');
$name   = htmlspecialchars($_SESSION['name'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Access Denied – Janus</title>
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<style>
body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
.denied-card {
    max-width:480px; width:100%; text-align:center;
    padding:48px 40px; border-radius:16px;
    background:var(--card-bg); border:1px solid var(--border);
    box-shadow:0 8px 32px rgba(0,0,0,.35);
}
.denied-icon { font-size:4rem; margin-bottom:16px; }
.denied-title { font-size:1.6rem; font-weight:700; color:var(--text); margin-bottom:8px; }
.denied-reason { font-size:.9rem; color:var(--text-muted); margin-bottom:28px; padding:12px 16px; background:rgba(220,53,69,.08); border:1px solid rgba(220,53,69,.2); border-radius:8px; }
.role-badge { display:inline-block; padding:.3em .8em; border-radius:20px; font-size:.8rem; font-weight:600; background:rgba(59,130,246,.15); color:#60a5fa; border:1px solid rgba(59,130,246,.25); margin-bottom:20px; }
</style>
</head>
<body>
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content" style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="denied-card">
        <div class="denied-icon">🚫</div>
        <div class="denied-title">Access Denied</div>
        <div class="role-badge"><i class="fas fa-user-tag me-1"></i><?= $name ?> · <?= $role ?></div>
        <div class="denied-reason"><i class="fas fa-info-circle me-1"></i><?= $reason ?></div>
        <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:24px;">
            Contact your system administrator if you believe you should have access to this area.
        </p>
        <a href="/home.php" class="btn btn-primary me-2"><i class="fas fa-home me-1"></i>Go to Dashboard</a>
        <a href="javascript:history.back()" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Go Back</a>
    </div>
</div>
<script src="/js/bootstrap.bundle.min.js"></script>
</body>
</html>
