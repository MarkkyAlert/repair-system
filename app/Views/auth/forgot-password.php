<section class="guest-panel">
    <div class="guest-brand">
        <div class="brand-mark" aria-hidden="true"><?= lucide("wrench", "brand-icon") ?></div>
        <div>
            <p class="brand-title"><?= e((string) setting('app_name', config('app.name', 'Repair System'))) ?></p>
            <p class="brand-subtitle">Forgot Password</p>
        </div>
    </div>

    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">Password Reset</span>
            <h1 class="hero-title">ลืมรหัสผ่าน</h1>
            <p class="hero-text">กรอกอีเมลที่ผูกกับบัญชี ระบบจะส่งขั้นตอนตั้งรหัสผ่านใหม่ให้คุณอย่างปลอดภัย</p>
        </div>

        <form class="auth-card" method="post" action="<?= e(url('/forgot-password')) ?>">
            <?= csrf_field() ?>
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
