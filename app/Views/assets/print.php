<?php $hasPrintableAssets = !empty($assets); ?>
<section class="stack-lg">
    <section class="panel-card no-print">
        <div class="panel-head">
            <h2 class="panel-title">พิมพ์แผ่น QR ทรัพย์สิน</h2>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับไปทะเบียนทรัพย์สิน', 'variant' => 'ghost', 'icon' => 'arrow-left', 'href' => '/asset-registry']) ?>
                <?php if ($hasPrintableAssets): ?>
                    <button type="button" class="btn btn-primary btn-md" data-print-trigger>
                        <?= lucide('printer', 'button-icon') ?>
                        <span>พิมพ์แผ่น QR</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="qr-print-summary" aria-label="สรุปการพิมพ์แผ่น QR">
            <div class="qr-print-summary-card">
                <span>จำนวน QR</span>
                <strong><?= number_format(count($assets ?? [])) ?> รายการ</strong>
            </div>
            <div class="qr-print-summary-card">
                <span>ขนาดกระดาษ</span>
                <strong>A4 แนวตั้ง</strong>
            </div>
            <div class="qr-print-summary-card">
                <span>การพิมพ์ (แนะนำ)</span>
                <strong>Scale 100%</strong>
            </div>
            <div class="qr-print-summary-card">
                <span>จำนวนหน้า</span>
                <strong><?= ceil(count($assets ?? []) / 9) ?> แผ่น A4</strong>
            </div>
        </div>
        <?php if ($hasPrintableAssets): ?>
            <p class="field-hint">เพื่อให้ได้ 9 ดวงต่อแผ่นพอดีและแผ่นสะอาด แนะนำตั้งค่าในกล่องพิมพ์: กระดาษ A4 แนวตั้ง · Scale 100% · ปิด “Fit to page/ย่อพอดีหน้า” · ปิด “Headers and footers/หัว-ท้ายกระดาษ”</p>
        <?php endif; ?>
    </section>

    <?= render_partial('partials/components/qr-print-sheet', [
        'title' => 'แผ่น QR พร้อมพิมพ์ ขนาด A4',
        'items' => $assets,
        'brandName' => $brandName ?? null,
        'brandLogoUrl' => $brandLogoUrl ?? null,
    ]) ?>

    <?php if ($hasPrintableAssets): ?>
        <div class="qr-sticky-bar no-print">
            <button type="button" class="btn btn-primary btn-md" data-print-trigger>
                <?= lucide('printer', 'button-icon') ?>
                <span>พิมพ์แผ่น QR</span>
            </button>
        </div>
    <?php endif; ?>
</section>
