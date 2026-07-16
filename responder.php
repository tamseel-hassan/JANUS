<?php
/**
 * responder.php - Automated Incident Response via FortiGate REST API
 * Allows blocking IPs, creating firewall policies, and automated mitigation
 */

session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

// Create response_config table if not exists
mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS response_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_ip VARCHAR(45) NOT NULL UNIQUE,
        api_key VARCHAR(255) NOT NULL,
        username VARCHAR(100),
        is_active TINYINT(1) DEFAULT 1,
        added_by INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

// Create response_actions table
mysqli_query($con, "
    CREATE TABLE IF NOT EXISTS response_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_ip VARCHAR(45) NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        target_ip VARCHAR(45),
        details TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        executed_at TIMESTAMP NULL
    ) ENGINE=InnoDB
");

$message = '';

// Handle adding firewall
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_firewall'])) {
    $fw_ip = trim($_POST['firewall_ip']);
    $api_key = trim($_POST['api_key']);
    $username = trim($_POST['username']) ?: 'admin';
    $user_id = $_SESSION['id'];
    
    if (!filter_var($fw_ip, FILTER_VALIDATE_IP)) {
        $message = '<div class="alert alert-danger">Invalid firewall IP</div>';
    } else {
        // Test API connection
        $test = testFortiGateAPI($fw_ip, $api_key);
        if ($test['success']) {
            $stmt = $con->prepare("INSERT INTO response_config (firewall_ip, api_key, username, added_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssi', $fw_ip, $api_key, $username, $user_id);
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Firewall added successfully! Version: ' . $test['version'] . '</div>';
            } else {
                $message = '<div class="alert alert-danger">Database error</div>';
            }
        } else {
            $message = '<div class="alert alert-danger">API test failed: ' . $test['error'] . '</div>';
        }
    }
}

// Handle blocking IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_ip'])) {
    $target_ip = trim($_POST['target_ip']);
    $firewall_ip = $_POST['firewall_ip'];
    $reason = trim($_POST['reason']) ?: 'Blocked via SIEM';
    $user_id = $_SESSION['id'];
    
    if (!filter_var($target_ip, FILTER_VALIDATE_IP)) {
        $message = '<div class="alert alert-danger">Invalid target IP</div>';
    } else {
        // Get firewall config
        $stmt = $con->prepare("SELECT * FROM response_config WHERE firewall_ip = ? AND is_active = 1");
        $stmt->bind_param('s', $firewall_ip);
        $stmt->execute();
        $fw = $stmt->get_result()->fetch_assoc();
        
        if ($fw) {
            $result = blockIPOnFortiGate($fw, $target_ip, $reason);
            if ($result['success']) {
                // Log action
                $stmt = $con->prepare("INSERT INTO response_actions (firewall_ip, action_type, target_ip, details, status, created_by) VALUES (?, 'block_ip', ?, ?, 'success', ?)");
                $details = json_encode(['reason' => $reason, 'result' => $result]);
                $stmt->bind_param('sssi', $firewall_ip, $target_ip, $details, $user_id);
                $stmt->execute();
                
                $message = '<div class="alert alert-success">IP ' . htmlspecialchars($target_ip) . ' blocked successfully!</div>';
            } else {
                $message = '<div class="alert alert-danger">Failed to block IP: ' . $result['error'] . '</div>';
            }
        } else {
            $message = '<div class="alert alert-danger">Firewall not configured</div>';
        }
    }
}

// Fetch configured firewalls
$firewalls = [];
$res = mysqli_query($con, "SELECT * FROM response_config ORDER BY added_at DESC");
while ($r = mysqli_fetch_assoc($res)) {
    $firewalls[] = $r;
}

// Fetch recent actions
$actions = [];
$res = mysqli_query($con, "
    SELECT ra.*, a.username 
    FROM response_actions ra 
    JOIN accounts a ON ra.created_by = a.id 
    ORDER BY ra.created_at DESC 
    LIMIT 20
");
while ($r = mysqli_fetch_assoc($res)) {
    $actions[] = $r;
}

$theme = $_COOKIE['theme'] ?? 'dark';

// ========== FORTIGATE API FUNCTIONS ==========

function testFortiGateAPI($ip, $api_key) {
    $url = "https://$ip/api/v2/monitor/system/status";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $data = json_decode($response, true);
        return [
            'success' => true,
            'version' => $data['version'] ?? 'Unknown'
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP $http_code - " . ($response ?: 'Connection failed')
        ];
    }
}

