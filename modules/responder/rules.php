<?php
/**
 * modules/responder/rules.php - Auto-Response Rule Management
 */
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: /index.html');
    exit;
}

require_once __DIR__ . '/../../db_config.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

// Handle rule toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_rule'])) {
    $rule_id = intval($_POST['rule_id']);
    $con->query("UPDATE responder_rules SET is_active = NOT is_active WHERE id = $rule_id");
    header('Location: rules.php');
    exit;
}

// Handle rule delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rule'])) {
    $rule_id = intval($_POST['rule_id']);
    $con->query("DELETE FROM responder_rules WHERE id = $rule_id");
    header('Location: rules.php');
    exit;
}

// Handle new rule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rule'])) {
    $name = trim($_POST['name'] ?? '');
    $trigger_type = $_POST['trigger_type'] ?? 'threshold';
    $action_type = $_POST['action_type'] ?? 'both';

    $config = [];
    if ($trigger_type === 'threshold') {
        $config['threshold'] = intval($_POST['threshold'] ?? 10);
        $config['window_minutes'] = intval($_POST['window_minutes'] ?? 5);
        $config['message_pattern'] = trim($_POST['message_pattern'] ?? '');
    } elseif ($trigger_type === 'keyword') {
        $config['keywords'] = array_map('trim', explode(',', $_POST['keywords'] ?? ''));
    }

    $act_config = [];
    if ($action_type === 'block_ip' || $action_type === 'both') {
        $act_config['duration'] = intval($_POST['block_duration'] ?? 3600);
    }
    if ($action_type === 'create_incident' || $action_type === 'both') {
        $act_config['severity'] = $_POST['incident_severity'] ?? 'medium';
    }

    $cooldown = intval($_POST['cooldown_minutes'] ?? 60);

    $stmt = $con->prepare("INSERT INTO responder_rules (name, trigger_type, trigger_config, action_type, action_config, cooldown_minutes) VALUES (?, ?, ?, ?, ?, ?)");
    $tt = $trigger_type;
    $tc = json_encode($config);
    $at = $action_type;
    $ac = json_encode($act_config);
    $cd = $cooldown;
    $stmt->bind_param('sssssi', $name, $tt, $tc, $at, $ac, $cd);
    $stmt->execute();
    $stmt->close();

    header('Location: rules.php');
    exit;
}

