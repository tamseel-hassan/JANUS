<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../db_config.php';
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    echo json_encode(['error' => 'DB Error']);
    http_response_code(500);
    exit;
}

// === Original NOC Summary Stats (Network Devices) ===
$total = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM devices"))['c'] ?? 0);
$up = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM devices d LEFT JOIN ( SELECT device_id, status, ROW_NUMBER() OVER (PARTITION BY device_id ORDER BY checked_at DESC) rn FROM ping_logs WHERE link_id IS NULL ) pl ON d.id = pl.device_id AND pl.rn = 1 WHERE COALESCE(pl.status, 'down') = 'up'"))['c'] ?? 0);
$down = max(0, $total - $up);
$uptime = $total > 0 ? round(($up / $total) * 100, 1) : 0;

// === SOC/NOC Incident/Ticket Stats ===
$my_id = intval($_SESSION['id']);
$my_tasks_count = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM incidents WHERE assigned_to = $my_id AND status != 'closed'"))['c'] ?? 0);
$open_tickets = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM incidents WHERE status != 'closed'"))['c'] ?? 0);
$overdue_tickets = intval(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM incidents WHERE due_date < NOW() AND status != 'closed'"))['c'] ?? 0);

// --- Servers Chart ---
$server_query = "SELECT CASE WHEN model IN ('Hypervisor','Virtual Machine','Container') THEN model ELSE COALESCE(NULLIF(model,''),'Other') END AS model, COUNT(*) AS cnt FROM devices WHERE type = 'server' GROUP BY model ORDER BY cnt DESC";
$server_result = mysqli_query($con, $server_query);
$server_labels = []; $server_data = []; $server_colors = [];
$modern_palette = ['Hypervisor' => '#16a34a', 'Virtual Machine' => '#2563eb', 'Container' => '#f97316', 'Other' => '#6b7280', 'Unknown' => '#ef4444'];
while ($row = mysqli_fetch_assoc($server_result)) {
    $lbl = $row['model'] ?: 'Unknown';
    $server_labels[] = $lbl;
    $server_data[] = intval($row['cnt']);
    $server_colors[] = $modern_palette[$lbl] ?? '#6b7280';
}

// --- Firewalls Chart ---
$fw_query = "SELECT COALESCE(NULLIF(model,''),'Unknown') AS model, COUNT(*) AS cnt FROM devices WHERE type = 'firewall' GROUP BY model ORDER BY cnt DESC";
$fw_result = mysqli_query($con, $fw_query);
$fw_labels = []; $fw_data = []; $fw_colors = [];
$fw_palette = ['#ef4444', '#fb923c', '#8b5cf6', '#06b6d4', '#fbbf24', '#3b82f6'];
$i = 0;
while ($row = mysqli_fetch_assoc($fw_result)) {
    $fw_labels[] = $row['model'];
    $fw_data[] = intval($row['cnt']);
    $fw_colors[] = $fw_palette[$i++ % count($fw_palette)];
}

// --- Switches Chart ---
$sw_query = "SELECT COALESCE(NULLIF(model,''),'Unknown') AS model, COUNT(*) AS cnt FROM devices WHERE type = 'switch' GROUP BY model ORDER BY cnt DESC";
$sw_result = mysqli_query($con, $sw_query);
$sw_labels = []; $sw_data = [];
while ($row = mysqli_fetch_assoc($sw_result)) {
    $sw_labels[] = $row['model'];
    $sw_data[] = intval($row['cnt']);
}

mysqli_close($con);

echo json_encode([
    'stats' => [
        'total' => $total,
        'up' => $up,
        'down' => $down,
        'uptime' => $uptime
    ],
    'tickets' => [
        'my_tasks' => $my_tasks_count,
        'open' => $open_tickets,
        'overdue' => $overdue_tickets
    ],
    'charts' => [
        'servers' => [
            'labels' => $server_labels,
            'data' => $server_data,
            'colors' => $server_colors
        ],
        'firewalls' => [
            'labels' => $fw_labels,
            'data' => $fw_data,
            'colors' => $fw_colors
        ],
        'switches' => [
            'labels' => $sw_labels,
            'data' => $sw_data
        ]
    ]
]);
