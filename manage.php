<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
} else {
    $message = '';
}
require_once __DIR__ . '/db_config.php';
/* ------------------------------------------------------------------
   FETCH DEVICES (once)
------------------------------------------------------------------ */
$devices_query = "SELECT * FROM devices ORDER BY type ASC, name ASC";
$devices_result = mysqli_query($con, $devices_query);
$devices = [];
while ($row = mysqli_fetch_assoc($devices_result)) {
    $devices[] = $row;
}
/* ------------------------------------------------------------------
   DEVICE DELETE
------------------------------------------------------------------ */
if (isset($_GET['delete_device'])) {
    $delete_id = intval($_GET['delete_device']);
    if ($delete_id > 0) {
        $stmt = $con->prepare("DELETE FROM ping_logs WHERE device_id = ?");
        $stmt->bind_param("i", $delete_id); $stmt->execute(); $stmt->close();
        $stmt = $con->prepare("DELETE FROM snmp_metrics WHERE device_id = ?");
        $stmt->bind_param("i", $delete_id); $stmt->execute(); $stmt->close();
        $stmt = $con->prepare("DELETE FROM devices WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $_SESSION['message'] = $stmt->execute() ? 'device_deleted' : 'error: ' . $stmt->error;
        $stmt->close();
    }
    header('Location: manage.php'); exit;
}
/* ------------------------------------------------------------------
   LINK DELETE
------------------------------------------------------------------ */
if (isset($_GET['delete_link'])) {
    $delete_id = intval($_GET['delete_link']);
    if ($delete_id > 0) {
        $stmt = $con->prepare("DELETE FROM ping_logs WHERE link_id = ?");
        $stmt->bind_param("i", $delete_id); $stmt->execute(); $stmt->close();
        $stmt = $con->prepare("DELETE FROM links WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $_SESSION['message'] = $stmt->execute() ? 'link_deleted' : 'error: ' . $stmt->error;
        $stmt->close();
    }
    header('Location: manage.php'); exit;
}
/* ------------------------------------------------------------------
   ADD DEVICE
------------------------------------------------------------------ */
if ($_POST && isset($_POST['add_device'])) {
    $name = mysqli_real_escape_string($con, $_POST['name']);
    $ip   = mysqli_real_escape_string($con, $_POST['ip']);
    $type = mysqli_real_escape_string($con, $_POST['type']);
    $model = $_POST['model'] ?? '';

    // Handle custom model
    if (isset($_POST['custom_model']) && $_POST['model'] === 'Other') {
        $model = mysqli_real_escape_string($con, $_POST['custom_model']);
    } else {
        $model = mysqli_real_escape_string($con, $model);
    }

    $city   = mysqli_real_escape_string($con, $_POST['city'] ?? '');
    $sub_office = mysqli_real_escape_string($con, $_POST['sub_office'] ?? '');
    $country= mysqli_real_escape_string($con, $_POST['country'] ?? '');
    $contact= mysqli_real_escape_string($con, $_POST['contact_number'] ?? '');
    $email  = mysqli_real_escape_string($con, $_POST['email'] ?? '');
    $snmp_c = mysqli_real_escape_string($con, $_POST['snmp_community'] ?? '');
    $snmp_v = mysqli_real_escape_string($con, $_POST['snmp_version'] ?? '2c');
    $snmp_p = intval($_POST['snmp_port'] ?? 161);

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $_SESSION['message'] = 'error: Invalid IP address';
        header('Location: manage.php'); exit;
    }

    // Check for duplicate IP
    $check_stmt = $con->prepare("SELECT id, name FROM devices WHERE ip = ?");
    $check_stmt->bind_param('s', $ip);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $existing = $check_result->fetch_assoc();
        $_SESSION['message'] = 'error: IP address ' . $ip . ' is already assigned to device "' . $existing['name'] . '"';
        $check_stmt->close();
        header('Location: manage.php'); exit;
    }
    $check_stmt->close();

    // Insert the new device
    $stmt = $con->prepare("INSERT INTO devices (name, type, ip, model, city, sub_office, country, contact_number, email, snmp_community, snmp_version, snmp_port) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssssssssi', $name, $type, $ip, $model, $city, $sub_office, $country, $contact, $email, $snmp_c, $snmp_v, $snmp_p);
    $_SESSION['message'] = $stmt->execute() ? 'device_success' : 'error: ' . $stmt->error;
    $stmt->close();
    header('Location: manage.php'); exit;
}
/* ------------------------------------------------------------------
   EDIT DEVICE
------------------------------------------------------------------ */
if ($_POST && isset($_POST['edit_device'])) {
    $id = intval($_POST['id']);
    $name = mysqli_real_escape_string($con, $_POST['name']);
    $ip   = mysqli_real_escape_string($con, $_POST['ip']);
    $type = mysqli_real_escape_string($con, $_POST['type']);
    $model = $_POST['model'] ?? '';

    // Handle custom model
    if (isset($_POST['custom_model']) && $_POST['model'] === 'Other') {
        $model = mysqli_real_escape_string($con, $_POST['custom_model']);
    } else {
        $model = mysqli_real_escape_string($con, $model);
    }

    $city   = mysqli_real_escape_string($con, $_POST['city'] ?? '');
    $sub_office = mysqli_real_escape_string($con, $_POST['sub_office'] ?? '');
    $country= mysqli_real_escape_string($con, $_POST['country'] ?? '');
    $contact= mysqli_real_escape_string($con, $_POST['contact_number'] ?? '');
    $email  = mysqli_real_escape_string($con, $_POST['email'] ?? '');
    $snmp_c = mysqli_real_escape_string($con, $_POST['snmp_community'] ?? '');
    $snmp_v = mysqli_real_escape_string($con, $_POST['snmp_version'] ?? '2c');
    $snmp_p = intval($_POST['snmp_port'] ?? 161);

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $_SESSION['message'] = 'error: Invalid IP address';
        header('Location: manage.php'); exit;
    }

    // Check for duplicate IP (excluding current device)
    $check_stmt = $con->prepare("SELECT id, name FROM devices WHERE ip = ? AND id != ?");
    $check_stmt->bind_param('si', $ip, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $existing = $check_result->fetch_assoc();
        $_SESSION['message'] = 'error: IP address ' . $ip . ' is already assigned to device "' . $existing['name'] . '"';
        $check_stmt->close();
        header('Location: manage.php'); exit;
    }
    $check_stmt->close();

    // Update the device
    $stmt = $con->prepare("UPDATE devices SET name=?, type=?, ip=?, model=?, city=?, sub_office=?, country=?, contact_number=?, email=?, snmp_community=?, snmp_version=?, snmp_port=? WHERE id=?");
    $stmt->bind_param('sssssssssssii', $name, $type, $ip, $model, $city, $sub_office, $country, $contact, $email, $snmp_c, $snmp_v, $snmp_p, $id);
    $_SESSION['message'] = $stmt->execute() ? 'device_updated' : 'error: ' . $stmt->error;
    $stmt->close();
    header('Location: manage.php'); exit;
}
/* ------------------------------------------------------------------
   ADD LINK
------------------------------------------------------------------ */
if ($_POST && isset($_POST['add_link'])) {
    $name = mysqli_real_escape_string($con, $_POST['name']);
    $ip   = mysqli_real_escape_string($con, $_POST['ip']);
    $from_device_id = intval($_POST['from_device_id']);
    $to_device_id = intval($_POST['to_device_id']);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $_SESSION['message'] = 'error: Invalid IP address for link';
        header('Location: manage.php'); exit;
    }
    if ($from_device_id === $to_device_id) {
        $_SESSION['message'] = 'error: From and To devices cannot be the same';
        header('Location: manage.php'); exit;
    }
    $stmt = $con->prepare("INSERT INTO links (name, ip, from_device_id, to_device_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssii', $name, $ip, $from_device_id, $to_device_id);
    $_SESSION['message'] = $stmt->execute() ? 'link_success' : 'error: ' . $stmt->error;
    $stmt->close();
    header('Location: manage.php'); exit;
}
/* ------------------------------------------------------------------
   EDIT LINK
------------------------------------------------------------------ */
if ($_POST && isset($_POST['edit_link'])) {
    $id = intval($_POST['id']);
    $name = mysqli_real_escape_string($con, $_POST['name']);
    $ip   = mysqli_real_escape_string($con, $_POST['ip']);
    $from_device_id = intval($_POST['from_device_id']);
    $to_device_id = intval($_POST['to_device_id']);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $_SESSION['message'] = 'error: Invalid IP address for link';
        header('Location: manage.php'); exit;
    }
    if ($from_device_id === $to_device_id) {
        $_SESSION['message'] = 'error: From and To devices cannot be the same';
        header('Location: manage.php'); exit;
    }
    $stmt = $con->prepare("UPDATE links SET name=?, ip=?, from_device_id=?, to_device_id=? WHERE id=?");
    $stmt->bind_param('ssiii', $name, $ip, $from_device_id, $to_device_id, $id);
    $_SESSION['message'] = $stmt->execute() ? 'link_updated' : 'error: ' . $stmt->error;
    $stmt->close();
    header('Location: manage.php'); exit;
}
/* ------------------------------------------------------------------
   FETCH LINKS (with device names)
------------------------------------------------------------------ */
$links_query = "
    SELECT l.*, df.name AS from_name, dt.name AS to_name
    FROM links l
    LEFT JOIN devices df ON l.from_device_id = df.id
    LEFT JOIN devices dt ON l.to_device_id = dt.id
    ORDER BY l.name ASC