function blockIPOnFortiGate($fw_config, $target_ip, $reason) {
    $fw_ip = $fw_config['firewall_ip'];
    $api_key = $fw_config['api_key'];
    
    // Create address object
    $address_name = "blocked_" . str_replace('.', '_', $target_ip) . "_" . time();
    $url = "https://$fw_ip/api/v2/cmdb/firewall/address";
    
    $data = json_encode([
        'name' => $address_name,
        'type' => 'ipmask',
        'subnet' => "$target_ip 255.255.255.255",
        'comment' => $reason
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        // Create deny policy
        $policy_result = createDenyPolicy($fw_config, $address_name, $reason);
        return [
            'success' => true,
            'address_created' => $address_name,
            'policy_result' => $policy_result
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP $http_code - " . ($response ?: 'Connection failed')
        ];
    }
}

function createDenyPolicy($fw_config, $address_name, $reason) {
    $fw_ip = $fw_config['firewall_ip'];
    $api_key = $fw_config['api_key'];
    
    $url = "https://$fw_ip/api/v2/cmdb/firewall/policy";
    
    $data = json_encode([
        'name' => "SIEM_Block_" . time(),
        'srcintf' => [['name' => 'any']],
        'dstintf' => [['name' => 'any']],
        'srcaddr' => [['name' => $address_name]],
        'dstaddr' => [['name' => 'all']],
        'action' => 'deny',
        'schedule' => 'always',
        'service' => [['name' => 'ALL']],
        'logtraffic' => 'all',
        'comments' => $reason
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code == 200;
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Incident Responder - JanusSIEM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
<link rel="stylesheet" href="css/pages/responder.css">
<link rel="stylesheet" href="/css/theme.css">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i data-lucide="shield" class="icon-lucide"></i> Incident Response System</h2>
            <p class="text-muted">Automated response actions via FortiGate REST API</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="reports.php" class="btn btn-outline-primary">
                <i data-lucide="arrow-left" class="icon-lucide"></i> Back to Reports
            </a>
        </div>
    </div>

    <?= $message ?>

    <!-- Configure Firewall -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="response-card">
                <h5><i data-lucide="settings" class="icon-lucide"></i> Configure FortiGate API</h5>
                <form method="POST" class="mt-3">
                    <div class="mb-3">
                        <label class="form-label">Firewall IP Address</label>
                        <input type="text" name="firewall_ip" class="form-control" placeholder="e.g., 10.11.1.1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Key</label>
                        <input type="password" name="api_key" class="form-control" placeholder="Generated from FortiGate" required>
                        <small class="text-muted">System > Administrators > Create New > REST API Admin</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username (Optional)</label>
                        <input type="text" name="username" class="form-control" placeholder="admin" value="admin">
                    </div>
                    <button type="submit" name="add_firewall" class="btn btn-primary">
                        <i data-lucide="plus-circle" class="icon-lucide"></i> Add Firewall
                    </button>
                </form>

                <hr class="my-4">

                <h6>Configured Firewalls (<?= count($firewalls) ?>)</h6>
                <?php if (empty($firewalls)): ?>
                    <p class="text-muted small">No firewalls configured yet</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($firewalls as $fw): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($fw['firewall_ip']) ?></strong>
                                    <span class="fw-badge <?= $fw['is_active'] ? 'bg-success' : 'bg-secondary' ?> ms-2">
                                        <?= $fw['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                                    </span>
                                </div>
                                <small class="text-muted"><?= date('M d, Y', strtotime($fw['added_at'])) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="action-box">
                <h5><i data-lucide="ban" class="icon-lucide"></i> Quick Block IP</h5>
                <p class="text-muted small">Block malicious IP address on firewall</p>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Select Firewall</label>
                        <select name="firewall_ip" class="form-select" required>
                            <option value="">Choose firewall...</option>
                            <?php foreach ($firewalls as $fw): if ($fw['is_active']): ?>
                                <option value="<?= htmlspecialchars($fw['firewall_ip']) ?>">
                                    <?= htmlspecialchars($fw['firewall_ip']) ?>
                                </option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target IP to Block</label>
                        <input type="text" name="target_ip" class="form-control" id="targetIPField" placeholder="e.g., 172.20.102.222" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g., Brute force attack detected" value="Blocked via JanusSIEM">
                    </div>
                    <button type="submit" name="block_ip" class="btn btn-danger w-100">
                        <i data-lucide="ban" class="icon-lucide"></i> Block IP Now
                    </button>
                </form>
            </div>

            <div class="response-card">
                <h6><i data-lucide="info" class="icon-lucide"></i> How It Works</h6>
                <ol class="small">
                    <li>Creates firewall address object for the IP</li>
                    <li>Creates deny policy at top of firewall rules</li>
                    <li>Blocks all traffic from that IP immediately</li>
                    <li>Logs action to audit trail</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Recent Actions -->
    <div class="response-card">
        <h5><i data-lucide="history" class="icon-lucide"></i> Recent Response Actions</h5>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Firewall</th>
                        <th>Action</th>
                        <th>Target IP</th>
                        <th>User</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($actions)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No actions yet</td></tr>
                    <?php else: ?>
                        <?php foreach ($actions as $act): ?>
                            <tr>
                                <td><?= date('M d, H:i', strtotime($act['created_at'])) ?></td>
                                <td><code><?= htmlspecialchars($act['firewall_ip']) ?></code></td>
                                <td><?= ucfirst(str_replace('_', ' ', $act['action_type'])) ?></td>
                                <td><code><?= htmlspecialchars($act['target_ip']) ?></code></td>
                                <td><?= htmlspecialchars($act['username']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $act['status']=='success'?'success':'danger' ?>">
                                        <?= strtoupper($act['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Allow pre-filling IP from URL parameter
const urlParams = new URLSearchParams(window.location.search);
const ipParam = urlParams.get('ip');
if (ipParam) {
    document.getElementById('targetIPField').value = ipParam;
}
</script>
</body>
</html>
