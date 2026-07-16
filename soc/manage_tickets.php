<?php
require_once __DIR__ . '/../db_config.php';
// manage_tickets.php - Admin-only: Full control
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header('Location: /home.php');
    exit;
}


if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $reported_by = $_SESSION['id'];

        if ($id <= 0) {
            $message = '<div class="alert alert-danger">Invalid ticket ID.</div>';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'delete') {
                $child_tables = ['incident_comments', 'incident_history', 'observables'];
                $success = true;
                foreach ($child_tables as $table) {
                    $stmt = $con->prepare("DELETE FROM `$table` WHERE incident_id = ?");
                    $stmt->bind_param("i", $id);
                    if (!$stmt->execute()) $success = false;
                    $stmt->close();
                }
                if ($success) {
                    $stmt = $con->prepare("DELETE FROM incidents WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        $message = '<div class="alert alert-success">Ticket #' . $id . ' deleted permanently.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Failed to delete ticket.</div>';
                    }
                    $stmt->close();
                }
            } elseif ($action === 'edit_severity') {
                $severity = in_array($_POST['severity'], ['low','medium','high','critical']) ? $_POST['severity'] : 'medium';
                $stmt = $con->prepare("UPDATE incidents SET severity = ? WHERE id = ?");
                $stmt->bind_param("si", $severity, $id);
                $stmt->execute();
                $message = '<div class="alert alert-success">Priority updated.</div>';
            } elseif ($action === 'assign') {
                $assigned_to = (int)$_POST['assigned_to'];
                $stmt = $con->prepare("UPDATE incidents SET assigned_to = ?, status = 'in_progress', unread_by_assignee = 1 WHERE id = ?");
                $stmt->bind_param("ii", $assigned_to, $id);
                $stmt->execute();
                $message = '<div class="alert alert-success">Ticket assigned.</div>';
            } elseif ($action === 'change_status') {
                $status = $_POST['new_status'];
                $allowed = ['new','assigned','in_progress','resolved','closed','escalated'];
                if (in_array($status, $allowed)) {
                    $stmt = $con->prepare("UPDATE incidents SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $status, $id);
                    $stmt->execute();
                    $message = '<div class="alert alert-success">Status updated.</div>';
                }
            }
        }
    }
}

