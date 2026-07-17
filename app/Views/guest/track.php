<?php $trackLogoUrl = branding_logo_url(); ?>
<?php $trackAppName = (string) setting('app_name', config('app.name', 'Repair System')); ?>
<section class="guest-panel">
    <div class="guest-brand">
        <?php if ($trackLogoUrl !== null): ?>
            <div class="brand-mark brand-mark-logo" aria-hidden="true"><img src="<?= e($trackLogoUrl) ?>" alt="<?= e($trackAppName) ?>"></div>
        <?php else: ?>
            <div class="brand-mark" aria-hidden="true"><?= lucide('wrench', 'brand-icon') ?></div>
        <?php endif; ?>
        <div>
            <p class="brand-title"><?= e($trackAppName) ?></p>
            <p class="brand-subtitle">ระบบจัดการงานซ่อมบำรุง</p>
        </div>
    </div>

    <div class="hero-card">
        <div class="hero-copy">
            <span class="pill">ติดตามสถานะ</span>
            <h1 class="hero-title">เช็คสถานะ<br>คำขอแจ้งปัญหา</h1>
            <p class="hero-text">ไม่ต้องเข้าสู่ระบบ — แค่มีเลขอ้างอิงกับเบอร์โทร/อีเมลที่แจ้งไว้ ก็ดูสถานะคำขอของคุณได้ทันที</p>

            <ol class="hero-feature-list guest-track-steps">
                <li>
                    <span class="hero-feature-icon">1</span>
                    <div>
                        <strong>กรอกเลขที่อ้างอิง</strong>
                        <span>เลข GR-… ที่ได้รับตอนแจ้งปัญหา</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon">2</span>
                    <div>
                        <strong>ยืนยันตัวตน</strong>
                        <span>ด้วยเบอร์โทรหรืออีเมลที่แจ้งไว้ตอนส่ง</span>
                    </div>
                </li>
                <li>
                    <span class="hero-feature-icon">3</span>
                    <div>
                        <strong>ดูสถานะล่าสุด</strong>
                        <span>รออนุมัติ · รับเรื่องแล้ว · หรือผลการพิจารณา</span>
                    </div>
                </li>
            </ol>
        </div>

        <form method="post" action="<?= e(url('/track')) ?>" class="auth-card">
            <?= csrf_field() ?>
            <div class="auth-card-header">
                <p class="page-kicker">เช็คสถานะคำขอ</p>
                <h2 class="auth-card-title">กรอกข้อมูลเพื่อติดตาม</h2>
                <p class="helper-text">ข้อมูลนี้ใช้ยืนยันว่าเป็นคำขอของคุณเท่านั้น</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="auth-alert auth-alert-danger" role="alert">
                    <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                    <p><?= e((string) $error) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($result)): ?>
                <div class="guest-track-result">
                    <p class="helper-text">เลขที่อ้างอิง</p>
                    <p class="guest-track-result-ref"><?= e((string) $result['request_no']) ?></p>
                    <span class="badge badge-<?= e((string) $result['status_tone']) ?>"><?= e((string) $result['status_label']) ?></span>

                    <?php if ($result['status'] === 'converted'): ?>
                        <p style="margin-top:1rem">รับเรื่องแล้ว · แปลงเป็นงานเลขที่ <strong><?= e((string) $result['ticket_no']) ?></strong></p>
                        <p style="margin-top:.5rem">สถานะงานตอนนี้: <span class="badge badge-<?= e((string) $result['ticket_status_tone']) ?>"><?= e((string) $result['ticket_status_label']) ?></span></p>
                    <?php elseif ($result['status'] === 'rejected'): ?>
                        <p style="margin-top:1rem">ทีมงานพิจารณาแล้วไม่รับดำเนินการคำขอนี้</p>
                        <?php if (($result['review_note'] ?? '') !== ''): ?>
                            <p class="helper-text" style="margin-top:.5rem">เหตุผล: <?= e((string) $result['review_note']) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="helper-text" style="margin-top:1rem">ทีมงานกำลังตรวจสอบคำขอของคุณ จะติดต่อกลับโดยเร็ว</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="field-group">
                <label for="request_no" class="field-label">เลขที่อ้างอิง</label>
                <input id="request_no" name="request_no" type="text" class="input" value="<?= e((string) ($ref ?? '')) ?>" placeholder="GR-20260706-XXXXXX" required maxlength="30">
            </div>
            <div class="field-group">
                <label for="contact" class="field-label">เบอร์โทร หรือ อีเมล ที่แจ้งไว้</label>
                <input id="contact" name="contact" type="text" class="input" placeholder="0812345678 หรือ you@example.com" required maxlength="190">
                <p class="field-hint">กรอกอย่างใดอย่างหนึ่งตามที่แจ้งตอนส่ง</p>
            </div>

            <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เช็คสถานะ', 'variant' => 'primary', 'icon' => 'search', 'fullWidth' => true]) ?>
        </form>
    </div>
</section>
