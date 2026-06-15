<?php $appName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<?php $brandLogoUrl = branding_logo_url(); ?>
<!DOCTYPE html>
<html lang="th" class="h-full antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1c1554">
    <title><?= e($title ?? $appName) ?></title>
    <script>
        (() => {
            const storedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest-body">
    <main class="guest-shell">
        <?php if ($brandLogoUrl !== null): ?>
            <div class="guest-brand" style="display:flex;flex-direction:column;align-items:center;gap:8px;margin-bottom:24px">
                <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($appName) ?>" style="max-height:72px;max-width:240px;object-fit:contain;">
                <p style="margin:0;font-weight:700;letter-spacing:.4px;color:#1c1554"><?= e($appName) ?></p>
            </div>
        <?php endif; ?>
        <?= $content ?>
    </main>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
