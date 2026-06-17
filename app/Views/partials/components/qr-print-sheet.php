<section class="qr-sheet-preview">
    <div>
        <p class="panel-kicker">แผ่น QR ทรัพย์สิน</p>
        <h3 class="panel-title"><?= e($title ?? 'ตัวอย่างแผ่น QR ขนาด A4') ?></h3>
    </div>
    <div class="qr-grid" role="list" aria-label="รายการฉลาก QR ทรัพย์สิน">
        <?php foreach (($items ?? []) as $item): ?>
            <div class="qr-cell" role="listitem">
                <div class="qr-label-brand"><?= lucide('wrench', 'qr-label-icon') ?><strong>แจ้งซ่อม</strong></div>
                <img src="<?= e($item['qr_png_url'] ?? '') ?>" alt="QR สำหรับทรัพย์สิน <?= e($item['asset_code'] ?? '') ?>" class="panel-card">
                <div class="stack-md">
                    <p class="panel-title"><?= e($item['name'] ?? '-') ?></p>
                    <span class="badge badge-default"><?= e($item['asset_code'] ?? '-') ?></span>
                    <p class="helper-text"><?= e($item['location_name'] ?? '-') ?></p>
                    <p class="qr-instruction">สแกนเพื่อแจ้งซ่อมอุปกรณ์นี้</p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
