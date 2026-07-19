<?php
// layout ของหน้า error (404/403/500) ที่ไม่แตะ DB เลย ต่างจาก guest layout ตรงที่ไม่อ่าน system setting
// เพราะค่าพวกนั้นเก็บใน DB — ตอน DB ล่ม ตัวจับ error 500 ต้องยังเรนเดอร์หน้าเต็ม ๆ ที่จัดสไตล์ครบได้อยู่ มันจึงอ่านแค่
// ค่า default จาก config, stylesheet แบบ static และ session เท่านั้น ทั้ง stylesheet และฟอนต์เป็น static asset ที่ web
// server ส่งได้เองแม้ตอน DB ล่ม
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
    <?php // ไม่ใส่ preconnect เพราะ CSP connect-src เป็น 'self' อยู่แล้ว มันจะโดนบล็อกและทำให้ console รกเปล่า ๆ ส่วน stylesheet ด้านล่างโหลดผ่าน style-src/font-src ?>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <!-- สีแบรนด์ — แก้ที่ public/assets/css/theme.css เพื่อเปลี่ยนแบรนด์ได้โดยไม่ต้อง build โหลดไว้ท้ายสุดเพื่อให้ทับค่าอื่นได้ -->
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
</head>
<body class="guest-body">
    <main class="guest-shell">
        <?= $content ?>
    </main>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
