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
<link rel="stylesheet" href="/css/pages/access_denied.css">
</head>
<body>
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content" style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="denied-card">
        <div class="denied-icon">🚫</div>
        <div class="denied-title">Access Denied</div>
        <div class="role-badge"><i data-lucide="user" class="icon-lucide -tag me-1"></i><?= $name ?> · <?= $role ?></div>
        <div class="denied-reason"><i data-lucide="info" class="icon-lucide me-1"></i><?= $reason ?></div>
        <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:24px;">
            Contact your system administrator if you believe you should have access to this area.
        </p>
        <a href="/home.php" class="btn btn-primary me-2"><i data-lucide="home" class="icon-lucide me-1"></i>Go to Dashboard</a>
        <a href="javascript:history.back()" class="btn btn-outline-secondary"><i data-lucide="arrow-left" class="icon-lucide me-1"></i>Go Back</a>
    </div>
</div>
<script src="/js/bootstrap.bundle.min.js"></script>
</body>
</html>
