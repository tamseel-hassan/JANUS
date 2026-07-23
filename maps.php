<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_config.php';
date_default_timezone_set('Asia/Karachi');
$theme = $_COOKIE['theme'] ?? 'dark';

// ── Map CRUD ────────────────────────────────────────────────────────────────
if ($_POST && isset($_POST['create_map'])) {
    $name = mysqli_real_escape_string($con, $_POST['map_name']);
    $stmt = $con->prepare("INSERT INTO topology_maps (name, created_at) VALUES (?, NOW())");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $new_map_id = $stmt->insert_id;
    $stmt->close();
    $ds = $con->prepare("SELECT id FROM devices");
    $ds->execute();
    $dr = $ds->get_result();
    while ($dev = $dr->fetch_assoc()) {
        $vs = $con->prepare("INSERT INTO map_device_visibility (map_id, device_id, is_visible) VALUES (?, ?, TRUE)");
        $vs->bind_param('ii', $new_map_id, $dev['id']);
        $vs->execute(); $vs->close();
    }
    $ds->close();
    header('Location: maps.php?map_id=' . $new_map_id); exit;
}

// ── Device visibility (single) ───────────────────────────────────────────────
if ($_POST && isset($_POST['update_visibility'])) {
    $map_id    = intval($_POST['map_id']);
    $device_id = intval($_POST['device_id']);
    $is_visible = isset($_POST['is_visible']) ? 1 : 0;
    $stmt = $con->prepare("INSERT INTO map_device_visibility (map_id, device_id, is_visible) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE is_visible = ?");
    $stmt->bind_param('iiii', $map_id, $device_id, $is_visible, $is_visible);
    $stmt->execute(); $stmt->close();
    echo json_encode(['status' => 'success']); exit;
}

// ── Bulk visibility ──────────────────────────────────────────────────────────
if ($_POST && isset($_POST['bulk_visibility'])) {
    $map_id     = intval($_POST['map_id']);
    $device_ids = isset($_POST['device_ids']) && is_array($_POST['device_ids']) ? $_POST['device_ids'] : [];
    $is_visible = intval($_POST['is_visible']);
    foreach ($device_ids as $did) {
        $did = intval($did);
        if ($did <= 0) continue;
        $stmt = $con->prepare("INSERT INTO map_device_visibility (map_id, device_id, is_visible) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE is_visible = ?");
        $stmt->bind_param('iiii', $map_id, $did, $is_visible, $is_visible);
        $stmt->execute(); $stmt->close();
    }
    $_SESSION['message'] = 'visibility_updated';
    header('Location: maps.php?map_id=' . $map_id); exit;
}

// ── Delete map ───────────────────────────────────────────────────────────────
if (isset($_GET['delete_map'])) {
    $map_id = intval($_GET['delete_map']);
    foreach (['device_positions','map_device_visibility'] as $tbl) {
        $s = $con->prepare("DELETE FROM $tbl WHERE map_id = ?");
        $s->bind_param('i', $map_id); $s->execute(); $s->close();
    }
    $s = $con->prepare("DELETE FROM topology_maps WHERE id = ?");
    $s->bind_param('i', $map_id); $s->execute(); $s->close();
    header('Location: maps.php'); exit;
}

// ── Fetch maps ───────────────────────────────────────────────────────────────
$all_maps = [];
$mr = mysqli_query($con, "SELECT * FROM topology_maps ORDER BY name ASC");
while ($r = mysqli_fetch_assoc($mr)) $all_maps[] = $r;

$current_map_id = isset($_GET['map_id']) ? intval($_GET['map_id']) : null;
if (!$current_map_id && count($all_maps) > 0) $current_map_id = $all_maps[0]['id'];
$current_map = null;
foreach ($all_maps as $m) { if ($m['id'] == $current_map_id) { $current_map = $m; break; } }

// ── Devices query (includes icon_set from device_positions) ─────────────────
// Using mysqli_query with direct interpolation is safe here since
// $current_map_id is already cast to int above.
$cmi = intval($current_map_id);
$devices_query = "
    SELECT
        d.id, d.name, d.ip, d.type, d.model, d.city, d.sub_office, d.country,
        COALESCE(dsc.status, 'down')             AS status,
        COALESCE(dsc.rtt_avg, 0)                 AS rtt_avg,
        COALESCE(dsc.checked_at, NOW())          AS checked_at,
        COALESCE(dp.x, 50 + (d.id % 5)*150)     AS x,
        COALESCE(dp.y, 100 + FLOOR(d.id/5)*120) AS y,
        COALESCE(mdv.is_visible, 1)              AS is_visible,
        dsc.last_status_change,
        dp.icon_set,
        COALESCE(dp.icon_size, 36)               AS icon_size
    FROM devices d
    LEFT JOIN device_status_cache   dsc ON d.id = dsc.device_id
    LEFT JOIN device_positions      dp  ON d.id = dp.device_id  AND dp.map_id  = $cmi
    LEFT JOIN map_device_visibility mdv ON d.id = mdv.device_id AND mdv.map_id = $cmi
    ORDER BY d.name
";
$devices_result = mysqli_query($con, $devices_query);
if (!$devices_result) exit('Devices query error: ' . mysqli_error($con));
$devices = [];
while ($row = mysqli_fetch_assoc($devices_result)) $devices[] = $row;

// ── All devices (for selector modal) ────────────────────────────────────────
$all_devices = [];
$adr = mysqli_query($con, "SELECT id, name, ip, type FROM devices ORDER BY name");
while ($r = mysqli_fetch_assoc($adr)) $all_devices[] = $r;

// ── Visibility settings ──────────────────────────────────────────────────────
$visibility_settings = [];
$vr = mysqli_query($con, "SELECT device_id, is_visible FROM map_device_visibility WHERE map_id = $cmi");
while ($r = mysqli_fetch_assoc($vr)) $visibility_settings[$r['device_id']] = $r['is_visible'];

