<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: /index.html');
    exit;
}

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

// Fetch users & devices
$users_result = mysqli_query($con, "SELECT id, username FROM accounts ORDER BY username");
$devices_result = mysqli_query($con, "SELECT id, name FROM devices ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = mysqli_real_escape_string($con, $_POST['title']);
    $description = mysqli_real_escape_string($con, $_POST['description']);
    $type        = mysqli_real_escape_string($con, $_POST['type']);
    $priority    = mysqli_real_escape_string($con, $_POST['priority']);
    $subcategory = mysqli_real_escape_string($con, $_POST['subcategory'] ?? '');
    $device_id   = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : 'NULL';
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 'NULL';
    $reported_by = $_SESSION['id'];

    // File upload
    $attachment_path = '';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === 0 && $_FILES['attachment']['size'] <= 5 * 1024 * 1024) {
        $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ['txt','pdf','jpg','png','doc','docx','zip'])) {
            $path = $uploadDir . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['attachment']['tmp_name'], $path);
            $attachment_path = $path;
        }
    }

    // Due date
    $due_days = ['low'=>14, 'medium'=>7, 'high'=>3, 'critical'=>1];
    $due_date = date('Y-m-d H:i:s', strtotime('+' . ($due_days[$priority] ?? 7) . ' days'));

    // Status: 'open' initially, or 'in_progress' if assigned
    $status = ($assigned_to != 'NULL') ? 'in_progress' : 'open';

    // Insert incident
    $stmt = $con->prepare("INSERT INTO incidents (title, description, type, severity, subcategory, device_id, assigned_to, reported_by, due_date, status, unread_by_assignee, attachment)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->bind_param('sssssiissss', $title, $description, $type, $priority, $subcategory, $device_id, $assigned_to, $reported_by, $due_date, $status, $attachment_path);
    if (!$stmt->execute()) {
        error_log('Incident insert error: ' . $stmt->error);
        die('Error creating incident. Please try again.');
    }
    $incident_id = $stmt->insert_id;
    $stmt->close();

    // Incident history (creation)
    $stmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'status', 'none', 'open')");
    $stmt->bind_param('ii', $incident_id, $reported_by);
    $stmt->execute();
    $stmt->close();

    if ($assigned_to != 'NULL') {
        $stmt = $con->prepare("INSERT INTO incident_history (incident_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'assigned_to', 'none', ?)");
        $stmt->bind_param('iis', $incident_id, $reported_by, $_POST['assigned_to']);
        $stmt->execute();
        $stmt->close();
    }

    // Observables
    if (!empty($_POST['observables'])) {
        foreach ($_POST['observables'] as $obs) {
            $obs_type  = mysqli_real_escape_string($con, $obs['type']);
            $obs_value = mysqli_real_escape_string($con, $obs['value']);
            $obs_desc  = mysqli_real_escape_string($con, $obs['description'] ?? '');
            $stmt = $con->prepare("INSERT INTO observables (incident_id, type, value, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $incident_id, $obs_type, $obs_value, $obs_desc);
            $stmt->execute();
            $stmt->close();
        }
    }

    header('Location: my_tasks.php?success=1');
    exit;
}

// Ensure 'attachment' column exists in incidents
$col_check = mysqli_query($con, "SHOW COLUMNS FROM incidents LIKE 'attachment'");
if (mysqli_num_rows($col_check) == 0) {
    mysqli_query($con, "ALTER TABLE incidents ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER status");
}
$col_check = mysqli_query($con, "SHOW COLUMNS FROM incidents LIKE 'subcategory'");
if (mysqli_num_rows($col_check) == 0) {
    mysqli_query($con, "ALTER TABLE incidents ADD COLUMN subcategory VARCHAR(100) DEFAULT NULL AFTER type");
}
$col_check = mysqli_query($con, "SHOW COLUMNS FROM incidents LIKE 'severity'");
if (mysqli_num_rows($col_check) == 0) {
    mysqli_query($con, "ALTER TABLE incidents ADD COLUMN severity ENUM('low','medium','high','critical') DEFAULT 'medium' AFTER type");
}

