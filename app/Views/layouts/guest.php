<?php $appName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<!DOCTYPE html>
<html lang="th" class="h-full antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1c1554">
    <meta name="referrer" content="no-referrer">
    <title><?= e($title ?? $appName) ?></title>
    <?= render_partial('partials/theme-init') ?>
    <?php // ไม่ใส่ preconnect: เพราะ CSP connect-src เป็น 'self' อยู่แล้ว จึงถูกบล็อกและมีแต่จะทำให้ console รก; stylesheet ด้านล่างโหลดผ่าน style-src/font-src. ?>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <!-- สีของแบรนด์ — แก้ที่ public/assets/css/theme.css เพื่อเปลี่ยนแบรนด์ (ไม่ต้อง build). โหลดเป็นลำดับสุดท้ายเพื่อให้ทับค่าอื่นได้. -->
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
</head>
<body class="guest-body">
    <main class="guest-shell">
        <?= $content ?>
    </main>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
