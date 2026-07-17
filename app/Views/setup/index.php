<section class="guest-panel">
    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">ตั้งค่าครั้งแรก</span>
            <h1 class="hero-title">ยินดีต้อนรับ<br>สู่ระบบแจ้งซ่อม</h1>
            <p class="hero-text">กรอกข้อมูลพื้นฐานเพื่อเริ่มใช้งาน — ใช้เวลาไม่ถึง 2 นาที</p>

            <ul class="hero-feature-list">
                <li>
                    <span class="hero-feature-icon"><?= lucide('wrench', 'h-4 w-4') ?></span>
                    <div>
                        <strong>ตั้งชื่อระบบ</strong>
                        <span>กำหนดชื่อที่จะแสดงในหัวกระดาษ + อีเมล</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon"><?= lucide('shield-check', 'h-4 w-4') ?></span>
                    <div>
                        <strong>สร้างบัญชีผู้ดูแลระบบ</strong>
                        <span>คนแรกที่จะเข้าระบบ — สามารถเพิ่มผู้ใช้คนอื่นได้ภายหลัง</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon"><?= lucide('zap', 'h-4 w-4') ?></span>
                    <div>
                        <strong>ลองใช้พร้อมข้อมูลตัวอย่าง</strong>
                        <span>โหลดทรัพย์สิน, Ticket, คะแนน เพื่อทดลองได้ทันที</span>
                    </div>
                </li>
            </ul>
        </div>

        <form class="auth-card stack-md" method="post" action="<?= e(url('/setup')) ?>" data-loading-submit>
            <?= csrf_field() ?>

            <div class="auth-card-header">
                <p class="page-kicker">ตัวช่วยติดตั้งระบบ</p>
                <h2 class="auth-card-title">ตั้งค่าระบบครั้งแรก</h2>
                <p class="helper-text">กรอกครั้งเดียว ระบบจะข้ามหน้านี้ไปในครั้งต่อไป</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="auth-alert auth-alert-danger" role="alert">
                    <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                    <p><?= e((string) $errorMessage) ?></p>
                </div>
            <?php endif; ?>

            <div class="field-group">
                <label for="app_name" class="field-label">ชื่อระบบ / องค์กร <span class="required">*</span></label>
                <input id="app_name" name="app_name" type="text" class="input" required maxlength="100" placeholder="เช่น Acme Maintenance">
            </div>

            <?php if (empty($hasAdmin)): ?>
                <fieldset class="field-group" style="border:0;padding:0;margin:0">
                    <legend class="field-label" style="padding:0;margin-bottom:.6rem">บัญชีผู้ดูแลระบบ <span class="required">*</span></legend>
                    <div class="stack-md">
                        <div class="field-group">
                            <label for="admin_username" class="field-label">ชื่อผู้ใช้</label>
                            <input id="admin_username" name="admin_username" type="text" class="input" required placeholder="เช่น admin" autocomplete="username">
                        </div>
                        <div class="field-group">
                            <label for="admin_email" class="field-label">อีเมล</label>
                            <input id="admin_email" name="admin_email" type="email" class="input" required placeholder="เช่น admin@example.com">
                        </div>
                        <div class="field-group">
                            <label for="admin_full_name" class="field-label">ชื่อ-นามสกุล</label>
                            <input id="admin_full_name" name="admin_full_name" type="text" class="input" required placeholder="ชื่อ-นามสกุลของผู้ดูแล">
                        </div>
                        <div class="field-group">
                            <label for="admin_password" class="field-label">รหัสผ่าน</label>
                            <input id="admin_password" name="admin_password" type="password" class="input" required minlength="8" placeholder="อย่างน้อย 8 ตัวอักษร" autocomplete="new-password">
                        </div>
                    </div>
                </fieldset>
            <?php else: ?>
                <div class="auth-alert auth-alert-info">
                    <span class="auth-alert-icon"><?= lucide('info', 'h-4 w-4') ?></span>
                    <p>ตรวจพบบัญชีผู้ดูแลระบบในระบบแล้ว ข้ามขั้นตอนสร้างผู้ดูแลระบบ</p>
                </div>
            <?php endif; ?>

            <label class="checkbox-row">
                <input type="checkbox" name="load_demo" value="1">
                <span>โหลดข้อมูลตัวอย่าง (3 แผนก, 5 สถานที่, 8 ทรัพย์สิน, 3 ช่าง, 20 Ticket ครอบทุกสถานะ) — สำหรับ dev/demo เท่านั้น ไม่ควรใช้บน production; ระบบจะสร้างบัญชีช่างตัวอย่างพร้อมรหัสสุ่มและแสดงให้ครั้งเดียว</span>
            </label>

            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'เริ่มใช้งานระบบ',
                'variant' => 'primary',
                'icon' => 'arrow-right',
                'iconPosition' => 'right',
                'size' => 'lg',
                'fullWidth' => true,
            ]) ?>

            <p class="auth-footnote">
                <?= lucide('shield-check', 'h-3.5 w-3.5') ?>
                ข้อมูลทั้งหมดถูกเก็บใน database ของคุณเอง ระบบจะไม่ส่งข้อมูลออกภายนอก
            </p>
        </form>
    </div>
</section>
