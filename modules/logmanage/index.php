<?php
// modules/logmanage/index.php - Janus Log Management
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../auth_check.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die('Database connection failed: ' . htmlspecialchars(mysqli_connect_error()));
}

// Create tables if needed
mysqli_query($con, "
CREATE TABLE IF NOT EXISTS syslog_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appliance_type VARCHAR(100) NOT NULL,
    source_ip VARCHAR(45) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    added_by INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

mysqli_query($con, "
CREATE TABLE IF NOT EXISTS syslog_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NULL,
    appliance_type VARCHAR(100) NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    message TEXT NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (source_ip),
    INDEX (received_at),
    FOREIGN KEY (source_id) REFERENCES syslog_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Get current retention setting
$retention_hours = 168;
$res = mysqli_query($con, "SELECT `value` FROM system_config WHERE `key` = 'archive_retention_hours'");
if ($row = mysqli_fetch_assoc($res)) {
    $retention_hours = intval($row['value']);
}

// Handle Save Retention Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retention'])) {
    $new_hours = intval($_POST['retention_hours'] ?? 168);
    if ($new_hours < 1 || $new_hours > 8760) {
        $_SESSION['message'] = 'error: Hours must be between 1 and 8760';
    } else {
        $stmt = mysqli_prepare($con, "UPDATE system_config SET `value` = ? WHERE `key` = 'archive_retention_hours'");
        mysqli_stmt_bind_param($stmt, 'i', $new_hours);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['message'] = "retention_saved: Retention policy set to $new_hours hours";
            $retention_hours = $new_hours;
        } else {
            $_SESSION['message'] = 'error: Failed to save settings';
        }
        mysqli_stmt_close($stmt);
    }
    header('Location: index.php'); exit;
}

// Handle Archive Cleanup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_archive'])) {
    $hours = intval($_POST['cleanup_hours'] ?? $retention_hours);
    if ($hours < 1) {
        $_SESSION['message'] = 'error: Invalid hours value';
        header('Location: index.php'); exit;
    }

    $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

    // Check if archive table exists
    $check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
    if (mysqli_num_rows($check) > 0) {
        $count_stmt = mysqli_prepare($con, "SELECT COUNT(*) FROM syslog_entries_archive WHERE received_at < ?");
        mysqli_stmt_bind_param($count_stmt, 's', $cutoff);
        mysqli_stmt_execute($count_stmt);
        mysqli_stmt_bind_result($count_stmt, $count_to_delete);
        mysqli_stmt_fetch($count_stmt);
        mysqli_stmt_close($count_stmt);

        if ($count_to_delete > 0) {
            $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries_archive WHERE received_at < ?");
            mysqli_stmt_bind_param($stmt, 's', $cutoff);
            if (mysqli_stmt_execute($stmt)) {
                $deleted = mysqli_stmt_affected_rows($stmt);
                if ($deleted > 100) {
                    mysqli_query($con, "OPTIMIZE TABLE syslog_entries_archive");
                }
                $_SESSION['message'] = "archive_cleaned: Successfully deleted " . number_format($deleted) . " old records (older than $hours hours)";
            } else {
                $_SESSION['message'] = 'error: Delete operation failed - ' . mysqli_error($con);
            }
            mysqli_stmt_close($stmt);
        } else {
            $_SESSION['message'] = "archive_cleaned: No records older than $hours hours found";
        }
    } else {
        $_SESSION['message'] = 'error: Archive table not found';
    }
    header('Location: index.php'); exit;
}

// Handle Add Source
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_source'])) {
    $appliance_type = trim($_POST['appliance_type'] ?? '');
    $other_type = trim($_POST['other_type'] ?? '');
    $source_ip = trim($_POST['source_ip'] ?? '');
    $added_by = intval($_SESSION['id']);

    if ($appliance_type === '') {
        $_SESSION['message'] = 'error: Appliance type required';
        header('Location: index.php'); exit;
    }

    if ($appliance_type === 'other') {
        if ($other_type === '') {
            $_SESSION['message'] = 'error: Please specify appliance type';
            header('Location: index.php'); exit;
        }
        $appliance_type = $other_type;
    }

    if (!filter_var($source_ip, FILTER_VALIDATE_IP)) {
        $_SESSION['message'] = 'error: Invalid IP address';
        header('Location: index.php'); exit;
    }

    $stmt = mysqli_prepare($con, "INSERT INTO syslog_sources (appliance_type, source_ip, added_by) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssi', $appliance_type, $source_ip, $added_by);
    $ok = @mysqli_stmt_execute($stmt);
    if (!$ok) {
        $err = mysqli_error($con);
        if (stripos($err, 'Duplicate') !== false) {
            $_SESSION['message'] = 'error: Source already exists';
        } else {
            $_SESSION['message'] = 'error: DB error adding source';
        }
    } else {
        $_SESSION['message'] = 'source_added';
    }
    mysqli_stmt_close($stmt);
    header('Location: index.php');
    exit;
}

