<section class="guest-panel">
    <div class="hero-card hero-card-single">
        <div class="hero-copy">
            <span class="pill">ติดตามสถานะ</span>
            <h1 class="hero-title">เช็คสถานะคำขอแจ้งปัญหา</h1>
            <p class="hero-text">กรอกเลขอ้างอิงและเบอร์โทร/อีเมลที่แจ้งไว้ เพื่อดูสถานะคำขอของคุณ</p>

            <?php if (!empty($error)): ?>
                <div style="margin-top:1.5rem;padding:1rem 1.25rem;border-radius:12px;background:var(--danger-50, #fef2f2);color:var(--danger-700, #be123c);border:1px solid var(--danger-200, #fecaca)">
                    <?= e((string) $error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($result)): ?>
                <div style="margin-top:1.5rem;padding:1.5rem;border-radius:14px;background:linear-gradient(135deg,var(--indigo-50),rgba(99,102,241,.08));text-align:center">
                    <p class="helper-text">เลขที่อ้างอิง</p>
                    <p style="font-size:1.25rem;font-weight:800;color:var(--indigo-600);margin:.25rem 0 .75rem;font-family:var(--font-mono,monospace)"><?= e((string) $result['request_no']) ?></p>
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

            <form method="post" action="<?= e(url('/track')) ?>" class="stack-md" style="margin-top:1.75rem;text-align:left">
                <?= csrf_field() ?>
                <div class="field-group">
                    <label for="request_no" class="field-label">เลขที่อ้างอิง</label>
                    <input id="request_no" name="request_no" type="text" class="input" value="<?= e((string) ($ref ?? '')) ?>" placeholder="GR-20260706-XXXXXX" required maxlength="30">
                </div>
                <div class="field-group">
                    <label for="contact" class="field-label">เบอร์โทร หรือ อีเมล ที่แจ้งไว้</label>
                    <input id="contact" name="contact" type="text" class="input" placeholder="0812345678 หรือ you@example.com" required maxlength="190">
                    <p class="field-hint">ใช้ยืนยันว่าเป็นคำขอของคุณ (กรอกอย่างใดอย่างหนึ่งตามที่แจ้งตอนส่ง)</p>
                </div>
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เช็คสถานะ', 'variant' => 'primary', 'icon' => 'search', 'fullWidth' => true]) ?>
            </form>
        </div>
    </div>
</section>
