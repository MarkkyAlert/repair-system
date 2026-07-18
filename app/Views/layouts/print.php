<?php $appName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<!DOCTYPE html>
<html lang="th" class="h-full bg-white text-slate-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? $appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <!-- Brand colours — edit public/assets/css/theme.css to rebrand (no build step). Loaded last so it wins. -->
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
</head>
<body class="print-body">
<?= $content ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