// ── Links ────────────────────────────────────────────────────────────────────
$links = [];
$lr = mysqli_query($con, "
    SELECT l.id, l.name, l.from_device_id, l.to_device_id,
           COALESCE(pl.status,'down') AS status
    FROM links l
    LEFT JOIN ping_logs pl ON l.id = pl.link_id
        AND pl.checked_at = (SELECT MAX(checked_at) FROM ping_logs WHERE link_id = l.id)
    ORDER BY l.name
");
while ($r = mysqli_fetch_assoc($lr)) $links[] = $r;

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ── Icon-set helpers ─────────────────────────────────────────────────────────
// Maps a device_type  →  default icon prefix
$type_to_icon = [
    'firewall'     => 'firewall',
    'switch'       => 'switch',
    'router'       => 'router',
    'server'       => 'server',
    'iot'          => 'node',
    'wireless'     => 'node',
    'loadbalancer' => 'node',
    'storage'      => 'server',
    'other'        => 'node',
    'others'       => 'node',
];

// Returns the icon filename prefix for a device row
function getIconPrefix(array $device, array $type_to_icon): string {
    // If a custom icon_set was saved for this device on this map, use it
    if (!empty($device['icon_set'])) return $device['icon_set'];
    $type = strtolower(trim($device['type']));
    return $type_to_icon[$type] ?? 'node';
}

// Build the <img> filename from prefix + status
function iconFilename(string $prefix, string $status): string {
    // node set uses nodeup/nodedown (no dash), everything else uses prefix-up/prefix-down
    if ($prefix === 'node') return "node{$status}";
    return "{$prefix}-{$status}";
}

mysqli_close($con);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <title>Network Topology Maps - JanusNMS</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/font-awesome/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/theme.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/css/pages/maps.css?v=<?= time() ?>">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content">
    <script>
    function updateContentMargin() {
        const sb = document.getElementById('sidebar');
        const mc = document.getElementById('main-content');
        if (!document.body.classList.contains('fullscreen')) {
            mc.style.marginLeft = sb && sb.classList.contains('collapsed') ? '80px' : '260px';
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateContentMargin();
        const sb = document.getElementById('sidebar');
        if (sb) new MutationObserver(() => updateContentMargin()).observe(sb, {attributes:true});
    });
    </script>

    <?php if ($message): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1100;">
        <div class="toast align-items-center text-bg-success border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body"><?= htmlspecialchars($message === 'visibility_updated' ? 'Device visibility updated!' : $message) ?></div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="header-row" class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2><i data-lucide="map-marked-alt" class="icon-lucide"></i> Network Topology Maps</h2>
            <p class="text-muted mb-0">Visualize your network — right-click a device to change its icon set</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($current_map): ?>
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deviceSelectorModal">
                <i data-lucide="eye" class="icon-lucide"></i> Manage Devices
            </button>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a href="icon_manager.php" class="btn btn-outline-secondary btn-sm">
                <i data-lucide="icons" class="icon-lucide"></i> Icon Manager
            </a>
            <?php endif; ?>
            <button id="toggle-fullscreen-btn" class="btn btn-primary btn-sm"><i data-lucide="expand" class="icon-lucide"></i> Maximize Map</button>
            <button class="btn btn-warning btn-sm" onclick="toggleDebugPanel()"><i data-lucide="bug" class="icon-lucide"></i> Debug</button>
        </div>
    </div>

    <div id="debug-panel">
        <strong>Debug Info</strong><br>
        Last update: <span id="debug-last-update">Never</span><br>
        Updates: <span id="debug-update-count">0</span>
    </div>

    <!-- Map selector -->
    <div id="map-selector">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <strong><i data-lucide="layers" class="icon-lucide"></i> Select Map:</strong>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i data-lucide="map" class="icon-lucide me-1"></i> <?= htmlspecialchars($current_map['name'] ?? 'Select Map') ?>
                    </button>
                    <ul class="dropdown-menu">
                        <?php foreach ($all_maps as $map): ?>
                        <li>
                            <a class="dropdown-item <?= $map['id'] == $current_map_id ? 'active' : '' ?>" href="maps.php?map_id=<?= $map['id'] ?>">
                                <i data-lucide="map-pin" class="icon-lucide me-2"></i> <?= htmlspecialchars($map['name']) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createMapModal">
                <i data-lucide="plus" class="icon-lucide"></i> New Map
            </button>
        </div>
        <div class="map-tabs">
            <?php foreach ($all_maps as $map): ?>
            <div class="map-tab <?= $map['id'] == $current_map_id ? 'active' : '' ?>"
                 onclick="window.location.href='maps.php?map_id=<?= $map['id'] ?>'">
                <i data-lucide="map" class="icon-lucide"></i>
                <span><?= htmlspecialchars($map['name']) ?></span>
                <?php if (count($all_maps) > 1): ?>
                <a href="?delete_map=<?= $map['id'] ?>" class="delete-map"
                   onclick="event.stopPropagation();return confirm('Delete this map?')">
                    <i data-lucide="x" class="icon-lucide"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($current_map): ?>
        <small class="text-muted">
            Viewing: <strong><?= htmlspecialchars($current_map['name']) ?></strong>
            &bull; Visible: <span id="visible-device-count"><?= count(array_filter($devices, fn($d) => $d['is_visible'])) ?></span>/<?= count($all_devices) ?>
        </small>
        <?php endif; ?>
    </div>

    <!-- Device type filter -->
    <div id="device-filter">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><i data-lucide="filter" class="icon-lucide"></i> Filter Devices:</strong>
        </div>
        <div class="filter-checkboxes">
            <?php foreach (['firewall','switch','router','server','iot','wireless','loadbalancer','storage'] as $t): ?>
            <label><input type="checkbox" class="device-type-checkbox" data-type="<?= $t ?>" checked> <?= ucfirst($t) ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <p id="lastUpdate" style="color:var(--text-muted);">
        Last loaded: <?= date('H:i:s') ?>
        <?php if ($current_map): ?>
        &bull; <span id="visible-device-count-text">Showing <?= count(array_filter($devices, fn($d) => $d['is_visible'])) ?> of <?= count($all_devices) ?> devices</span>
        &bull; Auto-update: <span id="update-timer">30</span>s
        <?php endif; ?>
    </p>

    <!-- Map canvas -->
    <div id="map-container">
        <!-- Floating Navigation HUD Controls -->
        <div id="map-nav-controls" class="map-nav-hud">
            <button type="button" class="nav-btn" onclick="zoomIn()" title="Zoom In (+)">
                <i class="fas fa-plus"></i>
            </button>
            <button type="button" class="nav-btn" onclick="zoomOut()" title="Zoom Out (-)">
                <i class="fas fa-minus"></i>
            </button>
            <button type="button" class="nav-btn" onclick="resetZoom()" title="Reset Scale (100%)">
                <span id="zoom-level-indicator" class="zoom-badge">100%</span>
            </button>
            <button type="button" class="nav-btn" onclick="fitToView()" title="Fit / Center All Devices">
                <i class="fas fa-crosshairs"></i> <span>Fit View</span>
            </button>
            <button type="button" class="nav-btn" onclick="autoLayoutNodes()" title="Auto Organize Cluster">
                <i class="fas fa-th-large"></i> <span>Auto Layout</span>
            </button>
            <button type="button" class="nav-btn" onclick="toggleFullscreenMap()" title="Maximize / Fullscreen">
                <i class="fas fa-expand"></i>
            </button>
        </div>

        <div id="map-viewport">
            <?php foreach ($devices as $device):
                if (!$device['is_visible']) continue;
                $device_type = strtolower(trim($device['type']));
                $prefix      = getIconPrefix($device, $type_to_icon);
                $status      = $device['status'] === 'up' ? 'up' : 'down';
                $filename    = iconFilename($prefix, $status);
                $image_path  = "images/icons/maps/{$filename}.png";
                $style       = "left:{$device['x']}px;top:{$device['y']}px;transform:translate(-50%,0);";
                $last_check  = $device['checked_at'] ? date('M j, H:i:s', strtotime($device['checked_at'])) : 'No ping data';
                $lsc         = !empty($device['last_status_change'])
                               ? date('Y-m-d H:i:s', strtotime($device['last_status_change']))
                               : 'Never changed';
            ?>
            <div class="node <?= $status ?>"
                 id="node_<?= $device['id'] ?>"
                 data-id="<?= $device['id'] ?>"
                 data-type="<?= $device_type ?>"
                 data-name="<?= htmlspecialchars($device['name']) ?>"
                 data-ip="<?= htmlspecialchars($device['ip']) ?>"
                 data-type-full="<?= htmlspecialchars($device['type']) ?>"
                 data-model="<?= htmlspecialchars($device['model']) ?>"
                 data-status="<?= $status ?>"
                 data-last-check="<?= $last_check ?>"
                 data-last-status-change="<?= $lsc ?>"
                 data-location="<?= htmlspecialchars($device['city'] . ($device['sub_office'] ? ', '.$device['sub_office'] : '')) ?>"
                 data-icon-set="<?= htmlspecialchars($prefix) ?>"
                 data-icon-size="<?= intval($device['icon_size']) ?>"
                 style="<?= $style ?>">
                <img src="<?= $image_path ?>" alt="<?= $device['type'] ?> <?= $status ?>"
                     style="width:<?= intval($device['icon_size']) ?>px !important;height:<?= intval($device['icon_size']) ?>px !important;">
                <span class="node-name"><?= htmlspecialchars($device['name']) ?></span>
            </div>
            <?php endforeach; ?>
            <div id="links-container"></div>
            <div id="labels-container"></div>
        </div>
    </div>
</div><!-- /#main-content -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     RIGHT-CLICK CONTEXT MENU
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="ctx-menu">
    <div class="ctx-item" id="ctx-change-icon">
        <i data-lucide="palette" class="icon-lucide"></i> Change Icon Set
    </div>
    <div class="ctx-item" id="ctx-resize-icon">
        <i data-lucide="expand-arrows-alt" class="icon-lucide"></i> Resize Icon
    </div>
    <div class="ctx-divider"></div>
    <div class="ctx-item" id="ctx-hide-device">
        <i data-lucide="eye-off" class="icon-lucide"></i> Hide Device
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ICON PICKER MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="iconPickerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i data-lucide="palette" class="icon-lucide"></i> Choose Icon Set — <span id="icon-picker-device-name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    The selected icon set applies only to this device on this map.
                    Each card shows the <strong>UP</strong> icon (green glow when online, red when down).
                </p>
                <div class="icon-set-grid" id="icon-set-grid">
                    <!-- Populated by JS -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="apply-icon-set-btn">
                    <i data-lucide="check" class="icon-lucide"></i> Apply Icon Set
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Create Map Modal -->
<div class="modal fade" id="createMapModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Map</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Map Name *</label>
                    <input type="text" name="map_name" class="form-control" placeholder="e.g., Main Office Network" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_map" class="btn btn-primary"><i data-lucide="plus" class="icon-lucide"></i> Create Map</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Device Selector Modal -->
<?php if ($current_map): ?>
<div class="modal fade" id="deviceSelectorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Device Visibility — <?= htmlspecialchars($current_map['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span>Total: <?= count($all_devices) ?> devices</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-success" onclick="bulkSetVisibility(true)"><i data-lucide="eye" class="icon-lucide"></i> Show All</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="bulkSetVisibility(false)"><i data-lucide="eye-off" class="icon-lucide"></i> Hide All</button>
                    </div>
                </div>
                <div class="row">
                    <?php foreach ($all_devices as $device):
                        $vis = $visibility_settings[$device['id']] ?? true;
                    ?>
                    <div class="col-md-6 mb-2">
                        <div class="device-selector-item">
                            <input type="checkbox" class="device-visibility-checkbox"
                                   data-device-id="<?= $device['id'] ?>" <?= $vis ? 'checked' : '' ?>
                                   onchange="updateDeviceVisibility(<?= $device['id'] ?>, this.checked)">
                            <div class="device-info">
                                <div class="device-name"><?= htmlspecialchars($device['name']) ?></div>
                                <div class="device-details"><?= htmlspecialchars($device['ip']) ?> &bull; <?= ucfirst($device['type']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="location.reload()"><i data-lucide="refresh-ccw" class="icon-lucide"></i> Refresh Map</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     RESIZE MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="resizeModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i data-lucide="expand-arrows-alt" class="icon-lucide"></i> Resize — <span id="resize-device-name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="size-preview-wrap">
                    <img id="resize-preview-img" src="" alt="preview" width="36" height="36">
                    <span id="resize-px-label" class="badge bg-secondary">36 px</span>
                </div>
                <input type="range" class="form-range" id="resize-slider"
                       min="24" max="128" step="4" value="36">
                <div class="size-labels"><span>24 px<br><small>Small</small></span><span style="text-align:center">64 px<br><small>Large</small></span><span style="text-align:right">128 px<br><small>Huge</small></span></div>
                <!-- Quick-pick buttons -->
                <div class="d-flex gap-2 mt-3 flex-wrap">
                    <button class="btn btn-outline-secondary btn-sm flex-fill size-preset" data-size="24">XS</button>
                    <button class="btn btn-outline-secondary btn-sm flex-fill size-preset" data-size="36">S</button>
                    <button class="btn btn-outline-secondary btn-sm flex-fill size-preset" data-size="52">M</button>
                    <button class="btn btn-outline-secondary btn-sm flex-fill size-preset" data-size="72">L</button>
                    <button class="btn btn-outline-secondary btn-sm flex-fill size-preset" data-size="96">XL</button>
                    <button class="btn btn-outline-secondary btn-sm flex-fill size-preset" data-size="128">XXL</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="apply-resize-btn">
                    <i data-lucide="check" class="icon-lucide"></i> Apply
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Tooltip element -->
<div class="node-tooltip" id="node-tooltip" style="display:none;"></div>

<script>
// ── Constants & state ────────────────────────────────────────────────────────
const devices     = <?= json_encode($devices) ?>;
let   links       = <?= json_encode($links) ?>;
const currentMapId = <?= intval($current_map_id) ?>;

const mapContainer = document.getElementById('map-container');
const mapViewport  = document.getElementById('map-viewport');
const tooltip      = document.getElementById('node-tooltip');

const UPDATE_INTERVAL = 30000; // ms
let updateTimer = null, countdownInterval = null;
let updateCount = 0, lastUpdateTime = null;

// ── Image resolution helpers ─────────────────────────────────────────────────
// All available icon sets and how their filenames are built
// node → nodeup / nodedown   (no dash)
// everything else → prefix-up / prefix-down
// Dynamically built from disk by PHP — no manual editing needed
const ICON_SETS = <?php
    $sets = [];
    // Special node set
    if (file_exists(__DIR__.'/images/icons/maps/nodeup.png'))
        $sets[] = ['id'=>'node','label'=>'Default Node','sub'=>'Generic device'];
    // All prefix-up.png sets
    foreach (glob(__DIR__.'/images/icons/maps/*-up.png') as $f) {
        $prefix = substr(basename($f, '.png'), 0, -3); // strip "-up"
        $sets[] = ['id'=>$prefix, 'label'=>ucfirst($prefix), 'sub'=>''];
    }
    echo json_encode($sets, JSON_PRETTY_PRINT);
?>;

function iconFilename(prefix, status) {
    return prefix === 'node' ? `node${status}` : `${prefix}-${status}`;
}
function iconPath(prefix, status) {
    return `images/icons/maps/${iconFilename(prefix, status)}.png`;
}

const deviceImageMap = {
    firewall:'firewall', switch:'switch', router:'router', server:'server',
    iot:'node', wireless:'node', loadbalancer:'node', storage:'server', others:'node', other:'node'
};

// ── Tooltip ──────────────────────────────────────────────────────────────────
function setupTooltips() {
    document.querySelectorAll('.node').forEach(n => {
        n.addEventListener('mouseenter', showTooltip);
        n.addEventListener('mousemove',  moveTooltip);
        n.addEventListener('mouseleave', hideTooltip);
    });
}
function showTooltip(e) {
    const n = e.currentTarget;
    const sc = n.getAttribute('data-status') === 'up' ? 'status-up' : 'status-down';
    tooltip.innerHTML = `
        <div class="tooltip-section"><strong>${n.dataset.name}</strong></div>
        <div class="tooltip-section">IP: <strong>${n.dataset.ip}</strong></div>
        <div class="tooltip-section">Type: ${n.dataset.typeFull}${n.dataset.model ? ' – '+n.dataset.model : ''}</div>
        <div class="tooltip-section">Status: <span class="${sc}">${n.dataset.status.toUpperCase()}</span></div>
        <div class="tooltip-section">Last Check: ${n.dataset.lastCheck}</div>
        <div class="tooltip-section">Last Change: ${n.dataset.lastStatusChange}</div>
        <div class="tooltip-section">Icon Set: <em>${n.dataset.iconSet}</em></div>
        ${n.dataset.location ? `<div class="tooltip-section">Location: ${n.dataset.location}</div>` : ''}
    `;
    tooltip.style.display = 'block';
    moveTooltip(e);
}
function moveTooltip(e) {
    const tw = tooltip.getBoundingClientRect().width;
    const th = tooltip.getBoundingClientRect().height;
    let x = e.clientX + 15, y = e.clientY + 15;
    if (x + tw > window.innerWidth  - 10) x = e.clientX - tw - 15;
    if (y + th > window.innerHeight - 10) y = e.clientY - th - 15;
    tooltip.style.left = x + 'px';
    tooltip.style.top  = y + 'px';
}
function hideTooltip() { tooltip.style.display = 'none'; }

// ── Context menu ─────────────────────────────────────────────────────────────
const ctxMenu      = document.getElementById('ctx-menu');
let   ctxTargetNode = null;   // the .node element that was right-clicked

// Show context menu on right-click of a node
mapViewport.addEventListener('contextmenu', e => {
    const node = e.target.closest('.node');
    if (!node) return;
    e.preventDefault();
    hideTooltip();
    ctxTargetNode = node;
    ctxMenu.style.display = 'block';
    // Keep inside viewport
    let x = e.clientX, y = e.clientY;
    const mw = ctxMenu.offsetWidth  || 180;
    const mh = ctxMenu.offsetHeight || 100;
    if (x + mw > window.innerWidth  - 8) x = window.innerWidth  - mw - 8;
    if (y + mh > window.innerHeight - 8) y = window.innerHeight - mh - 8;
    ctxMenu.style.left = x + 'px';
    ctxMenu.style.top  = y + 'px';
});

// Dismiss context menu on any other click
document.addEventListener('click', e => {
    if (!ctxMenu.contains(e.target)) ctxMenu.style.display = 'none';
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') ctxMenu.style.display = 'none';
});

// ── "Change Icon Set" action ─────────────────────────────────────────────────
document.getElementById('ctx-change-icon').addEventListener('click', () => {
    ctxMenu.style.display = 'none';
    if (!ctxTargetNode) return;
    openIconPicker(ctxTargetNode);
});

// ── "Hide Device" action ─────────────────────────────────────────────────────
document.getElementById('ctx-hide-device').addEventListener('click', () => {
    ctxMenu.style.display = 'none';
    if (!ctxTargetNode) return;
    const did = parseInt(ctxTargetNode.dataset.id);
    updateDeviceVisibility(did, false);
    ctxTargetNode = null;
});

// ── Icon Picker ───────────────────────────────────────────────────────────────
let pickerSelectedSet = null;

function openIconPicker(node) {
    const currentSet = node.dataset.iconSet || 'node';
    pickerSelectedSet = currentSet;

    document.getElementById('icon-picker-device-name').textContent = node.dataset.name;

    // Build grid
    const grid = document.getElementById('icon-set-grid');
    grid.innerHTML = '';
    ICON_SETS.forEach(set => {
        const card = document.createElement('div');
        card.className = 'icon-set-card' + (set.id === currentSet ? ' selected' : '');
        card.dataset.setId = set.id;
        card.innerHTML = `
            <img src="${iconPath(set.id, 'up')}" alt="${set.label}"
                 onerror="this.src='images/icons/maps/nodeup.png'">
            <div class="isc-label">${set.label}</div>
            <div class="isc-sub">${set.sub}</div>
        `;
        card.addEventListener('click', () => {
            grid.querySelectorAll('.icon-set-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            pickerSelectedSet = set.id;
        });
        grid.appendChild(card);
    });

    const modal = new bootstrap.Modal(document.getElementById('iconPickerModal'));
    modal.show();
}

document.getElementById('apply-icon-set-btn').addEventListener('click', () => {
    if (!ctxTargetNode || !pickerSelectedSet) return;
    const did = parseInt(ctxTargetNode.dataset.id);
    applyIconSet(ctxTargetNode, pickerSelectedSet);
    bootstrap.Modal.getInstance(document.getElementById('iconPickerModal')).hide();
});

function applyIconSet(node, setId) {
    // Update DOM immediately
    const status = node.dataset.status || 'down';
    const img = node.querySelector('img');
    if (img) img.src = iconPath(setId, status);
    node.dataset.iconSet = setId;

    // Persist to server
    fetch('save_icon_set.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `device_id=${parseInt(node.dataset.id)}&map_id=${currentMapId}&icon_set=${encodeURIComponent(setId)}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            showToast(`Icon set changed to "${setId}" for ${node.dataset.name}`, 'success');
        } else {
            showToast('Failed to save icon set: ' + d.message, 'danger');
        }
    })
    .catch(err => showToast('Error: ' + err.message, 'danger'));
}

// ── Resize icon ───────────────────────────────────────────────────────────────
document.getElementById('ctx-resize-icon').addEventListener('click', () => {
    ctxMenu.style.display = 'none';
    if (!ctxTargetNode) return;
    openResizeModal(ctxTargetNode);
});

function openResizeModal(node) {
    const currentSize = parseInt(node.dataset.iconSize) || 36;
    const img = node.querySelector('img');

    document.getElementById('resize-device-name').textContent = node.dataset.name;
    document.getElementById('resize-preview-img').src = img ? img.src : '';
    document.getElementById('resize-preview-img').style.width  = currentSize + 'px';
    document.getElementById('resize-preview-img').style.height = currentSize + 'px';
    document.getElementById('resize-slider').value = currentSize;
    document.getElementById('resize-px-label').textContent = currentSize + ' px';

    new bootstrap.Modal(document.getElementById('resizeModal')).show();
}

// Slider live preview
document.getElementById('resize-slider').addEventListener('input', function() {
    const sz = parseInt(this.value);
    document.getElementById('resize-preview-img').style.width  = sz + 'px';
    document.getElementById('resize-preview-img').style.height = sz + 'px';
    document.getElementById('resize-px-label').textContent = sz + ' px';
});

// Quick-pick presets
document.querySelectorAll('.size-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const sz = parseInt(btn.dataset.size);
        const slider = document.getElementById('resize-slider');
        slider.value = sz;
        slider.dispatchEvent(new Event('input'));
    });
});

