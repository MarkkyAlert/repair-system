<?php
$accountAgeDays = null;
if (!empty($profile['created_at'])) {
    $ts = strtotime((string) $profile['created_at']);
    if ($ts !== false) {
        $accountAgeDays = max(0, (int) floor((time() - $ts) / 86400));
    }
}
$lastLoginLabel = !empty($profile['last_login_at']) ? human_date((string) $profile['last_login_at']) : '—';
?>
<section class="stack-lg panel-narrow">
    <h1 class="sr-only">ข้อมูลบัญชีของฉัน — แก้ไขชื่อ อีเมล และเบอร์โทรศัพท์</h1>

    <section class="page-hero profile-hero">
        <div class="page-hero-copy">
            <p class="page-hero-eyebrow">บัญชีผู้ใช้งาน</p>
            <h2 class="page-hero-title">ข้อมูลบัญชีของฉัน</h2>
            <p class="page-hero-description">ข้อมูลนี้ใช้สำหรับติดต่อกลับและส่งการแจ้งเตือนของระบบ</p>
        </div>
        <div class="profile-hero-aside">
            <div class="profile-hero-stats" aria-label="ข้อมูลสรุปบัญชี">
                <div class="profile-hero-stat">
                    <span class="profile-hero-stat-value"><?= $accountAgeDays !== null ? e((string) $accountAgeDays) : '—' ?></span>
                    <span class="profile-hero-stat-label">วันที่ใช้งาน</span>
                </div>
                <div class="profile-hero-stat">
                    <span class="profile-hero-stat-value profile-hero-stat-value-sm"><?= e($lastLoginLabel) ?></span>
                    <span class="profile-hero-stat-label">เข้าระบบล่าสุด</span>
                </div>
            </div>
            <div class="page-hero-actions">
                <?= render_partial('partials/components/button', [
                    'label' => 'ตั้งค่าการแจ้งเตือน',
                    'variant' => 'secondary',
                    'href' => '/profile/notifications',
                    'icon' => 'bell',
                ]) ?>
            </div>
        </div>
    </section>

    <?php if (!empty($errorMessage)): ?>
        <div class="toast-stack" aria-live="polite">
            <?= render_partial('partials/components/toast', ['tone' => 'danger', 'message' => (string) $errorMessage]) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($successMessage)): ?>
        <div class="toast-stack" aria-live="polite">
            <?= render_partial('partials/components/toast', ['tone' => 'success', 'message' => (string) $successMessage]) ?>
        </div>
    <?php endif; ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">รายละเอียดบัญชี</h2>
                <p class="field-hint">ชื่อผู้ใช้และสิทธิ์ของคุณกำหนดโดยผู้ดูแลระบบ ไม่สามารถแก้ไขจากที่นี่ได้</p>
            </div>
            <span class="metric-icon metric-icon-md"><?= lucide('user', 'h-5 w-5') ?></span>
        </div>

        <dl class="description-list">
            <dt>ชื่อผู้ใช้</dt>
            <dd><code class="mono"><?= e($profile['username'] ?? '-') ?></code></dd>
            <dt>บทบาท</dt>
            <dd><?= e(role_label_th((string) ($profile['role'] ?? 'guest'))) ?></dd>
            <?php if (!empty($profile['created_at'])): ?>
                <dt>สมาชิกตั้งแต่</dt>
                <dd><?= e(human_date((string) $profile['created_at'], false)) ?></dd>
            <?php endif; ?>
            <?php if (!empty($profile['last_login_at'])): ?>
                <dt>เข้าระบบล่าสุด</dt>
                <dd><?= e(human_date((string) $profile['last_login_at'])) ?></dd>
            <?php endif; ?>
        </dl>

        <form method="post" action="<?= e(url('/profile')) ?>" class="stack-md" data-loading-submit data-warn-unsaved>
            <?= csrf_field() ?>
            <div class="field-group">
                <label for="full_name" class="field-label">ชื่อ-นามสกุล <span class="required" aria-hidden="true">*</span></label>
                <input id="full_name" name="full_name" type="text" class="input" required aria-required="true" autocomplete="name" maxlength="200" value="<?= e($profile['full_name'] ?? '') ?>">
            </div>
            <div class="content-grid">
                <div class="field-group">
                    <label for="email" class="field-label">อีเมล <span class="required" aria-hidden="true">*</span></label>
                    <input id="email" name="email" type="email" class="input" required aria-required="true" autocomplete="email" value="<?= e($profile['email'] ?? '') ?>" aria-describedby="email-hint">
                    <p id="email-hint" class="field-hint">เปลี่ยนอีเมลต้องยืนยันด้วยรหัสผ่านปัจจุบัน</p>
                </div>
                <div class="field-group">
                    <label for="phone" class="field-label">เบอร์โทรศัพท์ <span class="helper-text">(ไม่บังคับ)</span></label>
                    <input id="phone" name="phone" type="tel" class="input" autocomplete="tel" maxlength="30" value="<?= e($profile['phone'] ?? '') ?>" placeholder="เช่น 0812345678" aria-describedby="phone-hint">
                    <p id="phone-hint" class="field-hint">ใช้สำหรับการแจ้งเตือนและติดต่อภายในองค์กร</p>
                </div>
            </div>
            <div class="field-group" data-reveal-when-changed="email" hidden>
                <label for="current_password" class="field-label">ยืนยันด้วยรหัสผ่านปัจจุบัน <span class="required" aria-hidden="true">*</span></label>
                <input id="current_password" name="current_password" type="password" class="input" autocomplete="current-password" placeholder="••••••••" aria-describedby="current-password-hint">
                <p id="current-password-hint" class="field-hint">จำเป็นเพราะมีการเปลี่ยนอีเมล (ใช้เข้าสู่ระบบ + รีเซ็ตรหัสผ่าน)</p>
            </div>
            <div class="button-row profile-save-row">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกข้อมูลบัญชี', 'variant' => 'primary', 'icon' => 'check-circle', 'size' => 'lg']) ?>
            </div>
        </form>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ความปลอดภัย</h2>
                <p class="field-hint">ตรวจสอบสถานะรหัสผ่านและการจดจำการเข้าระบบ</p>
            </div>
            <span class="metric-icon metric-icon-md"><?= lucide('shield-check', 'h-5 w-5') ?></span>
        </div>

        <?php
        $passwordAgeDays = $security['password_age_days'] ?? null;
        $passwordChangedAt = (string) ($security['password_changed_at'] ?? '');
        $hasRememberToken = (bool) ($security['has_remember_token'] ?? false);

        $passwordTone = 'default';
        $passwordLabel = 'ยังไม่เคยเปลี่ยน';
        if ($passwordAgeDays !== null) {
            if ($passwordAgeDays > 180) {
                $passwordTone = 'danger';
                $passwordLabel = $passwordAgeDays . ' วัน · ควรเปลี่ยน';
            } elseif ($passwordAgeDays > 90) {
                $passwordTone = 'warning';
                $passwordLabel = $passwordAgeDays . ' วันที่แล้ว';
            } else {
                $passwordTone = 'success';
                $passwordLabel = $passwordAgeDays . ' วันที่แล้ว';
            }
        }
        ?>

        <dl class="description-list">
            <dt>เปลี่ยนรหัสผ่านล่าสุด</dt>
            <dd>
                <?php if ($passwordChangedAt !== ''): ?>
                    <?= e(human_date($passwordChangedAt, false)) ?>
                    <?= render_partial('partials/components/badge', ['label' => $passwordLabel, 'tone' => $passwordTone]) ?>
                <?php else: ?>
                    <span class="helper-text">ยังไม่เคยเปลี่ยน</span>
                    <?= render_partial('partials/components/badge', ['label' => 'ควรตั้งใหม่', 'tone' => 'warning']) ?>
                <?php endif; ?>
            </dd>
            <dt>จดจำการเข้าระบบ 30 วัน</dt>
            <dd>
                <?php if ($hasRememberToken): ?>
                    <?= render_partial('partials/components/badge', ['label' => 'เปิดใช้งานในอุปกรณ์นี้', 'tone' => 'info']) ?>
                <?php else: ?>
                    <span class="helper-text">ไม่ได้เปิดใช้งาน</span>
                <?php endif; ?>
            </dd>
        </dl>

        <div class="button-row">
            <?= render_partial('partials/components/button', ['label' => 'เปลี่ยนรหัสผ่าน', 'variant' => 'primary', 'href' => '/change-password', 'icon' => 'key-round']) ?>
            <?php if ($hasRememberToken): ?>
                <form method="post" action="<?= e(url('/profile/security/revoke-remember-me')) ?>" data-confirm-submit="ยกเลิกการจดจำการเข้าระบบ? อุปกรณ์ที่เคยเปิดจดจำจะต้อง login ใหม่">
                    <?= csrf_field() ?>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ล้าง remember-me', 'variant' => 'secondary', 'icon' => 'refresh-cw']) ?>
                </form>
            <?php endif; ?>
        </div>
    </section>
</section>
