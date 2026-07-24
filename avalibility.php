<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

require_once __DIR__ . '/db_config.php';

date_default_timezone_set('Asia/Karachi'); // Adjust to your timezone

// Get current script name for dynamic redirects
$script_name = basename($_SERVER['PHP_SELF']);

// Handle comment submission
if ($_POST && isset($_POST['save_comment'])) {
    $device_id = intval($_POST['device_id']);
    $event_start = mysqli_real_escape_string($con, $_POST['event_start']);
    $event_end = !empty($_POST['event_end']) ? mysqli_real_escape_string($con, $_POST['event_end']) : null;
    $comments = mysqli_real_escape_string($con, $_POST['comments']);
    $action_taken = mysqli_real_escape_string($con, $_POST['action_taken']);
    $escalation_level = intval($_POST['escalation_level']);
    $vendor_contacted = mysqli_real_escape_string($con, $_POST['vendor_contacted']);
    $ticket_number = mysqli_real_escape_string($con, $_POST['ticket_number']);
    $resolution_time = !empty($_POST['resolution_time']) ? mysqli_real_escape_string($con, $_POST['resolution_time']) : null;
    
    // Check if comment exists
    $check_stmt = $con->prepare("SELECT id FROM event_comments WHERE device_id = ? AND event_start_time = ?");
    $check_stmt->bind_param('is', $device_id, $event_start);
    $check_stmt->execute();
    $check_stmt->store_result();
    $num_rows = $check_stmt->num_rows;
    
    if ($num_rows > 0) {
        // Update existing
        $stmt = $con->prepare("UPDATE event_comments SET comments = ?, action_taken = ?, escalation_level = ?, vendor_contacted = ?, ticket_number = ?, resolution_time = ?, event_end_time = ? WHERE device_id = ? AND event_start_time = ?");
        $stmt->bind_param('ssissssis', $comments, $action_taken, $escalation_level, $vendor_contacted, $ticket_number, $resolution_time, $event_end, $device_id, $event_start);
    } else {
        // Insert new
        $stmt = $con->prepare("INSERT INTO event_comments (device_id, event_start_time, event_end_time, comments, action_taken, escalation_level, vendor_contacted, ticket_number, resolution_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssisss', $device_id, $event_start, $event_end, $comments, $action_taken, $escalation_level, $vendor_contacted, $ticket_number, $resolution_time);
    }
    
    $_SESSION['message'] = $stmt->execute() ? 'comment_saved' : 'error: ' . $stmt->error;
    $stmt->close();
    $check_stmt->close();
    
    // Build redirect URL with all parameters
    $redirect = "$script_name?device_id=$device_id&time_range=" . urlencode($_POST['time_range']);
    if ($_POST['time_range'] === 'custom') {
        $redirect .= "&start_datetime=" . urlencode($_POST['start_datetime']) . "&end_datetime=" . urlencode($_POST['end_datetime']);
    }
    header("Location: $redirect");
    exit;
}

// Fetch all devices for dropdown
$devices_query = "SELECT id, name, city, sub_office, country FROM devices ORDER BY name ASC";
$devices_result = mysqli_query($con, $devices_query);
$devices = [];
while ($row = mysqli_fetch_assoc($devices_result)) {
    $devices[] = $row;
}

// Initialize variables
$selected_device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;
$time_range = isset($_GET['time_range']) ? $_GET['time_range'] : '24h';
$start_datetime = isset($_GET['start_datetime']) ? $_GET['start_datetime'] : null;
$end_datetime = isset($_GET['end_datetime']) ? $_GET['end_datetime'] : null;
$message = isset($_SESSION['message']) ? $_SESSION['message'] : '';
unset($_SESSION['message']);

$report_data = null;
$device_info = null;
$current_status = null;
$current_status_since = null;
$last_status_change = null;
$availability = 0;
$avg_rtt = 'N/A';
$event_comments = [];

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

if ($selected_device_id && $time_range) {
    // Determine start and end times in UTC based on range
    $end_time_raw = time();
    switch ($time_range) {
        case '24h':
            $start_time_raw = strtotime('-24 hours');
            break;
        case '7d':
            $start_time_raw = strtotime('-7 days');
            break;
        case '30d':
            $start_time_raw = strtotime('-30 days');
            break;
        case 'custom':
            if ($start_datetime && $end_datetime) {
                $start_time_raw = strtotime(str_replace('T', ' ', $start_datetime) . ':00');
                $end_time_raw = strtotime(str_replace('T', ' ', $end_datetime) . ':59');
                if (!$start_time_raw || !$end_time_raw) {
                    $start_time_raw = strtotime('-24 hours');
                    $end_time_raw = time();
                }
            } else {
                $start_time_raw = strtotime('-24 hours');
            }
            break;
        default:
            $start_time_raw = strtotime('-24 hours');
    }
    $start_time = gmdate('Y-m-d H:i:s', $start_time_raw);
    $end_time = gmdate('Y-m-d H:i:s', $end_time_raw);
    $start_time_local = date('Y-m-d H:i:s', $start_time_raw);
    $end_time_local = date('Y-m-d H:i:s', $end_time_raw);

    // Fetch device info
    foreach ($devices as $dev) {
        if ($dev['id'] == $selected_device_id) {
            $device_info = $dev;
            break;
        }
    }

    if ($device_info) {
        // Check for export
        if (isset($_POST['export_csv'])) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="report_' . $device_info['name'] . '_' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Status', 'Start Time', 'End Time', 'Duration', 'Comments', 'Action Taken', 'Vendor', 'Ticket #']);
            // We'll populate periods below
        }

        // Fetch ping logs for the device in the time range, ordered ASC for timeline processing
        $logs_query = "
            (SELECT status, checked_at, rtt_avg
            FROM ping_logs
            WHERE device_id = ? AND link_id IS NULL
            AND checked_at >= ? AND checked_at <= ?)
            
            UNION ALL
            
            (SELECT status, checked_at, rtt_avg
            FROM ping_logs_archive
            WHERE device_id = ? AND link_id IS NULL
            AND checked_at >= ? AND checked_at <= ?)
            
            ORDER BY checked_at ASC
        ";
        
        $stmt = $con->prepare($logs_query);
        $stmt->bind_param('ississ', $selected_device_id, $start_time, $end_time, $selected_device_id, $start_time, $end_time);
        $stmt->execute();
        $stmt->bind_result($db_status, $db_checked_at, $db_rtt_avg);
        $logs = [];
        $sum_rtt = 0;
        $count_rtt = 0;
        
        // Prepare arrays for RTT chart with proper data
        $rtt_chart_labels = [];
        $rtt_chart_data = [];
        $rtt_chart_status = [];
        
        while ($stmt->fetch()) {
            $local_checked_at = date('Y-m-d H:i:s', strtotime($db_checked_at . ' UTC'));
            $row = [
                'status' => $db_status,
                'checked_at' => $local_checked_at,
                'rtt_avg' => $db_rtt_avg
            ];
            $logs[] = $row;
            if ($db_status === 'up' && $db_rtt_avg !== null) {
                $sum_rtt += $db_rtt_avg;
                $count_rtt++;
            }
            
            // Store for RTT chart
            $rtt_chart_labels[] = $local_checked_at;
            $rtt_chart_data[] = $db_rtt_avg !== null ? floatval($db_rtt_avg) : null;
            $rtt_chart_status[] = $db_status;
        }
        $stmt->close();

        if ($count_rtt > 0) {
            $avg_rtt = round($sum_rtt / $count_rtt, 1);
        }

        // Process logs to calculate periods, changes, availability
        $periods = [];
        $total_time = $end_time_raw - $start_time_raw;
        $total_up_time = 0;
        $total_down_time = 0;

        if (!empty($logs)) {
            $current_status = $logs[count($logs) - 1]['status']; // Latest status
            $last_status_change = null;
            $current_start = $logs[0]['checked_at']; // Start with first log
            $prev_status = $logs[0]['status'];

            for ($i = 1; $i < count($logs); $i++) {
                if ($logs[$i]['status'] !== $prev_status) {
                    // Status change detected
                    $period_end = $logs[$i]['checked_at'];
                    $duration = strtotime($period_end) - strtotime($current_start);
                    $periods[] = [
                        'status' => $prev_status,
                        'start' => $current_start,
                        'end' => $period_end,
                        'duration' => $duration
                    ];
                    if ($prev_status === 'up') {
                        $total_up_time += $duration;
                    } else {
                        $total_down_time += $duration;
                    }
                    $current_start = $period_end;
                    $prev_status = $logs[$i]['status'];
                    $last_status_change = $period_end;
                }
            }

            // Add the last (current) period up to end_time
            $duration = strtotime($end_time_local) - strtotime($current_start);
            $periods[] = [
                'status' => $prev_status,
                'start' => $current_start,
                'end' => $end_time_local,
                'duration' => $duration
            ];
            if ($prev_status === 'up') {
                $total_up_time += $duration;
            } else {
                $total_down_time += $duration;
            }

            $current_status_since = $current_start;
            if (!$last_status_change) {
                $last_status_change = $current_start; // No change in period
            }
            
            // Fetch comments for these periods
            if (!empty($periods)) {
                $comment_conditions = [];
                $comment_params = [];
                $comment_types = '';
                
                foreach ($periods as $index => $period) {
                    $comment_conditions[] = "(device_id = ? AND event_start_time = ?)";
                    $comment_params[] = $selected_device_id;
                    $comment_params[] = $period['start'];
                    $comment_types .= 'is';
                }
                
                if (!empty($comment_conditions)) {
                    $comments_query = "SELECT id, device_id, event_start_time, event_end_time, comments, action_taken, escalation_level, vendor_contacted, ticket_number, resolution_time, created_at FROM event_comments WHERE " . implode(' OR ', $comment_conditions);
                    $stmt = $con->prepare($comments_query);
                    $stmt->bind_param($comment_types, ...$comment_params);
                    $stmt->execute();
                    $stmt->bind_result($c_id, $c_dev_id, $c_start, $c_end, $c_comments, $c_action, $c_level, $c_vendor, $c_ticket, $c_res, $c_created);
                    while ($stmt->fetch()) {
                        $event_comments[$c_start] = [
                            'id' => $c_id,
                            'device_id' => $c_dev_id,
                            'event_start_time' => $c_start,
                            'event_end_time' => $c_end,
                            'comments' => $c_comments,
                            'action_taken' => $c_action,
                            'escalation_level' => $c_level,
                            'vendor_contacted' => $c_vendor,
                            'ticket_number' => $c_ticket,
                            'resolution_time' => $c_res,
                            'created_at' => $c_created
                        ];
                    }
                    $stmt->close();
                }
            }
        } else {
            $current_status = 'unknown';
            $current_status_since = 'N/A';
            $last_status_change = 'N/A';
        }

        // Calculate availability percentage
        $availability = $total_time > 0 ? round(($total_up_time / $total_time) * 100, 2) : 0;

        // Number of outages
        $num_outages = 0;
        $sum_outage_duration = 0;
        foreach ($periods as $period) {
            if ($period['status'] === 'down') {
                $num_outages++;
                $sum_outage_duration += $period['duration'];
            }
        }
        $avg_outage_duration = $num_outages > 0 ? $sum_outage_duration / $num_outages : 0;

        // Prepare report data
        $report_data = [
            'periods' => $periods,
            'total_logs' => count($logs),
            'start_time' => $start_time_local,
            'end_time' => $end_time_local,
            'total_up_time' => $total_up_time,
            'total_down_time' => $total_down_time,
            'num_outages' => $num_outages,
            'avg_outage_duration' => $avg_outage_duration
        ];

        // Prepare RTT data for chart with proper formatting
        $rtt_labels = $rtt_chart_labels;
        $rtt_data = $rtt_chart_data;

        if (isset($_POST['export_csv'])) {
            foreach ($periods as $period) {
                $comment = isset($event_comments[$period['start']]) ? $event_comments[$period['start']]['comments'] : '';
                $action = isset($event_comments[$period['start']]) ? $event_comments[$period['start']]['action_taken'] : '';
                $vendor = isset($event_comments[$period['start']]) ? $event_comments[$period['start']]['vendor_contacted'] : '';
                $ticket = isset($event_comments[$period['start']]) ? $event_comments[$period['start']]['ticket_number'] : '';
                
                fputcsv($output, [
                    strtoupper($period['status']),
                    date('Y-m-d H:i:s', strtotime($period['start'])),
                    date('Y-m-d H:i:s', strtotime($period['end'])),
                    formatDuration($period['duration']),
                    $comment,
                    $action,
                    $vendor,
                    $ticket
                ]);
            }
            fclose($output);
            exit;
        }
    }
}