// Apply
document.getElementById('apply-resize-btn').addEventListener('click', () => {
    if (!ctxTargetNode) return;
    const sz = parseInt(document.getElementById('resize-slider').value);
    applyIconSize(ctxTargetNode, sz);
    bootstrap.Modal.getInstance(document.getElementById('resizeModal')).hide();
});

function applyIconSize(node, size) {
    // Update DOM immediately — use setProperty so !important is respected
    const img = node.querySelector('img');
    if (img) {
        img.style.setProperty('width',  size + 'px', 'important');
        img.style.setProperty('height', size + 'px', 'important');
    }
    node.dataset.iconSize = size;

    // Persist
    fetch('save_icon_size.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `device_id=${parseInt(node.dataset.id)}&map_id=${currentMapId}&icon_size=${size}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') showToast(`${node.dataset.name} resized to ${size}px`, 'success');
        else showToast('Failed to save size: ' + d.message, 'danger');
    })
    .catch(err => showToast('Error: ' + err.message, 'danger'));
}
let scale = 1, panX = 0, panY = 0, isPanning = false, startPanX, startPanY;

function loadViewportState() {
    try {
        const s = JSON.parse(localStorage.getItem(`mapViewport_${currentMapId}`) || '{}');
        if (s.scale && s.panX !== undefined && s.panY !== undefined) {
            scale = s.scale; panX = s.panX; panY = s.panY;
            updateTransform();
            return;
        }
    } catch(e) {}
    fitToView();
}

