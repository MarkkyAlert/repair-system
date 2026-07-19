<?php
$qrBrandName = trim((string) ($brandName ?? setting('app_name', config('app.name', 'Repair System'))));
$qrBrandLogoUrl = trim((string) ($brandLogoUrl ?? (branding_logo_url() ?? '')));
$qrItems = $items ?? [];
?>
<section class="qr-sheet-preview" aria-label="<?= e($title ?? 'แผ่น QR พร้อมพิมพ์ ขนาด A4') ?>">

    <?php if (empty($qrItems)): ?>
        <div class="empty-state no-print">
            <div class="empty-state-illustration" aria-hidden="true">
                <span class="empty-state-illustration-blob empty-state-illustration-blob-1"></span>
                <span class="empty-state-illustration-blob empty-state-illustration-blob-2"></span>
                <span class="empty-state-illustration-icon"><?= lucide('qr-code') ?></span>
            </div>
            <p class="empty-state-title">ยังไม่มีทรัพย์สินสำหรับพิมพ์ QR</p>
            <p class="empty-state-copy">เพิ่มทรัพย์สินหรือกลับไปตรวจรายการทรัพย์สินก่อน แล้วค่อยพิมพ์แผ่น QR อีกครั้ง</p>
            <?= render_partial('partials/components/button', ['label' => 'กลับไปทะเบียนทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
        </div>
    <?php else: ?>
        <div class="qr-grid" role="list" aria-label="<?= e($title ?? 'แผ่น QR พร้อมพิมพ์ ขนาด A4') ?>">
            <?php foreach ($qrItems as $item): ?>
                <?php
                $assetCode = (string) ($item['asset_code'] ?? '-');
                $assetName = (string) ($item['name'] ?? '-');
                $assetLocation = (string) ($item['location_name'] ?? '-');
                ?>
                <div class="qr-cell" role="listitem">
                    <div class="qr-cut-guide" aria-hidden="true"></div>
                    <div class="qr-label-brand">
                        <?php // จงใจใช้ onerror แบบ inline บน img: เพราะแผ่นพิมพ์ QR (print sheet) นี้อยู่ได้ด้วยตัวเอง (อาจ render โดยไม่มี app.js) และ event `error` ไม่ bubble ขึ้นไป การใช้ delegation จึงไม่น่าเชื่อถือ. ?>
                        <?php if ($qrBrandLogoUrl !== ''): ?>
                            <img src="<?= e($qrBrandLogoUrl) ?>" alt="" aria-hidden="true" class="qr-label-logo" onerror="this.remove()">
                        <?php else: ?>
                            <?= lucide('wrench', 'qr-label-icon') ?>
                        <?php endif; ?>
                        <strong><?= e($qrBrandName !== '' ? $qrBrandName : 'แจ้งซ่อม') ?></strong>
                    </div>
                    <span class="qr-code-frame">
                        <img src="<?= e($item['qr_png_url'] ?? '') ?>" alt="QR สำหรับแจ้งซ่อม <?= e($assetCode) ?> <?= e($assetName) ?> ที่ <?= e($assetLocation) ?>" class="qr-code-image" onerror="this.style.display='none';this.nextElementSibling.classList.add('is-shown');">
                        <span class="qr-code-fallback" aria-hidden="true"><?= lucide('qr-code', 'h-8 w-8') ?><span>QR ไม่พร้อมใช้งาน</span></span>
                    </span>
                    <div class="qr-label-meta">
                        <p class="qr-label-title"><?= e($assetName) ?></p>
                        <span class="qr-label-code"><?= e($assetCode) ?></span>
                        <p class="qr-label-location"><?= e($assetLocation) ?></p>
                        <p class="qr-instruction">สแกนเพื่อแจ้งซ่อมอุปกรณ์นี้</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
