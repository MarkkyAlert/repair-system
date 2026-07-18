<?php
// DB-free layout for error pages (404/403/500). Unlike the guest layout it never reads a DB-backed system
// setting — during a database outage the 500 boundary must still render a full, styled page. It reads only
// config defaults, the (static) stylesheet, and the session; the stylesheet + fonts are static assets the web
// server serves even when the DB is down.
$appName = (string) config('app.name', 'Repair System');
?>
<!DOCTYPE html>
<html lang="th" class="h-full antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1c1554">
    <meta name="referrer" content="no-referrer">
    <title><?= e($title ?? $appName) ?></title>
    <?= render_partial('partials/theme-init') ?>
    <?php // No preconnect hints: the CSP connect-src is 'self' so they are blocked and only add console noise; the stylesheet below loads under style-src/font-src. ?>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <!-- Brand colours — edit public/assets/css/theme.css to rebrand (no build step). Loaded last so it wins. -->
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
</head>
<body class="guest-body">
    <main class="guest-shell">
        <?= $content ?>
    </main>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