function saveViewportState() {
    localStorage.setItem(`mapViewport_${currentMapId}`, JSON.stringify({ scale, panX, panY }));
}

function updateTransform() {
    mapViewport.style.transform = `translate(${panX}px,${panY}px) scale(${scale})`;
    const indicator = document.getElementById('zoom-level-indicator');
    if (indicator) indicator.textContent = `${Math.round(scale * 100)}%`;
    saveViewportState();
}

function zoomIn() { 
    scale = Math.min(scale * 1.2, 3.0); 
    updateTransform(); 
}

function zoomOut() { 
    scale = Math.max(scale / 1.2, 0.25); 
    updateTransform(); 
}

function resetZoom() { 
    scale = 1; 
    panX = 0; 
    panY = 0; 
    updateTransform(); 
}

function fitToView() {
    const visibleNodes = [...document.querySelectorAll('.node')].filter(n => n.style.display !== 'none');
    if (!visibleNodes.length) {
        resetZoom();
        return;
    }

    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
    visibleNodes.forEach(node => {
        const x = parseFloat(node.style.left) || 0;
        const y = parseFloat(node.style.top) || 0;
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        if (y < minY) minY = y;
        if (y > maxY) maxY = y;
    });

    const rect = mapContainer.getBoundingClientRect();
    const containerW = rect.width || 1000;
    const containerH = rect.height || 600;

    const pad = 140;
    const bboxW = Math.max((maxX - minX) + pad * 2, 200);
    const bboxH = Math.max((maxY - minY) + pad * 2, 200);

    const scaleX = containerW / bboxW;
    const scaleY = containerH / bboxH;
    scale = Math.min(scaleX, scaleY, 1.4);
    scale = Math.max(scale, 0.35);

    const centerX = (minX + maxX) / 2;
    const centerY = (minY + maxY) / 2;

    panX = (containerW / 2) - (centerX * scale);
    panY = (containerH / 2) - (centerY * scale);

    updateTransform();
}

