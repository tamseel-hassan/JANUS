<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../db_connect.php';

// Verify authentication for the API
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
// For RESTful PUT/DELETE, we might receive JSON body
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (is_array($data)) {
        $_POST = array_merge($_POST, $data);
        $action = $_POST['action'] ?? $action;
    }
}

$user_id = $_SESSION['id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'user';

if ($action === 'create') {
    $title = mysqli_real_escape_string($mysqli, $_POST['title'] ?? '');
    $description = mysqli_real_escape_string($mysqli, $_POST['description'] ?? '');
    $type = mysqli_real_escape_string($mysqli, $_POST['type'] ?? '');
    $priority = mysqli_real_escape_string($mysqli, $_POST['priority'] ?? 'medium');
    $subcategory = mysqli_real_escape_string($mysqli, $_POST['subcategory'] ?? '');
    $device_id = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : 'NULL';
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 'NULL';
    
    // File upload logic
    $attachment_path = '';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === 0) {
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ['txt','pdf','jpg','png','doc','docx','zip'])) {
            $path = $uploadDir . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $path)) {
                $attachment_path = $path;
            }
        }
    }

    $due_days = ['low'=>14, 'medium'=>7, 'high'=>3, 'critical'=>1];
    $due_date = date('Y-m-d H:i:s', strtotime('+' . ($due_days[$priority] ?? 7) . ' days'));
    $status = ($assigned_to != 'NULL') ? 'in_progress' : 'open';

    $stmt = $mysqli->prepare("INSERT INTO incidents (title, description, type, severity, subcategory, device_id, assigned_to, reported_by, due_date, status, unread_by_assignee, attachment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->bind_param('sssssiissss', $title, $description, $type, $priority, $subcategory, $device_id, $assigned_to, $user_id, $due_date, $status, $attachment_path);
    
    if ($stmt->execute()) {
        $incident_id = $stmt->insert_id;
        $stmt->close();

        // History
        $mysqli->query("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES ($incident_id, $user_id, 'status', 'none', 'open')");
        if ($assigned_to != 'NULL') {
            $assigned_str = mysqli_real_escape_string($mysqli, $_POST['assigned_to']);
            $mysqli->query("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES ($incident_id, $user_id, 'assigned_to', 'none', '$assigned_str')");
        }

        // Observables
        if (!empty($_POST['observables']) && is_array($_POST['observables'])) {
            foreach ($_POST['observables'] as $obs) {
                $obs_type = mysqli_real_escape_string($mysqli, $obs['type']);
                $obs_value = mysqli_real_escape_string($mysqli, $obs['value']);
                $obs_desc = mysqli_real_escape_string($mysqli, $obs['description'] ?? '');
                $obs_stmt = $mysqli->prepare("INSERT INTO observables (incident_id, type, value, notes) VALUES (?, ?, ?, ?)");
                $obs_stmt->bind_param('isss', $incident_id, $obs_type, $obs_value, $obs_desc);
                $obs_stmt->execute();
                $obs_stmt->close();
            }
        }

        echo json_encode(['success' => true, 'incident_id' => $incident_id]);
    } else {
        echo json_encode(['error' => 'Failed to create incident']);
    }
    exit;
}

if ($action === 'assign') {
    $id = (int)$_POST['id'];
    $assigned_to = (int)$_POST['assigned_to'];
    
    $stmt = $mysqli->prepare("UPDATE incidents SET assigned_to = ?, status = 'in_progress', unread_by_assignee = 1 WHERE id = ?");
    $stmt->bind_param("ii", $assigned_to, $id);
    if ($stmt->execute()) {
        $stmt->close();
        $stmt = $mysqli->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'assigned_to', 'none', ?)");
        $assigned_str = (string)$assigned_to;
        $stmt->bind_param("iis", $id, $user_id, $assigned_str);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to assign incident']);
    }
    exit;
}

if ($action === 'change_status') {
    $id = (int)$_POST['id'];
    $new_status = $_POST['new_status'];
    
    if (in_array($new_status, ['open','in_progress','resolved','closed'])) {
        $stmt = $mysqli->prepare("UPDATE incidents SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $id);
        if ($stmt->execute()) {
            $stmt->close();
            $stmt = $mysqli->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'status', '', ?)");
            $stmt->bind_param("iis", $id, $user_id, $new_status);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Failed to update status']);
        }
    } else {
        echo json_encode(['error' => 'Invalid status']);
    }
    exit;
}

if ($action === 'add_comment') {
    $id = (int)$_POST['id'];
    $comment = mysqli_real_escape_string($mysqli, $_POST['comment']);
    
    $stmt = $mysqli->prepare("INSERT INTO incident_comments (incident_id, user_id, comment) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $id, $user_id, $comment);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to add comment']);
    }
    exit;
}

if ($action === 'delete') {
    if ($user_role !== 'admin') {
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $id = (int)$_POST['id'];
    foreach (['incident_comments','incident_history','observables','incidents'] as $table) {
        $stmt = $mysqli->prepare("DELETE FROM $table WHERE incident_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
