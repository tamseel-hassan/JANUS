<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../db_connect.php';

// Verify authentication for the API
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'user';

if ($action === 'options') {
    // Return users and devices for select dropdowns
    $users = [];
    $res = $mysqli->query("SELECT id, username FROM accounts ORDER BY username");
    while ($row = $res->fetch_assoc()) $users[] = $row;

    $devices = [];
    $res = $mysqli->query("SELECT id, name, ip FROM devices ORDER BY name");
    while ($row = $res->fetch_assoc()) $devices[] = $row;

    echo json_encode([
        'users' => $users,
        'devices' => $devices
    ]);
    exit;
}

if ($action === 'list') {
    $type = $_GET['type'] ?? 'active';
    $where = "1=1";

    if ($type === 'my_tasks') {
        $where = "i.assigned_to = $user_id AND i.status != 'closed'";
    } elseif ($type === 'active') {
        $where = "i.status != 'closed'";
    } elseif ($type === 'history') {
        $where = "i.status = 'closed' OR i.status = 'resolved'";
    } elseif ($type === 'manage') {
        // usually same as active or all, let's just do all
        $where = "1=1";
    }

    $query = "
        SELECT 
            i.id, i.title, i.description, i.severity, i.status, i.type, i.subcategory,
            i.assigned_to, i.reported_by, i.device_id, i.due_date, i.resolved_at,
            i.unread_by_assignee, i.created_at, i.updated_at, i.attachment,
            a.username AS reporter_name, 
            ass.username AS assignee_name, 
            d.name AS device_name, d.ip AS device_ip
        FROM incidents i
        LEFT JOIN accounts a ON i.reported_by = a.id
        LEFT JOIN accounts ass ON i.assigned_to = ass.id
        LEFT JOIN devices d ON i.device_id = d.id
        WHERE $where
        ORDER BY i.created_at DESC
    ";

    $res = $mysqli->query($query);
    $incidents = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $incidents[] = $row;
        }
    }

    echo json_encode(['incidents' => $incidents]);
    exit;
}

if ($action === 'details') {
    $id = (int)($_GET['id'] ?? 0);
    
    // Fetch incident
    $query = "
        SELECT 
            i.*, 
            a.username AS reporter_name, 
            ass.username AS assignee_name, 
            d.name AS device_name, d.ip AS device_ip
        FROM incidents i
        LEFT JOIN accounts a ON i.reported_by = a.id
        LEFT JOIN accounts ass ON i.assigned_to = ass.id
        LEFT JOIN devices d ON i.device_id = d.id
        WHERE i.id = $id
    ";
    $res = $mysqli->query($query);
    $incident = $res ? $res->fetch_assoc() : null;

    if (!$incident) {
        echo json_encode(['error' => 'Incident not found']);
        exit;
    }

    // Mark as read if assigned to current user
    if ($incident['assigned_to'] == $user_id && $incident['unread_by_assignee'] == 1) {
        $mysqli->query("UPDATE incidents SET unread_by_assignee = 0 WHERE id = $id");
        $incident['unread_by_assignee'] = 0;
    }

    // Fetch comments
    $comments = [];
    $res = $mysqli->query("
        SELECT c.*, a.username 
        FROM incident_comments c 
        LEFT JOIN accounts a ON c.user_id = a.id 
        WHERE c.incident_id = $id 
        ORDER BY c.created_at ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $comments[] = $row;
    }

    // Fetch observables
    $observables = [];
    $res = $mysqli->query("SELECT * FROM observables WHERE incident_id = $id ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) $observables[] = $row;
    }

    // Fetch history
    $history = [];
    $res = $mysqli->query("
        SELECT h.*, a.username 
        FROM incident_history h 
        LEFT JOIN accounts a ON h.changed_by = a.id 
        WHERE h.incident_id = $id 
        ORDER BY h.changed_at DESC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $history[] = $row;
    }

    echo json_encode([
        'incident' => $incident,
        'comments' => $comments,
        'observables' => $observables,
        'history' => $history
    ]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