function autoLayoutNodes() {
    const visibleNodes = [...document.querySelectorAll('.node')].filter(n => n.style.display !== 'none');
    if (!visibleNodes.length) return;

    const rect = mapContainer.getBoundingClientRect();
    const centerX = 1000, centerY = 700;
    const radius = Math.max(220, visibleNodes.length * 50);
    const step = (2 * Math.PI) / visibleNodes.length;

    visibleNodes.forEach((node, idx) => {
        const angle = idx * step - (Math.PI / 2);
        const x = Math.round(centerX + radius * Math.cos(angle));
        const y = Math.round(centerY + radius * Math.sin(angle));

        node.style.left = x + 'px';
        node.style.top = y + 'px';

        const dev = devices.find(d => d.id == node.dataset.id);
        if (dev) { dev.x = x; dev.y = y; }

        fetch('save_position_map.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `device_id=${node.dataset.id}&x=${x}&y=${y}&map_id=${currentMapId}`
        }).catch(console.error);
    });

    drawLinks();
    fitToView();
    showToast('Auto-layout arranged & saved', 'success');
}

function toggleFullscreenMap() {
    document.body.classList.toggle('fullscreen');
    mapContainer.classList.toggle('fullscreen');
    setTimeout(fitToView, 120);
}

const mainFsBtn = document.getElementById('toggle-fullscreen-btn');
if (mainFsBtn) mainFsBtn.addEventListener('click', toggleFullscreenMap);

