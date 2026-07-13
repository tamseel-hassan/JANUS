<?php
require_once __DIR__ . '/../../db_config.php';
/**
 * ipam_log.php  –  IPAM Change / Alert Log viewer
 *
 * Improvements vs old version:
 *  - Uses local CSS/JS (no CDN)
 *  - Filter by alert type
 *  - Shows acknowledged status + who/when
 *  - Inline acknowledge button
 *  - Pagination (50 per page)
 *  - Unacked rows highlighted
 */
session_start();
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    header('Location: ../index.html'); exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) die('DB Error');

/* ── Pagination & Filters ── */
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;
$type_filter = $_GET['type'] ?? '';
$ip_filter   = trim($_GET['ip'] ?? '');

/* ── Build WHERE clause ── */
$where_parts = [];
$params      = [];
$param_types = '';
if ($type_filter === 'spoof') {
    $where_parts[] = "note LIKE '%spoof%'";
} elseif ($type_filter === 'mac_change') {
    $where_parts[] = "(note LIKE '%MAC%change%' AND note NOT LIKE '%spoof%')";
} elseif ($type_filter === 'offline') {
    $where_parts[] = "note LIKE '%offline%'";
} elseif ($type_filter === 'online') {
    $where_parts[] = "note LIKE '%online%'";
} elseif ($type_filter === 'new') {
    $where_parts[] = "note LIKE '%discovered%'";
} elseif ($type_filter === 'unacked') {
    $where_parts[] = "acknowledged = 0";
}
if ($ip_filter) {
    $like = '%' . $ip_filter . '%';
    $where_parts[] = "ip LIKE ?";
    $params[]      = $like;
    $param_types  .= 's';
}
$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

/* ── Count total ── */
$count_sql = "SELECT COUNT(*) AS c FROM janus_ipam_log $where";
if ($params) {
    $stmt_c = $db->prepare($count_sql);
    $stmt_c->bind_param($param_types, ...$params);
    $stmt_c->execute();
    $total_rows = $stmt_c->get_result()->fetch_assoc()['c'] ?? 0;
    $stmt_c->close();
} else {
    $total_rows = $db->query($count_sql)->fetch_assoc()['c'] ?? 0;
}
$total_pages = (int)ceil($total_rows / $per_page);

/* ── Fetch page ── */
$data_sql = "SELECT * FROM janus_ipam_log $where ORDER BY changed_at DESC LIMIT ? OFFSET ?";
$page_params = array_merge($params, [$per_page, $offset]);
$page_types  = $param_types . 'ii';
$stmt_d = $db->prepare($data_sql);
$stmt_d->bind_param($page_types, ...$page_params);
$stmt_d->execute();
$logs = $stmt_d->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_d->close();
$db->close();

/* ── Summary counts ── */
$db2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$counts = [
    'all'        => $db2->query("SELECT COUNT(*) FROM janus_ipam_log")->fetch_row()[0],
    'spoof'      => $db2->query("SELECT COUNT(*) FROM janus_ipam_log WHERE note LIKE '%spoof%'")->fetch_row()[0],
    'mac_change' => $db2->query("SELECT COUNT(*) FROM janus_ipam_log WHERE note LIKE '%MAC%change%' AND note NOT LIKE '%spoof%'")->fetch_row()[0],
    'offline'    => $db2->query("SELECT COUNT(*) FROM janus_ipam_log WHERE note LIKE '%offline%'")->fetch_row()[0],
    'online'     => $db2->query("SELECT COUNT(*) FROM janus_ipam_log WHERE note LIKE '%online%'")->fetch_row()[0],
    'unacked'    => $db2->query("SELECT COUNT(*) FROM janus_ipam_log WHERE acknowledged=0")->fetch_row()[0],
];
$db2->close();

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus– IPAM Change Log</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/font-aweso/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/pages/ipam_log.css">
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; include __DIR__ . '/../../sidebar.php'; ?>

