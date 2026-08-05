<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/_helpers.php';

session_start();
if (!isset($_SESSION['loggedin'])) {
    die('Unauthorized');
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>';
    exit;
}

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$range  = $_GET['range']  ?? '24h';

$device_where = $device ? "AND (source_ip = '" . mysqli_real_escape_string($con, $device) . "' OR message LIKE '%" . mysqli_real_escape_string($con, $device) . "%')" : '';

require_once __DIR__ . '/../includes/cache.php';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("printer_activity", $start, $end, $device, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $start, $end, $device_where) {
    // Get registered printer IPs from janus_printers
    $printer_ips = [];
    $pr_res = mysqli_query($con, "SELECT ip FROM janus_printers");
    if ($pr_res) {
        while ($p_row = mysqli_fetch_assoc($pr_res)) {
            $printer_ips[] = "'" . mysqli_real_escape_string($con, $p_row['ip']) . "'";
        }
    }

    if (!empty($printer_ips)) {
        $ip_condition = "(source_ip IN (" . implode(',', $printer_ips) . ") OR LOWER(appliance_type) LIKE '%printer%')";
    } else {
        $ip_condition = "LOWER(appliance_type) LIKE '%printer%'";
    }

    $where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "' 
              AND $ip_condition
              AND LOWER(appliance_type) NOT LIKE '%firewall%'
              $device_where";

    $result = unionQuery($con, 'message, source_ip, received_at, appliance_type', $where, 'received_at DESC', 5000);

    $records = [];
    $total_events = 0;
    $jobs_count = ['print' => 0, 'scan' => 0, 'copy' => 0];
    $auth_counts = ['success' => 0, 'failed' => 0];
    $config_changes = 0;
    $system_alerts = 0;
    $printers_seen = [];

    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $msg = $row['message'];
            $msg_lower = strtolower($msg);

            // --- HP Printer Log Parser ---
            // Handles formats like:
            // "printer: EWS Sign In Authentication; time="..." sign-in_method=local_device user="Administrator" source_IP="10.0.1.41" outcome=failure"
            // "printer: Print job completion; time="..." job_name="..." user="RELIANCE\furqan" source_IP="10.0.1.29" outcome=success"
            $hp = [];
            if (preg_match_all('/([a-zA-Z0-9_-]+)=(?:"([^"]*)"|\'([^\']*)\'|([^ \t;]+))/', $msg, $hm, PREG_SET_ORDER)) {
                foreach ($hm as $m) {
                    $key = strtolower($m[1]); // normalise key to lowercase
                    $value = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
                    $hp[$key] = $value;
                }
            }

            // Also run generic key=value parser for non-HP logs
            $parsed = parseMessage($msg);

            // Resolve source IP (sender workstation) - HP uses source_IP field
            $src_ip   = $hp['source_ip'] ?? $parsed['srcip'] ?? $parsed['remip'] ?? $parsed['client_ip'] ?? $row['source_ip'];
            // Printer device is always the syslog source
            $printer_ip = $row['source_ip'];
            // Resolve user - HP uses user field with DOMAIN\user format
            $raw_user = $hp['user'] ?? $parsed['user'] ?? $parsed['username'] ?? $parsed['xauthuser'] ?? 'N/A';
            // Strip domain prefix (e.g. "RELIANCE\john" -> "john")
            $user = (strpos($raw_user, '\\') !== false) ? substr($raw_user, strrpos($raw_user, '\\') + 1) : $raw_user;
            // Resolve job name
            $job_name = $hp['job_name'] ?? $parsed['job_name'] ?? '';
            // Resolve outcome
            $outcome = strtolower($hp['outcome'] ?? $parsed['outcome'] ?? 'success');
            // Resolve sign-in method if present
            $signin_method = $hp['sign-in_method'] ?? $hp['signin_method'] ?? '';

            $printers_seen[$printer_ip] = true;

            // --- Classify Event Category ---
            $category  = 'System & Hardware';
            $severity  = 'INFO';
            $sev_badge = 'info';
            $action    = 'COMPLETED';

            // 1. Authentication & Sign-in events (EWS, Web Admin, Control Panel, Card Swipe)
            $is_auth = (
                strpos($msg_lower, 'sign in') !== false ||
                strpos($msg_lower, 'sign-in') !== false ||
                strpos($msg_lower, 'authentication') !== false ||
                strpos($msg_lower, 'login') !== false ||
                strpos($msg_lower, 'auth') !== false
            ) && (strpos($msg_lower, 'print job') === false);

            if ($is_auth) {
                $category = 'Authentication';
                if (
                    $outcome === 'failure' ||
                    $outcome === 'failed' ||
                    $outcome === 'error' ||
                    strpos($msg_lower, 'failed') !== false ||
                    strpos($msg_lower, 'invalid') !== false ||
                    strpos($msg_lower, 'denied') !== false
                ) {
                    $severity  = 'HIGH';
                    $sev_badge = 'danger';
                    $action    = 'FAILED';
                    $auth_counts['failed']++;
                } else {
                    $severity  = 'INFO';
                    $sev_badge = 'success';
                    $action    = 'SUCCESS';
                    $auth_counts['success']++;
                }

            // 2. Admin & Config changes (Permission changes, SNMP, Settings modified, Security/Network config)
            } elseif (
                strpos($msg_lower, 'permission') !== false ||
                strpos($msg_lower, 'settings modified') !== false ||
                strpos($msg_lower, 'setting modified') !== false ||
                strpos($msg_lower, 'snmp') !== false ||
                strpos($msg_lower, 'password changed') !== false ||
                strpos($msg_lower, 'syslog settings') !== false ||
                strpos($msg_lower, 'security') !== false ||
                strpos($msg_lower, 'certificate') !== false ||
                strpos($msg_lower, 'access control') !== false ||
                strpos($msg_lower, 'policy modified') !== false ||
                strpos($msg_lower, 'network settings') !== false ||
                (strpos($msg_lower, 'modified') !== false && strpos($msg_lower, 'print job') === false && strpos($msg_lower, 'scan') === false) ||
                (strpos($msg_lower, 'config') !== false && strpos($msg_lower, 'print job') === false)
            ) {
                $category  = 'Admin & Config';
                $severity  = 'MEDIUM';
                $sev_badge = 'warning';
                $action    = 'MODIFIED';
                $config_changes++;

            // 3. Print Job
            } elseif (
                strpos($msg_lower, 'print job') !== false ||
                strpos($msg_lower, 'ipp') !== false ||
                strpos($msg_lower, 'lpd') !== false ||
                strpos($msg_lower, 'pcl') !== false
            ) {
                $category  = 'Print Job';
                $severity  = ($outcome === 'failure' || $outcome === 'error') ? 'HIGH' : 'INFO';
                $sev_badge = ($outcome === 'failure' || $outcome === 'error') ? 'danger' : 'success';
                $action    = ($outcome === 'failure' || $outcome === 'error') ? 'FAILED' : 'PRINTED';
                $jobs_count['print']++;

            // 4. Scan Job (includes Scan, Save to USB, Save to Network/Folder/Email, Digitize)
            } elseif (
                strpos($msg_lower, 'scan') !== false ||
                strpos($msg_lower, 'save to usb') !== false ||
                strpos($msg_lower, 'scan to usb') !== false ||
                strpos($msg_lower, 'save to network') !== false ||
                strpos($msg_lower, 'save to folder') !== false ||
                strpos($msg_lower, 'save to email') !== false ||
                strpos($msg_lower, 'digitize') !== false
            ) {
                $category  = 'Scan Job';
                $severity  = ($outcome === 'failure' || $outcome === 'error') ? 'HIGH' : 'INFO';
                $sev_badge = ($outcome === 'failure' || $outcome === 'error') ? 'danger' : 'info';
                $action    = ($outcome === 'failure' || $outcome === 'error') ? 'FAILED' : 'SCANNED';
                $jobs_count['scan']++;

            // 5. Copy Job
            } elseif (strpos($msg_lower, 'copy') !== false) {
                $category  = 'Copy Job';
                $severity  = 'INFO';
                $sev_badge = 'secondary';
                $action    = 'COPIED';
                $jobs_count['copy']++;

            // 6. System & Hardware alerts
            } else {
                $category = 'System & Hardware';
                $system_alerts++;
                if (
                    strpos($msg_lower, 'error') !== false ||
                    strpos($msg_lower, 'paper jam') !== false ||
                    strpos($msg_lower, 'offline') !== false ||
                    strpos($msg_lower, 'low-power') !== false ||
                    strpos($msg_lower, 'low power') !== false ||
                    strpos($msg_lower, 'sleep') !== false
                ) {
                    $severity  = 'MEDIUM';
                    $sev_badge = 'warning';
                    $action    = 'WARNING';
                } elseif (
                    strpos($msg_lower, 'toner') !== false ||
                    strpos($msg_lower, 'supply') !== false
                ) {
                    $severity  = 'MEDIUM';
                    $sev_badge = 'warning';
                    $action    = 'WARNING';
                } else {
                    $severity  = 'INFO';
                    $sev_badge = 'info';
                    $action    = 'COMPLETED';
                }
            }

            // Set details to the exact raw syslog message
            $details = $msg;

            $total_events++;
            if (count($records) < 500) {
                $records[] = [
                    'time'       => $row['received_at'],
                    'severity'   => $severity,
                    'sev_badge'  => $sev_badge,
                    'src_ip'     => $src_ip,
                    'user'       => $user,
                    'printer_ip' => $printer_ip,
                    'category'   => $category,
                    'action'     => $action,
                    'details'    => $details
                ];
            }
        }
    }

    return compact(
        'total_events', 'jobs_count', 'auth_counts', 'config_changes', 'system_alerts',
        'printers_seen', 'records'
    );
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please refresh.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);
mysqli_close($con);
?>

