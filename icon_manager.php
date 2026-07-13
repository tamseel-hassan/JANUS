<?php
// icon_manager.php - Upload and manage icon sets for topology maps
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
// Only admins should manage icons
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: home.php');
    exit;
}

$theme        = $_COOKIE['theme'] ?? 'dark';
$icons_dir    = __DIR__ . '/images/icons/maps/';
$message      = '';
$message_type = 'success';

// ── Helper: get all icon sets currently on disk ──────────────────────────────
// An icon set is valid when BOTH prefix-up.png and prefix-down.png exist
// (or nodeup.png / nodedown.png for the special "node" set)
function getIconSets(string $dir): array {
    $sets = [];
    if (!is_dir($dir)) return $sets;
    foreach (glob($dir . '*-up.png') as $up_file) {
        $base   = basename($up_file, '.png');          // e.g. router-up
        $prefix = substr($base, 0, -3);                // e.g. router  (strip "-up")
        $down   = $dir . $prefix . '-down.png';
        $sets[] = [
            'id'        => $prefix,
            'label'     => ucfirst($prefix),
            'up_file'   => $up_file,
            'down_file' => $down,
            'has_down'  => file_exists($down),
            'up_url'    => 'images/icons/maps/' . basename($up_file),
            'down_url'  => 'images/icons/maps/' . $prefix . '-down.png',
            'special'   => false,
        ];
    }
    // Handle special "node" set (nodeup / nodedown — no dash)
    if (file_exists($dir . 'nodeup.png')) {
        array_unshift($sets, [
            'id'        => 'node',
            'label'     => 'Node (Default)',
            'up_file'   => $dir . 'nodeup.png',
            'down_file' => $dir . 'nodedown.png',
            'has_down'  => file_exists($dir . 'nodedown.png'),
            'up_url'    => 'images/icons/maps/nodeup.png',
            'down_url'  => 'images/icons/maps/nodedown.png',
            'special'   => true,   // cannot be deleted
        ]);
    }
    return $sets;
}

// ── Upload handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Validate set name
    $set_name = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['set_name'] ?? '')));

    if ($_POST['action'] === 'upload') {

        if (empty($set_name)) {
            $message = 'Icon set name is required (letters, numbers, hyphens only).';
            $message_type = 'danger';
        } else {
            $allowed_mime = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
            $errors = [];
            $saved  = [];

            foreach (['up', 'down'] as $variant) {
                $file_key = "icon_{$variant}";
                if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) {
                    $errors[] = "No file uploaded for the '{$variant}' variant.";
                    continue;
                }
                $f = $_FILES[$file_key];
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = "Upload error for '{$variant}': code " . $f['error'];
                    continue;
                }
                // Check MIME via finfo
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, $allowed_mime)) {
                    $errors[] = "'{$variant}' file must be an image (PNG/JPG/GIF/WebP/SVG). Got: $mime";
                    continue;
                }
                // Max 512 KB
                if ($f['size'] > 524288) {
                    $errors[] = "'{$variant}' file exceeds 512 KB limit.";
                    continue;
                }

                // Build destination filename
                // Special case: "node" set uses nodeup.png / nodedown.png
                if ($set_name === 'node') {
                    $dest_name = "node{$variant}.png";
                } else {
                    $dest_name = "{$set_name}-{$variant}.png";
                }
                $dest = $icons_dir . $dest_name;

                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $errors[] = "Failed to save '{$variant}' file. Check directory permissions.";
                } else {
                    $saved[] = $dest_name;
                }
            }

            if ($errors) {
                $message = implode('<br>', $errors);
                $message_type = 'danger';
            } else {
                $message = 'Icon set <strong>' . htmlspecialchars($set_name) . '</strong> saved successfully! Files: ' . implode(', ', $saved);
                $message_type = 'success';
            }
        }

    } elseif ($_POST['action'] === 'delete') {

        // Prevent deletion of built-in sets
        $protected = ['node', 'router', 'firewall', 'switch', 'server'];
        if (in_array($set_name, $protected)) {
            $message = "Built-in icon set <strong>{$set_name}</strong> cannot be deleted.";
            $message_type = 'warning';
        } else {
            $deleted = [];
            foreach (["{$set_name}-up.png", "{$set_name}-down.png"] as $fn) {
                $path = $icons_dir . $fn;
                if (file_exists($path)) { unlink($path); $deleted[] = $fn; }
            }
            $message = $deleted
                ? 'Deleted: ' . implode(', ', $deleted)
                : 'No files found for that icon set.';
            $message_type = $deleted ? 'success' : 'warning';
        }
    }
}

