<?php
require_once __DIR__ . '/../db_config.php';
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['loggedin'])) {
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!isset($data['action'])) {
    echo json_encode(['error' => 'No action specified']);
    exit;
}

if ($data['action'] === 'change_password') {
    $current_password = $data['current_password'] ?? '';
    $new_password = $data['new_password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        echo json_encode(['error' => 'All fields are required.']);
        exit;
    }
    
    if ($new_password !== $confirm_password) {
        echo json_encode(['error' => 'New passwords do not match.']);
        exit;
    }
    
    if (strlen($new_password) < 8) {
        echo json_encode(['error' => 'New password must be at least 8 characters long.']);
        exit;
    }

    $stmt = $con->prepare('SELECT password FROM accounts WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['id']);
    $stmt->execute();
    $stmt->bind_result($password);
    $stmt->fetch();
    $stmt->close();

    if (password_verify($current_password, $password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $con->prepare('UPDATE accounts SET password = ? WHERE id = ?');
        $update->bind_param('si', $hashed_password, $_SESSION['id']);
        
        if ($update->execute()) {
            echo json_encode(['success' => 'Password changed successfully!']);
        } else {
            echo json_encode(['error' => 'Failed to update password: ' . $update->error]);
        }
        $update->close();
    } else {
        echo json_encode(['error' => 'Current password is incorrect.']);
    }
    exit;
}

if ($data['action'] === 'change_email') {
    $new_email = trim($data['new_email'] ?? '');
    
    if (empty($new_email)) {
        echo json_encode(['error' => 'Email cannot be empty.']);
        exit;
    }
    
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Invalid email format.']);
        exit;
    }
    
    $stmt = $con->prepare('UPDATE accounts SET email = ? WHERE id = ?');
    $stmt->bind_param('si', $new_email, $_SESSION['id']);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => 'Email updated successfully!']);
    } else {
        echo json_encode(['error' => 'Failed to update email: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

echo json_encode(['error' => 'Unknown action']);
