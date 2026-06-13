<section class="stack-lg">
    <section class="panel-card">
        <div class="panel-head">
            <div style="display:flex;flex-direction:column;gap:.25rem;min-width:0">
                <code class="mono" style="width:max-content"><?= e($asset['asset_code']) ?></code>
                <h2 class="panel-title" style="margin:0"><?= e($asset['name']) ?></h2>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/badge', ['label' => $asset['status_label'], 'tone' => $asset['status_tone']]) ?>
                <?= render_partial('partials/components/button', ['label' => 'กลับไป Assets', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
            </div>
        </div>
    </section>

    <div class="content-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ข้อมูลทรัพย์สิน</h2>
            </div>
            <div class="stack-md">
                <p class="body-text"><strong>Category:</strong> <?= e($asset['category_name']) ?></p>
                <p class="body-text"><strong>Serial Number:</strong> <?= e($asset['serial_number']) ?></p>
                <p class="body-text"><strong>Brand:</strong> <?= e($asset['brand'] !== '' ? $asset['brand'] : '-') ?></p>
                <p class="body-text"><strong>Model:</strong> <?= e($asset['model'] !== '' ? $asset['model'] : '-') ?></p>
                <p class="body-text"><strong>Vendor:</strong> <?= e($asset['vendor'] !== '' ? $asset['vendor'] : '-') ?></p>
                <p class="body-text"><strong>Department:</strong> <?= e($asset['department_name']) ?></p>
                <p class="body-text"><strong>Custodian:</strong> <?= e($asset['custodian_name']) ?></p>
                <p class="body-text"><strong>Location:</strong> <?= e($asset['location_name']) ?></p>
                <p class="body-text"><strong>Purchase Date:</strong> <?= e($asset['purchase_date']) ?></p>
                <p class="body-text"><strong>Warranty Expires:</strong> <?= e($asset['warranty_expires_at']) ?></p>
                <p class="body-text"><strong>Notes:</strong> <?= e($asset['notes'] !== '' ? $asset['notes'] : '-') ?></p>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">QR Code และการสแกน</h2>
            </div>
            <div class="stack-md">
                <img src="<?= e($asset['qr_png_url']) ?>" alt="QR Code for <?= e($asset['asset_code']) ?>" class="panel-card">
                <p class="body-text"><strong>Scan URL:</strong> <?= e($asset['scan_url'] !== '' ? $asset['scan_url'] : '-') ?></p>
                <p class="body-text"><strong>QR Created:</strong> <?= e($asset['qr_created_at']) ?></p>
                <p class="body-text"><strong>Last Scanned:</strong> <?= e($asset['last_scanned_at']) ?></p>
            </div>
            <div class="button-row">
                <a href="<?= e($asset['qr_png_url']) ?>" class="btn btn-secondary" target="_blank" rel="noopener">
                    <span>เปิด QR PNG</span>
                </a>
                <button type="button" class="btn btn-secondary" data-qr-url="<?= e($asset['qr_png_url']) ?>" onclick="(function(trigger){const qrUrl=trigger.getAttribute('data-qr-url')||'';if(qrUrl===''){return;}const printWindow=window.open('', '_blank', 'noopener');if(!printWindow){window.location.href=qrUrl;return;}printWindow.document.open();printWindow.document.write('<!DOCTYPE html><html><head><meta charset=\'utf-8\'><title>Print QR</title></head><body style=\'margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#fff;\'><img src=\''+qrUrl+'\' alt=\'QR Code\' style=\'max-width:90vw;max-height:90vh;\' onload=\'window.print();setTimeout(function(){window.close();},150);\'></body></html>');printWindow.document.close();})(this);">
                    <span>พิมพ์ QR นี้</span>
                </button>
                <?= render_partial('partials/components/button', ['label' => 'สร้าง Ticket จาก Asset นี้', 'variant' => 'primary', 'href' => $asset['prefill_ticket_url']]) ?>
                <?php if (!empty($canManage)): ?>
                    <?= render_partial('partials/components/button', ['label' => 'แก้ไข Asset', 'variant' => 'secondary', 'href' => '/asset-registry/' . $asset['id'] . '/edit']) ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($canManage)): ?>
                <form method="post" action="<?= e(url('/asset-registry/' . $asset['id'] . '/qr/regenerate')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'สร้าง QR Token ใหม่', 'variant' => 'secondary']) ?>
                </form>
            <?php endif; ?>
        </section>
    </div>
</section>
