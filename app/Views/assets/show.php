<section class="stack-lg">
    <section class="panel-card asset-detail-hero">
        <div class="asset-detail-hero-copy">
            <code class="mono"><?= e($asset['asset_code']) ?></code>
            <h2 class="panel-title"><?= e($asset['name']) ?></h2>
            <p class="field-hint">ข้อมูลทรัพย์สิน จุดติดตั้ง และ QR สำหรับสแกนแจ้งซ่อม</p>
        </div>
        <div class="asset-detail-hero-actions">
            <?= render_partial('partials/components/badge', ['label' => $asset['status_label'], 'tone' => $asset['status_tone']]) ?>
            <?= render_partial('partials/components/button', ['label' => 'กลับทะเบียนทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
        </div>
    </section>

    <div class="content-grid asset-detail-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">ข้อมูลทรัพย์สิน</h2>
                    <p class="field-hint">รายละเอียดหลักสำหรับค้นหา ตรวจสอบ และจัดการงานซ่อม</p>
                </div>
            </div>

            <div class="asset-description-sections">
                <section class="asset-description-section">
                    <h3>ข้อมูลหลัก</h3>
                    <dl class="asset-description-list">
                        <div><dt>หมวดหมู่</dt><dd><?= e($asset['category_name']) ?></dd></div>
                        <div><dt>เลขซีเรียล</dt><dd><?= e($asset['serial_number']) ?></dd></div>
                        <div><dt>ยี่ห้อ</dt><dd><?= e($asset['brand'] !== '' ? $asset['brand'] : '-') ?></dd></div>
                        <div><dt>รุ่น</dt><dd><?= e($asset['model'] !== '' ? $asset['model'] : '-') ?></dd></div>
                        <div><dt>ผู้จำหน่าย</dt><dd><?= e($asset['vendor'] !== '' ? $asset['vendor'] : '-') ?></dd></div>
                    </dl>
                </section>

                <section class="asset-description-section">
                    <h3>ผู้ดูแลและสถานที่</h3>
                    <dl class="asset-description-list">
                        <div><dt>แผนก</dt><dd><?= e($asset['department_name']) ?></dd></div>
                        <div><dt>ผู้ดูแล</dt><dd><?= e($asset['custodian_name']) ?></dd></div>
                        <div><dt>สถานที่</dt><dd><?= e($asset['location_name']) ?></dd></div>
                    </dl>
                </section>

                <section class="asset-description-section">
                    <h3>การซื้อและประกัน</h3>
                    <dl class="asset-description-list">
                        <div><dt>วันที่ซื้อ</dt><dd><?= e($asset['purchase_date']) ?></dd></div>
                        <div><dt>ประกันหมดอายุ</dt><dd><?= e($asset['warranty_expires_at']) ?></dd></div>
                    </dl>
                </section>

                <section class="asset-description-section">
                    <h3>หมายเหตุ</h3>
                    <p class="body-text"><?= e($asset['notes'] !== '' ? $asset['notes'] : '-') ?></p>
                </section>
            </div>
        </section>

        <section class="panel-card stack-md asset-qr-panel">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">QR สำหรับแจ้งซ่อม</h2>
                    <p class="field-hint">สแกน QR เพื่อเปิดหน้าแจ้งซ่อมพร้อมข้อมูลทรัพย์สินนี้</p>
                </div>
            </div>

            <div class="asset-qr-preview">
                <img src="<?= e($asset['qr_png_url']) ?>" alt="QR สำหรับทรัพย์สิน <?= e($asset['asset_code']) ?>">
                <span class="badge badge-default"><?= e($asset['asset_code']) ?></span>
            </div>

            <dl class="asset-description-list asset-qr-meta">
                <div><dt>ลิงก์สแกน</dt><dd><?= e($asset['scan_url'] !== '' ? $asset['scan_url'] : '-') ?></dd></div>
                <div><dt>สร้าง QR เมื่อ</dt><dd><?= e($asset['qr_created_at']) ?></dd></div>
                <div><dt>สแกนล่าสุด</dt><dd><?= e($asset['last_scanned_at']) ?></dd></div>
            </dl>

            <div class="asset-detail-actions">
                <?= render_partial('partials/components/button', [
                    'label' => 'แจ้งซ่อมจากทรัพย์สินนี้',
                    'variant' => 'primary',
                    'href' => $asset['prefill_ticket_url'],
                    'icon' => 'zap',
                ]) ?>
                <button type="button" class="btn btn-secondary" data-print-qr-url="<?= e($asset['qr_png_url']) ?>" aria-label="พิมพ์ QR ของทรัพย์สิน <?= e($asset['asset_code']) ?>">
                    <span>พิมพ์ QR</span>
                </button>
                <a href="<?= e($asset['qr_png_url']) ?>" class="btn btn-secondary" target="_blank" rel="noopener" aria-label="เปิด QR PNG ของทรัพย์สิน <?= e($asset['asset_code']) ?>">
                    <span>เปิด PNG</span>
                </a>
                <?php if (!empty($canManage)): ?>
                    <?= render_partial('partials/components/button', ['label' => 'แก้ไขทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry/' . $asset['id'] . '/edit']) ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($canManage)): ?>
                <form method="post" action="<?= e(url('/asset-registry/' . $asset['id'] . '/qr/regenerate')) ?>" class="asset-maintenance-actions">
                    <?= csrf_field() ?>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'สร้าง QR token ใหม่', 'variant' => 'secondary']) ?>
                    <p class="field-hint">ใช้เมื่อ QR เดิมรั่วไหลหรือต้องการเปลี่ยนลิงก์สแกน</p>
                </form>
            <?php endif; ?>
        </section>
    </div>
</section>
