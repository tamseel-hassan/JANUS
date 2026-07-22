<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
session_write_close();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/db_config.php';

// Fetch user details
$stmt = $con->prepare('SELECT password, email, username FROM accounts WHERE id = ?');
$stmt->bind_param('i', $_SESSION['id']);
$stmt->execute();
$stmt->bind_result($password, $email, $username);
$stmt->fetch();
$stmt->close();

// Handle password/email change
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = '<div class="alert alert-danger">Security token validation failed!</div>';
    } else {
        if (isset($_POST['change_password'])) {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate inputs
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $message = '<div class="alert alert-danger">All fields are required.</div>';
            } elseif ($new_password !== $confirm_password) {
                $message = '<div class="alert alert-danger">New passwords do not match.</div>';
            } elseif (strlen($new_password) < 8) {
                $message = '<div class="alert alert-danger">New password must be at least 8 characters long.</div>';
            } else {
                // Verify current password
                if (password_verify($current_password, $password)) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $con->prepare('UPDATE accounts SET password = ? WHERE id = ?');
                    $stmt->bind_param('si', $hashed_password, $_SESSION['id']);
                    
                    if ($stmt->execute()) {
                        // Update the password variable for display
                        $password = $hashed_password;
                        $message = '<div class="alert alert-success">Password changed successfully!</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Failed to update password: ' . $stmt->error . '</div>';
                    }
                    $stmt->close();
                } else {
                    $message = '<div class="alert alert-danger">Current password is incorrect.</div>';
                }
            }
        } elseif (isset($_POST['change_email'])) {
            $new_email = trim($_POST['new_email'] ?? '');
            
            if (empty($new_email)) {
                $message = '<div class="alert alert-danger">Email cannot be empty.</div>';
            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $message = '<div class="alert alert-danger">Invalid email format.</div>';
            } else {
                $stmt = $con->prepare('UPDATE accounts SET email = ? WHERE id = ?');
                $stmt->bind_param('si', $new_email, $_SESSION['id']);
                
                if ($stmt->execute()) {
                    $email = $new_email; // Update the variable for display
                    $message = '<div class="alert alert-success">Email updated successfully!</div>';
                } else {
                    $message = '<div class="alert alert-danger">Failed to update email: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Regenerate CSRF token after form processing
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Get user role from session
$user_role = $_SESSION['role'] ?? 'analyst';

// Get theme from cookie
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Profile - Janus</title>
    <!-- Local Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- Local Font Awesome -->
    <link href="css/fontawesome.min.css" rel="stylesheet">
    <!-- Local Theme CSS -->
    <link href="css/theme.css" rel="stylesheet">
    <link rel="stylesheet" href="css/pages/profile.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container">
            <a class="navbar-brand" href="home.php">
                <i data-lucide="home" class="icon-lucide me-2"></i>JanusNMS
            </a>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-3 d-flex align-items-center">
                    <i data-lucide="user" class="icon-lucide -tag me-2"></i>
                    <?= htmlspecialchars(ucfirst($user_role)) ?>
                </span>
                <a href="home.php" class="btn btn-outline-light me-2">
                    <i data-lucide="arrow-left" class="icon-lucide me-1"></i>Back to Home
                </a>
                <a href="logout.php" class="btn btn-danger">
                    <i data-lucide="sign-out-alt" class="icon-lucide me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h2 class="mb-0"><i data-lucide="circle-user" class="icon-lucide me-2"></i>User Profile</h2>
                    </div>
                    <div class="card-body">
                        <!-- Toast Container -->
                        <div id="toastContainer" class="toast-container position-fixed top-0 end-0 p-3">
                            <?php if ($message): ?>
                                <div class="toast show align-items-center text-white border-0 <?php echo strpos($message, 'success') ? 'bg-success' : 'bg-danger'; ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
                                    <div class="d-flex">
                                        <div class="toast-body">
                                            <?php echo strpos($message, 'success') ? '<i data-lucide="check-circle" class="icon-lucide me-2"></i>' : '<i data-lucide="alert-circle" class="icon-lucide me-2"></i>'; ?>
                                            <?php echo strip_tags($message); ?>
                                        </div>
                                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Account Details -->
                        <div class="mb-4">
                            <h5><i data-lucide="id-card" class="icon-lucide me-2"></i>Account Details</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 30%;">Username:</th>
                                    <td><?= htmlspecialchars($username) ?></td>
                                </tr>
                                <tr>
                                    <th>Role:</th>
                                    <td>
                                        <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : ($user_role === 'analyst' ? 'primary' : 'info') ?>">
                                            <?= htmlspecialchars(ucfirst($user_role)) ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?= htmlspecialchars($email ?? 'Not set') ?></td>
                                </tr>
                                <tr>
                                    <th>User ID:</th>
                                    <td>#<?= htmlspecialchars($_SESSION['id']) ?></td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Change Password Form -->
                        <div class="mb-4">
                            <h5><i data-lucide="key" class="icon-lucide me-2"></i>Change Password</h5>
                            <form method="post" id="passwordChangeForm" onsubmit="event.preventDefault(); confirmPasswordChange();">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="change_password" value="1">
                                
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('current_password', this)">
                                            <i data-lucide="eye" class="icon-lucide"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="new_password" name="new_password" required 
                                               oninput="checkPasswordStrength(this.value); validatePasswordRequirements();">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('new_password', this)">
                                            <i data-lucide="eye" class="icon-lucide"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength mt-2">
                                        <div id="password-strength-bar" class="password-strength-bar"></div>
                                    </div>
                                    <small id="password-strength-text" class="text-muted"></small>
                                    
                                    <div class="password-requirements mt-2">
                                        <div class="requirement invalid" id="req-length">
                                            <i data-lucide="x" class="icon-lucide -circle"></i>
                                            <span>At least 8 characters</span>
                                        </div>
                                        <div class="requirement invalid" id="req-uppercase">
                                            <i data-lucide="x" class="icon-lucide -circle"></i>
                                            <span>At least one uppercase letter</span>
                                        </div>
                                        <div class="requirement invalid" id="req-lowercase">
                                            <i data-lucide="x" class="icon-lucide -circle"></i>
                                            <span>At least one lowercase letter</span>
                                        </div>
                                        <div class="requirement invalid" id="req-number">
                                            <i data-lucide="x" class="icon-lucide -circle"></i>
                                            <span>At least one number</span>
                                        </div>
                                        <div class="requirement invalid" id="req-special">
                                            <i data-lucide="x" class="icon-lucide -circle"></i>
                                            <span>At least one special character</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirm_password', this)">
                                            <i data-lucide="eye" class="icon-lucide"></i>
                                        </button>
                                    </div>
                                    <div class="mt-1">
                                        <small id="password-match-text" class="text-muted"></small>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" id="passwordChangeButton">
                                    <span id="passwordChangeText"><i data-lucide="key" class="icon-lucide me-1"></i>Change Password</span>
                                    <span id="passwordChangeSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                            </form>
                        </div>
                        
                        <!-- Change Email Form -->
                        <div>
                            <h5><i data-lucide="envelope" class="icon-lucide me-2"></i>Change Email</h5>
                            <form method="post" id="emailChangeForm" onsubmit="event.preventDefault(); confirmEmailChange();">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="change_email" value="1">
                                
                                <div class="mb-3">
                                    <label for="new_email" class="form-label">New Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="new_email" name="new_email" 
                                           value="<?= htmlspecialchars($email ?? '') ?>" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" id="emailChangeButton">
                                    <span id="emailChangeText"><i data-lucide="envelope" class="icon-lucide me-1"></i>Update Email</span>
                                    <span id="emailChangeSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Confirmation Modals -->
    <div class="modal fade" id="confirmPasswordChangeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Password Change</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to change your password?
                    <div class="alert alert-warning mt-2">
                        <i data-lucide="triangle-alert" class="icon-lucide me-2"></i>
                        You will need to log in again with your new password.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmPasswordChangeBtn">Confirm Change</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="confirmEmailChangeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Email Change</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to change your email to <strong id="newEmailDisplay"></strong>?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmEmailChangeBtn">Confirm Change</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <!-- Local Bootstrap JS -->
    <script src="js/bootstrap.bundle.min.js"></script>
    <script>
        // Password Strength Meter
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('password-strength-text');
            let strength = 0;

            // Length check
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;
            
            // Complexity checks
            if (/\d/.test(password)) strength += 1;
            if (/[a-z]/.test(password)) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 1;

            let strengthPercentage = (strength / 6) * 100;
            let strengthLabel = '';
            let strengthColor = '';

            if (strengthPercentage <= 20) {
                strengthLabel = 'Very Weak';
                strengthColor = '#dc3545';
            } else if (strengthPercentage <= 40) {
                strengthLabel = 'Weak';
                strengthColor = '#fd7e14';
            } else if (strengthPercentage <= 60) {
                strengthLabel = 'Moderate';
                strengthColor = '#ffc107';
            } else if (strengthPercentage <= 80) {
                strengthLabel = 'Strong';
                strengthColor = '#28a745';
            } else {
                strengthLabel = 'Very Strong';
                strengthColor = '#20c997';
            }

            strengthBar.style.width = strengthPercentage + '%';
            strengthBar.style.backgroundColor = strengthColor;
            strengthText.textContent = 'Strength: ' + strengthLabel;
        }

        // Validate password requirements
        function validatePasswordRequirements() {
            const password = document.getElementById('new_password').value;
            
            // Length
            const lengthReq = document.getElementById('req-length');
            if (password.length >= 8) {
                lengthReq.classList.remove('invalid');
                lengthReq.classList.add('valid');
                lengthReq.querySelector('i').className = 'fas fa-check-circle';
            } else {
                lengthReq.classList.remove('valid');
                lengthReq.classList.add('invalid');
                lengthReq.querySelector('i').className = 'fas fa-times-circle';
            }
            
            // Uppercase
            const upperReq = document.getElementById('req-uppercase');
            if (/[A-Z]/.test(password)) {
                upperReq.classList.remove('invalid');
                upperReq.classList.add('valid');
                upperReq.querySelector('i').className = 'fas fa-check-circle';
            } else {
                upperReq.classList.remove('valid');
                upperReq.classList.add('invalid');
                upperReq.querySelector('i').className = 'fas fa-times-circle';
            }
            
            // Lowercase
            const lowerReq = document.getElementById('req-lowercase');
            if (/[a-z]/.test(password)) {
                lowerReq.classList.remove('invalid');
                lowerReq.classList.add('valid');
                lowerReq.querySelector('i').className = 'fas fa-check-circle';
            } else {
                lowerReq.classList.remove('valid');
                lowerReq.classList.add('invalid');
                lowerReq.querySelector('i').className = 'fas fa-times-circle';
            }
            
            // Number
            const numberReq = document.getElementById('req-number');
            if (/\d/.test(password)) {
                numberReq.classList.remove('invalid');
                numberReq.classList.add('valid');
                numberReq.querySelector('i').className = 'fas fa-check-circle';
            } else {
                numberReq.classList.remove('valid');
                numberReq.classList.add('invalid');
                numberReq.querySelector('i').className = 'fas fa-times-circle';
            }
            
            // Special character
            const specialReq = document.getElementById('req-special');
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                specialReq.classList.remove('invalid');
                specialReq.classList.add('valid');
                specialReq.querySelector('i').className = 'fas fa-check-circle';
            } else {
                specialReq.classList.remove('valid');
                specialReq.classList.add('invalid');
                specialReq.querySelector('i').className = 'fas fa-times-circle';
            }
        }

        // Check password match
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const matchText = document.getElementById('password-match-text');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
                matchText.className = 'text-muted';
            } else if (newPassword === confirmPassword) {
                matchText.textContent = '✓ Passwords match';
                matchText.className = 'text-success';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.className = 'text-danger';
            }
        });

        // Toggle password visibility
        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Password change confirmation
        function confirmPasswordChange() {
            const currentPass = document.getElementById('current_password').value;
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            // Basic validation
            if (!currentPass || !newPass || !confirmPass) {
                showToast('Please fill in all password fields', 'danger');
                return;
            }
            
            if (newPass !== confirmPass) {
                showToast('New passwords do not match', 'danger');
                return;
            }
            
            if (newPass.length < 8) {
                showToast('New password must be at least 8 characters', 'danger');
                return;
            }
            
            // Check password requirements
            const hasUpper = /[A-Z]/.test(newPass);
            const hasLower = /[a-z]/.test(newPass);
            const hasNumber = /\d/.test(newPass);
            const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(newPass);
            
            if (!hasUpper || !hasLower || !hasNumber || !hasSpecial) {
                showToast('Password must contain uppercase, lowercase, number, and special character', 'danger');
                return;
            }
            
            // Show confirmation modal
            const modal = new bootstrap.Modal(document.getElementById('confirmPasswordChangeModal'));
            modal.show();
            
            // Set up confirmation button
            document.getElementById('confirmPasswordChangeBtn').onclick = function() {
                document.getElementById('passwordChangeText').classList.add('visually-hidden');
                document.getElementById('passwordChangeSpinner').classList.remove('d-none');
                document.getElementById('passwordChangeButton').disabled = true;
                document.getElementById('passwordChangeForm').submit();
            };
        }

        // Email change confirmation
        function confirmEmailChange() {
            const newEmail = document.getElementById('new_email').value;
            
            if (!newEmail) {
                showToast('Please enter a new email address', 'danger');
                return;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(newEmail)) {
                showToast('Please enter a valid email address', 'danger');
                return;
            }
            
            // Show confirmation modal
            document.getElementById('newEmailDisplay').textContent = newEmail;
            const modal = new bootstrap.Modal(document.getElementById('confirmEmailChangeModal'));
            modal.show();
            
            // Set up confirmation button
            document.getElementById('confirmEmailChangeBtn').onclick = function() {
                document.getElementById('emailChangeText').classList.add('visually-hidden');
                document.getElementById('emailChangeSpinner').classList.remove('d-none');
                document.getElementById('emailChangeButton').disabled = true;
                document.getElementById('emailChangeForm').submit();
            };
        }

        // Show toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer');
            const toastId = 'toast-' + Date.now();
            
            const toastHTML = `
                <div id="${toastId}" class="toast show align-items-center text-white border-0 bg-${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
                    <div class="d-flex">
                        <div class="toast-body">
                            ${type === 'success' ? '<i data-lucide="check-circle" class="icon-lucide me-2"></i>' : 
                              type === 'danger' ? '<i data-lucide="alert-circle" class="icon-lucide me-2"></i>' : 
                              '<i data-lucide="info" class="icon-lucide me-2"></i>'}
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHTML);
            
            // Initialize Bootstrap toast
            const toastElement = new bootstrap.Toast(document.getElementById(toastId));
            toastElement.show();
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                const toastEl = document.getElementById(toastId);
                if (toastEl) {
                    toastEl.remove();
                }
            }, 5000);
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Show any existing toast
            var toastEl = document.querySelector('.toast.show');
            if (toastEl) {
                var toast = new bootstrap.Toast(toastEl);
                toast.show();
            }
            
            // Initialize password requirements display
            validatePasswordRequirements();
            
            // Clear password fields if page was refreshed
            if (performance.navigation.type === 1) {
                document.getElementById('current_password').value = '';
                document.getElementById('new_password').value = '';
                document.getElementById('confirm_password').value = '';
            }
        });
    </script>
</body>
</html>