";
$links_result = mysqli_query($con, $links_query);
$theme = $_COOKIE['theme'] ?? 'dark';
// EXPANDED Model options per type for Network Monitoring
$model_options = [
    'server'   => ['Dell PowerEdge', 'HP ProLiant', 'Hypervisor (VMware)', 'Hypervisor (Hyper-V)', 'Virtual Machine', 'Container Host', 'Other'],
    'switch'   => ['Cisco Catalyst 2960', 'Cisco Catalyst 3750', 'Cisco Nexus', 'HP ProCurve 2530', 'HP Aruba 2930F', 'Juniper EX2300', 'Juniper EX4300', 'Other'],
    'firewall' => ['Cisco ASA 5506', 'Cisco ASA 5516', 'Palo Alto PA-220', 'Palo Alto PA-850', 'FortiGate 60E', 'FortiGate 100E', 'FortiGate 200E', 'pfSense', 'OPNsense', 'Other'],
    'router'   => ['Cisco ISR 4331', 'Cisco ISR 4451', 'Cisco ASR 1001', 'Juniper MX204', 'Juniper MX480', 'MikroTik CCR1036', 'MikroTik RB4011', 'Ubiquiti EdgeRouter', 'Other'],
    'iot'      => ['IP Camera', 'Access Point', 'Smart Sensor', 'Environmental Monitor', 'UPS System', 'PDU', 'KVM Switch', 'Other'],
    'wireless' => ['Cisco Aironet', 'Aruba AP-515', 'Ubiquiti UniFi AP', 'Ruckus R650', 'Other'],
    'loadbalancer' => ['F5 BIG-IP', 'Citrix ADC', 'HAProxy', 'NGINX Plus', 'Other'],
    'storage'  => ['NetApp FAS', 'Dell EMC Unity', 'HPE 3PAR', 'Synology NAS', 'QNAP NAS', 'Other'],
    'others'   => ['Other']
];
// Get device count by type for dashboard
$type_counts = [];
$type_query = "SELECT type, COUNT(*) as count FROM devices GROUP BY type ORDER BY count DESC";
$type_result = mysqli_query($con, $type_query);
while($row = mysqli_fetch_assoc($type_result)) {
    $type_counts[$row['type']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manage Devices & Links - JanusNMS</title>
    <!-- Local Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- Local Font Awesome CSS -->
    <link href="css/font-awesome/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/theme.css">
<style>
        /* ==== NO FLICKER ==== */
        .modal, .modal *, .modal-backdrop { transition: none !important; }
        .modal.show { display: block !important; }
        .form-label { color: var(--text) !important; }
        #main-content { transition: margin-left .3s ease; }
        .custom-model { display: none; }
        .table code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            color: var(--text) !important;
        }
        [data-theme="light"] .table code {
            background: rgba(0,0,0,0.05);
        }
        /* Type badges */
        .type-badge {            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .type-badge.firewall { background: #ef4444; color: white; }
        .type-badge.router { background: #3b82f6; color: white; }
        .type-badge.switch { background: #10b981; color: white; }
        .type-badge.server { background: #8b5cf6; color: white; }
        .type-badge.iot { background: #f59e0b; color: white; }
        .type-badge.wireless { background: #06b6d4; color: white; }
        .type-badge.loadbalancer { background: #ec4899; color: white; }
        .type-badge.storage { background: #6366f1; color: white; }
        .type-badge.others { background: #6b7280; color: white; }
        /* Summary cards */
        .summary-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .summary-card h6 {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-bottom: 8px;
        }
        .summary-card .count {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
        }
    </style>
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content">
<div class="container-fluid">
<!-- Toast Messages -->
<?php if ($message): ?>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
    <div class="toast align-items-center text-bg-<?= strpos($message,'error')===0?'danger':'success' ?> border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body">
                <?php
                $msg = $message;
                if ($msg === 'device_success') $msg = 'Device added successfully!';
                elseif ($msg === 'device_deleted') $msg = 'Device deleted!';
                elseif ($msg === 'device_updated') $msg = 'Device updated!';
                elseif ($msg === 'link_success') $msg = 'Link added successfully!';
                elseif ($msg === 'link_deleted') $msg = 'Link deleted!';
                elseif ($msg === 'link_updated') $msg = 'Link updated!';
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
        <h2><i class="fas fa-network-wired"></i> Network Infrastructure Management</h2>
        <p class="text-muted">Manage all network devices, appliances, and interconnections</p>
    </div>
</div>
<!-- Summary Statistics -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="summary-card">
            <h6>TOTAL DEVICES</h6>
            <div class="count"><?= count($devices) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h6>DEVICE TYPES</h6>
            <div class="count"><?= count($type_counts) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h6>NETWORK LINKS</h6>
            <div class="count"><?= mysqli_num_rows($links_result) ?></div>
        </div>
    </div>
</div>
<!-- Add Buttons -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeviceModal">
                    <i class="fas fa-plus"></i> Add New Device
                </button>
                <button class="btn btn-info ms-2" data-bs-toggle="modal" data-bs-target="#addLinkModal">
                    <i class="fas fa-link"></i> Add New Link
                </button>
                <button class="btn btn-secondary ms-2" onclick="exportToCSV()">
                    <i class="fas fa-file-export"></i> Export Inventory
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Devices Table -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Devices Inventory (<?= count($devices) ?>)</h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="typeFilter" style="width: 150px;">
                        <option value="">All Types</option>
                        <option value="firewall">Firewalls</option>
                        <option value="router">Routers</option>
                        <option value="switch">Switches</option>
                        <option value="server">Servers</option>
                        <option value="iot">IoT Devices</option>
                        <option value="wireless">Wireless</option>
                        <option value="loadbalancer">Load Balancers</option>
                        <option value="storage">Storage</option>
                        <option value="others">Others</option>
                    </select>
                    <input type="search" class="form-control form-control-sm" placeholder="Search..." id="deviceSearch" style="width: 200px;">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="deviceTable">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th><th>Name</th><th>IP</th><th>Type</th><th>Model</th>
                                <th>Location</th><th>Contact</th><th>Email</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $d): ?>
                            <tr data-type="<?= htmlspecialchars($d['type']) ?>">
                                <td><?= $d['id'] ?></td>
                                <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                                <td><code><?= htmlspecialchars($d['ip']) ?></code></td>
                                <td><span class="type-badge <?= htmlspecialchars($d['type']) ?>"><?= strtoupper($d['type']) ?></span></td>
                                <td><?= htmlspecialchars($d['model']) ?></td>
                                <td><?= htmlspecialchars($d['city']) ?><?= $d['sub_office'] ? ', ' . htmlspecialchars($d['sub_office']) : '' ?></td>
                                <td><?= htmlspecialchars($d['contact_number']) ?></td>
                                <td><?= htmlspecialchars($d['email']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDevice<?= $d['id'] ?>" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete_device=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this device? All associated data will be removed.')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Links Table -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Network Links (<?= mysqli_num_rows($links_result) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr><th>ID</th><th>Name</th><th>IP</th><th>From Device</th><th>To Device</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php while ($l = mysqli_fetch_assoc($links_result)): ?>
                            <tr>
                                <td><?= $l['id'] ?></td>
                                <td><strong><?= htmlspecialchars($l['name']) ?></strong></td>
                                <td><code><?= htmlspecialchars($l['ip']) ?></code></td>
                                <td><?= htmlspecialchars($l['from_name']) ?></td>
                                <td><?= htmlspecialchars($l['to_name']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editLink<?= $l['id'] ?>" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete_link=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this link?')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<!-- ========================================
     EDIT DEVICE MODALS
     ======================================== -->
<?php foreach ($devices as $d):
    $options = $model_options[$d['type']] ?? ['Other'];
    $isCustom = $d['model'] && !in_array($d['model'], $options);
?>
<div class="modal fade" id="editDevice<?= $d['id'] ?>" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <input type="hidden" name="edit_device" value="1">
            <input type="hidden" name="id" value="<?= $d['id'] ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Device: <?= htmlspecialchars($d['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Device Name *</label>
                            <input name="name" class="form-control" value="<?= htmlspecialchars($d['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IP Address *</label>
                            <input name="ip" class="form-control" value="<?= htmlspecialchars($d['ip']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Device Type *</label>
                            <select name="type" class="form-select type-select" data-target="model-<?= $d['id'] ?>" required>
                                <option value="firewall" <?= $d['type']=='firewall'?'selected':'' ?>>Firewall</option>
                                <option value="router" <?= $d['type']=='router'?'selected':'' ?>>Router</option>
                                <option value="switch" <?= $d['type']=='switch'?'selected':'' ?>>Switch</option>
                                <option value="server" <?= $d['type']=='server'?'selected':'' ?>>Server</option>
                                <option value="iot" <?= $d['type']=='iot'?'selected':'' ?>>IoT Device</option>
                                <option value="wireless" <?= $d['type']=='wireless'?'selected':'' ?>>Wireless AP</option>
                                <option value="loadbalancer" <?= $d['type']=='loadbalancer'?'selected':'' ?>>Load Balancer</option>
                                <option value="storage" <?= $d['type']=='storage'?'selected':'' ?>>Storage Device</option>
                                <option value="others" <?= $d['type']=='others'?'selected':'' ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model</label>
                            <select name="model" class="form-select model-select" id="model-<?= $d['id'] ?>">
                                <?php foreach ($options as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $d['model']==$opt?'selected':'' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="custom_model" class="form-control mt-2 custom-model"
                                   placeholder="Enter custom model"
                                   value="<?= $isCustom?htmlspecialchars($d['model']):'' ?>"
                                   style="<?= ($d['model']=='Other' || $isCustom) ? '' : 'display:none' ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input name="city" class="form-control" value="<?= htmlspecialchars($d['city']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sub-Office</label>
                            <input name="sub_office" class="form-control" value="<?= htmlspecialchars($d['sub_office']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Country</label>
                            <input name="country" class="form-control" value="<?= htmlspecialchars($d['country']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input name="contact_number" class="form-control" value="<?= htmlspecialchars($d['contact_number']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input name="email" type="email" class="form-control" value="<?= htmlspecialchars($d['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SNMP Community</label>
                            <input name="snmp_community" class="form-control" value="<?= htmlspecialchars($d['snmp_community']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SNMP Version</label>
                            <select name="snmp_version" class="form-select">
                                <option value="1" <?= $d['snmp_version']=='1'?'selected':'' ?>>1</option>
                                <option value="2c" <?= $d['snmp_version']=='2c'?'selected':'' ?>>2c</option>
                                <option value="3" <?= $d['snmp_version']=='3'?'selected':'' ?>>3</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SNMP Port</label>
                            <input name="snmp_port" type="number" class="form-control" value="<?= $d['snmp_port'] ?? 161 ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Device</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<!-- ========================================
     EDIT LINK MODALS
     ======================================== -->
<?php
mysqli_data_seek($links_result, 0);
while ($l = mysqli_fetch_assoc($links_result)):
?>
<div class="modal fade" id="editLink<?= $l['id'] ?>" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <form method="post">
            <input type="hidden" name="edit_link" value="1">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Link: <?= htmlspecialchars($l['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Link Name *</label>
                        <input name="name" class="form-control" value="<?= htmlspecialchars($l['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IP Address *</label>
                        <input name="ip" class="form-control" value="<?= htmlspecialchars($l['ip']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">From Device *</label>
                        <select name="from_device_id" class="form-select" required>
                            <?php foreach ($devices as $dev): ?>
                                <option value="<?= $dev['id'] ?>" <?= $dev['id']==$l['from_device_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To Device *</label>
                        <select name="to_device_id" class="form-select" required>
                            <?php foreach ($devices as $dev): ?>
                                <option value="<?= $dev['id'] ?>" <?= $dev['id']==$l['to_device_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Link</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endwhile; ?>
<!-- Add Device Modal -->
<div class="modal fade" id="addDeviceModal" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <input type="hidden" name="add_device" value="1">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Network Device</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Device Name *</label>
                            <input name="name" class="form-control" placeholder="e.g., Core-Router-01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IP Address *</label>
                            <input name="ip" class="form-control" placeholder="e.g., 192.168.1.1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Device Type *</label>
                            <select name="type" class="form-select" id="add_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="firewall">Firewall</option>
                                <option value="router">Router</option>
                                <option value="switch">Switch</option>
                                <option value="server">Server</option>
                                <option value="iot">IoT Device</option>
                                <option value="wireless">Wireless Access Point</option>
                                <option value="loadbalancer">Load Balancer</option>
                                <option value="storage">Storage Device</option>
                                <option value="others">Others</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model</label>
                            <select name="model" class="form-select" id="add_model">
                                <option value="">-- Select Model --</option>
                            </select>
                            <input type="text" name="custom_model" class="form-control mt-2 custom-model" id="add_custom" placeholder="Enter custom model" style="display:none">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input name="city" class="form-control" placeholder="e.g., Rawalpindi">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sub-Office</label>
                            <input name="sub_office" class="form-control" placeholder="e.g., HQ Building A">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Country</label>
                            <input name="country" class="form-control" placeholder="e.g., Pakistan">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input name="contact_number" class="form-control" placeholder="e.g., +92-51-1234567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input name="email" type="email" class="form-control" placeholder="e.g., admin@company.com">
                        </div>
                        <div class="col-12"><hr></div>
                        <div class="col-12"><h6>SNMP Configuration (Optional)</h6></div>
                        <div class="col-md-6">
                            <label class="form-label">SNMP Community</label>
                            <input name="snmp_community" class="form-control" placeholder="e.g., public">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SNMP Version</label>
                            <select name="snmp_version" class="form-select">
                                <option value="1">v1</option>
                                <option value="2c" selected>v2c</option>
                                <option value="3">v3</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SNMP Port</label>
                            <input name="snmp_port" type="number" class="form-control" value="161">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Device</button>
                </div>
            </div>
        </form>
    </div>
</div>
<!-- Add Link Modal -->
<div class="modal fade" id="addLinkModal" data-bs-backdrop="static">
    <div class="modal-dialog">
        <form method="post">
            <input type="hidden" name="add_link" value="1">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Network Link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Link Name *</label>
                        <input name="name" class="form-control" placeholder="e.g., Primary WAN Link" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IP Address to Monitor *</label>
                        <input name="ip" class="form-control" placeholder="Gateway or endpoint IP" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">From Device *</label>
                        <select name="from_device_id" class="form-select" required>
                            <option value="">-- Select Source Device --</option>
                            <?php foreach ($devices as $dev): ?>
                                <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To Device *</label>
                        <select name="to_device_id" class="form-select" required>
                            <option value="">-- Select Destination Device --</option>
                            <?php foreach ($devices as $dev): ?>
                                <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Link</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
const models = <?= json_encode($model_options) ?>;
function populate(select, type, selected = null) {
    select.innerHTML = '<option value="">-- Select Model --</option>';
    if (models[type]) {
        models[type].forEach(m => {
            const opt = new Option(m, m, false, m === selected);
            select.add(opt);
        });
    }
    const custom = select.parentElement.querySelector('.custom-model');
    if (custom) custom.style.display = select.value === 'Other' ? 'block' : 'none';
}
// Add Device Modal
document.getElementById('addDeviceModal').addEventListener('show.bs.modal', () => {
    const type = document.getElementById('add_type');
    const model = document.getElementById('add_model');
    type.onchange = () => populate(model, type.value);
    model.onchange = () => {
        document.getElementById('add_custom').style.display = model.value === 'Other' ? 'block' : 'none';
    };
});
// Edit Modals: Handle type/model change
document.addEventListener('change', e => {
    if (e.target.matches('.type-select')) {
        const target = e.target.dataset.target;
        const model = document.getElementById(target);
        populate(model, e.target.value, model.value);
    }
    if (e.target.matches('.model-select')) {
        const custom = e.target.parentElement.querySelector('.custom-model');
        if (custom) custom.style.display = e.target.value === 'Other' ? 'block' : 'none';
    }
});
// Search functionality
document.getElementById('deviceSearch')?.addEventListener('input', e => {
    const term = e.target.value.toLowerCase();
    filterTable(term, document.getElementById('typeFilter').value);
});
// Type filter
document.getElementById('typeFilter')?.addEventListener('change', e => {
    const type = e.target.value;
    filterTable(document.getElementById('deviceSearch').value.toLowerCase(), type);
});
function filterTable(searchTerm, typeFilter) {
    document.querySelectorAll('#deviceTable tbody tr').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const rowType = tr.getAttribute('data-type');
        const matchesSearch = text.includes(searchTerm);
        const matchesType = !typeFilter || rowType === typeFilter;
        tr.style.display = (matchesSearch && matchesType) ? '' : 'none';
    });
}
// Export to CSV
function exportToCSV() {
    const rows = [];
    const headers = ['ID', 'Name', 'IP', 'Type', 'Model', 'City', 'Sub Office', 'Country', 'Contact', 'Email'];
    rows.push(headers.join(','));
    document.querySelectorAll('#deviceTable tbody tr').forEach(tr => {
        if (tr.style.display !== 'none') {
            const cells = tr.querySelectorAll('td');
            const row = [];
            for (let i = 0; i < cells.length - 1; i++) { // Exclude actions column
                let text = cells[i].textContent.trim();
                text = text.replace(/"/g, '""'); // Escape quotes
                row.push('"' + text + '"');
            }
            rows.push(row.join(','));
        }
    });
    const csv = rows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'device_inventory_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
// Sidebar margin adjustment
document.addEventListener('DOMContentLoaded', function(){
    const s = document.getElementById('sidebar');
    const c = document.getElementById('main-content');
    function adj(){
        if (s && c) {
            c.style.marginLeft = s.classList.contains('collapsed') ? '80px' : '260px';
        }
    }
    adj();
    if(s) {
        new MutationObserver(adj).observe(s, {
            attributes: true,
            attributeFilter: ['class']
        });
    }
});
// Show toasts
document.querySelectorAll('.toast').forEach(t => new bootstrap.Toast(t).show());
</script>
<!-- Local Bootstrap JS Bundle -->
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