<div id="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i data-lucide="history" class="icon-lucide me-2"></i>IPAM Change Log</h2>
            <p class="text-muted small mb-0">All MAC address changes, spoofing alerts, and device state events</p>
        </div>
        <a href="/modules/ipam/ipam.php" class="btn btn-sm btn-outline-primary">
            <i data-lucide="arrow-left" class="icon-lucide me-1"></i>Back to IPAM
        </a>
    </div>

    <!-- Filter tabs -->
    <ul class="nav nav-tabs mb-3 flex-wrap">
        <?php
        $tabs = [
            ''          => ['label' => 'All',        'icon' => 'fa-list',         'count' => $counts['all']],
            'unacked'   => ['label' => 'Unacked',    'icon' => 'fa-bell',         'count' => $counts['unacked']],
            'spoof'     => ['label' => 'Spoofing',   'icon' => 'fa-skull-crossbones','count' => $counts['spoof']],
            'mac_change'=> ['label' => 'MAC Change', 'icon' => 'fa-exchange-alt', 'count' => $counts['mac_change']],
            'offline'   => ['label' => 'Offline',    'icon' => 'fa-times-circle', 'count' => $counts['offline']],
            'online'    => ['label' => 'Online',     'icon' => 'fa-check-circle', 'count' => $counts['online']],
        ];
        foreach ($tabs as $key => $t):
            $active = ($type_filter === $key) ? 'active' : '';
            $badge_class = ($key==='spoof') ? 'danger' : (($key==='unacked') ? 'warning' : 'secondary');
        ?>
        <li class="nav-item">
            <a class="nav-link filter-tab <?= $active ?>"
               href="?type=<?= $key ?>&ip=<?= urlencode($ip_filter) ?>">
                <i class="fas <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
                <span class="badge bg-<?= $badge_class ?> ms-1"><?= $t['count'] ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- IP Search -->
    <form method="GET" class="mb-3 d-flex gap-2">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">
        <input type="text" name="ip" class="form-control form-control-sm" style="max-width:200px"
               placeholder="Filter by IP…" value="<?= htmlspecialchars($ip_filter) ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
            <i data-lucide="filter" class="icon-lucide"></i>
        </button>
        <?php if ($ip_filter): ?>
        <a href="?type=<?= urlencode($type_filter) ?>" class="btn btn-sm btn-outline-danger">
            <i data-lucide="x" class="icon-lucide"></i>
        </a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:130px">IP</th>
                            <th style="width:155px">Old MAC</th>
                            <th style="width:155px">New MAC</th>
                            <th>Note / Alert</th>
                            <th style="width:150px">Changed At</th>
                            <th style="width:130px">Status</th>
                            <th style="width:90px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">
                            <i data-lucide="inbox" class="icon-lucide me-2"></i>No log entries found.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log):
                            $is_spoof  = stripos($log['note'],'spoof') !== false;
                            $is_change = !$is_spoof && stripos($log['note'],'MAC') !== false;
                            $is_off    = stripos($log['note'],'offline') !== false;
                            $is_on     = stripos($log['note'],'online') !== false;
                            $unacked   = !$log['acknowledged'];

                            $row_cls  = 'row-unacked ' . ($is_spoof ? 'row-spoof' :
                                        ($is_change ? 'row-change' :
                                        ($is_off    ? 'row-offline' :
                                        ($is_on     ? 'row-online' : ''))));
                            if (!$unacked) $row_cls = ltrim(str_replace('row-unacked','',$row_cls));

                            $type_badge = $is_spoof
                                ? '<span class="badge bg-danger"><i data-lucide="skull-crossbones" class="icon-lucide me-1"></i>SPOOFING</span>'
                                : ($is_change
                                    ? '<span class="badge bg-warning text-dark">MAC Change</span>'
                                    : ($is_off
                                        ? '<span class="badge bg-secondary">Offline</span>'
                                        : ($is_on
                                            ? '<span class="badge bg-success">Online</span>'
                                            : '<span class="badge bg-info">Info</span>')));
                        ?>
                        <tr class="<?= $row_cls ?>">
                            <td class="fw-semibold font-monospace small"><?= htmlspecialchars($log['ip']) ?></td>
                            <td class="mac-cell text-muted"><?= htmlspecialchars($log['old_mac']??'—') ?></td>
                            <td class="mac-cell"><?= htmlspecialchars($log['new_mac']??'—') ?></td>
                            <td>
                                <?= $type_badge ?>
                                <span class="ms-2 small"><?= htmlspecialchars($log['note']) ?></span>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($log['changed_at']??'') ?></td>
                            <td>
                                <?php if ($log['acknowledged']): ?>
                                    <span class="badge bg-success">
                                        <i data-lucide="check" class="icon-lucide me-1"></i>ACK'd
                                    </span>
                                    <div class="small text-muted mt-1">
                                        by <?= htmlspecialchars($log['acknowledged_by']??'?') ?>
                                        <?php if ($log['ack_note']): ?>
                                        <br><em><?= htmlspecialchars(mb_strimwidth($log['ack_note'],0,40,'…')) ?></em>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i data-lucide="exclamation" class="icon-lucide me-1"></i>Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$log['acknowledged']): ?>
                                <button class="btn btn-warning btn-sm py-0 px-2 ack-btn"
                                    data-log-id="<?= intval($log['id']) ?>"
                                    data-ip="<?= htmlspecialchars($log['ip']) ?>"
                                    title="Acknowledge">
                                    <i data-lucide="check" class="icon-lucide fa-xs"></i> Ack
                                </button>
                                <?php else: ?>
                                <button class="btn btn-outline-secondary btn-sm py-0 px-2" disabled>
                                    <i data-lucide="check" class="icon-lucide fa-xs"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted">
                Showing <?= count($logs) ?> of <?= $total_rows ?> entries
            </span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?= $p==$page?'active':'' ?>">
                        <a class="page-link"
                           href="?type=<?= urlencode($type_filter) ?>&ip=<?= urlencode($ip_filter) ?>&page=<?= $p ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<!-- Ack Modal -->
