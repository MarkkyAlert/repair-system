<?php $appName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<!DOCTYPE html>
<html lang="th" class="h-full bg-white text-slate-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? $appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <!-- สีของแบรนด์ — แก้ที่ public/assets/css/theme.css เพื่อเปลี่ยนแบรนด์ (ไม่ต้อง build). โหลดเป็นลำดับสุดท้ายเพื่อให้ทับค่าอื่นได้. -->
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
</head>
<body class="print-body">
<?= $content ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