$incidents = $con->query("
    SELECT i.*, a.username AS reporter, ass.username AS assignee, d.name AS device_name
    FROM incidents i
    JOIN accounts a ON i.reported_by = a.id
    LEFT JOIN accounts ass ON i.assigned_to = ass.id
    LEFT JOIN devices d ON i.device_id = d.id
    ORDER BY i.created_at DESC
");

$users = $con->query("SELECT id, username FROM accounts ORDER BY username");

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Janus- Manage All Tickets (Admin)</title>
    <!-- Local Bootstrap CSS -->
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <!-- Local Font Awesome -->
    <link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/pages/manage_tickets.css">
</head>
<body class="loggedin">
    <?php include __DIR__ . '/../topbar.php'; ?>
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <div id="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i data-lucide="ticket-alt" class="icon-lucide me-2"></i> Manage All Tickets</h2>
                <span class="badge bg-primary">Admin Only</span>
            </div>
            
            <?= $message ?>

            <?php if ($incidents->num_rows == 0): ?>
                <div class="alert alert-info">
                    <i data-lucide="info" class="icon-lucide me-2"></i> No tickets found.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Reporter</th>
                                <th>Assignee</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($incident = $incidents->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?= $incident['id'] ?></strong></td>
                                <td><?= htmlspecialchars($incident['title']) ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle severity-btn" data-bs-toggle="dropdown">
                                            <?= ucfirst($incident['severity']) ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php foreach (['low','medium','high','critical'] as $p): ?>
                                            <li>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="id" value="<?= $incident['id'] ?>">
                                                    <input type="hidden" name="action" value="edit_severity">
                                                    <input type="hidden" name="severity" value="<?= $p ?>">
                                                    <button type="submit" class="dropdown-item"><?= ucfirst($p) ?></button>
                                                </form>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    switch($incident['status']) {
                                        case 'new': $status_class = 'primary'; break;
                                        case 'assigned': $status_class = 'info'; break;
                                        case 'in_progress': $status_class = 'warning'; break;
                                        case 'resolved': $status_class = 'success'; break;
                                        case 'escalated': $status_class = 'danger'; break;
                                        case 'closed': $status_class = 'secondary'; break;
                                    }
                                    ?>
                                    <span class="badge bg-<?= $status_class ?>"><?= ucfirst(str_replace('_', ' ', $incident['status'])) ?></span>
                                </td>
                                <td><?= date('M j, Y', strtotime($incident['created_at'])) ?></td>
                                <td><?= htmlspecialchars($incident['reporter']) ?></td>
                                <td><?= htmlspecialchars($incident['assignee'] ?? 'Unassigned') ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modal<?= $incident['id'] ?>" title="View Details">
                                            <i data-lucide="eye" class="icon-lucide"></i>
                                        </button>

                                        <div class="btn-group">
                                            <button type="button" class="btn btn-warning dropdown-toggle" data-bs-toggle="dropdown" title="Assign">
                                                <i data-lucide="user-plus" class="icon-lucide"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
                                                <li>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="id" value="<?= $incident['id'] ?>">
                                                        <input type="hidden" name="action" value="assign">
                                                        <input type="hidden" name="assigned_to" value="<?= $u['id'] ?>">
                                                        <button class="dropdown-item"><?= htmlspecialchars($u['username']) ?></button>
                                                    </form>
                                                </li>
                                                <?php endwhile; ?>
                                            </ul>
                                        </div>

                                        <div class="btn-group">
                                            <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" title="Change Status">
                                                <i data-lucide="edit" class="icon-lucide"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php foreach (['new','assigned','in_progress','resolved','closed','escalated'] as $s): ?>
                                                <li>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="id" value="<?= $incident['id'] ?>">
                                                        <input type="hidden" name="action" value="change_status">
                                                        <input type="hidden" name="new_status" value="<?= $s ?>">
                                                        <button class="dropdown-item"><?= ucfirst(str_replace('_', ' ', $s)) ?></button>
                                                    </form>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>

                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete ticket #<?= $incident['id'] ?>? This action cannot be undone.')">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="id" value="<?= $incident['id'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-danger" title="Delete">
                                                <i data-lucide="trash" class="icon-lucide"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            <!-- View Modal -->
                            <div class="modal fade" id="modal<?= $incident['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i data-lucide="ticket-alt" class="icon-lucide me-2"></i>
                                                #<?= $incident['id'] ?> - <?= htmlspecialchars($incident['title']) ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><strong>Description:</strong></p>
                                                    <p class="border p-3 rounded"><?= nl2br(htmlspecialchars($incident['description'])) ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th>Type:</th>
                                                            <td><?= ucfirst($incident['type']) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Subcategory:</th>
                                                            <td><?= ucfirst($incident['subcategory'] ?? 'N/A') ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Priority:</th>
                                                            <td><?= ucfirst($incident['severity']) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Status:</th>
                                                            <td><?= ucfirst(str_replace('_', ' ', $incident['status'])) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Device:</th>
                                                            <td><?= htmlspecialchars($incident['device_name'] ?? 'N/A') ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Reported By:</th>
                                                            <td><?= htmlspecialchars($incident['reporter']) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Assigned To:</th>
                                                            <td><?= htmlspecialchars($incident['assignee'] ?? 'Unassigned') ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Created:</th>
                                                            <td><?= date('Y-m-d H:i', strtotime($incident['created_at'])) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Due Date:</th>
                                                            <td><?= date('Y-m-d H:i', strtotime($incident['due_date'])) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Attachment:</th>
                                                            <td>
                                                                <?php if ($incident['attachment'] && $incident['attachment'] != 'NULL'): ?>
                                                                    <a href="<?= htmlspecialchars($incident['attachment']) ?>" class="btn btn-sm btn-primary" download>
                                                                        <i data-lucide="download" class="icon-lucide"></i> Download
                                                                    </a>
                                                                <?php else: ?>
                                                                    None
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Local Bootstrap JS -->
    <script src="/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar collapse handler
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            
            if (sidebar) {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'class') {
                            if (sidebar.classList.contains('collapsed')) {
                                mainContent.style.marginLeft = '78px';
                            } else {
                                mainContent.style.marginLeft = '250px';
                            }
                        }
                    });
                });
                
                observer.observe(sidebar, { attributes: true });
            }
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>
