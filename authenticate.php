<?php
session_start();
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/license_check.php';

// ── License gate — block login before any credential check ───────────────────
// If the POC license is expired or revoked, nobody can log in at all.
if (!janus_license_is_valid()) {
    session_destroy();
    header('Location: /license_expired.php');
    exit;
}

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$con) {
    die('Database connection failed: ' . mysqli_connect_error());
}


if (!isset($_POST['username'], $_POST['password'])) {
    exit('Please fill both the username and password fields!');
}

// Fetch id, password, AND role
if ($stmt = $con->prepare('SELECT id, password, role, name FROM accounts WHERE username = ?')) {
    $stmt->bind_param('s', $_POST['username']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $password, $role, $name);
        $stmt->fetch();

        if (password_verify($_POST['password'], $password)) {
            // Success!
            session_regenerate_id();
            $_SESSION['loggedin'] = TRUE;
            $_SESSION['name'] = $name;
            $_SESSION['id'] = $id;
            $_SESSION['role'] = $role ?? 'analyst';

            if (!isset($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }

            header('Location: home.php');
            exit;
        } else {
            echo 'Incorrect username and/or password!';
        }
    } else {
        echo 'Incorrect username and/or password!';
    }

    $stmt->close();
}
$con->close();
?>
