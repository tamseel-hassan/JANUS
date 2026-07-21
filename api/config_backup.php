<?php
session_start();
require_once __DIR__ . '/../db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'] ?? '', ['admin', 'backup_manager', 'analyst'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$upload_dir = realpath(__DIR__ . '/../modules/config_backup/uploads');
if (!$upload_dir) {
    // If it doesn't exist, try to create it
    $target = __DIR__ . '/../modules/config_backup/uploads';
    if (!is_dir($target)) {
        @mkdir($target, 0755, true);
    }
    $upload_dir = realpath($target);
}
$max_size = 50 * 1024 * 1024; // 50 MB

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'download') {
        // Handle Download
        if (!in_array($_SESSION['role'] ?? '', ['admin', 'backup_manager'])) {
            http_response_code(403);
            die('Access denied.');
        }
        $file = $_GET['file'] ?? '';
        $path = realpath($upload_dir . '/' . $file);
        if (!$path || strpos($path, $upload_dir) !== 0 || !is_file($path)) {
            http_response_code(404);
            die('File not found.');
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // Handle Listing
    $devices = [];
    $res = mysqli_query($con, "SELECT id, name, ip FROM devices ORDER BY name");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $devices[] = $row;
        }
    }

    $backups = [];
    if ($upload_dir && is_dir($upload_dir)) {
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
                
                // Get relative path for downloading safely
                $rel_path = str_replace($upload_dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $rel_path = str_replace('\\', '/', $rel_path);

                $backups[] = [
                    'path'      => $rel_path,
                    'name'      => $file->getFilename(),
                    'size'      => $file->getSize(),
                    'modified'  => date('c', $file->getMTime()),
                    'meta'      => $meta_data,
                ];
            }
        }
        usort($backups, function($a, $b) { return strcmp($b['modified'], $a['modified']); });
    }

    echo json_encode([
        'devices' => $devices,
        'backups' => $backups
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'backup_manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You do not have permission to upload configs.']);
        exit;
    }

    $device_id   = (int)($_POST['device_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $uploaded_by = $_SESSION['name'] ?? 'unknown';

    if (!isset($_FILES['backup_file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded.']);
        exit;
    }

    $file = $_FILES['backup_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => "Upload error code: " . $file['error']]);
        exit;
    }
    if ($file['size'] > $max_size) {
        http_response_code(400);
        echo json_encode(['error' => "File too large. Maximum 50 MB allowed."]);
        exit;
    }
    if ($device_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => "Please select a device."]);
        exit;
    }

    $month_dir = $upload_dir . '/' . date('Y-m');
    if (!is_dir($month_dir)) {
        mkdir($month_dir, 0755, true);
    }

    $orig_name = basename($file['name']);
    $safe_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $orig_name);
    $dest_path = $month_dir . '/' . time() . '_' . $safe_name;

    if (move_uploaded_file($file['tmp_name'], $dest_path)) {
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

        echo json_encode(['success' => true, 'message' => 'File uploaded successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'backup_manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
    
    // get raw input
    $data = json_decode(file_get_contents('php://input'), true);
    $file = $data['file'] ?? '';
    if (!$file) {
        http_response_code(400);
        echo json_encode(['error' => 'No file specified.']);
        exit;
    }

    $path = realpath($upload_dir . '/' . $file);
    if (!$path || strpos($path, $upload_dir) !== 0 || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found.']);
        exit;
    }
    
    // delete file and metadata
    unlink($path);
    if (file_exists($path . '.meta')) {
        unlink($path . '.meta');
    }
    echo json_encode(['success' => true, 'message' => 'File deleted successfully.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
