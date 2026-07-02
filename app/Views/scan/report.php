<section class="guest-panel">
    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">แจ้งปัญหาจาก QR</span>
            <h1 class="hero-title"><?= e((string) ($asset['name'] ?? '')) ?></h1>
            <p class="hero-text"><code class="mono"><?= e((string) ($asset['asset_code'] ?? '')) ?></code> · <?= e((string) ($asset['location_label'] ?? '-')) ?></p>

            <ul class="hero-feature-list">
                <li>
                    <span class="hero-feature-icon"><?= lucide('zap', 'h-4 w-4') ?></span>
                    <div>
                        <strong>กรอกแค่ชื่อ + ติดต่อ + รายละเอียด</strong>
                        <span>ไม่ต้องสมัครสมาชิก ไม่ต้องล็อกอิน</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon"><?= lucide('clipboard-list', 'h-4 w-4') ?></span>
                    <div>
                        <strong>ทีมงานจะรับเรื่องและติดต่อกลับ</strong>
                        <span>ตามอีเมลหรือเบอร์ที่กรอกไว้</span>
                    </div>
                </li>
            </ul>
        </div>

        <form class="auth-card stack-md" method="post" action="<?= e(url('/scan/' . rawurlencode($token) . '/report')) ?>" data-loading-submit>
            <?= csrf_field() ?>

            <div class="auth-card-header">
                <p class="page-kicker">แจ้งปัญหา</p>
                <h2 class="auth-card-title">กรอกข้อมูลเพื่อแจ้งซ่อม</h2>
                <p class="helper-text">ทีมงานจะติดต่อกลับตามข้อมูลที่กรอก</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="auth-alert auth-alert-danger" role="alert">
                    <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                    <p><?= e((string) $errorMessage) ?></p>
                </div>
            <?php endif; ?>

            <!-- honeypot -->
            <input type="text" name="website" value="" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="field-group">
                <label for="guest_name" class="field-label">ชื่อของคุณ <span class="required">*</span></label>
                <input id="guest_name" name="guest_name" type="text" class="input" required maxlength="150" value="<?= e((string) ($oldInput['guest_name'] ?? '')) ?>">
            </div>

            <p class="field-hint" id="contact-hint">ช่องทางติดต่อกลับ — กรอกอีเมลหรือเบอร์โทร <strong>อย่างน้อย 1 ช่อง</strong> เพื่อให้ทีมงานติดต่อกลับได้</p>

            <div class="field-group">
                <label for="guest_email" class="field-label">อีเมล</label>
                <input id="guest_email" name="guest_email" type="email" class="input" placeholder="คุณ@example.com" aria-describedby="contact-hint" value="<?= e((string) ($oldInput['guest_email'] ?? '')) ?>">
            </div>

            <div class="field-group">
                <label for="guest_phone" class="field-label">เบอร์โทร</label>
                <input id="guest_phone" name="guest_phone" type="tel" class="input" placeholder="0812345678" aria-describedby="contact-hint" value="<?= e((string) ($oldInput['guest_phone'] ?? '')) ?>">
            </div>

            <div class="field-group">
                <label for="title" class="field-label">หัวข้อปัญหา <span class="required">*</span></label>
                <input id="title" name="title" type="text" class="input" required maxlength="200" placeholder="เช่น อินเทอร์เน็ตห้องนี้ใช้ไม่ได้" value="<?= e((string) ($oldInput['title'] ?? '')) ?>">
            </div>

            <div class="field-group">
                <label for="description" class="field-label">รายละเอียด <span class="required">*</span></label>
                <textarea id="description" name="description" class="input" rows="5" required placeholder="อธิบายอาการที่พบ"><?= e((string) ($oldInput['description'] ?? '')) ?></textarea>
            </div>

            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'ส่งคำขอแจ้งซ่อม',
                'variant' => 'primary',
                'icon' => 'send',
                'iconPosition' => 'right',
                'size' => 'lg',
                'fullWidth' => true,
            ]) ?>

            <p class="auth-footnote">
                <?= lucide('shield-check', 'h-3.5 w-3.5') ?>
                ข้อมูลของคุณใช้สำหรับติดต่อกลับเท่านั้น
            </p>
        </form>
    </div>
</section>

<script>
(function () {
    var form = document.querySelector('form.auth-card');
    if (!form) return;
    var email = document.getElementById('guest_email');
    var phone = document.getElementById('guest_phone');
    if (!email || !phone) return;

    // Inline error styled like the server-side alert; created only when JS is available.
    var alertBox = document.createElement('div');
    alertBox.className = 'auth-alert auth-alert-danger';
    alertBox.setAttribute('role', 'alert');
    alertBox.style.display = 'none';
    alertBox.innerHTML = '<span class="auth-alert-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg></span><p>กรุณากรอกอีเมลหรือเบอร์โทรอย่างน้อย 1 ช่อง เพื่อให้ทีมงานติดต่อกลับได้</p>';
    var emailGroup = email.closest('.field-group');
    if (emailGroup && emailGroup.parentNode) {
        emailGroup.parentNode.insertBefore(alertBox, emailGroup);
    }

    var clearError = function () {
        alertBox.style.display = 'none';
        email.removeAttribute('aria-invalid');
        phone.removeAttribute('aria-invalid');
    };
    email.addEventListener('input', clearError);
    phone.addEventListener('input', clearError);

    form.addEventListener('submit', function (e) {
        if (email.value.trim() === '' && phone.value.trim() === '') {
            e.preventDefault();
            e.stopImmediatePropagation(); // keep the loading handler from firing on a blocked submit
            alertBox.style.display = '';
            email.setAttribute('aria-invalid', 'true');
            phone.setAttribute('aria-invalid', 'true');
            email.focus();
            // Defensive: if the loading handler ran first, re-enable the submit button.
            setTimeout(function () {
                var btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.classList.remove('is-loading'); btn.disabled = false; }
            }, 0);
        }
    });
})();
</script>
