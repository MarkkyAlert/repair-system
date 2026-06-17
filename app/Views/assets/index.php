<section class="stack-lg">
    <?php ob_start(); ?>
                <?php if (!empty($canManage)): ?>
                    <?= render_partial('partials/components/button', ['label' => 'เพิ่มทรัพย์สิน', 'variant' => 'primary', 'href' => '/asset-registry/create', 'icon' => 'arrow-right']) ?>
                    <?= render_partial('partials/components/button', ['label' => 'พิมพ์แผ่น QR', 'variant' => 'secondary', 'href' => '/asset-registry/print']) ?>
                <?php endif; ?>
    <?php $heroActions = (string) ob_get_clean(); ?>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ทะเบียนทรัพย์สิน',
        'title' => 'ทรัพย์สินและ QR',
        'description' => 'ดูทรัพย์สิน สแกน QR และเปิดงานแจ้งซ่อมจากอุปกรณ์ได้ทันที',
        'actions' => $heroActions,
    ]) ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">ทรัพย์สินทั้งหมด</h2>
            <span class="badge badge-info"><?= e($roleLabel ?? '-') ?></span>
        </div>

        <?php if ($assets === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'qr-code',
                'title' => 'ยังไม่มีทรัพย์สินในระบบ',
                'description' => 'เพิ่มทรัพย์สินรายการแรกเพื่อสร้าง QR สำหรับสแกนแจ้งซ่อมได้ทันที',
            ]) ?>
        <?php else: ?>
            <div class="asset-grid">
                <?php foreach ($assets as $asset): ?>
                    <article class="asset-card">
                        <div class="asset-card-main">
                            <div class="asset-card-copy">
                                <header class="asset-card-head">
                                    <div class="asset-card-id">
                                        <code class="mono"><?= e($asset['asset_code']) ?></code>
                                        <?= render_partial('partials/components/badge', ['label' => $asset['status_label'], 'tone' => $asset['status_tone']]) ?>
                                    </div>
                                    <h3 class="asset-card-title"><?= e($asset['name']) ?></h3>
                                </header>
                            </div>
                            <a class="asset-card-qr" href="<?= e($asset['qr_png_url']) ?>" target="_blank" rel="noopener" aria-label="ดู QR ของทรัพย์สิน <?= e($asset['asset_code']) ?>">
                                <img src="<?= e($asset['qr_png_url']) ?>" alt="QR สำหรับทรัพย์สิน <?= e($asset['asset_code']) ?>">
                                <span>ดู QR</span>
                            </a>
                        </div>
                        <dl class="asset-card-meta">
                            <div><dt><?= lucide('tag', 'h-3.5 w-3.5') ?>หมวดหมู่</dt><dd><?= e($asset['category_name']) ?></dd></div>
                            <div><dt><?= lucide('map-pin', 'h-3.5 w-3.5') ?>สถานที่</dt><dd><?= e($asset['location_label']) ?></dd></div>
                            <div><dt><?= lucide('user', 'h-3.5 w-3.5') ?>ผู้ดูแล</dt><dd><?= e($asset['custodian_name'] ?: '-') ?></dd></div>
                        </dl>
                        <footer class="asset-card-actions">
                            <a class="asset-card-cta" href="<?= e(url($asset['prefill_ticket_url'])) ?>" aria-label="แจ้งซ่อมจากทรัพย์สิน <?= e($asset['asset_code']) ?>"><?= lucide('zap', 'h-3.5 w-3.5') ?>แจ้งซ่อม</a>
                            <a class="asset-card-link" href="<?= e(url('/asset-registry/' . $asset['id'])) ?>" aria-label="ดูรายละเอียดทรัพย์สิน <?= e($asset['asset_code']) ?>">รายละเอียด</a>
                            <a class="asset-card-link" href="<?= e($asset['qr_png_url']) ?>" target="_blank" rel="noopener" aria-label="เปิด QR PNG ของทรัพย์สิน <?= e($asset['asset_code']) ?>">เปิด QR</a>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
            <?= render_partial('partials/components/pagination', ['pagination' => $pagination]) ?>
        <?php endif; ?>
    </section>
</section>