mysqli_close($con);

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Reports - JanusNMS</title>

    <!-- LOCAL Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- LOCAL Font Awesome -->
    <link rel="stylesheet" href="css/font-awesome/css/all.min.css">
    <!-- LOCAL Bootstrap JS for dropdowns (loaded early for topbar) -->
    <script src="js/bootstrap.bundle.min.js"></script>

    <link rel="stylesheet" href="css/theme.css">

    <!-- LOCAL Chart.js -->
    <script src="js/chart.js"></script>
    <!-- Add Chart.js date adapter for time scale -->
    <script src="js/chartjs-adapter-date-fns.bundle.min.js"></script>

    <link rel="stylesheet" href="css/pages/avalibility.css">
</head>
<body class="loggedin">

<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <div class="container-fluid">
    
    <?php if ($message): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
        <div class="toast align-items-center text-bg-<?= strpos($message,'error')===0?'danger':'success' ?> border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <?php
                    $msg = $message;
                    if ($msg === 'comment_saved') $msg = 'Event comment saved successfully!';
                    echo htmlspecialchars(strpos($message,'error')===0 ? substr($message,6) : $msg);
                    ?>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
        <div class="row mb-4">
            <div class="col-12">
                <h2><i data-lucide="file-alt" class="icon-lucide"></i> Reports & Incident Management</h2>
                <p class="lead">Comprehensive device monitoring reports with incident tracking and resolution logging</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Select Device and Time Range</h5>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Device</label>
                        <select class="form-select" name="device_id" required>
                            <option value="">Select Device</option>
                            <?php foreach ($devices as $dev): ?>
                                <option value="<?= $dev['id'] ?>" <?= $dev['id'] == $selected_device_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dev['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Time Range</label>
                        <select class="form-select" name="time_range" id="time_range" required>
                            <option value="24h" <?= $time_range == '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                            <option value="7d" <?= $time_range == '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                            <option value="30d" <?= $time_range == '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                            <option value="custom" <?= $time_range == 'custom' ? 'selected' : '' ?>>Custom Range</option>
                        </select>
                    </div>
                    <div class="col-md-4 <?= $time_range !== 'custom' ? 'd-none' : '' ?>" id="custom-range">
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" value="<?= htmlspecialchars($start_datetime) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" value="<?= htmlspecialchars($end_datetime) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="line-chart" class="icon-lucide"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($report_data && $device_info): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        Report for <?= htmlspecialchars($device_info['name']) ?>
                        <small class="text-muted">(<?= $device_info['city'] ?>, <?= $device_info['sub_office'] ?>, <?= $device_info['country'] ?>)</small>
                    </h5>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="device_id" value="<?= $selected_device_id ?>">
                        <input type="hidden" name="time_range" value="<?= $time_range ?>">
                        <?php if ($time_range === 'custom'): ?>
                            <input type="hidden" name="start_datetime" value="<?= htmlspecialchars($start_datetime) ?>">
                            <input type="hidden" name="end_datetime" value="<?= htmlspecialchars($end_datetime) ?>">
                        <?php endif; ?>
                        <button type="submit" name="export_csv" value="1" class="btn btn-success btn-sm">
                            <i data-lucide="file-csv" class="icon-lucide"></i> Export to CSV
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="row mb-3 g-3">
                        <div class="col-md-3">
                            <strong>Current Status:</strong>
                            <span class="badge <?= $current_status == 'up' ? 'badge-up' : 'badge-down' ?>">
                                <?= strtoupper($current_status) ?>
                            </span>
                        </div>
                        <div class="col-md-3">
                            <strong>Current Status Since:</strong><br>
                            <?= $current_status_since ? date('Y-m-d H:i:s', strtotime($current_status_since)) : 'N/A' ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Last Status Change:</strong><br>
                            <?= $last_status_change ? date('Y-m-d H:i:s', strtotime($last_status_change)) : 'N/A' ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Availability:</strong>
                            <span class="fs-5 text-primary"><?= $availability ?>%</span>
                        </div>
                    </div>

                    <div class="row mb-3 g-3">
                        <div class="col-md-3">
                            <strong>Average RTT (when up):</strong><br>
                            <?= $avg_rtt ?> ms
                        </div>
                        <div class="col-md-3">
                            <strong>Total Uptime:</strong><br>
                            <?= formatDuration($report_data['total_up_time']) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Total Downtime:</strong><br>
                            <?= formatDuration($report_data['total_down_time']) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Number of Outages:</strong><br>
                            <?= $report_data['num_outages'] ?>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <strong>Average Outage Duration:</strong><br>
                            <?= $report_data['num_outages'] > 0 ? formatDuration($report_data['avg_outage_duration']) : 'N/A' ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Report Period:</strong><br>
                            <?= date('Y-m-d H:i:s', strtotime($report_data['start_time'])) ?> to
                            <?= date('Y-m-d H:i:s', strtotime($report_data['end_time'])) ?>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Availability Overview</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="availabilityChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">RTT Over Time</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="rttChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6 class="mb-3">Status Timeline with Incident Tracking</h6>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                    <th>Duration</th>
                                    <th>Actions/Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['periods'] as $period): 
                                    $has_comment = isset($event_comments[$period['start']]);
                                    $comment = $has_comment ? $event_comments[$period['start']] : null;
                                ?>
                                    <tr>
                                        <td>
                                            <span class="status-<?= $period['status'] ?>">
                                                <i data-lucide="circle" class="icon-lucide"></i> <?= strtoupper($period['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('Y-m-d H:i:s', strtotime($period['start'])) ?></td>
                                        <td><?= date('Y-m-d H:i:s', strtotime($period['end'])) ?></td>
                                        <td><?= formatDuration($period['duration']) ?></td>
                                        <td>
                                            <?php if ($period['status'] == 'down'): ?>
                                                <button class="btn btn-sm btn-warning" onclick="openCommentModal('<?= $period['start'] ?>', '<?= $period['end'] ?>', 'down')">
                                                    <i data-lucide="triangle-alert" class="icon-lucide"></i> Log Resolution
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="openCommentModal('<?= $period['start'] ?>', '<?= $period['end'] ?>', 'up')">
                                                    <i data-lucide="comment" class="icon-lucide"></i> Add Note
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($has_comment): ?>
                                                <span class="badge bg-success" data-bs-toggle="tooltip" title="<?= htmlspecialchars($comment['comments']) ?>">
                                                    <i data-lucide="check-circle" class="icon-lucide"></i> Logged
                                                </span>
                                                <?php if (!empty($comment['vendor_contacted'])): ?>
                                                    <span class="badge bg-info"><?= htmlspecialchars($comment['vendor_contacted']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($comment['ticket_number'])): ?>
                                                    <span class="badge bg-secondary">#<?= htmlspecialchars($comment['ticket_number']) ?></span>
                                                <?php endif; ?>
                                                <span class="escalation-badge escalation-<?= $comment['escalation_level'] ?>">
                                                    L<?= $comment['escalation_level'] ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($has_comment && !empty($comment['comments'])): ?>
                                                <div class="timeline-comment">
                                                    <i data-lucide="quote-left" class="icon-lucide"></i> <?= htmlspecialchars(substr($comment['comments'], 0, 50)) ?>...
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($report_data['periods'])): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">
                                            No logs available for this period.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Comment Modal -->
<div class="modal fade" id="commentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="">
                <input type="hidden" name="save_comment" value="1">
                <input type="hidden" name="device_id" value="<?= $selected_device_id ?>">
                <input type="hidden" name="time_range" value="<?= $time_range ?>">
                <?php if ($time_range === 'custom'): ?>
                    <input type="hidden" name="start_datetime" value="<?= htmlspecialchars($start_datetime) ?>">
                    <input type="hidden" name="end_datetime" value="<?= htmlspecialchars($end_datetime) ?>">
                <?php endif; ?>
                <input type="hidden" name="event_start" id="modal_event_start">
                <input type="hidden" name="event_end" id="modal_event_end">
                
                <div class="modal-header">
                    <h5 class="modal-title">Log Incident Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Event Time</label>
                            <input type="text" class="form-control" id="modal_event_time_display" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Event Type</label>
                            <input type="text" class="form-control" id="modal_event_type" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Action Taken *</label>
                        <select name="action_taken" class="form-select" required>
                            <option value="">-- Select Action --</option>
                            <option value="ISP Contacted">ISP Contacted</option>
                            <option value="Vendor Contacted">Vendor Contacted</option>
                            <option value="Internal Team Notified">Internal Team Notified</option>
                            <option value="Ticket Created">Ticket Created</option>
                            <option value="Emergency Maintenance">Emergency Maintenance</option>
                            <option value="Hardware Replaced">Hardware Replaced</option>
                            <option value="Configuration Change">Configuration Change</option>
                            <option value="Power Cycled">Power Cycled</option>
                            <option value="Escalated">Escalated</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Comments / Resolution Details</label>
                        <textarea name="comments" class="form-control" rows="4" placeholder="Describe what happened, who was contacted, what was done..."></textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Vendor/ISP Contacted</label>
                            <input type="text" name="vendor_contacted" class="form-control" placeholder="e.g., PTCL, Nayatel, Cisco TAC">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ticket/Reference Number</label>
                            <input type="text" name="ticket_number" class="form-control" placeholder="e.g., INC123456, SR7890">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Escalation Level</label>
                            <select name="escalation_level" class="form-select">
                                <option value="1">Level 1 - Initial Response</option>
                                <option value="2">Level 2 - Technical Team</option>
                                <option value="3">Level 3 - Management/Escalated</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Resolution Time (if resolved)</label>
                            <input type="datetime-local" name="resolution_time" class="form-control">
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i data-lucide="info" class="icon-lucide"></i> Logging actions helps track incident response time and vendor performance.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Action Log</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Custom range toggle
document.getElementById('time_range').addEventListener('change', function() {
    document.getElementById('custom-range').classList.toggle('d-none', this.value !== 'custom');
});

// Sidebar margin adjustment
document.addEventListener('DOMContentLoaded', function(){
    const s = document.getElementById('sidebar');
    const c = document.getElementById('main-content');

    function adj(){
        c.style.marginLeft = s && s.classList.contains('collapsed') ? '80px' : '250px';
    }

    adj();

    if(s) {
        new MutationObserver(adj).observe(s, {
            attributes: true,
            attributeFilter: ['class']
        });
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Show toasts
    document.querySelectorAll('.toast').forEach(t => new bootstrap.Toast(t).show());
});

// Comment modal function
function openCommentModal(startTime, endTime, eventType) {
    document.getElementById('modal_event_start').value = startTime;
    document.getElementById('modal_event_end').value = endTime;
    document.getElementById('modal_event_time_display').value = startTime + ' to ' + endTime;
    document.getElementById('modal_event_type').value = eventType.toUpperCase();
    
    var modal = new bootstrap.Modal(document.getElementById('commentModal'));
    modal.show();
}

// Charts
<?php if ($report_data): ?>
    // Get CSS variable for text color
    function getCssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }
    
    // Availability Pie Chart
    new Chart(document.getElementById('availabilityChart'), {
        type: 'pie',
        data: {
            labels: ['Uptime', 'Downtime'],
            datasets: [{
                data: [<?= $report_data['total_up_time'] ?>, <?= $report_data['total_down_time'] ?>],
                backgroundColor: ['#00c853', '#d50000']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: getCssVar('--text')
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return label + ': ' + formatDuration(value) + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });

    // RTT Line Chart with time adapter
    new Chart(document.getElementById('rttChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($rtt_labels) ?>,
            datasets: [{
                label: 'RTT (ms)',
                data: <?= json_encode($rtt_data) ?>,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: function(context) {
                    // Color points based on status (if available)
                    const index = context.dataIndex;
                    if (<?= json_encode($rtt_chart_status) ?> && <?= json_encode($rtt_chart_status) ?>[index] === 'down') {
                        return '#dc3545';
                    }
                    return '#007bff';
                }
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: <?= $time_range == '24h' ? "'hour'" : ($time_range == '7d' ? "'day'" : "'day'") ?>,
                        displayFormats: {
                            hour: 'HH:mm',
                            day: 'MMM d'
                        },
                        tooltipFormat: 'MMM d, yyyy HH:mm:ss'
                    },
                    title: {
                        display: true,
                        text: 'Time',
                        color: getCssVar('--text')
                    },
                    ticks: {
                        color: getCssVar('--text'),
                        maxRotation: 45,
                        minRotation: 45
                    },
                    grid: {
                        color: getCssVar('--border')
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Round Trip Time (ms)',
                        color: getCssVar('--text')
                    },
                    ticks: {
                        color: getCssVar('--text')
                    },
                    grid: {
                        color: getCssVar('--border')
                    },
                    min: 0
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: getCssVar('--text')
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let value = context.raw;
                            if (value === null) {
                                return label + ': Device Down';
                            }
                            return label + ': ' + value + ' ms';
                        }
                    }
                }
            }
        }
    });
<?php endif; ?>

// Helper function for duration formatting (used in chart tooltips)
function formatDuration(seconds) {
    if (!seconds) return '0 seconds';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    let parts = [];
    if (days > 0) parts.push(days + ' day' + (days > 1 ? 's' : ''));
    if (hours > 0) parts.push(hours + ' hour' + (hours > 1 ? 's' : ''));
    if (minutes > 0) parts.push(minutes + ' minute' + (minutes > 1 ? 's' : ''));
    if (secs > 0 || parts.length === 0) parts.push(secs + ' second' + (secs > 1 ? 's' : ''));
    return parts.join(', ');
}
</script>

</body>
</html>
