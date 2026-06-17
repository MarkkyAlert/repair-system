<section class="stack-lg">
    <section class="panel-card no-print">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">พิมพ์แผ่น QR</h2>
                <p class="field-hint">ตรวจตัวอย่างฉลาก QR ก่อนพิมพ์และนำไปติดกับทรัพย์สิน</p>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับทะเบียนทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
                <button type="button" class="btn btn-primary" onclick="window.print()">พิมพ์หน้านี้</button>
            </div>
        </div>
    </section>

    <?= render_partial('partials/components/qr-print-sheet', [
        'title' => 'ตัวอย่างแผ่น QR ขนาด A4',
        'items' => $assets,
    ]) ?>
</section>
