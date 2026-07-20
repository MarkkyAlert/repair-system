<section class="stack-lg">
    <h1 class="sr-only">ข้อมูลทรัพย์สินจากการสแกน QR</h1>
    <section class="panel-card">
        <div class="panel-head">
            <h2 class="panel-title">ข้อมูลทรัพย์สินจากการสแกน QR</h2>
            <span class="badge badge-info"><?= e(asset_status_label_th((string) ($asset['status'] ?? ''))) ?></span>
        </div>
        <p class="body-text">คุณสามารถใช้ข้อมูลทรัพย์สินนี้เพื่อเปิด Ticket ใหม่แบบเติมข้อมูลอัตโนมัติได้ทันที</p>
    </section>

    <div class="content-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <div style="display:flex;flex-direction:column;gap:.25rem;min-width:0">
                    <code class="mono" style="width:max-content"><?= e($asset['asset_code']) ?></code>
                    <h2 class="panel-title" style="margin:0"><?= e($asset['name']) ?></h2>
                </div>
            </div>
            <div class="stack-md">
                <p class="body-text"><strong>หมวดหมู่:</strong> <?= e($asset['category_name']) ?></p>
                <p class="body-text"><strong>หมายเลขเครื่อง / Serial:</strong> <?= e($asset['serial_number']) ?></p>
                <p class="body-text"><strong>สถานที่:</strong> <?= e($asset['location_label']) ?></p>
                <p class="body-text"><strong>สแกนล่าสุด:</strong> <?= e($asset['last_scanned_at']) ?></p>
                <?php if (!empty($isAuthenticated)): ?>
                    <?php // หมายเหตุเป็น free-text ภายในของแอดมิน — แสดงเฉพาะเจ้าหน้าที่ที่ล็อกอิน ไม่โชว์บนหน้า scan สาธารณะ (guest) ?>
                    <p class="body-text"><strong>หมายเหตุ:</strong> <?= e($asset['notes'] !== '' ? $asset['notes'] : '-') ?></p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ขั้นตอนถัดไป</h2>
            </div>
            <?php if (!empty($isAuthenticated)): ?>
                <p class="body-text">ระบบจะเติมค่าทรัพย์สินและสถานที่ให้ในหน้าแจ้งซ่อมใหม่</p>
                <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาจากทรัพย์สินนี้', 'variant' => 'primary', 'href' => $ticketCreatePath, 'icon' => 'arrow-right']) ?>
            <?php else: ?>
                <p class="body-text">แจ้งปัญหาได้ทันทีโดยไม่ต้องมีบัญชี ใช้เวลาไม่ถึง 1 นาที</p>
                <div class="stack-md">
                    <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหา (ไม่ต้อง login)', 'variant' => 'primary', 'href' => $guestReportPath, 'icon' => 'zap']) ?>
                    <?= render_partial('partials/components/button', ['label' => 'หรือเข้าสู่ระบบ (สำหรับช่าง/ผู้ดูแลระบบ)', 'variant' => 'secondary', 'href' => $loginPath, 'icon' => 'arrow-right']) ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
