<?php $appName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<?php $brandLogoUrl = branding_logo_url(); ?>
<!DOCTYPE html>
<html lang="th" class="h-full antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#6366f1">
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
    <?php if (is_path('/dashboard')): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <?php endif; ?>
</head>
<body class="min-h-screen">
<?php $viewer = auth()->user(); ?>
<?php $currentPath = request_path(); ?>
<?php $isTicketsPath = $currentPath === '/tickets' || str_starts_with($currentPath, '/tickets/'); ?>
<?php $isAssetsPath = $currentPath === '/asset-registry' || str_starts_with($currentPath, '/asset-registry/') || str_starts_with($currentPath, '/scan/'); ?>
<?php $isReportsPath = $currentPath === '/reports' || str_starts_with($currentPath, '/reports/'); ?>
<?php $isAdminPath = $currentPath === '/admin' || str_starts_with($currentPath, '/admin/'); ?>
<?php $viewerName = trim((string) ($viewer['full_name'] ?? 'User')); ?>
<?php $viewerParts = preg_split('/\s+/u', $viewerName, -1, PREG_SPLIT_NO_EMPTY) ?: []; ?>
<?php $viewerInitials = ''; ?>
<?php foreach (array_slice($viewerParts, 0, 2) as $part): ?>
    <?php $viewerInitials .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1); ?>
<?php endforeach; ?>
<?php $viewerInitials = $viewerInitials !== '' ? strtoupper($viewerInitials) : 'US'; ?>
<?php $successMessage = flash_message('success'); ?>
<?php $errorMessage = flash_message('error'); ?>
<div class="app-shell">
    <aside class="sidebar" id="app-sidebar">
        <div class="brand-block">
            <?php if ($brandLogoUrl !== null): ?>
                <div class="brand-mark brand-mark-logo" aria-hidden="true"><img src="<?= e($brandLogoUrl) ?>" alt="<?= e($appName) ?>"></div>
            <?php else: ?>
                <div class="brand-mark" aria-hidden="true"><?= lucide('wrench', 'brand-icon') ?></div>
            <?php endif; ?>
            <div class="brand-copy">
                <p class="brand-title"><?= e($appName) ?></p>
                <p class="brand-subtitle">Maintenance Operations</p>
            </div>
            <button type="button" class="sidebar-collapse-button" data-sidebar-collapse aria-label="ย่อเมนูด้านข้าง" aria-controls="app-sidebar" aria-expanded="true"><?= lucide('chevrons-left', 'h-5 w-5') ?></button>
        </div>

        <nav class="sidebar-nav">
            <p class="nav-section-label">ภาพรวม</p>
            <a href="<?= e(url('/dashboard')) ?>" class="nav-link<?= is_path('/dashboard') ? ' is-active' : '' ?>" data-tooltip="Dashboard">
                <?= lucide('layout-dashboard', 'nav-icon') ?>
                <span class="nav-label">Dashboard</span>
            </a>
            <p class="nav-section-label">งานปฏิบัติการ</p>
            <a href="<?= e(url('/tickets')) ?>" class="nav-link<?= $isTicketsPath && !is_path('/tickets/create') ? ' is-active' : '' ?>" data-tooltip="รายการแจ้งซ่อม">
                <?= lucide('clipboard-list', 'nav-icon') ?>
                <span class="nav-label">รายการแจ้งซ่อม</span>
            </a>
            <a href="<?= e(url('/tickets/create')) ?>" class="nav-link nav-link-sub<?= is_path('/tickets/create') ? ' is-active' : '' ?>" data-tooltip="แจ้งซ่อมใหม่">
                <?= lucide('plus-circle', 'nav-icon') ?>
                <span class="nav-label">แจ้งซ่อมใหม่</span>
            </a>
            <a href="<?= e(url('/asset-registry')) ?>" class="nav-link<?= $isAssetsPath ? ' is-active' : '' ?>" data-tooltip="ทรัพย์สินและ QR">
                <?= lucide('qr-code', 'nav-icon') ?>
                <span class="nav-label">ทรัพย์สินและ QR</span>
            </a>
            <?php if (in_array((string) ($viewer['role'] ?? 'guest'), ['manager', 'admin'], true)): ?>
                <a href="<?= e(url('/reports')) ?>" class="nav-link<?= $isReportsPath ? ' is-active' : '' ?>" data-tooltip="รายงาน">
                    <?= lucide('bar-chart-3', 'nav-icon') ?>
                    <span class="nav-label">รายงานและวิเคราะห์</span>
                </a>
            <?php endif; ?>
            <?php if ((string) ($viewer['role'] ?? 'guest') === 'admin'): ?>
                <p class="nav-section-label">ระบบ</p>
                <a href="<?= e(url('/admin')) ?>" class="nav-link<?= $isAdminPath ? ' is-active' : '' ?>" data-tooltip="ตั้งค่าระบบ">
                    <?= lucide('settings', 'nav-icon') ?>
                    <span class="nav-label">ตั้งค่าระบบ</span>
                </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <span class="system-status-dot"></span>
            <div class="brand-copy"><strong>ระบบพร้อมใช้งาน</strong><span>Asia/Bangkok</span></div>
        </div>
    </aside>
    <button type="button" class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close navigation"></button>

    <div class="main-shell">
        <header class="topbar no-print">
            <div class="topbar-left">
                <button type="button" class="icon-button mobile-only" data-sidebar-toggle aria-label="Toggle navigation" aria-controls="app-sidebar" aria-expanded="false">
                    <?= lucide('menu', 'h-5 w-5') ?>
                </button>
                <div>
                    <p class="page-kicker">ศูนย์ควบคุมงานซ่อมบำรุง</p>
                    <p class="page-title" aria-hidden="true"><?= e($pageHeading ?? $title ?? $appName) ?></p>
                </div>
            </div>
            <div class="topbar-actions">
                <?= render_partial('partials/components/notification-bell') ?>
                <button type="button" class="icon-button" data-theme-toggle aria-label="Toggle dark mode">
                    <span class="theme-icon theme-icon-light"><?= lucide('sun', 'h-5 w-5') ?></span>
                    <span class="theme-icon theme-icon-dark"><?= lucide('moon', 'h-5 w-5') ?></span>
                </button>
                <a href="<?= e(url('/profile')) ?>" class="user-chip" aria-label="ข้อมูลบัญชีของฉัน">
                    <span class="user-chip-avatar"><?= e($viewerInitials) ?></span>
                    <div>
                        <p class="user-chip-name"><?= e($viewer['full_name'] ?? 'User') ?></p>
                        <p class="user-chip-role"><?= e($viewer['role'] ?? 'guest') ?></p>
                    </div>
                </a>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="icon-button" aria-label="ออกจากระบบ"><?= lucide('log-out', 'h-5 w-5') ?></button>
                </form>
            </div>
        </header>

        <main class="content-area">
            <?php if ($successMessage || $errorMessage): ?>
                <div class="toast-stack" aria-live="polite" aria-atomic="true">
                    <?php if ($successMessage): ?>
                        <?= render_partial('partials/components/toast', ['tone' => 'success', 'message' => (string) $successMessage]) ?>
                    <?php endif; ?>
                    <?php if ($errorMessage): ?>
                        <?= render_partial('partials/components/toast', ['tone' => 'danger', 'message' => (string) $errorMessage]) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
