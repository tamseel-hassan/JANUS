<?php
require_once __DIR__ . '/../includes/db.php';

$page_title = $page_title ?? 'JanusDashboard';
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> - Janus</title>

    <!-- Bootstrap 5 CSS + JS Bundle (from CDN - fast & reliable) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Font Awesome 6 (icons for topbar & sidebar) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Your Custom Styles -->
    <link rel="stylesheet" href="/css/theme.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/css/topbar.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/css/sidebar.css?v=<?= time() ?>">

    <!-- Chart.js + Datalabels Plugin (for home.php charts) -->
    <script src="/assets/chart.min.js"></script>
    <script src="/js/chartjs-plugin-datalabels.min.js"></script>
</head>
<body class="loggedin">
