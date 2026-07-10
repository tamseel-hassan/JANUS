<?php
// includes/db.php - Secure PDO with error handling
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: /index.html');
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=alogin;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Check credentials.');
}

// Helper: safely execute query with parameters
function db_query(string $sql, array $params = []) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(string $sql, array $params = []) {
    return db_query($sql, $params)->fetch();
}

function db_fetch_all(string $sql, array $params = []) {
    return db_query($sql, $params)->fetchAll();
}
