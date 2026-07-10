<?php
// check_session.php - Verifies user session
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== TRUE) {
    // Not logged in, redirect to login page
    header('Location: index.html');
    exit();
}
?>
