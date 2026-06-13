<section class="qr-sheet-preview">
    <div>
        <p class="panel-kicker">QR Print Sheet</p>
        <h3 class="panel-title"><?= e($title ?? 'A4 QR Preview') ?></h3>
    </div>
    <div class="qr-grid">
        <?php foreach (($items ?? []) as $item): ?>
            <div class="qr-cell">
                <div class="qr-label-brand"><?= lucide('wrench', 'qr-label-icon') ?><strong>MAINTENANCE</strong></div>
                <img src="<?= e($item['qr_png_url'] ?? '') ?>" alt="QR Code <?= e($item['asset_code'] ?? '') ?>" class="panel-card">
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