mysqli_close($con);
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Janus – Report Incident</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/pages/report_incident.css">
</head>
<body class="loggedin">
    <?php include __DIR__ . '/../topbar.php'; ?>
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <div id="main-content">
        <div class="container-fluid py-4">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card shadow-lg border-0">
                        <div class="card-header text-white text-center" style="background: linear-gradient(135deg, #007bff, #00c6ff);">
                            <h3 class="mb-0"><i data-lucide="siren" class="icon-lucide fa-beat me-2"></i> Report New Incident</h3>
                        </div>
                        <div class="card-body p-5">
                            <div class="progress mb-5" style="height: 8px;">
                                <div class="progress-bar bg-info" id="progressBar" style="width: 20%;"></div>
                            </div>

                            <form id="incidentForm" method="POST" enctype="multipart/form-data" novalidate>
                                <!-- Step 1: Type -->
                                <div class="wizard-step active" data-step="1">
                                    <h4 class="text-center mb-4"><i data-lucide="question-circle" class="icon-lucide"></i> What type of incident?</h4>
                                    <div class="row g-4">
                                        <div class="col-md-3">
                                            <div class="type-card card h-100 text-center p-4 selected" data-type="link_down">
                                                <i data-lucide="link-slash" class="icon-lucide fa-3x text-danger mb-3"></i>
                                                <h5>Link Down</h5>
                                                <input type="radio" name="type" value="link_down" checked hidden>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="type-card card h-100 text-center p-4" data-type="malware">
                                                <i data-lucide="bug" class="icon-lucide fa-3x text-warning mb-3"></i>
                                                <h5>Malware/Threat</h5>
                                                <input type="radio" name="type" value="malware" hidden>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="type-card card h-100 text-center p-4" data-type="security_breach">
                                                <i data-lucide="shield-halved" class="icon-lucide fa-3x text-info mb-3"></i>
                                                <h5>Security Breach</h5>
                                                <input type="radio" name="type" value="security_breach" hidden>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="type-card card h-100 text-center p-4" data-type="other">
                                                <i data-lucide="ellipsis-h" class="icon-lucide fa-3x text-secondary mb-3"></i>
                                                <h5>Other</h5>
                                                <input type="radio" name="type" value="other" hidden>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Steps 2-5 remain the same but remove the "escalated_to" field -->
                                <!-- Step 2: Details -->
                                <div class="wizard-step" data-step="2">
                                    <h4 class="text-center mb-4"><i data-lucide="pencil" class="icon-lucide"></i> Describe the Incident</h4>
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Title</label>
                                        <input type="text" class="form-control" name="title" id="title" required minlength="5" maxlength="100">
                                    </div>
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" name="description" id="description" rows="5" required minlength="20"></textarea>
                                    </div>
                                    <div class="mb-3" id="subcategoryWrapper" style="display:none;">
                                        <label for="subcategorySelect" class="form-label">Subcategory</label>
                                        <select class="form-select" name="subcategory" id="subcategorySelect"></select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="device_id" class="form-label">Related Device (Optional)</label>
                                        <select class="form-select" name="device_id">
                                            <option value="">None</option>
                                            <?php while ($device = mysqli_fetch_assoc($devices_result)): ?>
                                            <option value="<?= $device['id'] ?>"><?= htmlspecialchars($device['name']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="attachment" class="form-label">Attachment (Optional, max 5MB)</label>
                                        <input type="file" class="form-control" name="attachment">
                                    </div>
                                </div>

                                <!-- Step 3: Priority & Assignment (without escalation) -->
                                <div class="wizard-step" data-step="3">
                                    <h4 class="text-center mb-4"><i data-lucide="triangle-alert" class="icon-lucide"></i> Set Priority & Assignment</h4>
                                    <div class="d-flex justify-content-around mb-4">
                                        <button type="button" class="btn btn-outline-secondary priority-btn" data-priority="low">Low</button>
                                        <button type="button" class="btn btn-outline-primary priority-btn" data-priority="medium">Medium</button>
                                        <button type="button" class="btn btn-outline-warning priority-btn" data-priority="high">High</button>
                                        <button type="button" class="btn btn-outline-danger priority-btn" data-priority="critical">Critical</button>
                                    </div>
                                    <input type="hidden" name="priority" value="medium">
                                    <p class="text-center text-muted" id="duePreview">Due in 7 days</p>
                                    <div class="mb-3">
                                        <label for="assigned_to" class="form-label">Assign To (Optional)</label>
                                        <select class="form-select" name="assigned_to">
                                            <option value="">Unassigned</option>
                                            <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Step 4: Observables -->
                                <div class="wizard-step" data-step="4">
                                    <h4 class="text-center mb-4"><i data-lucide="eye" class="icon-lucide"></i> Add Observables</h4>
                                    <div id="observablesContainer">
                                        <div class="observable-field">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <select class="form-select" name="observables[0][type]">
                                                        <option value="ip">IP Address</option>
                                                        <option value="url">URL</option>
                                                        <option value="hash">File Hash</option>
                                                        <option value="file">File</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-5">
                                                    <input type="text" class="form-control" name="observables[0][value]" placeholder="Value">
                                                </div>
                                                <div class="col-md-4">
                                                    <input type="text" class="form-control" name="observables[0][description]" placeholder="Description">
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <label>Tags:</label>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="observables[0][tags][]" value="malicious">
                                                    <label>Malicious</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="observables[0][tags][]" value="suspicious">
                                                    <label>Suspicious</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="observables[0][tags][]" value="c2">
                                                    <label>C&C</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary mt-3" id="addObservable">Add Another Observable</button>
                                </div>

                                <!-- Step 5: Preview & Confirm -->
                                <div class="wizard-step" data-step="5">
                                    <h4 class="text-center mb-4"><i data-lucide="check-circle" class="icon-lucide"></i> Preview & Confirm</h4>
                                    <div class="preview-box p-4 mb-4">
                                        <p><strong>Title:</strong> <span id="prevTitle">-</span></p>
                                        <p><strong>Type:</strong> <span id="prevType">-</span> / <span id="prevSub">-</span></p>
                                        <p><strong>Description:</strong> <span id="prevDesc">-</span></p>
                                        <p><strong>Priority:</strong> <span id="prevPriority">-</span> (Due <span id="prevDue">-</span>)</p>
                                        <p><strong>Device:</strong> <span id="prevDevice">-</span></p>
                                        <p><strong>Assigned To:</strong> <span id="prevAssign">-</span></p>
                                        <p><strong>Observables:</strong> <ul id="prevObservables"></ul></p>
                                    </div>
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" id="confirm" required>
                                        <label class="form-check-label" for="confirm">I confirm the details are accurate</label>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between mt-5">
                                    <button type="button" class="btn btn-outline-secondary px-4" id="prevBtn" style="display:none;">Previous</button>
                                    <button type="button" class="btn btn-primary px-4" id="nextBtn">Next</button>
                                    <button type="submit" class="btn btn-success px-5" id="submitBtn" style="display:none;">Submit Incident</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar collapse handler
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            if (sidebar) {
                new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'class') {
                            mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '78px' : '250px';
                        }
                    });
                }).observe(sidebar, { attributes: true });
            }
        });

        // Wizard logic
        const steps = document.querySelectorAll('.wizard-step');
        const nextBtn = document.getElementById('nextBtn');
        const prevBtn = document.getElementById('prevBtn');
        const submitBtn = document.getElementById('submitBtn');
        const progressBar = document.getElementById('progressBar');
        let currentStep = 1;
        let obsIndex = 1;

        const subcategories = {
            link_down: ['Outage', 'Performance Issue', 'Configuration Error', 'Fiber Cut', 'BGP Flap'],
            malware: ['Virus', 'Ransomware', 'Spyware', 'Trojan', 'Phishing', 'C&C Communication'],
            security_breach: ['Unauthorized Access', 'Data Exfiltration', 'Privilege Escalation'],
            other: ['Hardware Failure', 'Software Bug', 'User Error', 'DDoS Attack']
        };

        document.querySelectorAll('.type-card').forEach(card => {
            card.addEventListener('click', () => {
                document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                const type = card.dataset.type;
                card.querySelector('input[type=radio]').checked = true;
                const subSelect = document.getElementById('subcategorySelect');
                subSelect.innerHTML = '';
                if (subcategories[type]) {
                    subcategories[type].forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub.toLowerCase().replace(/ /g, '_');
                        opt.textContent = sub;
                        subSelect.appendChild(opt);
                    });
                    document.getElementById('subcategoryWrapper').style.display = 'block';
                } else {
                    document.getElementById('subcategoryWrapper').style.display = 'none';
                }
                updatePreview();
            });
        });

        document.querySelectorAll('.priority-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.priority-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                document.querySelector('input[name=priority]').value = btn.dataset.priority;
                const days = {low:14, medium:7, high:3, critical:1}[btn.dataset.priority];
                document.getElementById('duePreview').textContent = `Due in ${days} days`;
                updatePreview();
            });
        });

        document.getElementById('addObservable').addEventListener('click', () => {
            const container = document.getElementById('observablesContainer');
            const newField = document.createElement('div');
            newField.classList.add('observable-field');
            newField.innerHTML = `
                <div class="row">
                    <div class="col-md-3">
                        <select class="form-select" name="observables[${obsIndex}][type]">
                            <option value="ip">IP Address</option>
                            <option value="url">URL</option>
                            <option value="hash">File Hash</option>
                            <option value="file">File</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" class="form-control" name="observables[${obsIndex}][value]" placeholder="Value">
                    </div>
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="observables[${obsIndex}][description]" placeholder="Description">
                    </div>
                </div>
                <div class="mt-2">
                    <label>Tags:</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="observables[${obsIndex}][tags][]" value="malicious">
                        <label>Malicious</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="observables[${obsIndex}][tags][]" value="suspicious">
                        <label>Suspicious</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="observables[${obsIndex}][tags][]" value="c2">
                        <label>C&C</label>
                    </div>
                </div>
            `;
            container.appendChild(newField);
            obsIndex++;
        });

        nextBtn.addEventListener('click', () => {
            if (validateStep(currentStep) && currentStep < 5) {
                steps[currentStep-1].classList.remove('active');
                currentStep++;
                steps[currentStep-1].classList.add('active');
                updateButtons();
                updateProgress();
                updatePreview();
            }
        });

        prevBtn.addEventListener('click', () => {
            if (currentStep > 1) {
                steps[currentStep-1].classList.remove('active');
                currentStep--;
                steps[currentStep-1].classList.add('active');
                updateButtons();
                updateProgress();
            }
        });

        submitBtn.addEventListener('click', (e) => {
            if (!validateAllSteps()) {
                e.preventDefault();
                alert('Please fill all required fields correctly.');
            }
        });

        function updateButtons() {
            prevBtn.style.display = currentStep === 1 ? 'none' : 'block';
            nextBtn.style.display = currentStep === 5 ? 'none' : 'block';
            submitBtn.style.display = currentStep === 5 ? 'block' : 'none';
        }

        function updateProgress() {
            progressBar.style.width = (currentStep * 20) + '%';
        }

        function updatePreview() {
            if (currentStep !== 5) return;
            document.getElementById('prevTitle').textContent = document.querySelector('input[name=title]').value || '-';
            const typeRadio = document.querySelector('input[name=type]:checked');
            document.getElementById('prevType').textContent = typeRadio ? typeRadio.closest('.type-card').querySelector('h5').textContent : '-';
            document.getElementById('prevSub').textContent = document.getElementById('subcategorySelect').value ? document.getElementById('subcategorySelect').selectedOptions[0].textContent : 'N/A';
            document.getElementById('prevDesc').textContent = (document.querySelector('textarea[name=description]').value || '').slice(0,150) + (document.querySelector('textarea[name=description]').value.length > 150 ? '...' : '');
            document.getElementById('prevPriority').textContent = document.querySelector('input[name=priority]').value.charAt(0).toUpperCase() + document.querySelector('input[name=priority]').value.slice(1);
            document.getElementById('prevDue').textContent = document.getElementById('duePreview').textContent;
            const deviceSelect = document.querySelector('select[name=device_id]');
            document.getElementById('prevDevice').textContent = deviceSelect.selectedOptions[0].textContent === 'None' ? 'None' : deviceSelect.selectedOptions[0].textContent;
            const assignSelect = document.querySelector('select[name=assigned_to]');
            document.getElementById('prevAssign').textContent = assignSelect.selectedOptions[0].textContent;
            const obsList = document.getElementById('prevObservables');
            obsList.innerHTML = '';
            document.querySelectorAll('.observable-field').forEach(field => {
                const type = field.querySelector('select').value.toUpperCase();
                const value = field.querySelector('input[name*="[value]"]').value;
                if (value) {
                    const li = document.createElement('li');
                    li.textContent = `${type}: ${value}`;
                    obsList.appendChild(li);
                }
            });
        }

        function validateStep(step) {
            let valid = true;
            const stepElem = document.querySelector(`.wizard-step[data-step="${step}"]`);
            stepElem.querySelectorAll('input[required], textarea[required]').forEach(field => {
                if (!field.checkValidity()) {
                    valid = false;
                    field.classList.add('is-invalid');
                    alert(`Please fill ${field.name} correctly.`);
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            return valid;
        }

        function validateAllSteps() {
            for (let i = 1; i < 5; i++) if (!validateStep(i)) return false;
            if (!document.getElementById('confirm').checked) {
                alert('Please confirm the details.');
                return false;
            }
            return true;
        }

        updateButtons();
        updateProgress();
        document.querySelector('.type-card.selected').dispatchEvent(new Event('click'));
    </script>
</body>
</html>