// Handle delete source
if (isset($_GET['delete_source']) && is_numeric($_GET['delete_source'])) {
    $sid = intval($_GET['delete_source']);
    $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries WHERE source_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $sid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($con, "DELETE FROM syslog_sources WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $sid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $_SESSION['message'] = 'source_deleted';
    header('Location: index.php');
    exit;
}

// Handle toggle
if (isset($_GET['toggle_source']) && is_numeric($_GET['toggle_source'])) {
    $sid = intval($_GET['toggle_source']);
    $stmt = mysqli_prepare($con, "SELECT is_active FROM syslog_sources WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $sid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $cur_active);
    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'error: Unknown source';
        header('Location: index.php');
        exit;
    }
    mysqli_stmt_close($stmt);
    $new = $cur_active ? 0 : 1;
    $stmt = mysqli_prepare($con, "UPDATE syslog_sources SET is_active = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $new, $sid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION['message'] = $new ? 'source_unblocked' : 'source_blocked';
    header('Location: index.php');
    exit;
}

// Handle purge logs
if (isset($_GET['purge_logs']) && is_numeric($_GET['purge_logs'])) {
    $sid = intval($_GET['purge_logs']);
    $stmt = mysqli_prepare($con, "DELETE FROM syslog_entries WHERE source_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $sid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION['message'] = 'logs_purged';
    header('Location: index.php');
    exit;
}

// Fetch sources
$sources = [];
$res = mysqli_query($con, "
    SELECT ss.*, a.username
    FROM syslog_sources ss
    LEFT JOIN accounts a ON ss.added_by = a.id
    ORDER BY ss.added_at DESC
");
while ($r = mysqli_fetch_assoc($res)) $sources[] = $r;

// Fetch recent logs
$logs = [];
$res = mysqli_query($con, "
    SELECT se.*, ss.appliance_type AS source_type
    FROM syslog_entries se
    LEFT JOIN syslog_sources ss ON se.source_id = ss.id
    ORDER BY se.received_at DESC
    LIMIT 200
");
while ($r = mysqli_fetch_assoc($res)) $logs[] = $r;

// Stats
$stats = [];
$res = mysqli_query($con, "
    SELECT appliance_type, COUNT(*) as cnt, MAX(received_at) as last_received
    FROM syslog_entries
    GROUP BY appliance_type
");
while ($r = mysqli_fetch_assoc($res)) $stats[$r['appliance_type']] = $r;

// Archive stats
$archive_count = 0;
$archive_oldest = null;
$archive_newest = null;
$check = mysqli_query($con, "SHOW TABLES LIKE 'syslog_entries_archive'");
if (mysqli_num_rows($check) > 0) {
    $res = mysqli_query($con, "SELECT COUNT(*) as cnt, MIN(received_at) as oldest, MAX(received_at) as newest FROM syslog_entries_archive");
    if ($row = mysqli_fetch_assoc($res)) {
        $archive_count = $row['cnt'];
        $archive_oldest = $row['oldest'];
        $archive_newest = $row['newest'];
    }
}

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Log Management · Janus</title>
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
<style>
:root {
    --card-bg: rgba(10,12,20,0.7);
    --glass: rgba(255,255,255,0.03);
    --accent: #00c6ff;
    --text-primary: #e8eef7;
    --text-muted: #9aa6b2;
    --border: rgba(255,255,255,0.08);
    --input-bg: rgba(0,0,0,0.3);
    --input-border: rgba(255,255,255,0.15);
}
[data-theme="light"] {
    --card-bg: #ffffff;
    --glass: #f8f9fa;
    --accent: #2563eb;
    --text-primary: #1f2937;
    --text-muted: #6b7280;
    --border: #e5e7eb;
    --input-bg: #f9fafb;
    --input-border: #d1d5db;
}
body.loggedin { background: linear-gradient(180deg, #041025 0%, #071028 60%); color: var(--text-primary); min-height: 100vh; }
[data-theme="light"] body { background: #f5f8fb; color: var(--text-primary); }
.container-fluid { padding-top: 78px; }
#main-content { margin-left: 260px; transition: margin-left .25s ease; padding: 20px 24px; }
.sidebar.collapsed ~ #main-content { margin-left: 78px; }
.panel-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); margin-bottom: 20px; }
[data-theme="light"] .panel-card { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.type-badge { padding: 6px 10px; border-radius: 999px; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.03em; display: inline-block; }
.badge-active { background: #10b981; color: #fff; }
.badge-block { background: #ef4444; color: #fff; }
.source-card { padding: 12px; border-radius: 8px; background: var(--glass); border: 1px solid var(--border); margin-bottom: 12px; }
.log-card { background: var(--input-bg); padding: 10px; border-radius: 6px; margin-bottom: 10px; font-family: monospace; font-size: 0.85rem; color: var(--text-primary); border: 1px solid var(--border); }
.header-row h2 { font-weight: 700; letter-spacing: 0.02em; color: var(--accent); }
.text-muted { color: var(--text-muted) !important; }
.archive-panel { background: var(--card-bg); border: 2px solid var(--accent); border-radius: 12px; padding: 20px; }
.stat-box { background: var(--input-bg); border: 1px solid var(--border); border-radius: 8px; padding: 15px; text-align: center; }
.stat-box .stat-value { font-size: 2rem; font-weight: 700; color: var(--accent); }
.stat-box .stat-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.form-control, .form-select { background: var(--input-bg); border: 1px solid var(--input-border); color: var(--text-primary); }
.form-control:focus, .form-select:focus { background: var(--input-bg); border-color: var(--accent); color: var(--text-primary); box-shadow: 0 0 0 0.2rem rgba(0, 198, 255, 0.15); }
[data-theme="light"] .form-control:focus, [data-theme="light"] .form-select:focus { box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15); }
.form-label { color: var(--text-primary); }
.form-text { color: var(--text-muted) !important; }
.btn { color: var(--text-primary); }
.btn-primary { background: var(--accent); border-color: var(--accent); color: #ffffff !important; }
.btn-outline-light { border-color: var(--border); color: var(--text-primary); }
.btn-outline-light:hover { background: var(--input-bg); color: var(--text-primary); }
.btn-outline-secondary { border-color: var(--border); color: var(--text-muted); }
.btn-outline-secondary:hover { background: var(--input-bg); color: var(--text-primary); }
.settings-section { background: var(--glass); border: 1px dashed var(--border); border-radius: 8px; padding: 15px; margin-bottom: 15px; }
.settings-section h6 { color: var(--text-primary); margin-bottom: 10px; }
@media (max-width: 992px) { #main-content { margin-left: 78px; } }
</style>
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>
<div id="main-content">
    <div class="container-fluid">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert <?= strpos($_SESSION['message'],'error')===0 ? 'alert-danger' : 'alert-success' ?> alert-dismissible fade show" role="alert">
                <?php
                    $m = $_SESSION['message'];
                    if (strpos($m,'error:')===0) echo htmlspecialchars(substr($m,6));
                    else if ($m === 'source_added') echo 'Source added successfully.';
                    else if ($m === 'source_deleted') echo 'Source deleted and its logs removed.';
                    else if ($m === 'source_blocked') echo 'Source blocked (no future logs accepted).';
                    else if ($m === 'source_unblocked') echo 'Source unblocked.';
                    else if ($m === 'logs_purged') echo 'Logs purged for source.';
                    else if (strpos($m,'archive_cleaned:')===0) echo substr($m,16);
                    else if (strpos($m,'retention_saved:')===0) echo substr($m,16);
                    else echo htmlspecialchars($m);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php unset($_SESSION['message']); endif; ?>

        <div class="row header-row mb-3">
            <div class="col-8">
                <h2><i class="fas fa-server"></i> Log Management</h2>
                <p class="text-muted">Manage syslog sources and configure automatic archive cleanup</p>
            </div>
        </div>

        <!-- Archive Management Panel -->
        <div class="panel-card archive-panel">
            <div class="row align-items-center mb-3">
                <div class="col-md-8">
                    <h5><i class="fas fa-database"></i> Archive Management</h5>
                    <p class="mb-0 small text-muted">Configure retention policy and clean old archived logs</p>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($archive_count) ?></div>
                        <div class="stat-label">Archived Records</div>
                    </div>
                </div>
            </div>
            <?php if ($archive_count > 0): ?>
            <div class="row mb-3">
                <div class="col-md-6">
                    <small class="text-muted"><i class="fas fa-clock"></i> Oldest: <?= $archive_oldest ? date('M d, Y H:i', strtotime($archive_oldest)) : 'N/A' ?></small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted"><i class="fas fa-clock"></i> Newest: <?= $archive_newest ? date('M d, Y H:i', strtotime($archive_newest)) : 'N/A' ?></small>
                </div>
            </div>
            <?php endif; ?>

            <!-- Settings Section -->
            <div class="settings-section">
                <h6><i class="fas fa-cog"></i> Default Retention Policy</h6>
                <form method="post">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label small">Default retention period (hours)</label>
                            <input type="number" name="retention_hours" class="form-control" value="<?= $retention_hours ?>" min="1" max="8760" required>
                            <small class="form-text">Current setting: <?= $retention_hours ?> hours (<?= round($retention_hours/24, 1) ?> days)</small>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" name="save_retention" class="btn btn-success"><i class="fas fa-save"></i> Save Settings</button>
                            <button type="button" class="btn btn-outline-secondary ms-2" onclick="showCleanupInfo()"><i class="fas fa-info-circle"></i> Info</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Cleanup Section -->
            <div class="settings-section">
                <h6><i class="fas fa-trash-alt"></i> Manual Cleanup</h6>
                <form method="post" onsubmit="return confirm('Delete all archive records older than the specified hours? This action cannot be undone.');">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label small">Delete records older than (hours)</label>
                            <input type="number" name="cleanup_hours" class="form-control" value="<?= $retention_hours ?>" min="1" max="8760" required>
                            <small class="form-text">Records older than this will be permanently deleted</small>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" name="cleanup_archive" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Clean Archive Now</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-5">
                <div class="panel-card">
                    <h5>Add Allowed Syslog Source</h5>
                    <form method="post" class="mt-3">
                        <div class="mb-2">
                            <label class="form-label small">Appliance Type</label>
                            <select name="appliance_type" id="appliance_type" class="form-select" required>
                                <option value="">-- Select Type --</option>
                                <option value="ngfw">NGFW (Firewall)</option>
                                <option value="switch">Switch</option>
                                <option value="router">Router</option>
                                <option value="edr">EDR</option>
                                <option value="xdr">XDR</option>
                                <option value="other">Other (specify)</option>
                            </select>
                        </div>
                        <div class="mb-2" id="otherTypeRow" style="display:none;">
                            <label class="form-label small">Specify Type</label>
                            <input type="text" name="other_type" class="form-control" placeholder="e.g., Palo Alto Panorama">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Source IP</label>
                            <input type="text" name="source_ip" class="form-control" placeholder="e.g., 192.168.1.100" required>
                            <div class="form-text small">Only IPv4/IPv6 addresses allowed.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" name="add_source" type="submit"><i class="fas fa-plus-circle"></i> Allow Source</button>
                            <button class="btn btn-outline-secondary" type="reset">Reset</button>
                        </div>
                    </form>
                    <hr>
                    <h6 class="mt-2">Allowed Sources (<?= count($sources) ?>)</h6>
                    <?php if (empty($sources)): ?>
                        <div class="text-muted small">No sources allowed yet.</div>
                    <?php else: ?>
                        <div style="max-height:320px; overflow:auto; margin-top:8px;">
                        <?php foreach ($sources as $s): ?>
                            <div class="source-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div>
                                        <span class="type-badge"><?= htmlspecialchars(strtoupper($s['appliance_type'])) ?></span>
                                        <small class="text-muted ms-2"><?= htmlspecialchars($s['username'] ?? 'system') ?></small>
                                    </div>
                                    <div>
                                        <span class="<?= $s['is_active'] ? 'badge-active py-1 px-2' : 'badge-block py-1 px-2' ?>">
                                            <?= $s['is_active'] ? 'ACTIVE' : 'BLOCKED' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong style="color: var(--text-primary);"><?= htmlspecialchars($s['source_ip']) ?></strong>
                                        <div class="small text-muted">Added: <?= date('M d, Y H:i', strtotime($s['added_at'])) ?></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <a href="?toggle_source=<?= $s['id'] ?>" class="btn btn-sm btn-outline-light" title="<?= $s['is_active'] ? 'Block' : 'Unblock' ?>">
                                            <?= $s['is_active'] ? '<i class="fas fa-ban"></i>' : '<i class="fas fa-check"></i>' ?>
                                        </a>
                                        <a href="?purge_logs=<?= $s['id'] ?>" class="btn btn-sm btn-warning" onclick="return confirm('Purge ALL logs for <?= htmlspecialchars($s['source_ip']) ?>?')" title="Purge Logs">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                        <a href="?delete_source=<?= $s['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete source and purge logs? This cannot be undone.')" title="Delete Source">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="panel-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0"><i class="fas fa-terminal"></i> Recent Logs</h5>
                        <div>
                            <button id="refreshBtn" class="btn btn-sm btn-outline-light"><i class="fas fa-sync"></i> Refresh</button>
                            <button id="clearView" class="btn btn-sm btn-outline-secondary">Clear View</button>
                        </div>
                    </div>
                    <div id="logViewer" style="max-height:520px; overflow:auto; padding:6px; border-radius:8px; background:var(--input-bg);">
                        <?php if (empty($logs)): ?>
                            <div class="text-muted">No logs yet.</div>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <div class="log-card">
                                    <div class="d-flex justify-content-between">
                                        <div><span class="type-badge"><?= htmlspecialchars(strtoupper($l['appliance_type'] ?: $l['source_type'] ?: 'OTHER')) ?></span>
                                            <strong style="color: var(--text-primary);" class="ms-2"><?= htmlspecialchars($l['source_ip']) ?></strong>
                                        </div>
                                        <div class="small text-muted"><?= date('M d H:i:s', strtotime($l['received_at'])) ?></div>
                                    </div>
                                    <div class="mt-1 small" style="color: var(--text-primary);"><?= nl2br(htmlspecialchars(substr($l['message'], 0, 200))) ?><?= strlen($l['message']) > 200 ? '...' : '' ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats row -->
        <div class="row g-3">
            <?php foreach ($stats as $atype => $st): ?>
                <div class="col-md-3">
                    <div class="panel-card">
                        <div class="d-flex justify-content-between">
                            <div><strong style="color: var(--text-primary);"><?= htmlspecialchars(strtoupper($atype)) ?></strong></div>
                            <div class="small text-muted"><?= number_format($st['cnt']) ?> logs</div>
                        </div>
                        <div class="mt-2 small text-muted">Last: <?= $st['last_received'] ? date('M d, H:i', strtotime($st['last_received'])) : '—' ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Info Modal -->
<div class="modal fade" id="cleanupInfoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);color:var(--text-primary);border:1px solid var(--border);">
            <div class="modal-header" style="border-bottom:1px solid var(--border);">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Archive Cleanup Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6>How it works:</h6>
                <ul>
                    <li>The system automatically archives old syslog entries to the <code>syslog_entries_archive</code> table every 10 minutes via cron.</li>
                    <li>Only the most recent 5,000 records are kept in the live table for fast performance.</li>
                    <li><strong>Default Retention:</strong> Set how long to keep archive data (used by automated cleanup).</li>
                    <li><strong>Manual Cleanup:</strong> Immediately delete old records using custom hours.</li>
                </ul>
                <h6>Retention Recommendations:</h6>
                <ul>
                    <li><strong>72 hours (3 days):</strong> Minimal storage, fast queries</li>
                    <li><strong>168 hours (7 days):</strong> Balanced for most use cases</li>
                    <li><strong>720 hours (30 days):</strong> Compliance requirements</li>
                    <li><strong>2160 hours (90 days):</strong> Extended audit trail</li>
                </ul>
                <h6>Automation:</h6>
                <p class="mb-0">To automate cleanup, add this to your crontab:</p>
                <pre class="bg-dark text-light p-2 rounded mt-2 small">0 2 * * * /usr/bin/php /var/www/janus/modules/logmanage/cleanup_archive.php >> /var/log/archive_cleanup.log 2>&1</pre>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('appliance_type').addEventListener('change', function(){
    document.getElementById('otherTypeRow').style.display = (this.value === 'other') ? 'block' : 'none';
});
document.getElementById('refreshBtn').addEventListener('click', function(){
    location.reload();
});
document.getElementById('clearView').addEventListener('click', function(){
    document.getElementById('logViewer').innerHTML = '<div class="text-muted">View cleared. Click Refresh to reload.</div>';
});
function showCleanupInfo() {
    new bootstrap.Modal(document.getElementById('cleanupInfoModal')).show();
}
</script>
</body>
</html>
