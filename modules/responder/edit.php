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
$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$playbook = null;

if ($editId > 0) {
    $stmt = $con->prepare("SELECT * FROM responder_playbooks WHERE id = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $playbook = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$playbook) { header('Location: index.php'); exit; }
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $name = mysqli_real_escape_string($con, $_POST['name']);
    $desc = mysqli_real_escape_string($con, $_POST['description'] ?? '');
    $trigger = mysqli_real_escape_string($con, $_POST['trigger_type'] ?? 'manual');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $priority = intval($_POST['priority'] ?? 5);
    $timeout = intval($_POST['timeout'] ?? 300);
    $retry = intval($_POST['retry'] ?? 3);

    // Build actions from form
    $actions = [];
    $types = $_POST['action_type'] ?? [];
    foreach ($types as $i => $type) {
        $action = ['type' => $type];
        if ($type === 'block_ip') {
            $action['ip'] = $_POST['action_ip'][$i] ?? '';
            $action['reason'] = $_POST['action_reason'][$i] ?? '';
            $action['duration'] = intval($_POST['action_duration'][$i] ?? 0) ?: null;
        } elseif ($type === 'create_incident') {
            $action['title'] = $_POST['action_title'][$i] ?? '';
            $action['severity'] = $_POST['action_severity'][$i] ?? 'medium';
            $action['description'] = $_POST['action_desc'][$i] ?? '';
        } elseif ($type === 'send_webhook') {
            $action['url'] = $_POST['action_url'][$i] ?? '';
        } elseif ($type === 'delay') {
            $action['seconds'] = intval($_POST['action_seconds'][$i] ?? 5);
        }
        $action['on_error'] = $_POST['action_on_error'][$i] ?? 'continue';
        $actions[] = $action;
    }

    $actionsJson = json_encode($actions);

    if ($editId > 0) {
        $stmt = $con->prepare(
            "UPDATE responder_playbooks SET name=?, description=?, trigger_type=?, enabled=?, priority=?, timeout_seconds=?, retry_count=?, actions=? WHERE id=?"
        );
        $stmt->bind_param('sssiiiisi', $name, $desc, $trigger, $enabled, $priority, $timeout, $retry, $actionsJson, $editId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $con->prepare(
            "INSERT INTO responder_playbooks (name, description, trigger_type, enabled, priority, timeout_seconds, retry_count, actions, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $uid = $_SESSION['id'];
        $stmt->bind_param('sssiiiisi', $name, $desc, $trigger, $enabled, $priority, $timeout, $retry, $actionsJson, $uid);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: index.php');
    exit;
}

// Decode actions for edit
$existingActions = [];
if ($playbook) {
    $existingActions = json_decode($playbook['actions'] ?? '[]', true);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Janus :: <?= $editId ? 'Edit' : 'Create' ?> Playbook</title>
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

<div class="page-header">
    <div class="eyebrow"><i class="fas fa-bolt"></i> JANUS / PLAYBOOKS</div>
    <h1 class="page-title"><span class="accent">&gt;</span> <?= $editId ? 'Edit' : 'Create' ?> Playbook</h1>
</div>

<form method="post" id="playbookForm">
<div class="row">
    <div class="col-md-8">

        <!-- Basic Details -->
        <div class="form-card">
            <h5><i class="fas fa-cog"></i> Basic Details</h5>
            <div class="grid-2">
                <div>
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= htmlspecialchars($playbook['name'] ?? '') ?>" placeholder="e.g., Auto-block Malicious IPs">
                </div>
                <div>
                    <label class="form-label">Trigger Type</label>
                    <select name="trigger_type" class="form-select">
                        <option value="manual" <?= ($playbook['trigger_type'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                        <option value="incident_created" <?= ($playbook['trigger_type'] ?? '') === 'incident_created' ? 'selected' : '' ?>>On Incident Created</option>
                        <option value="ip_blocked" <?= ($playbook['trigger_type'] ?? '') === 'ip_blocked' ? 'selected' : '' ?>>On IP Blocked</option>
                        <option value="webhook" <?= ($playbook['trigger_type'] ?? '') === 'webhook' ? 'selected' : '' ?>>Webhook</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2" placeholder="What does this playbook do?"><?= htmlspecialchars($playbook['description'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Actions Builder -->
        <div class="form-card">
            <h5><i class="fas fa-tasks"></i> Actions</h5>
            <div id="actionsContainer">
                <?php if (!empty($existingActions)): ?>
                    <?php foreach ($existingActions as $i => $action): ?>
                    <div class="action-row" data-index="<?= $i ?>">
                        <button type="button" class="remove-btn btn-ghost" style="padding:2px 8px;font-size:0.7rem;" onclick="removeAction(this)"><i class="fas fa-times" style="color:var(--red);"></i></button>
                        <div class="grid-3">
                            <div>
                                <label class="form-label">Action Type</label>
                                <select name="action_type[]" class="form-select action-type" onchange="toggleActionFields(this)">
                                    <option value="block_ip" <?= ($action['type'] ?? '') === 'block_ip' ? 'selected' : '' ?>>Block IP Address</option>
                                    <option value="unblock_ip" <?= ($action['type'] ?? '') === 'unblock_ip' ? 'selected' : '' ?>>Unblock IP Address</option>
                                    <option value="create_incident" <?= ($action['type'] ?? '') === 'create_incident' ? 'selected' : '' ?>>Create Incident</option>
                                    <option value="send_webhook" <?= ($action['type'] ?? '') === 'send_webhook' ? 'selected' : '' ?>>Send Webhook</option>
                                    <option value="send_notification" <?= ($action['type'] ?? '') === 'send_notification' ? 'selected' : '' ?>>Send Notification</option>
                                    <option value="delay" <?= ($action['type'] ?? '') === 'delay' ? 'selected' : '' ?>>Delay</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">On Error</label>
                                <select name="action_on_error[]" class="form-select">
                                    <option value="continue" <?= ($action['on_error'] ?? 'continue') === 'continue' ? 'selected' : '' ?>>Continue</option>
                                    <option value="stop" <?= ($action['on_error'] ?? '') === 'stop' ? 'selected' : '' ?>>Stop Playbook</option>
                                </select>
                            </div>
                        </div>
                        <div class="action-fields mt-2">
                            <?php if (($action['type'] ?? '') === 'block_ip'): ?>
                            <div class="grid-2">
                                <input type="text" name="action_ip[]" class="form-control" placeholder="IP to block (e.g., 10.0.0.1)" value="<?= htmlspecialchars($action['ip'] ?? '') ?>">
                                <input type="text" name="action_reason[]" class="form-control" placeholder="Block reason" value="<?= htmlspecialchars($action['reason'] ?? '') ?>">
                                <input type="number" name="action_duration[]" class="form-control" placeholder="Duration (seconds, 0=permanent)" value="<?= htmlspecialchars($action['duration'] ?? '') ?>">
                            </div>
                            <?php elseif (($action['type'] ?? '') === 'create_incident'): ?>
                            <div class="grid-2">
                                <input type="text" name="action_title[]" class="form-control" placeholder="Incident title" value="<?= htmlspecialchars($action['title'] ?? '') ?>">
                                <select name="action_severity[]" class="form-select">
                                    <option value="low" <?= ($action['severity'] ?? '') === 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= ($action['severity'] ?? '') === 'medium' ? 'selected' : '' ?> selected>Medium</option>
                                    <option value="high" <?= ($action['severity'] ?? '') === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= ($action['severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                                <div class="grid-2" style="grid-column:1/-1;">
                                    <textarea name="action_desc[]" class="form-control" rows="1" placeholder="Description"><?= htmlspecialchars($action['description'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <?php elseif (($action['type'] ?? '') === 'send_webhook'): ?>
                            <input type="url" name="action_url[]" class="form-control" placeholder="Webhook URL" value="<?= htmlspecialchars($action['url'] ?? '') ?>">
                            <?php elseif (($action['type'] ?? '') === 'delay'): ?>
                            <input type="number" name="action_seconds[]" class="form-control" placeholder="Seconds (max 300)" value="<?= htmlspecialchars($action['seconds'] ?? '5') ?>" min="1" max="300">
                            <?php else: ?>
                            <input type="hidden" name="action_ip[]" value="">
                            <input type="hidden" name="action_reason[]" value="">
                            <input type="hidden" name="action_duration[]" value="">
                            <input type="hidden" name="action_title[]" value="">
                            <input type="hidden" name="action_severity[]" value="">
                            <input type="hidden" name="action_desc[]" value="">
                            <input type="hidden" name="action_url[]" value="">
                            <input type="hidden" name="action_seconds[]" value="">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-ghost mt-2" onclick="addAction()"><i class="fas fa-plus"></i> Add Action</button>
        </div>

    </div>

    <div class="col-md-4">
        <!-- Settings -->
        <div class="form-card">
            <h5><i class="fas fa-sliders-h"></i> Settings</h5>
            <div class="mb-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <?php for ($p = 1; $p <= 10; $p++): ?>
                    <option value="<?= $p ?>" <?= intval($playbook['priority'] ?? 5) === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Timeout (seconds)</label>
                <input type="number" name="timeout" class="form-control" value="<?= intval($playbook['timeout_seconds'] ?? 300) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Retry Count</label>
                <input type="number" name="retry" class="form-control" value="<?= intval($playbook['retry_count'] ?? 3) ?>" min="0" max="10">
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="enabled" class="form-check-input" id="enabled" value="1" <?= ($playbook['enabled'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="enabled" style="color:var(--text-mid);font-size:0.85rem;">Enabled</label>
            </div>
        </div>

        <!-- Info -->
        <div class="form-card">
            <h5><i class="fas fa-info-circle"></i> Info</h5>
            <div style="font-size:0.85rem;color:var(--text-mid);">
                <p><i class="fas fa-shield-alt" style="color:var(--cyan);width:20px;"></i> Actions run sequentially</p>
                <p><i class="fas fa-stopwatch" style="color:var(--amber);width:20px;"></i> Max 300s per action</p>
                <p><i class="fas fa-exclamation-triangle" style="color:var(--red);width:20px;"></i> Test playbooks manually first</p>
            </div>
        </div>

        <button type="submit" name="save" class="btn-primary-custom w-100 mt-2"><i class="fas fa-save"></i> <?= $editId ? 'Update' : 'Create' ?> Playbook</button>
        <a href="index.php" class="btn-ghost w-100 mt-2 text-center d-block"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>
</form>

</div>
</div>

<script src="../../js/bootstrap.bundle.min.js"></script>
<script>
function addAction() {
    var container = document.getElementById('actionsContainer');
    var idx = container.children.length;
    var div = document.createElement('div');
    div.className = 'action-row';
    div.dataset.index = idx;
    div.innerHTML = `
        <button type="button" class="remove-btn btn-ghost" style="padding:2px 8px;font-size:0.7rem;" onclick="removeAction(this)"><i class="fas fa-times" style="color:var(--red);"></i></button>
        <div class="grid-3">
            <div>
                <label class="form-label">Action Type</label>
                <select name="action_type[]" class="form-select action-type" onchange="toggleActionFields(this)">
                    <option value="block_ip">Block IP Address</option>
                    <option value="unblock_ip">Unblock IP Address</option>
                    <option value="create_incident">Create Incident</option>
                    <option value="send_webhook">Send Webhook</option>
                    <option value="send_notification">Send Notification</option>
                    <option value="delay">Delay</option>
                </select>
            </div>
            <div>
                <label class="form-label">On Error</label>
                <select name="action_on_error[]" class="form-select">
                    <option value="continue">Continue</option>
                    <option value="stop">Stop Playbook</option>
                </select>
            </div>
        </div>
        <div class="action-fields mt-2">
            <div class="grid-2">
                <input type="text" name="action_ip[]" class="form-control" placeholder="IP to block">
                <input type="text" name="action_reason[]" class="form-control" placeholder="Block reason">
                <input type="number" name="action_duration[]" class="form-control" placeholder="Duration (seconds, 0=permanent)">
            </div>
        </div>
    `;
    container.appendChild(div);
}

function removeAction(btn) {
    if (document.querySelectorAll('.action-row').length <= 1) return;
    btn.closest('.action-row').remove();
}

function toggleActionFields(select) {
    var row = select.closest('.action-row');
    var fields = row.querySelector('.action-fields');
    var val = select.value;
    var html = '';

    if (val === 'block_ip') {
        html = '<div class="grid-2">' +
            '<input type="text" name="action_ip[]" class="form-control" placeholder="IP to block">' +
            '<input type="text" name="action_reason[]" class="form-control" placeholder="Block reason">' +
            '<input type="number" name="action_duration[]" class="form-control" placeholder="Duration (seconds, 0=permanent)">' +
            '</div>';
    } else if (val === 'create_incident') {
        html = '<div class="grid-2">' +
            '<input type="text" name="action_title[]" class="form-control" placeholder="Incident title">' +
            '<select name="action_severity[]" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select>' +
            '<div style="grid-column:1/-1;"><textarea name="action_desc[]" class="form-control" rows="1" placeholder="Description"></textarea></div>' +
            '</div>';
    } else if (val === 'send_webhook') {
        html = '<input type="url" name="action_url[]" class="form-control" placeholder="Webhook URL">';
    } else if (val === 'delay') {
        html = '<input type="number" name="action_seconds[]" class="form-control" placeholder="Seconds (max 300)" value="5" min="1" max="300">';
    }

    // Add hidden fields for unused types
    var hiddenFields = '';
    if (val !== 'block_ip') {
        hiddenFields += '<input type="hidden" name="action_ip[]" value=""><input type="hidden" name="action_reason[]" value=""><input type="hidden" name="action_duration[]" value="">';
    }
    if (val !== 'create_incident') {
        hiddenFields += '<input type="hidden" name="action_title[]" value=""><input type="hidden" name="action_severity[]" value=""><input type="hidden" name="action_desc[]" value="">';
    }
    if (val !== 'send_webhook') {
        hiddenFields += '<input type="hidden" name="action_url[]" value="">';
    }
    if (val !== 'delay') {
        hiddenFields += '<input type="hidden" name="action_seconds[]" value="">';
    }
    if (val !== 'unblock_ip') {
        hiddenFields += '<input type="hidden" name="action_unblock_ip[]" value="">';
    }

    fields.innerHTML = html + hiddenFields;
}

document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('sidebar'), c = document.getElementById('main-content');
    function adj() { c.style.marginLeft = s && s.classList.contains('collapsed') ? '80px' : '250px'; }
    adj(); if (s) new MutationObserver(adj).observe(s, { attributes: true, attributeFilter: ['class'] });
});
</script>
</body>
</html>
<?php mysqli_close($con); ?>
