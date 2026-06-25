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

            <div class="field-group">
                <label for="guest_email" class="field-label">อีเมล <span class="helper-text">(หรือเบอร์โทรอย่างน้อย 1)</span></label>
                <input id="guest_email" name="guest_email" type="email" class="input" placeholder="คุณ@example.com" value="<?= e((string) ($oldInput['guest_email'] ?? '')) ?>">
            </div>

            <div class="field-group">
                <label for="guest_phone" class="field-label">เบอร์โทร</label>
                <input id="guest_phone" name="guest_phone" type="tel" class="input" placeholder="0812345678" value="<?= e((string) ($oldInput['guest_phone'] ?? '')) ?>">
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