$icon_sets = getIconSets($icons_dir);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <title>Icon Set Manager – JanusNMS</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/font-awesome/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="/css/pages/icon_manager.css">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content">
    <script>
    (function(){
        const sb = document.getElementById('sidebar');
        const mc = document.getElementById('main-content');
        function adj(){ if(mc) mc.style.marginLeft = sb && sb.classList.contains('collapsed') ? '80px' : '260px'; }
        document.addEventListener('DOMContentLoaded', function(){ adj(); if(sb) new MutationObserver(adj).observe(sb,{attributes:true}); });
    })();
    </script>

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i data-lucide="icons" class="icon-lucide"></i> Icon Set Manager</h2>
            <p class="text-muted mb-0">Upload custom UP/DOWN icon pairs for use on topology maps</p>
        </div>
        <a href="maps.php" class="btn btn-outline-primary btn-sm">
            <i data-lucide="map-marked-alt" class="icon-lucide"></i> Back to Maps
        </a>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Upload form ──────────────────────────────────────────────────── -->
    <div class="upload-card">
        <h5><i data-lucide="upload" class="icon-lucide"></i> Upload New Icon Set</h5>

        <form method="post" enctype="multipart/form-data" id="upload-form">
            <input type="hidden" name="action" value="upload">

            <div class="row g-3 align-items-end">
                <!-- Set name -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Icon Set Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i data-lucide="tag" class="icon-lucide"></i></span>
                        <input type="text" name="set_name" id="set_name" class="form-control"
                               placeholder="e.g. vsat, vpn, cloud"
                               pattern="[a-zA-Z0-9_-]+"
                               title="Letters, numbers, hyphens and underscores only"
                               required>
                    </div>
                    <div class="form-text">Lowercase, no spaces. Example: <code>vsat</code> → <code>vsat-up.png</code> / <code>vsat-down.png</code></div>
                </div>

                <!-- UP drop zone -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><span style="color:#00ff7f">● UP</span> Icon (device online)</label>
                    <div class="drop-zone" id="dz-up">
                        <input type="file" name="icon_up" id="file-up" accept="image/*">
                        <div class="dz-icon up"><i data-lucide="arrow-up" class="icon-lucide"></i></div>
                        <div class="dz-label">Click or drag &amp; drop<br><small>PNG recommended · max 512 KB</small></div>
                        <div class="dz-preview">
                            <img id="preview-up" src="" alt="UP preview">
                            <span id="preview-up-name" style="font-size:.8rem;color:var(--text-muted)"></span>
                        </div>
                    </div>
                </div>

                <!-- DOWN drop zone -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><span style="color:#ff4c4c">● DOWN</span> Icon (device offline)</label>
                    <div class="drop-zone" id="dz-down">
                        <input type="file" name="icon_down" id="file-down" accept="image/*">
                        <div class="dz-icon down"><i data-lucide="arrow-down" class="icon-lucide"></i></div>
                        <div class="dz-label">Click or drag &amp; drop<br><small>PNG recommended · max 512 KB</small></div>
                        <div class="dz-preview">
                            <img id="preview-down" src="" alt="DOWN preview">
                            <span id="preview-down-name" style="font-size:.8rem;color:var(--text-muted)"></span>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100" style="height:44px">
                        <i data-lucide="upload" class="icon-lucide"></i>
                    </button>
                </div>
            </div>

            <!-- Live filename preview -->
            <div id="filename-preview" class="mt-3" style="display:none;">
                <small class="text-muted">Files will be saved as:&nbsp;</small>
                <code id="fname-up"></code> &nbsp;&amp;&nbsp; <code id="fname-down"></code>
            </div>
        </form>
    </div>

    <!-- ── Existing icon sets ────────────────────────────────────────────── -->
    <div class="upload-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><i data-lucide="layers" class="icon-lucide"></i> Installed Icon Sets
                <span class="badge bg-secondary ms-2"><?= count($icon_sets) ?></span>
            </h5>
            <small class="text-muted">Right-click any device on the map to apply an icon set</small>
        </div>

        <?php if (empty($icon_sets)): ?>
        <p class="text-muted">No icon sets found in <code>images/icons/maps/</code>.</p>
        <?php else: ?>
        <div class="icon-grid">
            <?php foreach ($icon_sets as $set): ?>
            <div class="icon-card">
                <?php if (in_array($set['id'], ['node','router','firewall','switch','server'])): ?>
                <span class="badge-builtin">BUILT-IN</span>
                <?php endif; ?>

                <div class="ic-name"><?= htmlspecialchars($set['label']) ?></div>
                <div class="ic-sub text-muted" style="font-size:.75rem;margin-bottom:8px;">
                    <code><?= htmlspecialchars($set['id']) ?></code>
                </div>

                <div class="ic-pair">
                    <div class="ic-wrap">
                        <img class="up-img"
                             src="<?= htmlspecialchars($set['up_url']) ?>?<?= filemtime($set['up_file']) ?>"
                             alt="UP" onerror="this.src='images/icons/maps/nodeup.png'">
                        <span>UP</span>
                    </div>
                    <div class="ic-wrap">
                        <?php if ($set['has_down']): ?>
                        <img class="down-img"
                             src="<?= htmlspecialchars($set['down_url']) ?>?<?= filemtime($set['down_file']) ?>"
                             alt="DOWN" onerror="this.src='images/icons/maps/nodedown.png'">
                        <?php else: ?>
                        <img src="images/icons/maps/nodedown.png" alt="DOWN (missing)" style="opacity:.3">
                        <div class="ic-missing"><i data-lucide="triangle-alert" class="icon-lucide"></i> missing</div>
                        <?php endif; ?>
                        <span>DOWN</span>
                    </div>
                </div>

                <?php if (!in_array($set['id'], ['node','router','firewall','switch','server'])): ?>
                <form method="post" onsubmit="return confirm('Delete icon set \'<?= htmlspecialchars($set['id']) ?>\'?')">
                    <input type="hidden" name="action"   value="delete">
                    <input type="hidden" name="set_name" value="<?= htmlspecialchars($set['id']) ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm btn-delete">
                        <i data-lucide="trash" class="icon-lucide"></i> Delete
                    </button>
                </form>
                <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm btn-delete" disabled>
                    <i data-lucide="lock" class="icon-lucide"></i> Protected
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Usage guide ───────────────────────────────────────────────────── -->
    <div class="upload-card">
        <h5><i data-lucide="info" class="icon-lucide"></i> How It Works</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="p-3 rounded" style="background:rgba(0,198,255,.07);border:1px solid rgba(0,198,255,.2);">
                    <div class="fw-bold mb-1"><i data-lucide="upload" class="icon-lucide text-info"></i> 1. Upload</div>
                    <small>Give your icon set a short name (e.g. <code>cloud</code>), then upload the UP and DOWN PNG images.</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded" style="background:rgba(0,255,127,.07);border:1px solid rgba(0,255,127,.2);">
                    <div class="fw-bold mb-1"><i class="fas fa-mouse-pointer" style="color:#00ff7f"></i> 2. Apply on Map</div>
                    <small>Go to <strong>Topology Maps</strong>, right-click any device node and choose <strong>Change Icon Set</strong>.</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded" style="background:rgba(255,76,76,.07);border:1px solid rgba(255,76,76,.2);">
                    <div class="fw-bold mb-1"><i class="fas fa-sync" style="color:#ff4c4c"></i> 3. Live Updates</div>
                    <small>The map auto-updates every 30 s. Your custom icon switches between UP/DOWN colours automatically.</small>
                </div>
            </div>
        </div>
        <hr>
        <small class="text-muted">
            <strong>Naming convention:</strong> A set named <code>vpn</code> saves as <code>vpn-up.png</code> and <code>vpn-down.png</code>.
            The special built-in <code>node</code> set uses <code>nodeup.png</code> / <code>nodedown.png</code> (no dash).
            All files live in <code>images/icons/maps/</code>.
        </small>
    </div>
