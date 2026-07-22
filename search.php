<?php
require_once __DIR__ . '/db_config.php';
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) {
    exit('DB Error: ' . mysqli_connect_error());
}

date_default_timezone_set('Asia/Karachi'); // Adjust as needed

$theme = $_COOKIE['theme'] ?? 'dark';

// Get search query (from topbar) or detail params (for single view)
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : ''; // 'device' or 'link'
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$results = [];
$message = '';
$detail_data = null;
$report_data = null; // For graphs
$avg_rtt = 'N/A';

// Function to format duration (reused from reports.php)
function formatDuration($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $str = '';
    if ($days) $str .= "$days days, ";
    if ($hours) $str .= "$hours hours, ";
    if ($minutes) $str .= "$minutes minutes, ";
    if ($secs) $str .= "$secs seconds";
    return rtrim($str, ', ');
}

if ($type && $id) {
    // Single detail view (device or link)
    if ($type === 'device') {
        $stmt = $con->prepare("SELECT * FROM devices WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $detail_data = $result->fetch_assoc();
        $stmt->close();
    } elseif ($type === 'link') {
        $stmt = $con->prepare("
            SELECT l.*, d1.name AS from_name, d1.ip AS from_ip, d2.name AS to_name, d2.ip AS to_ip
            FROM links l
            LEFT JOIN devices d1 ON l.from_device_id = d1.id
            LEFT JOIN devices d2 ON l.to_device_id = d2.id
            WHERE l.id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $detail_data = $result->fetch_assoc();
        $stmt->close();
    }

    if ($detail_data) {
        // Fetch report data for graphs (default 24h, device/link ID)
        $end_time = date('Y-m-d H:i:s');
        $start_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $logs_query = "
            SELECT status, checked_at, rtt_avg
            FROM ping_logs
            WHERE " . ($type === 'device' ? 'device_id' : 'link_id') . " = ? 
            AND checked_at >= ? AND checked_at <= ?
            ORDER BY checked_at ASC
        ";
        $stmt = $con->prepare($logs_query);
        $stmt->bind_param('iss', $id, $start_time, $end_time);
        $stmt->execute();
        $logs_result = $stmt->get_result();
        $logs = [];
        $sum_rtt = 0;
        $count_rtt = 0;
        $rtt_labels = [];
        $rtt_data = [];
        while ($row = mysqli_fetch_assoc($logs_result)) {
            $logs[] = $row;
            if ($row['status'] === 'up' && $row['rtt_avg'] !== null) {
                $sum_rtt += $row['rtt_avg'];
                $count_rtt++;
            }
            $rtt_labels[] = $row['checked_at'];
            $rtt_data[] = $row['rtt_avg'] ?? 0;
        }
        $stmt->close();

        $avg_rtt = $count_rtt > 0 ? round($sum_rtt / $count_rtt, 1) : 'N/A';

        // Process periods for uptime chart (reused from reports.php)
        $periods = [];
        $total_time = strtotime($end_time) - strtotime($start_time);
        $total_up_time = 0;
        $total_down_time = 0;
        if (!empty($logs)) {
            $current_start = $logs[0]['checked_at'];
            $prev_status = $logs[0]['status'];
            for ($i = 1; $i < count($logs); $i++) {
                if ($logs[$i]['status'] !== $prev_status) {
                    $period_end = $logs[$i]['checked_at'];
                    $duration = strtotime($period_end) - strtotime($current_start);
                    $periods[] = [
                        'status' => $prev_status,
                        'start' => $current_start,
                        'end' => $period_end,
                        'duration' => $duration
                    ];
                    if ($prev_status === 'up') $total_up_time += $duration;
                    else $total_down_time += $duration;
                    $current_start = $period_end;
                    $prev_status = $logs[$i]['status'];
                }
            }
            $duration = strtotime($end_time) - strtotime($current_start);
            $periods[] = [
                'status' => $prev_status,
                'start' => $current_start,
                'end' => $end_time,
                'duration' => $duration
            ];
            if ($prev_status === 'up') $total_up_time += $duration;
            else $total_down_time += $duration;
        }
        $availability = $total_time > 0 ? round(($total_up_time / $total_time) * 100, 2) : 0;

        $report_data = [
            'periods' => $periods,
            'total_up_time' => $total_up_time,
            'total_down_time' => $total_down_time,
            'availability' => $availability,
            'rtt_labels' => $rtt_labels,
            'rtt_data' => $rtt_data
        ];
    } else {
        $message = 'No details found for this item.';
    }
} elseif (!empty($q)) {
    // Search mode: Query both devices and links
    $q_escaped = '%' . mysqli_real_escape_string($con, $q) . '%';

    // Devices query
    $devices_query = "SELECT id, 'device' AS type, name, ip, city, sub_office, country 
                      FROM devices 
                      WHERE name LIKE ? OR ip LIKE ?";
    $stmt = $con->prepare($devices_query);
    $stmt->bind_param('ss', $q_escaped, $q_escaped);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();

    // Links query
    $links_query = "
        SELECT l.id, 'link' AS type, l.name, l.ip, d1.name AS from_name, d2.name AS to_name
        FROM links l
        LEFT JOIN devices d1 ON l.from_device_id = d1.id
        LEFT JOIN devices d2 ON l.to_device_id = d2.id
        WHERE l.name LIKE ? OR l.ip LIKE ?
    ";
    $stmt = $con->prepare($links_query);
    $stmt->bind_param('ss', $q_escaped, $q_escaped);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();

    if (empty($results)) {
        $message = 'No devices or links found matching "' . htmlspecialchars($q) . '".';
    }
} else {
    $message = 'Please enter a search term (device/link name or IP).';
}

mysqli_close($con);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <title>Search - JanusNMS</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link rel="stylesheet" href="css/theme.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="loggedin">

<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <div class="container-fluid">
        <?php if ($detail_data): ?>
            <!-- Detailed Template View -->
            <h2><?= htmlspecialchars($detail_data['name']) ?> Details (<?= ucfirst($type) ?>)</h2>
            <div class="row g-3 mb-4">
                <!-- Basic Fields Card -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5>Basic Information</h5></div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item"><strong>IP Address:</strong> <?= htmlspecialchars($detail_data['ip']) ?></li>
                                <li class="list-group-item"><strong>Name:</strong> <?= htmlspecialchars($detail_data['name']) ?></li>
                                <?php if ($type === 'device'): ?>
                                    <li class="list-group-item"><strong>Type:</strong> <?= htmlspecialchars($detail_data['type']) ?></li>
                                    <li class="list-group-item"><strong>Model:</strong> <?= htmlspecialchars($detail_data['model'] ?: 'N/A') ?></li>
                                    <li class="list-group-item"><strong>Location:</strong> <?= htmlspecialchars($detail_data['city'] ?: 'N/A') ?>, <?= htmlspecialchars($detail_data['sub_office'] ?: 'N/A') ?>, <?= htmlspecialchars($detail_data['country'] ?: 'N/A') ?></li>
                                    <li class="list-group-item"><strong>Contact Number:</strong> <?= htmlspecialchars($detail_data['contact_number'] ?: 'N/A') ?></li>
                                    <li class="list-group-item"><strong>Email:</strong> <?= htmlspecialchars($detail_data['email'] ?: 'N/A') ?></li>
                                    <li class="list-group-item"><strong>SNMP Community:</strong> <?= htmlspecialchars($detail_data['snmp_community'] ?: 'N/A') ?></li>
                                <?php elseif ($type === 'link'): ?>
                                    <li class="list-group-item"><strong>From Device:</strong> <?= htmlspecialchars($detail_data['from_name'] ?: 'N/A') ?> (<?= htmlspecialchars($detail_data['from_ip'] ?: 'N/A') ?>)</li>
                                    <li class="list-group-item"><strong>To Device:</strong> <?= htmlspecialchars($detail_data['to_name'] ?: 'N/A') ?> (<?= htmlspecialchars($detail_data['to_ip'] ?: 'N/A') ?>)</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Status Summary Card -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5>Status Summary (Last 24h)</h5></div>
                        <div class="card-body">
                            <p><strong>Average RTT:</strong> <?= $avg_rtt ?> ms</p>
                            <p><strong>Availability:</strong> <?= $report_data['availability'] ?>%</p>
                            <!-- Uptime Pie Chart -->
                            <div class="chart-container mb-3"><canvas id="availabilityChart"></canvas></div>
                            <!-- RTT Line Chart -->
                            <div class="chart-container"><canvas id="rttChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Periods Table -->
            <div class="card">
                <div class="card-header"><h5>Status Periods</h5></div>
                <div class="card-body">
                    <table class="table table-hover">
                        <thead><tr><th>Status</th><th>Start</th><th>End</th><th>Duration</th></tr></thead>
                        <tbody>
                            <?php foreach ($report_data['periods'] as $period): ?>
                                <tr>
                                    <td><span class="badge <?= $period['status'] === 'up' ? 'bg-success' : 'bg-danger' ?>"><?= strtoupper($period['status']) ?></span></td>
                                    <td><?= date('Y-m-d H:i:s', strtotime($period['start'])) ?></td>
                                    <td><?= date('Y-m-d H:i:s', strtotime($period['end'])) ?></td>
                                    <td><?= formatDuration($period['duration']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report_data['periods'])): ?>
                                <tr><td colspan="4" class="text-center">No logs available.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif (!empty($results)): ?>
            <!-- Multiple Results List -->
            <h2>Search Results for "<?= htmlspecialchars($q) ?>" (<?= count($results) ?> found)</h2>
            <div class="card mt-3">
                <div class="card-body">
                    <table class="table table-hover">
                        <thead><tr><th>Type</th><th>Name</th><th>IP</th><th>Details</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <tr>
                                    <td><?= ucfirst($result['type']) ?></td>
                                    <td><?= htmlspecialchars($result['name']) ?></td>
                                    <td><?= htmlspecialchars($result['ip']) ?></td>
                                    <td>
                                        <?php if ($result['type'] === 'device'): ?>
                                            Location: <?= htmlspecialchars($result['city'] ?: 'N/A') ?>, <?= htmlspecialchars($result['sub_office'] ?: 'N/A') ?>
                                        <?php elseif ($result['type'] === 'link'): ?>
                                            From: <?= htmlspecialchars($result['from_name'] ?: 'N/A') ?> | To: <?= htmlspecialchars($result['to_name'] ?: 'N/A') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><a href="search.php?type=<?= $result['type'] ?>&id=<?= $result['id'] ?>" class="btn btn-sm btn-primary">View Details</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- No Results or Message -->
            <h2>Search</h2>
            <div class="alert alert-info mt-3"><?= $message ?></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar adjustment
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    function adjust() {
        mainContent.style.marginLeft = sidebar?.classList.contains('collapsed') ? '80px' : '250px';
    }
    adjust();
    if (sidebar) new MutationObserver(adjust).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
});

// Charts (if in detail view)
<?php if ($report_data): ?>
    new Chart(document.getElementById('availabilityChart'), {
        type: 'pie',
        data: { labels: ['Uptime', 'Downtime'], datasets: [{ data: [<?= $report_data['total_up_time'] ?>, <?= $report_data['total_down_time'] ?>], backgroundColor: ['#00c853', '#d50000'] }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    new Chart(document.getElementById('rttChart'), {
        type: 'line',
        data: { labels: <?= json_encode($report_data['rtt_labels']) ?>, datasets: [{ label: 'RTT (ms)', data: <?= json_encode($report_data['rtt_data']) ?>, borderColor: '#007bff', fill: true }] },
        options: { responsive: true, scales: { x: { type: 'time', time: { unit: 'hour' } }, y: { title: { display: true, text: 'RTT (ms)' } } } }
    });
<?php endif; ?>
</script>
</body>
</html>
