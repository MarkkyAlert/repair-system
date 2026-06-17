<?php $authLogoUrl = branding_logo_url(); ?>
<?php $authAppName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<section class="guest-panel auth-reset-panel">
    <div class="guest-brand">
        <?php if ($authLogoUrl !== null): ?>
            <div class="brand-mark brand-mark-logo" aria-hidden="true"><img src="<?= e($authLogoUrl) ?>" alt="<?= e($authAppName) ?>" style="max-width:100%;max-height:100%;object-fit:contain;"></div>
        <?php else: ?>
            <div class="brand-mark" aria-hidden="true"><?= lucide("wrench", "brand-icon") ?></div>
        <?php endif; ?>
        <div>
            <p class="brand-title"><?= e($authAppName) ?></p>
            <p class="brand-subtitle">Forgot Password</p>
        </div>
    </div>

    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">Password Reset</span>
            <h1 class="hero-title">ลืมรหัสผ่าน</h1>
            <p class="hero-text">กรอกอีเมลที่ผูกกับบัญชี ระบบจะส่งขั้นตอนตั้งรหัสผ่านใหม่ให้คุณอย่างปลอดภัย</p>
            <ul class="auth-step-list" aria-label="ขั้นตอนรีเซ็ตรหัสผ่าน">
                <li><span>1</span><strong>กรอกอีเมล</strong><small>ใช้บัญชีที่ลงทะเบียนไว้ในระบบ</small></li>
                <li><span>2</span><strong>เปิดลิงก์จากอีเมล</strong><small>ลิงก์มีอายุจำกัดและใช้ได้ครั้งเดียว</small></li>
                <li><span>3</span><strong>ตั้งรหัสผ่านใหม่</strong><small>กลับมาเข้าสู่ระบบด้วยรหัสใหม่</small></li>
            </ul>
        </div>

        <form class="auth-card" method="post" action="<?= e(url('/forgot-password')) ?>">
            <?= csrf_field() ?>
            <div class="auth-card-header">
                <p class="page-kicker">Password Reset</p>
                <h2 class="auth-card-title">รับลิงก์ตั้งรหัสผ่านใหม่</h2>
                <p class="helper-text">ใส่อีเมลของบัญชีผู้ใช้ ระบบจะส่งขั้นตอนถัดไปให้ หากพบอีเมลนี้ในระบบ</p>
            </div>
            <?php if (!empty($errorMessage)): ?>
                <div class="stack-md">
                    <span class="badge badge-danger">Error</span>
                    <p class="helper-text"><?= e((string) $errorMessage) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <div class="stack-md">
                    <span class="badge badge-success">Success</span>
                    <p class="helper-text"><?= e((string) $successMessage) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($debugResetLink)): ?>
                <div class="stack-md">
                    <span class="badge badge-info">Debug Reset Link</span>
                    <a class="btn btn-secondary" href="<?= e((string) $debugResetLink) ?>">เปิดลิงก์รีเซ็ตรหัสผ่าน</a>
                </div>
            <?php endif; ?>
            <div class="field-group">
                <label for="email" class="field-label">Email</label>
                <input id="email" name="email" type="email" class="input" placeholder="admin@example.com" value="<?= e((string) (($oldInput['email'] ?? ''))) ?>">
            </div>
            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'ส่งคำขอรีเซ็ตรหัสผ่าน',
                'variant' => 'primary',
                'fullWidth' => true,
            ]) ?>
            <p class="auth-footnote auth-footnote-center">
                จำรหัสผ่านได้แล้ว?
                <a href="<?= e(url('/login')) ?>">กลับไปเข้าสู่ระบบ</a>
            </p>
        </form>
    </div>
</section>