</div><!-- /#main-content -->

<script src="js/bootstrap.bundle.min.js"></script>
<script>
// ── Drop zone + preview ───────────────────────────────────────────────────────
function setupDropZone(dzId, fileId, previewImgId, previewNameId) {
    const dz      = document.getElementById(dzId);
    const input   = document.getElementById(fileId);
    const prevImg = document.getElementById(previewImgId);
    const prevNm  = document.getElementById(previewNameId);

    function handleFile(file) {
        if (!file || !file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = e => {
            prevImg.src = e.target.result;
            prevNm.textContent = file.name;
            dz.classList.add('has-file');
        };
        reader.readAsDataURL(file);
        updateFilenamePreview();
    }

    input.addEventListener('change', () => handleFile(input.files[0]));
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => {
        e.preventDefault();
        dz.classList.remove('dragover');
        if (e.dataTransfer.files[0]) {
            // Transfer file to input
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            input.files = dt.files;
            handleFile(e.dataTransfer.files[0]);
        }
    });
}

setupDropZone('dz-up',   'file-up',   'preview-up',   'preview-up-name');
setupDropZone('dz-down', 'file-down', 'preview-down', 'preview-down-name');

// ── Filename preview ──────────────────────────────────────────────────────────
function updateFilenamePreview() {
    const name = document.getElementById('set_name').value.trim().toLowerCase().replace(/[^a-z0-9_-]/g,'');
    const fp   = document.getElementById('filename-preview');
    if (!name) { fp.style.display = 'none'; return; }
    fp.style.display = 'block';
    if (name === 'node') {
        document.getElementById('fname-up').textContent   = 'nodeup.png';
        document.getElementById('fname-down').textContent = 'nodedown.png';
    } else {
        document.getElementById('fname-up').textContent   = `${name}-up.png`;
        document.getElementById('fname-down').textContent = `${name}-down.png`;
    }
}
document.getElementById('set_name').addEventListener('input', updateFilenamePreview);
</script>
</body>
</html>

