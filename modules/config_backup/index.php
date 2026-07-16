<?php
session_start();
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../auth_check.php';

// Restrict to admin and backup_manager
if (!in_array($_SESSION['role'] ?? '', ['admin', 'backup_manager'])) {
    header('Location: /home.php');
    exit;
}

$upload_dir = __DIR__ . '/uploads/';
$max_size = 50 * 1024 * 1024; // 50 MB

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    $device_id   = (int)($_POST['device_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $file        = $_FILES['backup_file'];
    $uploaded_by = $_SESSION['name'] ?? 'unknown';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Upload error code: " . $file['error'];
    } elseif ($file['size'] > $max_size) {
        $error = "File too large. Maximum 50 MB allowed.";
    } elseif ($device_id <= 0) {
        $error = "Please select a device.";
    } else {
        // Create year-month subdirectory
        $month_dir = $upload_dir . date('Y-m') . '/';
        if (!is_dir($month_dir)) mkdir($month_dir, 0755, true);

        // Sanitize original filename
        $orig_name = basename($file['name']);
        $safe_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $orig_name);
        $dest_path = $month_dir . time() . '_' . $safe_name;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            // Compress if not already compressed
            $compressed = false;
            if (!in_array(strtolower(pathinfo($orig_name, PATHINFO_EXTENSION)), ['gz','zip','bz2','xz','7z'])) {
                $gz_path = $dest_path . '.gz';
                $gz = gzopen($gz_path, 'wb9');
                if ($gz) {
                    gzwrite($gz, file_get_contents($dest_path));
                    gzclose($gz);
                    unlink($dest_path);
                    $dest_path = $gz_path;
                    $compressed = true;
                }
            }

            // Store metadata
            $meta = [
                'device_id'   => $device_id,
                'notes'       => $notes,
                'uploaded_by' => $uploaded_by,
                'original_name' => $orig_name,
                'size'        => filesize($dest_path),
                'compressed'  => $compressed,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($dest_path . '.meta', json_encode($meta));

            $success = "File uploaded successfully.";
        } else {
            $error = "Failed to move uploaded file.";
        }
    }
}

// Fetch devices for dropdown
$devices = [];
$res = mysqli_query($con, "SELECT id, name, ip FROM devices ORDER BY name");
while ($row = mysqli_fetch_assoc($res)) $devices[] = $row;

// Fetch existing backups
$backups = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($upload_dir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'meta') continue;
    if ($file->isFile()) {
        $meta_file = $file->getPathname() . '.meta';
        $meta_data = [];
        if (file_exists($meta_file)) {
            $meta_data = json_decode(file_get_contents($meta_file), true);
        }
        $backups[] = [
            'path'      => $file->getPathname(),
            'name'      => $file->getFilename(),
            'size'      => $file->getSize(),
            'modified'  => date('Y-m-d H:i:s', $file->getMTime()),
            'meta'      => $meta_data,
        ];
    }
}
usort($backups, function($a, $b) { return $b['modified'] <=> $a['modified']; });

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configuration Backup | Janus</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/font-awesome/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/pages/config_backup.css">
</head>
<body class="loggedin">
<?php include __DIR__ . '/../../topbar.php'; ?>
<?php include __DIR__ . '/../../sidebar.php'; ?>
<div id="main-content">
    <div class="container-fluid">
        <h2 class="mb-4"><i data-lucide="upload" class="icon-lucide me-2"></i>Configuration Backup Manager</h2>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i data-lucide="cloud-upload-alt" class="icon-lucide me-1"></i>Upload Backup</h5></div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Device</label>
                                <select name="device_id" class="form-select" required>
                                    <option value="">-- Select Device --</option>
                                    <?php foreach ($devices as $dev): ?>
                                    <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Optional description..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">File (max 50 MB)</label>
                                <input type="file" name="backup_file" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary"><i data-lucide="upload" class="icon-lucide me-1"></i>Upload</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0"><i data-lucide="history" class="icon-lucide me-1"></i>Backup Archives</h5></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Filename</th>
                                        <th>Size</th>
                                        <th>Device</th>
                                        <th>Uploaded</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($backups)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No backups yet.</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($backups as $b): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($b['meta']['original_name'] ?? $b['name']) ?></td>
                                        <td><?= round($b['size'] / 1024 / 1024, 2) ?> MB</td>
                                        <td>
                                            <?php 
                                            $dev_id = $b['meta']['device_id'] ?? 0;
                                            $dev_name = '';
                                            foreach ($devices as $d) if ($d['id'] == $dev_id) { $dev_name = $d['name'] . ' (' . $d['ip'] . ')'; break; }
                                            echo htmlspecialchars($dev_name ?: 'Unknown');
                                            ?>
                                        </td>
                                        <td><?= $b['modified'] ?></td>
                                        <td>
                                            <a href="/modules/config_backup/download.php?file=<?= urlencode(basename($b['path'])) ?>&dir=<?= urlencode(dirname($b['path'])) ?>" class="btn btn-sm btn-primary"><i data-lucide="download" class="icon-lucide"></i></a>
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
        </div>
    </div>
</div>
<script src="/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const sb = document.getElementById('sidebar');
    const mc = document.getElementById('main-content');
    if (sb) new MutationObserver(() => mc.style.marginLeft = sb.classList.contains('collapsed') ? '78px' : '260px').observe(sb, {attributes: true});
});
</script>
</body>
</html>
