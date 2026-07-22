<?php
// threat_intel.php - Unified Threat Intelligence & IP Reputation Center
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) die('DB Error');

$message = '';
$results = [];

// Handle search/analysis request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['analyze'])) {
    $type = mysqli_real_escape_string($con, $_POST['observable_type']);
    $value = mysqli_real_escape_string($con, trim($_POST['observable_value']));
    $incident_id = !empty($_POST['incident_id']) ? (int)$_POST['incident_id'] : null;
    
    if (!empty($value)) {
        // Check cache first (avoid rate limits)
        $cache_check = mysqli_query($con, "
            SELECT * FROM threat_intel_cache 
            WHERE observable_type = '$type' 
            AND observable_value = '$value'
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY checked_at DESC
        ");
        
        if (mysqli_num_rows($cache_check) > 0) {
            // Use cached results
            while ($row = mysqli_fetch_assoc($cache_check)) {
                $results[$row['source']] = json_decode($row['result_data'], true);
                $results[$row['source']]['cached'] = true;
                $results[$row['source']]['risk_score'] = $row['risk_score'];
                $results[$row['source']]['is_malicious'] = $row['is_malicious'];
            }
            $message = 'Results loaded from cache (less than 24 hours old)';
        } else {
            // Perform fresh analysis via API
            $api_url = "api/threat_check.php";
            $post_data = http_build_query([
                'type' => $type,
                'value' => $value
            ]);
            
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $post_data
                ]
            ];
            
            $context = stream_context_create($options);
            $response = @file_get_contents($api_url, false, $context);
            
            if ($response) {
                $results = json_decode($response, true);
                $message = 'Fresh analysis completed';
            } else {
                $message = 'Error: Unable to perform analysis. Check API configuration.';
            }
        }
        
        // Log to analysis history
        if (!empty($results)) {
            $risk_level = 'unknown';
            $avg_risk = 0;
            $count = 0;
            
            foreach ($results as $source => $data) {
                if (isset($data['risk_score'])) {
                    $avg_risk += $data['risk_score'];
                    $count++;
                }
            }
            
            if ($count > 0) {
                $avg_risk = round($avg_risk / $count);
                if ($avg_risk >= 75) $risk_level = 'critical';
                elseif ($avg_risk >= 50) $risk_level = 'high';
                elseif ($avg_risk >= 25) $risk_level = 'medium';
                else $risk_level = 'low';
            }
            
            $result_summary = mysqli_real_escape_string($con, json_encode($results));
            $stmt = $con->prepare("
                INSERT INTO analysis_history 
                (user_id, incident_id, observable_type, observable_value, analysis_type, result_summary, risk_level)
                VALUES (?, ?, ?, ?, 'threat_intel', ?, ?)
            ");
            $stmt->bind_param('iissss', $_SESSION['id'], $incident_id, $type, $value, $result_summary, $risk_level);
            $stmt->execute();
        }
    }
}