mapContainer.addEventListener('mousedown', e => {
    if (e.target.closest('.node') || e.target.closest('.map-nav-hud')) return;
    isPanning = true;
    startPanX = e.clientX - panX; 
    startPanY = e.clientY - panY;
    mapContainer.classList.add('panning');
    e.preventDefault();
});

document.addEventListener('mousemove', e => {
    if (!isPanning) return;
    panX = e.clientX - startPanX; 
    panY = e.clientY - startPanY;
    updateTransform();
});

document.addEventListener('mouseup', () => { 
    if (isPanning) { 
        isPanning = false; 
        mapContainer.classList.remove('panning'); 
        saveViewportState(); 
    } 
});

mapContainer.addEventListener('wheel', e => {
    e.preventDefault();
    const rect = mapContainer.getBoundingClientRect();
    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;

    const zoomFactor = e.deltaY < 0 ? 1.15 : (1 / 1.15);
    const newScale = Math.min(Math.max(scale * zoomFactor, 0.25), 3.0);

    panX = mouseX - (mouseX - panX) * (newScale / scale);
    panY = mouseY - (mouseY - panY) * (newScale / scale);
    scale = newScale;

    updateTransform();
}, { passive: false });

// ── Drag & drop ───────────────────────────────────────────────────────────────
let activeNode=null, dragStartX, dragStartY, nodeStartX, nodeStartY;

