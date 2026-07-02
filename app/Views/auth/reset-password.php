<?php $authLogoUrl = branding_logo_url(); ?>
<?php $authAppName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<section class="guest-panel">
    <div class="guest-brand">
        <?php if ($authLogoUrl !== null): ?>
            <div class="brand-mark brand-mark-logo" aria-hidden="true"><img src="<?= e($authLogoUrl) ?>" alt="<?= e($authAppName) ?>" style="max-width:100%;max-height:100%;object-fit:contain;"></div>
        <?php else: ?>
            <div class="brand-mark" aria-hidden="true"><?= lucide("wrench", "brand-icon") ?></div>
        <?php endif; ?>
        <div>
            <p class="brand-title"><?= e($authAppName) ?></p>
            <p class="brand-subtitle">รีเซ็ตรหัสผ่าน</p>
        </div>
    </div>

    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">รีเซ็ตอย่างปลอดภัย</span>
            <h1 class="hero-title">ตั้งรหัสผ่านใหม่</h1>
            <p class="hero-text">กำหนดรหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร ระบบจะลบโทเค็นรีเซ็ตทิ้งทันทีหลังตั้งค่ารหัสผ่านสำเร็จ</p>
        </div>

        <form class="auth-card" method="post" action="<?= e(url('/reset-password')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($email ?? '') ?>">
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
            <?php if (!empty($errorMessage)): ?>
                <div class="stack-md">
                    <span class="badge badge-danger">ข้อผิดพลาด</span>
                    <p class="helper-text"><?= e((string) $errorMessage) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <div class="stack-md">
                    <span class="badge badge-success">สำเร็จ</span>
                    <p class="helper-text"><?= e((string) $successMessage) ?></p>
                </div>
            <?php endif; ?>
            <div class="field-group">
                <label for="reset-email" class="field-label">อีเมล</label>
                <input id="reset-email" type="email" class="input" value="<?= e($email ?? '') ?>" disabled>
            </div>
            <div class="field-group">
                <label for="password" class="field-label">รหัสผ่านใหม่</label>
                <input id="password" name="password" type="password" class="input" placeholder="อย่างน้อย 8 ตัวอักษร">
            </div>
            <div class="field-group">
                <label for="password_confirmation" class="field-label">ยืนยันรหัสผ่านใหม่</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="input" placeholder="ยืนยันรหัสผ่านใหม่">
            </div>
            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'บันทึกรหัสผ่านใหม่',
                'variant' => 'primary',
                'fullWidth' => true,
            ]) ?>
            <div class="button-row">
                <?= render_partial('partials/components/button', [
                    'label' => 'กลับหน้าเข้าสู่ระบบ',
                    'variant' => 'secondary',
                    'href' => '/login',
                ]) ?>
            </div>
        </form>
    </div>
</section>
