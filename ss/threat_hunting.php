<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);
require_once __DIR__ . '/../db_config.php';
// ss/threat_hunting.php - Consolidated notable-event feed for manual hunting
session_start();
if (!isset($_SESSION['loggedin'])) { die('Unauthorized'); }
session_write_close();
require_once __DIR__ . '/_helpers.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (mysqli_connect_errno()) { echo '<div class="alert alert-danger">DB connection failed: ' . mysqli_connect_error() . '</div>'; exit; }

$start  = $_GET['start']  ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end    = $_GET['end']    ?? date('Y-m-d H:i:s');
$device = $_GET['device'] ?? '';
$search = trim($_GET['q'] ?? '');
$device_where = $device ? "AND source_ip = '" . mysqli_real_escape_string($con, $device) . "'" : '';

$notable_filter = "is_notable = 1";

$search_where = $search ? " AND message LIKE '%" . mysqli_real_escape_string($con, $search) . "%'" : '';

$where = "received_at BETWEEN '" . mysqli_real_escape_string($con, $start) . "' AND '" . mysqli_real_escape_string($con, $end) . "'
          AND $notable_filter $device_where $search_where";

require_once __DIR__ . '/../includes/cache.php';
$range = $_GET['range'] ?? '24h';
$cache_ttl = cache_ttl_for_range($range);
$cache_key = get_bucketed_cache_key("threat_hunting", $start, $end, $device . "_" . $search, $range);

$cached = query_cache($cache_key, $cache_ttl, function() use ($con, $where, $start, $end, $notable_filter, $device_where, $search_where) {
    $result = unionQuery($con, 'message, source_ip, received_at', $where, 'received_at DESC', 150);

    $events = [];
    $severity_counts = ['critical' => 0, 'high' => 0, 'medium' => 0];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $p = parseMessage($row['message']);
            $lower = strtolower($row['message']);
            if (strpos($lower, 'virus') !== false || strpos($lower, 'malware') !== false || strpos($lower, 'botnet') !== false || strpos($lower, 'c2') !== false) {
                $sev = 'critical';
            } elseif (strpos($lower, 'exploit') !== false || strpos($lower, 'attack') !== false || strpos($lower, 'ips') !== false) {
                $sev = 'high';
            } else {
                $sev = 'medium';
            }
            $severity_counts[$sev]++;
            $events[] = [
                'time' => $row['received_at'],
                'source' => $row['source_ip'],
                'severity' => $sev,
                'action' => $p['action'] ?? 'n/a',
                'summary' => $p['msg'] ?? mb_substr($row['message'], 0, 140),
            ];
        }
    }

    $total_notable_sql = "
        SELECT COUNT(*) AS total FROM (
            SELECT 1 FROM syslog_entries WHERE received_at BETWEEN '$start' AND '$end' AND $notable_filter $device_where $search_where
            UNION ALL
            SELECT 1 FROM syslog_entries_archive WHERE received_at BETWEEN '$start' AND '$end' AND $notable_filter $device_where $search_where
        ) AS combined
    ";
    $total_res = mysqli_query($con, $total_notable_sql);
    $total_notable = $total_res ? (int)mysqli_fetch_assoc($total_res)['total'] : count($events);

    return compact('events', 'severity_counts', 'total_notable');
});

if ($cached === null) {
    echo '<div class="alert alert-danger">Query error — please refresh.</div>';
    mysqli_close($con);
    exit;
}
extract($cached);

mysqli_close($con);
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="crosshair" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $total_notable ?>">0</div>
            <span class="stat-chip chip-danger">Notable Events</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-danger"><i data-lucide="biohazard" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $severity_counts['critical'] ?>">0</div>
            <span class="stat-chip chip-danger">Critical</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-warning"><i data-lucide="triangle-alert" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $severity_counts['high'] ?>">0</div>
            <span class="stat-chip chip-warning">High</span>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box">
            <div class="stat-icon text-info"><i data-lucide="info" class="icon-lucide"></i></div>
            <div class="stat-value" data-raw="<?= $severity_counts['medium'] ?>">0</div>
            <span class="stat-chip chip-info">Medium</span>
        </div>
    </div>
</div>

<div class="report-card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <h5 style="border-bottom:none; padding-bottom:0; margin-bottom:0;"><i data-lucide="search" class="icon-lucide"></i> Notable Event Feed</h5>
        <form onsubmit="event.preventDefault(); huntSearch();" style="display:flex; gap:8px;">
            <input type="text" id="huntQuery" class="form-control" placeholder="Search message content&hellip;" value="<?= htmlspecialchars($search) ?>" style="width:220px;">
            <button class="btn btn-outline-primary btn-sm" type="submit"><i data-lucide="search" class="icon-lucide"></i></button>
        </form>
    </div>
    <div class="table-container" style="max-height:520px;">
        <table class="table table-hover">
            <thead><tr><th>Time</th><th>Severity</th><th>Source</th><th>Action</th><th>Summary</th></tr></thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td><small><?= date('M d, H:i:s', strtotime($e['time'])) ?></small></td>
                        <td><span class="sev-pill sev-<?= $e['severity'] === 'critical' ? 'critical' : ($e['severity'] === 'high' ? 'high' : 'medium') ?>"><?= strtoupper($e['severity']) ?></span></td>
                        <td><a href="javascript:void(0)" onclick="drillDownIP('<?= htmlspecialchars($e['source']) ?>')" class="clickable-ip"><?= htmlspecialchars($e['source']) ?></a></td>
                        <td><small><?= htmlspecialchars($e['action']) ?></small></td>
                        <td><small><?= htmlspecialchars($e['summary']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($events)): ?>
                    <tr><td colspan="5" class="text-center">No notable events matched in this window</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function huntSearch() {
    const q = document.getElementById('huntQuery').value;
    loadReportContent('threat_hunting', 'q=' + encodeURIComponent(q));
}
</script>