function dragStart(e) {
    const node = e.target.closest('.node');
    if (!node) return;
    e.preventDefault(); e.stopPropagation();
    activeNode = node;
    activeNode.style.zIndex = '1000'; activeNode.style.cursor = 'grabbing';
    dragStartX = e.clientX; dragStartY = e.clientY;
    nodeStartX = parseFloat(node.style.left); nodeStartY = parseFloat(node.style.top);
    document.addEventListener('mousemove', dragMove);
    document.addEventListener('mouseup',   dragEnd);
}
function dragMove(e) {
    if (!activeNode) return;
    e.preventDefault();
    const dx = (e.clientX - dragStartX) / scale;
    const dy = (e.clientY - dragStartY) / scale;
    activeNode.style.left = (nodeStartX + dx) + 'px';
    activeNode.style.top  = (nodeStartY + dy) + 'px';
    const dev = devices.find(d => d.id == activeNode.dataset.id);
    if (dev) { dev.x = nodeStartX+dx; dev.y = nodeStartY+dy; }
    drawLinks();
}
function dragEnd(e) {
    if (!activeNode) return;
    activeNode.style.cursor = 'move'; activeNode.style.zIndex = '10';
    const did  = activeNode.dataset.id;
    const newX = parseFloat(activeNode.style.left);
    const newY = parseFloat(activeNode.style.top);
    fetch('save_position_map.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`device_id=${did}&x=${newX}&y=${newY}&map_id=${currentMapId}`
    }).then(r=>r.json()).catch(console.error);
    activeNode = null;
    document.removeEventListener('mousemove', dragMove);
    document.removeEventListener('mouseup',   dragEnd);
}
mapViewport.addEventListener('mousedown', dragStart);

// ── Device type filter ────────────────────────────────────────────────────────
document.querySelectorAll('.device-type-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        document.querySelectorAll(`.node[data-type="${this.dataset.type}"]`).forEach(n => {
            n.style.display = this.checked ? 'flex' : 'none';
        });
        drawLinks(); updateVisibleDeviceCount();
    });
});

// ── Link drawing ──────────────────────────────────────────────────────────────
function drawLinks() {
    document.getElementById('links-container').innerHTML = '';
    document.getElementById('labels-container').innerHTML = '';
    const lc = document.getElementById('links-container');
    const ll = document.getElementById('labels-container');
    const imgSize = 36;
    const groups  = new Map();
    links.forEach(l => {
        const k = [l.from_device_id, l.to_device_id].sort().join('-');
        if (!groups.has(k)) groups.set(k, []);
        groups.get(k).push(l);
    });
    links.forEach(link => {
        const fn = document.getElementById(`node_${link.from_device_id}`);
        const tn = document.getElementById(`node_${link.to_device_id}`);
        if (!fn || !tn || fn.style.display === 'none' || tn.style.display === 'none') return;
        const x1 = parseFloat(fn.style.left), y1 = parseFloat(fn.style.top) + imgSize/2;
        const x2 = parseFloat(tn.style.left), y2 = parseFloat(tn.style.top) + imgSize/2;
        const k   = [link.from_device_id, link.to_device_id].sort().join('-');
        const grp = groups.get(k) || [];
        const idx = grp.findIndex(l => l.id === link.id);
        grp.length > 1
            ? drawCurvedLink(link, x1,y1,x2,y2, idx, grp.length, lc, ll)
            : drawStraightLink(link, x1,y1,x2,y2, lc, ll);
    });
}
function drawStraightLink(link, x1,y1,x2,y2, lc, ll) {
    const dx=x2-x1, dy=y2-y1;
    const dist  = Math.sqrt(dx*dx+dy*dy);
    const angle = Math.atan2(dy,dx);
    const line  = document.createElement('div');
    line.className = `link-line ${link.status}`; line.id = `link_${link.id}`;
    Object.assign(line.style, {left:x1+'px', top:y1+'px', width:dist+'px', transform:`rotate(${angle}rad)`});
    lc.appendChild(line);
    const lbl = document.createElement('div');
    lbl.className = 'link-label';
    Object.assign(lbl.style, {left:((x1+x2)/2-20)+'px', top:((y1+y2)/2-10)+'px'});
    lbl.textContent = link.name; ll.appendChild(lbl);
}
function drawCurvedLink(link, x1,y1,x2,y2, idx, total, lc, ll) {
    const sep = 40, offset = (idx - (total-1)/2) * sep;
    const mx=(x1+x2)/2, my=(y1+y2)/2;
    const dx=x2-x1, dy=y2-y1, dist=Math.sqrt(dx*dx+dy*dy);
    if (!dist) return;
    const cx = mx + (-dy/dist)*offset, cy = my + (dx/dist)*offset;
    const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
    Object.assign(svg.style, {position:'absolute',left:'0',top:'0',width:'100%',height:'100%',pointerEvents:'none',zIndex:'5'});
    const path = document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('d', `M ${x1} ${y1} Q ${cx} ${cy} ${x2} ${y2}`);
    path.setAttribute('fill','none');
    path.setAttribute('stroke', link.status==='up' ? '#00ff7f' : '#ff4c4c');
    path.setAttribute('stroke-width','2');
    path.setAttribute('class', `curved-link ${link.status}`);
    path.setAttribute('id', `link_${link.id}`);
    svg.appendChild(path); lc.appendChild(svg);
    const lbl = document.createElement('div');
    lbl.className='link-label';
    const t=.5;
    const lx=(1-t)*(1-t)*x1+2*(1-t)*t*cx+t*t*x2;
    const ly=(1-t)*(1-t)*y1+2*(1-t)*t*cy+t*t*y2;
    Object.assign(lbl.style,{left:(lx-20)+'px',top:(ly-10)+'px'});
    lbl.textContent=link.name; ll.appendChild(lbl);
}

