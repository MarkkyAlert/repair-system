<section class="stack-lg">
    <section class="panel-card no-print">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">พิมพ์แผ่น QR ทรัพย์สิน</h2>
                <p class="field-hint">ตรวจฉลาก QR ก่อนพิมพ์ แนะนำกระดาษ A4 แนวตั้ง และตั้ง Scale 100%</p>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับไปทะเบียนทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
                <button type="button" class="btn btn-primary" data-print-trigger>พิมพ์แผ่น QR</button>
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
                <span>การพิมพ์</span>
                <strong>Scale 100%</strong>
            </div>
        </div>
    </section>

    <?= render_partial('partials/components/qr-print-sheet', [
        'title' => 'แผ่น QR พร้อมพิมพ์ ขนาด A4',
        'items' => $assets,
        'brandName' => $brandName ?? null,
        'brandLogoUrl' => $brandLogoUrl ?? null,
    ]) ?>
</section>
