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
            <p class="brand-subtitle">Maintenance Operations</p>
        </div>
    </div>

    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">Operations Center</span>
            <h1 class="hero-title">ทุกงานซ่อม<br>เห็นภาพเดียวกัน</h1>
            <p class="hero-text">รับแจ้ง ติดตาม SLA มอบหมายทีม และดูแลทรัพย์สินขององค์กรในระบบเดียว — เพื่อทีมที่อยากให้บริการเร็วและตรวจสอบได้</p>

            <ul class="hero-feature-list">
                <li>
                    <span class="hero-feature-icon"><?= lucide('zap', 'h-4 w-4') ?></span>
                    <div>
                        <strong>SLA อัตโนมัติ</strong>
                        <span>คำนวณเวลาตอบ/แก้ไขตาม priority</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon"><?= lucide('qr-code', 'h-4 w-4') ?></span>
                    <div>
                        <strong>QR Code ทุกอุปกรณ์</strong>
                        <span>สแกนแล้วแจ้งซ่อมได้ทันที</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon"><?= lucide('bar-chart-3', 'h-4 w-4') ?></span>
                    <div>
                        <strong>Dashboard เรียลไทม์</strong>
                        <span>ภาพรวมงาน + ทีม + แผนกครบ</span>
                    </div>
                </li>
            </ul>
        </div>

        <form class="auth-card" method="post" action="<?= e(url('/login')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo ?? '/dashboard') ?>">

            <div class="auth-card-header">
                <p class="page-kicker">เข้าสู่ระบบ</p>
                <h2 class="auth-card-title">ยินดีต้อนรับกลับ</h2>
                <p class="helper-text">เข้าสู่ระบบเพื่อจัดการงานซ่อมและทรัพย์สินของคุณ</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="auth-alert auth-alert-danger">
                    <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                    <p><?= e((string) $errorMessage) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <div class="auth-alert auth-alert-success">
                    <span class="auth-alert-icon"><?= lucide('check-circle', 'h-4 w-4') ?></span>
                    <p><?= e((string) $successMessage) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($debugResetLink)): ?>
                <div class="auth-alert auth-alert-info">
                    <span class="auth-alert-icon"><?= lucide('info', 'h-4 w-4') ?></span>
                    <div>
                        <strong>Debug Reset Link</strong>
                        <a href="<?= e((string) $debugResetLink) ?>">เปิดลิงก์รีเซ็ตรหัสผ่าน →</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field-group">
                <label for="login" class="field-label">Username หรือ Email</label>
                <input id="login" name="login" type="text" class="input" placeholder="admin หรือ admin@example.com" value="<?= e((string) (($oldInput['login'] ?? ''))) ?>" autocomplete="username">
            </div>
            <div class="field-group">
                <label for="password" class="field-label">Password</label>
                <input id="password" name="password" type="password" class="input" placeholder="••••••••" autocomplete="current-password">
            </div>

            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'เข้าสู่ระบบ',
                'variant' => 'primary',
                'icon' => 'arrow-right',
                'iconPosition' => 'right',
                'size' => 'lg',
                'fullWidth' => true,
            ]) ?>

            <div class="auth-divider"><span>หรือ</span></div>

            <a class="btn btn-secondary btn-md btn-block" href="<?= e(url('/forgot-password')) ?>">
                <?= lucide('key-round', 'button-icon') ?>
                <span>ลืมรหัสผ่าน</span>
            </a>

            <p class="auth-footnote">
                <?= lucide('shield-check', 'h-3.5 w-3.5') ?>
                ระบบภายในองค์กร · เข้ารหัสและตรวจสอบทุกการเข้าถึง
            </p>
        </form>
    </div>
</section>
