<?php
session_start();
require_once __DIR__ . '/db_config.php';
if (!isset($_SESSION['loggedin'])) { header('Location: /index.html'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: /home.php'); exit; }


$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

// Auto-add columns if missing (matching the actual janus schema)
$cols = [];
$r = mysqli_query($con, "SHOW COLUMNS FROM accounts");
while ($c = mysqli_fetch_assoc($r)) $cols[] = $c['Field'];
if (!in_array('role',      $cols)) mysqli_query($con, "ALTER TABLE accounts ADD COLUMN role VARCHAR(20) DEFAULT 'analyst' AFTER username");
if (!in_array('email',     $cols)) mysqli_query($con, "ALTER TABLE accounts ADD COLUMN email VARCHAR(255) AFTER password");
if (!in_array('is_active', $cols)) mysqli_query($con, "ALTER TABLE accounts ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER email");

$roles = [
    'admin'    => ['label' => 'Admin',    'color' => 'danger',  'desc' => 'Full access & user management'],
    'analyst'  => ['label' => 'Analyst',  'color' => 'primary', 'desc' => 'SOC/NOC analysis & incident response'],
    'operator' => ['label' => 'Operator', 'color' => 'info',    'desc' => 'Operations metrics only'],
];

// Page permission categories — mirrors auth_check.php
$permission_categories = [
    'Dashboard'               => ['home.php'                      => ['admin','analyst']],
    'Inventory & Monitoring'  => [
        'manage.php'          => ['admin','analyst'],
        'monitor.php'         => ['admin','analyst'],
        'maps.php'            => ['admin','analyst'],
        'resources.php'       => ['admin','analyst'],
        'reports.php'         => ['admin','analyst'],
    ],
    'SOC / Incidents'         => [
        'report_incident.php' => ['admin','analyst'],
        'my_tasks.php'        => ['admin','analyst'],
        'active_incidents.php'=> ['admin','analyst'],
        'incidents_history.php'=>['admin','analyst'],
        'manage_tickets.php'  => ['admin','analyst'],
    ],
    'Traffic & Security'      => [
        'fflow.php'           => ['admin','analyst'],
        'ips_logs.php'        => ['admin','analyst'],
        'avalibility.php'     => ['admin','analyst'],
        'vuln_scan.php'       => ['admin','analyst'],
    ],
    'NOC Tools'               => [
        'nac.php'             => ['admin','analyst'],
        'ipam.php'            => ['admin','analyst'],
        'config_backup.php'   => ['admin','analyst'],
        'compliance.php'      => ['admin','analyst'],
        'logmanage.php'       => ['admin','analyst'],
    ],
    'Administration'          => [
        'users.php'           => ['admin'],
        'profile.php'         => ['admin','analyst','operator'],
        'search.php'          => ['admin','analyst','operator'],
    ],
];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $username = trim($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $email    = trim($_POST['email'] ?? '');
            $role     = in_array($_POST['role'], array_keys($roles)) ? $_POST['role'] : 'analyst';
            if (empty($username)) {
                $_SESSION['toast'] = ['type'=>'danger','message'=>'Username is required!'];
                header('Location: users.php'); exit;
            }
            $stmt = $con->prepare("SELECT id FROM accounts WHERE username = ?");
            $stmt->bind_param("s", $username); $stmt->execute(); $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $_SESSION['toast'] = ['type'=>'danger','message'=>'Username already exists!'];
                header('Location: users.php'); exit;
            }
            $stmt = $con->prepare("INSERT INTO accounts (username, password, email, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $password, $email, $role);
            $_SESSION['toast'] = $stmt->execute()
                ? ['type'=>'success','message'=>"User '{$username}' created with role '{$role}'."]
                : ['type'=>'danger','message'=>'Failed to create user: '.$stmt->error];
            header('Location: users.php'); exit;
        }
        if (isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            switch ($_POST['action']) {
                case 'reset_password':
                    $np = password_hash('newpassword123', PASSWORD_DEFAULT);
                    $s  = $con->prepare("UPDATE accounts SET password=? WHERE id=?");
                    $s->bind_param("si",$np,$id); $s->execute();
                    $_SESSION['toast'] = ['type'=>'warning','message'=>'Password reset to "newpassword123". User should change it immediately.'];
                    break;
                case 'change_role':
                    $nr = in_array($_POST['new_role'], array_keys($roles)) ? $_POST['new_role'] : 'analyst';
                    $s  = $con->prepare("UPDATE accounts SET role=? WHERE id=? AND username != 'admin'");
                    $s->bind_param("si",$nr,$id); $s->execute();
                    $_SESSION['toast'] = ['type'=>'success','message'=>'Role updated.'];
                    break;
                case 'disable_user':
                    // is_active = 1 means enabled, 0 means disabled
                    $s = $con->prepare("UPDATE accounts SET is_active=0 WHERE id=? AND username != 'admin'");
                    $s->bind_param("i",$id); $s->execute();
                    $_SESSION['toast'] = ['type'=>'warning','message'=>'User disabled.'];
                    break;
                case 'enable_user':
                    $s = $con->prepare("UPDATE accounts SET is_active=1 WHERE id=?");
                    $s->bind_param("i",$id); $s->execute();
                    $_SESSION['toast'] = ['type'=>'success','message'=>'User enabled.'];
                    break;
                case 'delete':
                    $s = $con->prepare("DELETE FROM accounts WHERE id=? AND username != 'admin'");
                    $s->bind_param("i",$id);
                    $_SESSION['toast'] = $s->execute()
                        ? ['type'=>'success','message'=>'User deleted.']
                        : ['type'=>'danger','message'=>'Failed to delete user.'];
                    break;
            }
        }
    }
    header('Location: users.php'); exit;
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Search / filter
$search     = trim($_GET['search'] ?? '');
$roleFilter = isset($_GET['role']) && array_key_exists($_GET['role'], $roles) ? $_GET['role'] : null;

$query = "SELECT id, username, role, email, is_active FROM accounts";
$conds = []; $params = [];
if ($search)     { $conds[] = "(username LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($roleFilter) { $conds[] = "role = ?"; $params[] = $roleFilter; }
if ($conds) $query .= " WHERE ".implode(" AND ",$conds);
$query .= " ORDER BY username";
$stmt = $con->prepare($query);
if ($params) $stmt->bind_param(str_repeat('s',count($params)),...$params);
$stmt->execute();
$users_result = $stmt->get_result();

$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus – User Management</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <style>
        #main-content { transition: margin-left 0.3s ease; }
        .role-badge { padding:.3em .8em; border-radius:20px; font-size:.78rem; font-weight:600; }
        .password-strength { margin-top:4px; height:4px; background:var(--border); border-radius:3px; }
        .password-strength-bar { height:100%; width:0; border-radius:3px; transition:width .3s,background .3s; }
        .toast-container { position:fixed; top:72px; right:20px; z-index:9999; min-width:300px; }

        /* Permission matrix */
        .perm-matrix { font-size:.78rem; }
        .perm-matrix th { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); padding:8px 12px; }
        .perm-matrix td { padding:6px 12px; vertical-align:middle; }
        .perm-matrix .cat-header td { background:rgba(59,130,246,.07); font-weight:700; font-size:.8rem; color:var(--accent); border-top:1px solid var(--border); }
        .perm-check   { color:#22c55e; font-size:1rem; }
        .perm-cross   { color:rgba(150,150,150,.3); font-size:.9rem; }
        .perm-matrix .page-name { font-family:monospace; font-size:.75rem; color:var(--text-muted); }

        /* Role info cards */
        .role-card { border-radius:10px; padding:16px; border:1px solid var(--border); background:var(--card-bg); height:100%; }
        .role-card h6 { font-weight:700; margin-bottom:4px; }
        .role-card small { color:var(--text-muted); }

        /* User table */
        .user-table td { vertical-align:middle; }
        .status-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; }
        .status-dot.active   { background:#22c55e; box-shadow:0 0 4px #22c55e; }
        .status-dot.disabled { background:#6b7280; }

        .form-label { color:var(--text) !important; }
        .nav-tabs .nav-link { color:var(--text-muted); border:none; border-bottom:2px solid transparent; padding:.6rem 1rem; }
        .nav-tabs .nav-link.active { color:var(--accent); border-bottom-color:var(--accent); background:transparent; font-weight:600; }
        .nav-tabs { border-bottom:1px solid var(--border); margin-bottom:20px; }
    </style>
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<!-- Toast -->
<?php if ($toast): ?>
<div class="toast-container">
    <div class="toast show align-items-center text-white bg-<?= $toast['type'] ?> border-0 shadow" role="alert">
        <div class="d-flex">
            <div class="toast-body"><i class="fas fa-<?= $toast['type']==='success'?'check-circle':($toast['type']==='warning'?'exclamation-triangle':'times-circle') ?> me-2"></i><?= htmlspecialchars($toast['message']) ?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="main-content" style="padding:80px 24px 32px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="fas fa-users-cog me-2 text-primary"></i>User Management</h4>
            <small class="text-muted">Manage accounts and review role permissions</small>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" id="userTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-users"><i class="fas fa-users me-1"></i>Users</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-create"><i class="fas fa-user-plus me-1"></i>Create User</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-permissions"><i class="fas fa-shield-alt me-1"></i>Role Permissions</a></li>
    </ul>

    <div class="tab-content">

        <!-- ── Tab 1: Users ─────────────────────────────────────────────────── -->
        <div class="tab-pane fade show active" id="tab-users">

            <!-- Role summary cards -->
            <div class="row g-3 mb-4">
                <?php foreach ($roles as $rk => $rv):
                    $cnt = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) c FROM accounts WHERE role='$rk'"))['c'] ?? 0;
                ?>
                <div class="col-md-4">
                    <div class="role-card">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <h6><span class="badge bg-<?= $rv['color'] ?> role-badge me-2"><?= $rv['label'] ?></span></h6>
                            <span class="fs-4 fw-bold" style="color:var(--accent)"><?= $cnt ?></span>
                        </div>
                        <small><?= $rv['desc'] ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Search & filter -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search username or email…" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="role" class="form-select form-select-sm">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $k => $rv): ?>
                                <option value="<?= $k ?>" <?= $roleFilter===$k?'selected':'' ?>><?= $rv['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary btn-sm w-100">Filter</button>
                        </div>
                        <?php if ($search || $roleFilter): ?>
                        <div class="col-md-2">
                            <a href="users.php" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Users table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list me-1"></i>Accounts (<?= $users_result->num_rows ?>)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 user-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>Username</th><th>Email</th>
                                <th>Role</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($u = $users_result->fetch_assoc()):
                            $ur = $roles[$u['role']] ?? ['label'=>'Unknown','color'=>'secondary','desc'=>''];
                            $isActive = (int)($u['is_active'] ?? 1);
                        ?>
                            <tr>
                                <td class="text-muted"><?= $u['id'] ?></td>
                                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                <td class="text-muted"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                                <td>
                                    <span class="badge bg-<?= $ur['color'] ?> role-badge"><?= $ur['label'] ?></span>
                                    <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px"><?= $ur['desc'] ?></div>
                                </td>
                                <td>
                                    <?php if (!$isActive): ?>
                                        <span class="status-dot disabled"></span><span class="text-muted">Disabled</span>
                                    <?php else: ?>
                                        <span class="status-dot active"></span><span style="color:#22c55e">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                <?php if ($u['username'] !== 'admin'): ?>
                                    <div class="d-flex flex-wrap gap-1">

                                        <!-- Change Role -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <select name="new_role" class="form-select form-select-sm d-inline-block" style="width:auto" onchange="this.form.submit()" title="Change role">
                                                <?php foreach ($roles as $rk => $rv): ?>
                                                <option value="<?= $rk ?>" <?= $u['role']===$rk?'selected':'' ?>><?= $rv['label'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>

                                        <!-- Reset Password -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Reset password for <?= htmlspecialchars($u['username']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button class="btn btn-sm btn-warning" title="Reset password"><i class="fas fa-key"></i></button>
                                        </form>

                                        <!-- Disable / Enable -->
                                        <?php if (!$isActive): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="action" value="enable_user">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button class="btn btn-sm btn-success" title="Enable user"><i class="fas fa-user-check"></i></button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Disable <?= htmlspecialchars($u['username']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="action" value="disable_user">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button class="btn btn-sm btn-secondary" title="Disable user"><i class="fas fa-user-slash"></i></button>
                                        </form>
                                        <?php endif; ?>

                                        <!-- Delete -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete <?= htmlspecialchars($u['username']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button class="btn btn-sm btn-danger" title="Delete user"><i class="fas fa-trash"></i></button>
                                        </form>

                                    </div>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-lock me-1"></i>Protected</span>
                                <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Tab 2: Create User ────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="tab-create">
            <div class="card" style="max-width:560px;">
                <div class="card-header"><i class="fas fa-user-plus me-2"></i>Create New Account</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="newPassword" class="form-control" required autocomplete="new-password">
                            <div class="password-strength mt-1"><div class="password-strength-bar" id="strengthBar"></div></div>
                            <small class="text-muted">Min 8 chars, mix upper/lower/numbers/symbols</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <?php foreach ($roles as $k => $rv): ?>
                                <option value="<?= $k ?>"><?= $rv['label'] ?> — <?= $rv['desc'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Role determines which pages the user can access. See the Role Permissions tab.</small>
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-1"></i>Create User</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Tab 3: Role Permissions Matrix ───────────────────────────────── -->
        <div class="tab-pane fade" id="tab-permissions">
            <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                <div><span class="perm-check me-1">✔</span> = Role has access</div>
                <div><span class="perm-cross me-1">—</span> = No access</div>
                <div class="ms-auto">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Permissions are defined in <code>auth_check.php</code>.
                        To change access, edit that file and redeploy.
                    </small>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm perm-matrix mb-0">
                        <thead>
                            <tr>
                                <th style="width:40%">Page / Feature</th>
                                <?php foreach ($roles as $rk => $rv): ?>
                                <th class="text-center">
                                    <span class="badge bg-<?= $rv['color'] ?>"><?= $rv['label'] ?></span>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($permission_categories as $cat => $pages): ?>
                            <tr class="cat-header">
                                <td colspan="<?= 1 + count($roles) ?>">
                                    <i class="fas fa-folder-open me-1"></i><?= $cat ?>
                                </td>
                            </tr>
                            <?php foreach ($pages as $page => $allowed): ?>
                            <tr>
                                <td><span class="page-name"><?= htmlspecialchars($page) ?></span></td>
                                <?php foreach ($roles as $rk => $rv): ?>
                                <td class="text-center">
                                    <?php if (in_array($rk, $allowed, true)): ?>
                                        <span class="perm-check" title="<?= $rv['label'] ?> can access">✔</span>
                                    <?php else: ?>
                                        <span class="perm-cross">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="alert mt-3" style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;">
                <h6 class="mb-1"><i class="fas fa-code me-1 text-primary"></i>Adding a new page to the system</h6>
                <ol class="mb-0 ps-3" style="font-size:.85rem;color:var(--text-muted);">
                    <li>Add one line at the top of the new PHP file:<br><code>require_once '/var/www/janus/auth_check.php';</code></li>
                    <li>Add the filename + allowed roles to <code>$PAGE_PERMISSIONS</code> in <code>auth_check.php</code></li>
                    <li>Add the sidebar link inside the correct role block in <code>sidebar.php</code></li>
                    <li>Done — access is enforced automatically.</li>
                </ol>
            </div>
        </div>

    </div><!-- end tab-content -->
</div>

<script src="/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Sidebar margin sync
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('main-content');
    const adjust  = () => content.style.marginLeft = sidebar?.classList.contains('collapsed') ? '80px' : '250px';
    adjust();
    sidebar && new MutationObserver(adjust).observe(sidebar, {attributes:true, attributeFilter:['class']});

    // Password strength
    const pw  = document.getElementById('newPassword');
    const bar = document.getElementById('strengthBar');
    if (pw && bar) {
        pw.addEventListener('input', () => {
            let s = 0, v = pw.value;
            if (v.length >= 1)  s += 10;
            if (v.length >= 8)  s += 15;
            if (v.length >= 12) s += 15;
            if (/[A-Z]/.test(v))       s += 15;
            if (/[0-9]/.test(v))       s += 15;
            if (/[^A-Za-z0-9]/.test(v)) s += 30;
            bar.style.width      = Math.min(s, 100) + '%';
            bar.style.background = s < 30 ? '#dc3545' : s < 70 ? '#ffc107' : '#22c55e';
        });
    }

    // Auto-dismiss toasts
    document.querySelectorAll('.toast').forEach(t => {
        new bootstrap.Toast(t, {delay:4000}).show();
    });

    // If URL has #create, switch to create tab
    if (location.hash === '#create') {
        document.querySelector('[href="#tab-create"]')?.click();
    }
});
</script>
</body>
</html>