// ── Status polling ────────────────────────────────────────────────────────────
function updateStatus() {
    fetch('get_status.php')
        .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(data => {
            let updates = 0;
            (data.devices||[]).forEach(d => {
                const node = document.getElementById(`node_${d.id}`);
                if (!node || node.style.display === 'none') return;
                const ns = d.status || 'down';
                if (node.dataset.status === ns) return;
                node.className = `node ${ns}`;
                node.dataset.status = ns;
                const img = node.querySelector('img');
                if (img) {
                    img.src = iconPath(node.dataset.iconSet || 'node', ns);
                    const sz = parseInt(node.dataset.iconSize) || 36;
                    img.style.setProperty('width',  sz + 'px', 'important');
                    img.style.setProperty('height', sz + 'px', 'important');
                }
                if (d.checked_at)        node.dataset.lastCheck = d.checked_at;
                if (d.last_status_change) node.dataset.lastStatusChange = d.last_status_change;
                updates++;
            });
            (data.links||[]).forEach(l => {
                const li = links.findIndex(x => x.id == l.id);
                if (li !== -1 && links[li].status !== l.status) { links[li].status = l.status; updates++; }
                const el = document.getElementById(`link_${l.id}`);
                if (el) {
                    if (el.tagName === 'DIV') el.className = `link-line ${l.status}`;
                    else { el.setAttribute('class',`curved-link ${l.status}`); el.setAttribute('stroke', l.status==='up'?'#00ff7f':'#ff4c4c'); }
                }
            });
            if (updates) { drawLinks(); showToast(`${updates} update(s)`, 'success'); }
            updateCount++; lastUpdateTime = new Date(); updateDebugInfo();
            document.getElementById('lastUpdate').innerHTML =
                `Last update: <strong>${new Date().toLocaleTimeString()}</strong> &bull; ${updates} update(s)`;
        })
        .catch(err => showToast('Status error: ' + err.message, 'danger'));
}

// ── Visibility helpers ────────────────────────────────────────────────────────
function updateDeviceVisibility(deviceId, isVisible) {
    fetch('maps.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`update_visibility=1&map_id=${currentMapId}&device_id=${deviceId}&is_visible=${isVisible?1:0}`
    }).then(r=>r.json()).then(d => {
        if (d.status !== 'success') throw new Error(d.message);
        const node = document.getElementById(`node_${deviceId}`);
        if (node) node.style.display = isVisible ? 'flex' : 'none';
        updateVisibleDeviceCount(); drawLinks();
        showToast('Visibility updated', 'success');
    }).catch(err => showToast('Error: '+err.message, 'danger'));
}
function bulkSetVisibility(isVisible) {
    const ids = [...document.querySelectorAll('.device-visibility-checkbox')].map(c=>c.dataset.deviceId);
    const fd  = new FormData();
    fd.append('bulk_visibility','1'); fd.append('map_id', currentMapId); fd.append('is_visible', isVisible?1:0);
    ids.forEach(id => fd.append('device_ids[]', id));
    fetch('maps.php',{method:'POST',body:fd}).then(()=>location.reload());
}
function updateVisibleDeviceCount() {
    const cnt = document.querySelectorAll('.node:not([style*="display: none"])').length;
    const el1 = document.getElementById('visible-device-count');
    const el2 = document.getElementById('visible-device-count-text');
    if (el1) el1.textContent = cnt;
    if (el2) el2.textContent = `Showing ${cnt} of <?= count($all_devices) ?> devices`;
}

// ── Fullscreen ────────────────────────────────────────────────────────────────
const fsBtn = document.getElementById('toggle-fullscreen-btn');
fsBtn.addEventListener('click', () => {
    const on = document.body.classList.toggle('fullscreen');
    document.getElementById('map-container').classList.toggle('fullscreen', on);
    fsBtn.innerHTML = on ? '<i data-lucide="compress" class="icon-lucide"></i> Minimize (ESC)' : '<i data-lucide="expand" class="icon-lucide"></i> Maximize Map';
    if (!on) { panX=0; panY=0; } updateTransform(); drawLinks();
});
document.addEventListener('keydown', e => { if (e.key==='Escape' && document.body.classList.contains('fullscreen')) fsBtn.click(); });

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type='success') {
    let c = document.querySelector('.toast-container');
    if (!c) { c=document.createElement('div'); c.className='toast-container position-fixed top-0 end-0 p-3'; c.style.zIndex='1100'; document.body.appendChild(c); }
    const t = document.createElement('div');
    t.className = `toast align-items-center text-bg-${type} border-0`;
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    c.appendChild(t);
    new bootstrap.Toast(t).show();
    t.addEventListener('hidden.bs.toast', ()=>t.remove());
}

// ── Debug ─────────────────────────────────────────────────────────────────────
function toggleDebugPanel() { const p=document.getElementById('debug-panel'); p.style.display=p.style.display==='block'?'none':'block'; }
function updateDebugInfo() {
    document.getElementById('debug-last-update').textContent = lastUpdateTime ? lastUpdateTime.toLocaleTimeString() : 'Never';
    document.getElementById('debug-update-count').textContent = updateCount;
}

// ── Countdown display ─────────────────────────────────────────────────────────
function startCountdown() {
    let s = UPDATE_INTERVAL/1000;
    if (countdownInterval) clearInterval(countdownInterval);
    countdownInterval = setInterval(()=>{ s--; if(s<=0) s=UPDATE_INTERVAL/1000; const el=document.getElementById('update-timer'); if(el) el.textContent=s; },1000);
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadViewportState();
    setupTooltips();
    updateVisibleDeviceCount();
    drawLinks();
    document.querySelectorAll('.toast').forEach(t => new bootstrap.Toast(t).show());
    updateStatus();
    if (updateTimer) clearInterval(updateTimer);
    updateTimer = setInterval(updateStatus, UPDATE_INTERVAL);
    startCountdown();
});
</script>
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>

