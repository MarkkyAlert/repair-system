<section class="stack-lg">
    <section class="panel-card">
        <div class="panel-head">
            <h2 class="panel-title">ข้อมูลทรัพย์สินจากการสแกน QR</h2>
            <span class="badge badge-info"><?= e($asset['status']) ?></span>
        </div>
        <p class="body-text">คุณสามารถใช้ข้อมูล Asset นี้เพื่อเปิด ticket ใหม่แบบ prefill ได้ทันที</p>
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
                <p class="body-text"><strong>Category:</strong> <?= e($asset['category_name']) ?></p>
                <p class="body-text"><strong>Serial Number:</strong> <?= e($asset['serial_number']) ?></p>
                <p class="body-text"><strong>Location:</strong> <?= e($asset['location_label']) ?></p>
                <p class="body-text"><strong>Last Scanned:</strong> <?= e($asset['last_scanned_at']) ?></p>
                <p class="body-text"><strong>Notes:</strong> <?= e($asset['notes'] !== '' ? $asset['notes'] : '-') ?></p>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ขั้นตอนถัดไป</h2>
            </div>
            <?php if (!empty($isAuthenticated)): ?>
                <p class="body-text">ระบบจะ prefill ค่า Asset และ Location ให้ในหน้าแจ้งซ่อมใหม่</p>
                <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาจาก Asset นี้', 'variant' => 'primary', 'href' => $ticketCreatePath, 'icon' => 'arrow-right']) ?>
            <?php else: ?>
                <p class="body-text">แจ้งปัญหาได้ทันทีโดยไม่ต้องมีบัญชี ใช้เวลาไม่ถึง 1 นาที</p>
                <div class="stack-md">
                    <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหา (ไม่ต้อง login)', 'variant' => 'primary', 'href' => $guestReportPath, 'icon' => 'zap']) ?>
                    <?= render_partial('partials/components/button', ['label' => 'หรือเข้าสู่ระบบ (สำหรับช่าง/admin)', 'variant' => 'secondary', 'href' => $loginPath, 'icon' => 'arrow-right']) ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