// Get recent analysis history
$recent_analyses = mysqli_query($con, "
    SELECT ah.*, a.username 
    FROM analysis_history ah
    JOIN accounts a ON ah.user_id = a.id
    ORDER BY ah.created_at DESC
    LIMIT 20
");

// Get incidents for dropdown
$incidents = mysqli_query($con, "
    SELECT id, title, status 
    FROM incidents 
    WHERE status != 'closed'
    ORDER BY created_at DESC
    LIMIT 50
");

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Threat Intelligence Hub - Janus</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="css/pages/threat_intel.css">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i data-lucide="shield-virus" class="icon-lucide"></i> Threat Intelligence Center</h2>
            <p class="text-muted">Analyze IPs, domains, URLs, file hashes, and investigate threats using multiple intelligence sources</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= strpos($message, 'Error') === 0 ? 'danger' : 'info' ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Search Hero Section -->
    <div class="search-hero">
        <h3 class="mb-4"><i data-lucide="search" class="icon-lucide"></i> Investigate Threat Indicators</h3>
        <form method="POST" class="row g-3">
            <input type="hidden" name="analyze" value="1">
            <div class="col-md-3">
                <select name="observable_type" class="form-select form-select-lg" required>
                    <option value="ip">IP Address</option>
                    <option value="domain">Domain</option>
                    <option value="url">URL</option>
                    <option value="hash">File Hash (MD5/SHA256)</option>
                </select>
            </div>
            <div class="col-md-6">
                <input type="text" name="observable_value" class="form-control form-control-lg" 
                       placeholder="Enter IP, domain, URL, or file hash..." required>
            </div>
            <div class="col-md-3">
                <select name="incident_id" class="form-select form-select-lg">
                    <option value="">Link to Incident (Optional)</option>
                    <?php while ($inc = mysqli_fetch_assoc($incidents)): ?>
                    <option value="<?= $inc['id'] ?>">#<?= $inc['id'] ?> - <?= htmlspecialchars(substr($inc['title'], 0, 40)) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-light btn-lg px-5">
                    <i data-lucide="search" class="icon-lucide"></i> Analyze Threat
                </button>
            </div>
        </form>
        
        <!-- Quick Actions -->
        <div class="row mt-4 g-3">
            <div class="col-md-3">
                <div class="card quick-action" onclick="quickFill('ip', '8.8.8.8')">
                    <div class="card-body text-center">
                        <i data-lucide="network" class="icon-lucide fa-2x mb-2"></i>
                        <p class="mb-0">Test IP</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card quick-action" onclick="quickFill('domain', 'example.com')">
                    <div class="card-body text-center">
                        <i data-lucide="globe" class="icon-lucide fa-2x mb-2"></i>
                        <p class="mb-0">Test Domain</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card quick-action" onclick="quickFill('hash', '44d88612fea8a8f36de82e1278abb02f')">
                    <div class="card-body text-center">
                        <i data-lucide="fingerprint" class="icon-lucide fa-2x mb-2"></i>
                        <p class="mb-0">Test Hash</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <a href="malware_analysis.php" class="card quick-action text-decoration-none">
                    <div class="card-body text-center">
                        <i data-lucide="virus" class="icon-lucide fa-2x mb-2"></i>
                        <p class="mb-0">Malware Analysis</p>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- Results Section -->
    <?php if (!empty($results)): 
        $overall_risk = 0;
        $malicious_count = 0;
        $total_sources = count($results);
        
        foreach ($results as $source => $data) {
            if (isset($data['risk_score'])) $overall_risk += $data['risk_score'];
            if (isset($data['is_malicious']) && $data['is_malicious']) $malicious_count++;
        }
        $overall_risk = $total_sources > 0 ? round($overall_risk / $total_sources) : 0;
        
        $risk_class = 'unknown';
        $risk_label = 'Unknown';
        if ($overall_risk >= 75) { $risk_class = 'critical'; $risk_label = 'Critical Risk'; }
        elseif ($overall_risk >= 50) { $risk_class = 'high'; $risk_label = 'High Risk'; }
        elseif ($overall_risk >= 25) { $risk_class = 'medium'; $risk_label = 'Medium Risk'; }
        elseif ($overall_risk > 0) { $risk_class = 'low'; $risk_label = 'Low Risk'; }
        else { $risk_class = 'clean'; $risk_label = 'Clean'; }
    ?>
    
    <!-- Overall Risk Summary -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted mb-3">OVERALL RISK SCORE</h6>
                    <div class="metric-value mb-3" style="color: var(--accent);"><?= $overall_risk ?>/100</div>
                    <span class="risk-badge risk-<?= $risk_class ?>"><?= $risk_label ?></span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted mb-3">MALICIOUS DETECTIONS</h6>
                    <div class="metric-value mb-3 text-danger"><?= $malicious_count ?></div>
                    <span class="text-muted">out of <?= $total_sources ?> sources</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted mb-3">CONFIDENCE</h6>
                    <div class="metric-value mb-3" style="color: #28a745;">
                        <?= $total_sources >= 3 ? 'High' : 'Medium' ?>
                    </div>
                    <span class="text-muted"><?= $total_sources ?> sources checked</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Results by Source -->
    <div class="row">
        <div class="col-12"><h4 class="mb-3">Intelligence Source Reports</h4></div>
        
        <?php foreach ($results as $source => $data): 
            $card_class = 'clean';
            if (isset($data['is_malicious'])) {
                if ($data['is_malicious']) $card_class = 'malicious';
                elseif (isset($data['risk_score']) && $data['risk_score'] > 25) $card_class = 'suspicious';
            }
        ?>
        <div class="col-md-6 mb-3">
            <div class="card result-card <?= $card_class ?>">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="source-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i data-lucide="shield" class="icon-lucide"></i>
                        </div>
                        <div>
                            <h5 class="mb-0"><?= htmlspecialchars(ucfirst($source)) ?></h5>
                            <small class="text-muted">
                                <?= isset($data['cached']) && $data['cached'] ? 'Cached Result' : 'Live Check' ?>
                            </small>
                        </div>
                        <?php if (isset($data['risk_score'])): ?>
                        <div class="ms-auto">
                            <span class="risk-badge risk-<?= $data['risk_score'] >= 75 ? 'critical' : ($data['risk_score'] >= 50 ? 'high' : ($data['risk_score'] >= 25 ? 'medium' : 'low')) ?>">
                                <?= $data['risk_score'] ?>/100
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isset($data['details'])): ?>
                    <div class="mt-3">
                        <?php foreach ($data['details'] as $key => $value): ?>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                            <span class="text-muted"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?>:</span>
                            <strong><?= is_bool($value) ? ($value ? 'Yes' : 'No') : htmlspecialchars($value) ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($data['message'])): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <i data-lucide="info" class="icon-lucide"></i> <?= htmlspecialchars($data['message']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent Analysis History -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="history" class="icon-lucide"></i> Recent Analysis History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Observable</th>
                                    <th>Risk Level</th>
                                    <th>Incident</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = mysqli_fetch_assoc($recent_analyses)): ?>
                                <tr>
                                    <td><?= date('M j, H:i', strtotime($row['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($row['username']) ?></td>
                                    <td><span class="badge bg-secondary"><?= strtoupper($row['observable_type']) ?></span></td>
                                    <td><code><?= htmlspecialchars(substr($row['observable_value'], 0, 40)) ?></code></td>
                                    <td><span class="risk-badge risk-<?= $row['risk_level'] ?>"><?= ucfirst($row['risk_level']) ?></span></td>
                                    <td>
                                        <?php if ($row['incident_id']): ?>
                                        <a href="my_tasks.php?id=<?= $row['incident_id'] ?>">#<?= $row['incident_id'] ?></a>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick='viewDetails(<?= json_encode($row['result_summary']) ?>)'>
                                            <i data-lucide="eye" class="icon-lucide"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Analysis Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="detailsContent" style="background: var(--card-bg); padding: 15px; border-radius: 8px; max-height: 500px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<script src="js/bootstrap.bundle.min.js"></script>
<script>
function quickFill(type, value) {
    document.querySelector('[name="observable_type"]').value = type;
    document.querySelector('[name="observable_value"]').value = value;
    document.querySelector('[name="observable_value"]').focus();
}

function viewDetails(jsonStr) {
    try {
        const data = JSON.parse(jsonStr);
        document.getElementById('detailsContent').textContent = JSON.stringify(data, null, 2);
        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    } catch (e) {
        alert('Error displaying details');
    }
}

// Sidebar adjustment
document.addEventListener('DOMContentLoaded', function() {
    const s = document.getElementById('sidebar');
    const c = document.getElementById('main-content');
    function adj() {
        if (s && c) c.style.marginLeft = s.classList.contains('collapsed') ? '80px' : '260px';
    }
    adj();
    if (s) new MutationObserver(adj).observe(s, {attributes: true, attributeFilter: ['class']});
});
</script>
</body>
</html>