<!-- POC Demo Banner -->
<div class="alert alert-dark d-flex align-items-center justify-content-between mb-4 border-secondary" style="background:#131a26;">
    <div>
        <h6 class="mb-1 text-warning"><i data-lucide="printer" class="icon-lucide"></i> Printer Activity & Security Telemetry (POC Evaluation Module)</h6>
        <div class="small text-muted">Comprehensive auditing of print/scan/copy jobs, authentication, admin access, security config changes, and hardware events.</div>
    </div>
    <div>
        <span class="badge bg-primary px-3 py-2"><i data-lucide="shield-check" class="icon-lucide"></i> Multi-Vendor Supported</span>
    </div>
</div>

<!-- Summary Statistics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="printer" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= (int)$total_events ?>">0</div>
            <span class="stat-chip chip-info">Total Printer Events</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-success"><i data-lucide="file-text" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= array_sum($jobs_count) ?>">0</div>
            <span class="stat-chip chip-success">Print / Scan / Copy Jobs</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="lock" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= (int)$auth_counts['failed'] ?>">0</div>
            <span class="stat-chip chip-danger">Auth Failures / Alerts</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="wrench" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= (int)$config_changes ?>">0</div>
            <span class="stat-chip chip-warning">Admin & Security Config Changes</span>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="chart-pie" class="icon-lucide"></i> Job Activity Breakdown</h5>
            <div class="chart-container">
                <canvas id="jobActivityChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="report-card">
            <h5><i data-lucide="shield-alert" class="icon-lucide"></i> Security & Authentication Audit</h5>
            <div class="chart-container">
                <canvas id="securityAuditChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Monitoring Log Table -->
