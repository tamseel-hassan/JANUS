<?php
require_once __DIR__ . '/../db_config.php';
// ss/availability.php - Availability Report

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
}
session_write_close();

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');

$token = 'janus-secure-internal-2026';

$url = "http://localhost/report_data.php"
     . "?type=availability"
     . "&start=" . urlencode($start)
     . "&end=" . urlencode($end)
     . "&internal_token=" . urlencode($token);

$response = @file_get_contents($url);

if ($response === false) {
    echo '<div class="alert alert-danger">Failed to connect to backend. Check logs.</div>';
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || isset($data['error'])) {
    echo '<div class="alert alert-danger">Backend error: ' . ($data['error'] ?? 'Invalid response') . '</div>';
    exit;
}
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-primary"><i class="fas fa-server"></i></div>
            <div class="stat-value"><?= $data['stats']['total_devices'] ?? 0 ?></div>
            <div class="stat-label">Total Devices</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i class="fas fa-arrow-up"></i></div>
            <div class="stat-value"><?= $data['stats']['up_devices'] ?? 0 ?></div>
            <div class="stat-label">Up</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i class="fas fa-arrow-down"></i></div>
            <div class="stat-value"><?= $data['stats']['down_devices'] ?? 0 ?></div>
            <div class="stat-label">Down</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i class="fas fa-percentage"></i></div>
            <div class="stat-value"><?= $data['stats']['avg_availability'] ?? 0 ?>%</div>
            <div class="stat-label">Avg Availability</div>
        </div>
    </div>
</div>

<div class="report-card">
    <h5><i class="fas fa-list"></i> Device Availability</h5>
    <div class="table-container">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>IP</th>
                    <th>Status</th>
                    <th>Availability</th>
                    <th>Uptime</th>
                    <th>Downtime</th>
                    <th>Last Check</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($data['devices'])): ?>
                    <?php foreach ($data['devices'] as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['name'] ?? 'N/A') ?></td>
                            <td><code><?= htmlspecialchars($d['ip'] ?? 'N/A') ?></code></td>
                            <td><span class="badge bg-<?= $d['status'] === 'up' ? 'success' : 'danger' ?>">
                                <?= strtoupper($d['status'] ?? 'unknown') ?>
                            </span></td>
                            <td><?= $d['availability'] ?? '0' ?>%</td>
                            <td><?= htmlspecialchars($d['uptime'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($d['downtime'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($d['last_check'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center">No devices monitored</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
