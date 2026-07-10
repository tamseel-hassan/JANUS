<?php
// db_connect.php
require_once __DIR__ . '/db_config.php';
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}
?>