<div class="report-card mt-4">
    <h5><i data-lucide="list" class="icon-lucide"></i> Printer Event Telemetry & Audit Logs (<?= htmlspecialchars(strtoupper($range)) ?> Window)</h5>
    <div class="table-container" style="max-height: 520px; overflow-y: auto;">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Event Timestamp</th>
                    <th>Severity</th>
                    <th>Source IP / User</th>
                    <th>Printer Device IP</th>
                    <th>Event Category</th>
                    <th>Action / Status</th>
                    <th>Detailed Log / Monitoring Event</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($records)): ?>
                    <?php foreach ($records as $r): 
                        // Distinct color map for Action/Status badges
                        $act_color = match($r['action']) {
                            'PRINTED', 'SUCCESS' => 'success',
                            'SCANNED'             => 'info text-dark',
                            'COPIED'              => 'primary',
                            'FAILED'              => 'danger',
                            'WARNING'             => 'warning text-dark',
                            'MODIFIED'            => 'warning text-dark',
                            default               => 'secondary'
                        };

                        // Distinct color map for Category badges
                        $cat_color = match($r['category']) {
                            'Print Job'           => 'success',
                            'Scan Job'            => 'info text-dark',
                            'Copy Job'            => 'primary',
                            'Authentication'      => $r['action'] === 'FAILED' ? 'danger' : 'success',
                            'Admin & Config'      => 'warning text-dark',
                            default               => 'secondary'
                        };
                    ?>
                        <tr>
                            <td><small class="font-monospace text-nowrap"><?= htmlspecialchars($r['time']) ?></small></td>
                            <td>
                                <span class="badge bg-<?= htmlspecialchars($r['sev_badge']) ?>">
                                    <?= htmlspecialchars($r['severity']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($r['src_ip']) ?>')" class="clickable-ip">
                                    <?= htmlspecialchars($r['src_ip']) ?>
                                </a>
                                <div class="small text-muted"><i data-lucide="user" class="icon-lucide"></i> <?= htmlspecialchars($r['user']) ?></div>
                            </td>
                            <td><code><?= htmlspecialchars($r['printer_ip']) ?></code></td>
                            <td><span class="badge bg-<?= $cat_color ?>"><?= htmlspecialchars($r['category']) ?></span></td>
                            <td>
                                <span class="badge bg-<?= $act_color ?>">
                                    <?= htmlspecialchars($r['action']) ?>
                                </span>
                            </td>
                            <td class="small" style="max-width: 420px;">
                                <code><?= htmlspecialchars($r['details']) ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No printer events found in the selected time range.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
const jobsData = <?= json_encode($jobs_count) ?>;
createChart('jobActivityChart', {
    type: 'doughnut',
    data: {
        labels: ['Print Jobs', 'Scan Jobs', 'Copy Jobs'],
        datasets: [{
            data: [jobsData.print || 0, jobsData.scan || 0, jobsData.copy || 0],
            backgroundColor: ['#2be8a4', '#29d3ee', '#8b5cf6'],
            borderColor: '#10151d',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%'
    }
});

const secData = <?= json_encode([
    'Auth Success' => $auth_counts['success'],
    'Auth Failed' => $auth_counts['failed'],
    'Config Changes' => $config_changes,
    'System Alerts' => $system_alerts
]) ?>;

createChart('securityAuditChart', {
    type: 'bar',
    data: {
        labels: Object.keys(secData),
        datasets: [{
            label: 'Event Count',
            data: Object.values(secData),
            backgroundColor: ['#2be8a4', '#ff4d5e', '#ffb020', '#60a5fa'],
            borderRadius: 3,
            maxBarThickness: 32
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
            x: { grid: { display: false } }
        }
    }
});
})();
</script>
