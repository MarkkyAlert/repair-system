<?php $appName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<?php $brandLogoUrl = branding_logo_url(); ?>
<!DOCTYPE html>
<html lang="th" class="h-full antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#6366f1">
    <title><?= e($title ?? $appName) ?></title>
    <?= render_partial('partials/theme-init') ?>
    <?php // ไม่ใส่ preconnect ของฟอนต์: CSP connect-src เป็น 'self' อยู่แล้ว มันเลยโดนบล็อก ได้แต่ทำให้ console รกเปล่า ๆ. stylesheet ด้านล่างโหลดผ่าน style-src/font-src. ?>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <!-- สีของแบรนด์ — อยากเปลี่ยนแบรนด์แก้ที่ public/assets/css/theme.css ได้เลย ไม่ต้อง build. โหลดเป็นไฟล์สุดท้ายจะได้ทับค่าอื่นได้. -->
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
    <?php if (is_path('/dashboard') || is_path('/reports/trend')): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <?php endif; ?>
</head>
<body class="min-h-screen">
<a href="#main-content" class="skip-link">ข้ามไปยังเนื้อหาหลัก</a>
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
                <?php $brandSubtitle = trim((string) setting('app_tagline', 'Maintenance Operations')); ?>
                <?php if ($brandSubtitle !== ''): ?>
                    <p class="brand-subtitle"><?= e($brandSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <button type="button" class="sidebar-collapse-button" data-sidebar-collapse aria-label="ย่อเมนูด้านข้าง" aria-controls="app-sidebar" aria-expanded="true"><?= lucide('chevrons-left', 'h-5 w-5') ?></button>
        </div>

        <nav class="sidebar-nav">
            <p class="nav-section-label">ภาพรวม</p>
            <a href="<?= e(url('/dashboard')) ?>" class="nav-link<?= $c = is_path('/dashboard') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="แดชบอร์ด">
                <?= lucide('layout-dashboard', 'nav-icon') ?>
                <span class="nav-label">แดชบอร์ด</span>
            </a>
            <p class="nav-section-label">งานปฏิบัติการ</p>
            <a href="<?= e(url('/tickets')) ?>" class="nav-link<?= $c = $isTicketsPath && !is_path('/tickets/create') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="รายการแจ้งซ่อม">
                <?= lucide('clipboard-list', 'nav-icon') ?>
                <span class="nav-label">รายการแจ้งซ่อม</span>
            </a>
            <a href="<?= e(url('/tickets/create')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/tickets/create') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="แจ้งซ่อมใหม่">
                <?= lucide('plus-circle', 'nav-icon') ?>
                <span class="nav-label">แจ้งซ่อมใหม่</span>
            </a>
            <?php if (is_manager_or_admin((string) ($viewer['role'] ?? 'guest'))): ?>
                <a href="<?= e(url('/asset-registry')) ?>" class="nav-link<?= $c = $isAssetsPath ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="ทรัพย์สินและ QR">
                    <?= lucide('qr-code', 'nav-icon') ?>
                    <span class="nav-label">ทรัพย์สินและ QR</span>
                </a>
                <p class="nav-section-label">ภาพรวม & ผู้บริหาร</p>
                <a href="<?= e(url('/reports/guide')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/guide') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="คู่มืออ่านรายงาน · เริ่มที่นี่">
                    <?= lucide('file-text', 'nav-icon') ?>
                    <span class="nav-label">คู่มืออ่านรายงาน</span>
                </a>
                <a href="<?= e(url('/reports')) ?>" class="nav-link nav-link-sub<?= $c = $isReportsPath && !is_path('/reports/guide') && !is_path('/reports/asset-reliability') && !is_path('/reports/sla-breach') && !is_path('/reports/technician-performance') && !is_path('/reports/problem-hotspot') && !is_path('/reports/trend') && !is_path('/reports/executive') && !is_path('/reports/backlog-aging') && !is_path('/reports/reopen-rate') && !is_path('/reports/csat') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="รายงานรวม">
                    <?= lucide('bar-chart-3', 'nav-icon') ?>
                    <span class="nav-label">รายงานรวม</span>
                </a>
                <a href="<?= e(url('/reports/executive')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/executive') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="สรุปผู้บริหาร">
                    <?= lucide('star', 'nav-icon') ?>
                    <span class="nav-label">สรุปผู้บริหาร</span>
                </a>
                <a href="<?= e(url('/reports/trend')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/trend') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="แนวโน้มตามเวลา">
                    <?= lucide('trending-up', 'nav-icon') ?>
                    <span class="nav-label">แนวโน้ม</span>
                </a>
                <p class="nav-section-label">วิเคราะห์ปัญหา</p>
                <a href="<?= e(url('/reports/sla-breach')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/sla-breach') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="วิเคราะห์ SLA เกินกำหนด">
                    <?= lucide('triangle-alert', 'nav-icon') ?>
                    <span class="nav-label">วิเคราะห์ SLA เกิน</span>
                </a>
                <a href="<?= e(url('/reports/problem-hotspot')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/problem-hotspot') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="พื้นที่ปัญหา">
                    <?= lucide('map-pin', 'nav-icon') ?>
                    <span class="nav-label">พื้นที่ปัญหา</span>
                </a>
                <a href="<?= e(url('/reports/backlog-aging')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/backlog-aging') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="งานค้างตามอายุ">
                    <?= lucide('clock', 'nav-icon') ?>
                    <span class="nav-label">งานค้างตามอายุ</span>
                </a>
                <p class="nav-section-label">คุณภาพ & ทีม</p>
                <a href="<?= e(url('/reports/technician-performance')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/technician-performance') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="ผลงานทีมช่าง">
                    <?= lucide('users', 'nav-icon') ?>
                    <span class="nav-label">ผลงานทีมช่าง</span>
                </a>
                <a href="<?= e(url('/reports/reopen-rate')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/reopen-rate') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="งานเปิดซ้ำ / First-Time-Fix">
                    <?= lucide('refresh-cw', 'nav-icon') ?>
                    <span class="nav-label">งานเปิดซ้ำ</span>
                </a>
                <a href="<?= e(url('/reports/csat')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/csat') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="ความพึงพอใจลูกค้า (CSAT)">
                    <?= lucide('message-circle', 'nav-icon') ?>
                    <span class="nav-label">ความพึงพอใจ</span>
                </a>
                <a href="<?= e(url('/reports/asset-reliability')) ?>" class="nav-link nav-link-sub<?= $c = is_path('/reports/asset-reliability') ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="สุขภาพทรัพย์สิน">
                    <?= lucide('activity', 'nav-icon') ?>
                    <span class="nav-label">สุขภาพทรัพย์สิน</span>
                </a>
            <?php endif; ?>
            <?php if ((string) ($viewer['role'] ?? 'guest') === 'admin'): ?>
                <p class="nav-section-label">ระบบ</p>
                <a href="<?= e(url('/admin')) ?>" class="nav-link<?= $c = $isAdminPath ? ' is-active' : '' ?>"<?= $c ? ' aria-current="page"' : '' ?> data-tooltip="ตั้งค่าระบบ">
                    <?= lucide('settings', 'nav-icon') ?>
                    <span class="nav-label">ตั้งค่าระบบ</span>
                </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <span class="system-status-dot"></span>
            <div class="brand-copy"><strong>ระบบพร้อมใช้งาน</strong><span><?= e(setting('default_timezone', config('app.timezone', 'Asia/Bangkok'))) ?></span></div>
        </div>
    </aside>
    <button type="button" class="sidebar-overlay" data-sidebar-overlay hidden aria-label="ปิดเมนู"></button>

    <div class="main-shell">
        <header class="topbar no-print">
            <div class="topbar-left">
                <button type="button" class="icon-button mobile-only" data-sidebar-toggle aria-label="เปิด/ปิดเมนู" aria-controls="app-sidebar" aria-expanded="false">
                    <?= lucide('menu', 'h-5 w-5') ?>
                </button>
                <div>
                    <p class="page-kicker">ศูนย์ควบคุมงานซ่อมบำรุง</p>
                    <p class="page-title" aria-hidden="true"><?= e($pageHeading ?? $title ?? $appName) ?></p>
                </div>
            </div>
            <div class="topbar-actions">
                <?= render_partial('partials/components/notification-bell') ?>
                <button type="button" class="icon-button" data-theme-toggle aria-label="สลับโหมดสว่าง/มืด">
                    <span class="theme-icon theme-icon-light"><?= lucide('sun', 'h-5 w-5') ?></span>
                    <span class="theme-icon theme-icon-dark"><?= lucide('moon', 'h-5 w-5') ?></span>
                </button>
                <a href="<?= e(url('/profile')) ?>" class="user-chip" aria-label="ข้อมูลบัญชีของฉัน">
                    <span class="user-chip-avatar"><?= e($viewerInitials) ?></span>
                    <div>
                        <p class="user-chip-name"><?= e($viewer['full_name'] ?? 'User') ?></p>
                        <p class="user-chip-role"><?= e(role_label_th((string) ($viewer['role'] ?? 'guest'))) ?></p>
                    </div>
                </a>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="icon-button" aria-label="ออกจากระบบ"><?= lucide('log-out', 'h-5 w-5') ?></button>
                </form>
            </div>
        </header>

        <main class="content-area" id="main-content" tabindex="-1">
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
