<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'จัดการระบบ',
        'title' => 'ตั้งค่าและดูแลระบบ',
        'description' => 'ตั้งค่า master data, ผู้ใช้, แผนก, หมวดหมู่ และ SLA',
        'actions' => render_partial('partials/components/button', [
            'label' => 'ส่งประกาศ',
            'variant' => 'secondary',
            'href' => '/admin/broadcast',
            'icon' => 'megaphone',
        ]) . render_partial('partials/components/button', [
            'label' => 'คำขอ Guest QR',
            'variant' => 'secondary',
            'href' => '/admin/guest-requests',
            'icon' => 'qr-code',
        ]) . '<span class="badge badge-warning">' . lucide('shield-check', 'h-4 w-4') . ' เฉพาะผู้ดูแลระบบ</span>',
    ]) ?>

    <div class="stat-grid admin-stat-scroll">
        <?= render_partial('partials/components/card', ['title' => 'ผู้ใช้งาน', 'value' => (string) count($users ?? []), 'meta' => 'บัญชีทั้งหมดในระบบ', 'tone' => 'default', 'icon' => 'users', 'href' => '/admin#tab-users', 'ariaLabel' => 'ไปที่แท็บผู้ใช้งาน']) ?>
        <?= render_partial('partials/components/card', ['title' => 'แผนก', 'value' => (string) count($departments ?? []), 'meta' => 'หน่วยงานในองค์กร', 'tone' => 'info', 'icon' => 'building', 'href' => '/admin#tab-departments', 'ariaLabel' => 'ไปที่แท็บแผนก']) ?>
        <?= render_partial('partials/components/card', ['title' => 'สถานที่', 'value' => (string) count($locations ?? []), 'meta' => 'จุดติดตั้ง/แจ้งซ่อม', 'tone' => 'info', 'icon' => 'map-pin', 'href' => '/admin#tab-locations', 'ariaLabel' => 'ไปที่แท็บสถานที่']) ?>
        <?= render_partial('partials/components/card', ['title' => 'ความสำคัญ/SLA', 'value' => (string) count($priorities ?? []), 'meta' => 'ระดับความเร่งด่วน', 'tone' => 'danger', 'icon' => 'clock', 'href' => '/admin#tab-priorities', 'ariaLabel' => 'ไปที่แท็บความสำคัญและ SLA']) ?>
        <?= render_partial('partials/components/card', ['title' => 'หมวดหมู่งาน', 'value' => (string) count($categories ?? []), 'meta' => 'ประเภทของ ticket', 'tone' => 'warning', 'icon' => 'tag', 'href' => '/admin#tab-categories', 'ariaLabel' => 'ไปที่แท็บหมวดหมู่งาน']) ?>
        <?= render_partial('partials/components/card', ['title' => 'หมวดหมู่ทรัพย์สิน', 'value' => (string) count($assetCategories ?? []), 'meta' => 'ประเภททรัพย์สิน', 'tone' => 'default', 'icon' => 'layers', 'href' => '/admin#tab-asset-categories', 'ariaLabel' => 'ไปที่แท็บหมวดหมู่ทรัพย์สิน']) ?>
        <?= render_partial('partials/components/card', ['title' => 'การตั้งค่า', 'value' => (string) count($settings ?? []), 'meta' => 'การตั้งค่าระบบ', 'tone' => 'success', 'icon' => 'settings', 'href' => '/admin#tab-settings', 'ariaLabel' => 'ไปที่แท็บการตั้งค่า']) ?>
        <?= render_partial('partials/components/card', ['title' => 'บันทึกการตรวจสอบ', 'value' => (string) (int) (($auditLogs['total'] ?? 0)), 'meta' => 'การดำเนินการของผู้ดูแล', 'tone' => 'info', 'icon' => 'file-text', 'href' => '/admin#tab-audit', 'ariaLabel' => 'ไปที่แท็บบันทึกการตรวจสอบ']) ?>
    </div>

    <div class="admin-tabs-scroller">
    <nav class="admin-tabs" role="tablist" aria-label="หมวดการตั้งค่า">
        <a href="#tab-users" class="admin-tab is-active" role="tab" aria-selected="true" aria-controls="tab-users"><?= lucide('users', 'h-4 w-4') ?><span>ผู้ใช้งาน</span></a>
        <a href="#tab-departments" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-departments"><?= lucide('building', 'h-4 w-4') ?><span>แผนก</span></a>
        <a href="#tab-locations" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-locations"><?= lucide('map-pin', 'h-4 w-4') ?><span>สถานที่</span></a>
        <a href="#tab-priorities" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-priorities"><?= lucide('clock', 'h-4 w-4') ?><span>ความสำคัญ/SLA</span></a>
        <a href="#tab-categories" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-categories"><?= lucide('tag', 'h-4 w-4') ?><span>หมวดหมู่งาน</span></a>
        <a href="#tab-asset-categories" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-asset-categories"><?= lucide('layers', 'h-4 w-4') ?><span>หมวดหมู่ทรัพย์สิน</span></a>
        <a href="#tab-roles" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-roles"><?= lucide('shield-check', 'h-4 w-4') ?><span>สิทธิ์ตามบทบาท</span></a>
        <a href="#tab-audit" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-audit"><?= lucide('file-text', 'h-4 w-4') ?><span>บันทึกการตรวจสอบ</span></a>
        <a href="#tab-security" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-security"><?= lucide('shield', 'h-4 w-4') ?><span>ความปลอดภัย</span><?php if ((int) ($loginAttemptStats['recent_failures'] ?? 0) > 0): ?> <span class="admin-tab-badge"><?= e((string) (int) $loginAttemptStats['recent_failures']) ?></span><?php endif; ?></a>
        <a href="#tab-email" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-email"><?= lucide('send', 'h-4 w-4') ?><span>อีเมล</span></a>
        <a href="#tab-settings" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-settings"><?= lucide('settings', 'h-4 w-4') ?><span>การตั้งค่า</span></a>
        <a href="#tab-backup" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-backup"><?= lucide('database', 'h-4 w-4') ?><span>สำรอง & กู้คืน</span></a>
    </nav>
    </div>

    <?php include __DIR__ . '/tabs/users.php'; ?>
    <?php include __DIR__ . '/tabs/departments.php'; ?>
    <?php include __DIR__ . '/tabs/locations.php'; ?>
    <?php include __DIR__ . '/tabs/priorities.php'; ?>
    <?php include __DIR__ . '/tabs/categories.php'; ?>
    <?php include __DIR__ . '/tabs/asset-categories.php'; ?>
    <?php include __DIR__ . '/tabs/roles.php'; ?>
    <?php include __DIR__ . '/tabs/audit.php'; ?>
    <?php include __DIR__ . '/tabs/security.php'; ?>
    <?php include __DIR__ . '/tabs/email.php'; ?>
    <?php include __DIR__ . '/tabs/settings.php'; ?>
    <?php include __DIR__ . '/tabs/backup.php'; ?>
</section>

<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