<div class="modal fade" id="ackModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h6 class="modal-title"><i data-lucide="check-circle" class="icon-lucide me-1"></i>Acknowledge</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2" id="ackInfoText"></p>
                <label class="form-label small fw-semibold">Note (optional)</label>
                <textarea id="ackNoteInput" class="form-control form-control-sm" rows="3"
                          placeholder="Reason / action taken…"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning btn-sm" id="confirmAckBtn">
                    <i data-lucide="check" class="icon-lucide me-1"></i>Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toast-area"></div>
<script src="/js/bootstrap.bundle.min.js"></script>
<script>
const ackModal = new bootstrap.Modal('#ackModal');
let pendingLogId = null;

function showToast(type, msg) {
    const el = document.createElement('div');
    el.className = `alert alert-${type} alert-dismissible shadow py-2 px-3`;
    el.style.cssText = 'min-width:250px;font-size:.875rem';
    el.innerHTML = msg + `<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.getElementById('toast-area').prepend(el);
    setTimeout(() => el.remove(), 5000);
}

document.querySelectorAll('.ack-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        pendingLogId = btn.dataset.logId;
        document.getElementById('ackInfoText').innerHTML =
            `Log entry for <strong>${btn.dataset.ip}</strong>`;
        document.getElementById('ackNoteInput').value = '';
        ackModal.show();
    });
});

document.getElementById('confirmAckBtn').addEventListener('click', function() {
    if (!pendingLogId) return;
    const note = document.getElementById('ackNoteInput').value.trim();
    const fd   = new FormData();
    fd.append('action',    'ack_log');
    fd.append('log_id',    pendingLogId);
    fd.append('note',      note);
    fetch('/soc/ipam_ajax.php', {method:'POST', body:fd})
        .then(r => r.text())
        .then(t => {
            ackModal.hide();
            if (t.trim() === 'ok') {
                showToast('success', 'Alert acknowledged');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('danger', 'Failed: ' + t);
            }
        })
        .catch(e => showToast('danger', e.message));
});

// Sidebar + topbar sync
const sb = document.getElementById('sidebar');
const mc = document.getElementById('main-content');
const tb = document.querySelector('.topbar');
const syncLayout = () => {
    const w = sb && sb.classList.contains('collapsed') ? '64px' : '260px';
    if(mc) { mc.style.marginLeft = w; mc.style.transition = 'margin-left 0.3s ease'; }
    if(tb) { tb.style.left = w; tb.style.transition = 'left 0.3s ease'; }
};
if(sb) {
    new MutationObserver(syncLayout).observe(sb, {attributes:true, attributeFilter:['class']});
    syncLayout();
}
</script>
</body>
</html>