$rules = mysqli_query($con, "SELECT * FROM responder_rules ORDER BY created_at DESC");
$blocked_count = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS c FROM responder_blocked_ips WHERE is_active = 1"))['c'];
$incident_count = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS c FROM incidents WHERE status = 'open'"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Response Rules - Janus SIEM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: 'Inter', sans-serif; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; }
        .badge { font-size: 0.7rem; }
        .rule-card { border-left: 4px solid #3b82f6; padding: 16px; margin-bottom: 12px; background: #1e293b; border-radius: 6px; }
        .rule-card.inactive { opacity: 0.5; border-left-color: #64748b; }
        .rule-card.active { border-left-color: #2be8a4; }
        h1 { color: #f1f5f9; font-size: 1.5rem; }
        .stat-box { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #29d3ee; }
        .form-control, .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    </style>
</head>
<body>
<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-robot"></i> Auto-Response Rules</h1>
        <a href="automation.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Automation</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-value"><?= mysqli_num_rows($rules) ?></div>
                <div class="text-muted">Total Rules</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-value" style="color:#ff4d5e"><?= $blocked_count ?></div>
                <div class="text-muted">Active Blocks</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-value" style="color:#ffb020"><?= $incident_count ?></div>
                <div class="text-muted">Open Incidents</div>
            </div>
        </div>
    </div>

    <!-- Add Rule Form -->
    <div class="card p-4 mb-4">
        <h5 class="mb-3"><i class="fas fa-plus"></i> Add New Rule</h5>
        <form method="POST">
            <input type="hidden" name="add_rule" value="1">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Rule Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Trigger Type</label>
                    <select name="trigger_type" class="form-select" id="triggerType" onchange="toggleTriggerConfig()">
                        <option value="threshold">Threshold</option>
                        <option value="keyword">Keyword</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Action</label>
                    <select name="action_type" class="form-select">
                        <option value="both">Block + Incident</option>
                        <option value="block_ip">Block IP Only</option>
                        <option value="create_incident">Incident Only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cooldown (min)</label>
                    <input type="number" name="cooldown_minutes" class="form-control" value="60" min="5">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Create Rule</button>
                </div>
            </div>
            <div id="thresholdConfig" class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Threshold</label>
                    <input type="number" name="threshold" class="form-control" value="10" min="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Window (minutes)</label>
                    <input type="number" name="window_minutes" class="form-control" value="5" min="1">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Message Pattern (REGEXP)</label>
                    <input type="text" name="message_pattern" class="form-control" placeholder="e.g. authentication.*failed|login.*failed">
                </div>
            </div>
            <div id="keywordConfig" class="row g-3 mt-2" style="display:none;">
                <div class="col-md-6">
                    <label class="form-label">Keywords (comma-separated)</label>
                    <input type="text" name="keywords" class="form-control" placeholder="e.g. virus, malware, botnet">
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Block Duration (sec)</label>
                    <input type="number" name="block_duration" class="form-control" value="3600" min="60">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Incident Severity</label>
                    <select name="incident_severity" class="form-select">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <!-- Existing Rules -->
    <div class="card p-4">
        <h5 class="mb-3"><i class="fas fa-list"></i> Active Rules</h5>
        <?php if ($rules && mysqli_num_rows($rules) > 0): ?>
            <?php while ($rule = mysqli_fetch_assoc($rules)):
                $config = json_decode($rule['trigger_config'], true);
                $act = json_decode($rule['action_config'], true);
            ?>
                <div class="rule-card <?= $rule['is_active'] ? 'active' : 'inactive' ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1">
                                <?= htmlspecialchars($rule['name']) ?>
                                <span class="badge bg-<?= $rule['is_active'] ? 'success' : 'secondary' ?>">
                                    <?= $rule['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                </span>
                            </h6>
                            <small class="text-muted">
                                Trigger: <strong><?= ucfirst($rule['trigger_type']) ?></strong> |
                                Action: <strong><?= str_replace('_', ' ', ucfirst($rule['action_type'])) ?></strong> |
                                Cooldown: <?= $rule['cooldown_minutes'] ?>min
                            </small>
                            <div class="mt-1">
                                <?php if ($rule['trigger_type'] === 'threshold'): ?>
                                    <small>Threshold: <?= $config['threshold'] ?? '?' ?> in <?= $config['window_minutes'] ?? '?' ?>min — <code><?= htmlspecialchars($config['message_pattern'] ?? '') ?></code></small>
                                <?php elseif ($rule['trigger_type'] === 'keyword'): ?>
                                    <small>Keywords: <?= htmlspecialchars(implode(', ', $config['keywords'] ?? [])) ?></small>
                                <?php endif; ?>
                            </div>
                            <?php if ($rule['last_triggered_at']): ?>
                                <small class="text-info">Last triggered: <?= date('M d, H:i', strtotime($rule['last_triggered_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="toggle_rule" value="1">
                                <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                <button class="btn btn-sm btn-outline-<?= $rule['is_active'] ? 'warning' : 'success' ?>" title="<?= $rule['is_active'] ? 'Disable' : 'Enable' ?>">
                                    <i class="fas fa-<?= $rule['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this rule?')">
                                <input type="hidden" name="delete_rule" value="1">
                                <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="alert alert-info mb-0">No rules configured. Add one above.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleTriggerConfig() {
    const type = document.getElementById('triggerType').value;
    document.getElementById('thresholdConfig').style.display = type === 'threshold' ? '' : 'none';
    document.getElementById('keywordConfig').style.display = type === 'keyword' ? '' : 'none';
}
</script>
</body>
</html>
