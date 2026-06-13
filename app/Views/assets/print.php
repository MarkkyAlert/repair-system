<section class="stack-lg">
    <section class="panel-card no-print">
        <div class="panel-head">
            <h2 class="panel-title">พิมพ์ QR Sheet</h2>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับไป Assets', 'variant' => 'secondary', 'href' => '/assets']) ?>
                <button type="button" class="btn btn-primary" onclick="window.print()">พิมพ์หน้านี้</button>
            </div>
        </div>
    </section>

    <?= render_partial('partials/components/qr-print-sheet', [
        'title' => 'A4 QR Print Sheet',
        'items' => $assets,
    ]) ?>
</section>
