<section class="stack-lg">
    <?php ob_start(); ?>
                <?php if (!empty($canManage)): ?>
                    <?= render_partial('partials/components/button', ['label' => 'เพิ่ม Asset', 'variant' => 'primary', 'href' => '/asset-registry/create', 'icon' => 'arrow-right']) ?>
                    <?= render_partial('partials/components/button', ['label' => 'พิมพ์ QR Sheet', 'variant' => 'secondary', 'href' => '/asset-registry/print']) ?>
                <?php endif; ?>
    <?php $heroActions = (string) ob_get_clean(); ?>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'Asset Registry',
        'title' => 'ทรัพย์สินและ QR',
        'description' => 'จัดการอุปกรณ์และพิมพ์ QR สำหรับสแกนแจ้งซ่อม',
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
                'title' => 'ยังไม่มี Asset ในระบบ',
                'description' => 'เมื่อเพิ่มข้อมูล Asset แล้ว จะสามารถสร้าง QR และใช้ prefill หน้าแจ้งซ่อมได้',
            ]) ?>
        <?php else: ?>
            <div class="asset-grid">
                <?php foreach ($assets as $asset): ?>
                    <article class="asset-card">
                        <header class="asset-card-head">
                            <div class="asset-card-id">
                                <code class="mono"><?= e($asset['asset_code']) ?></code>
                                <?= render_partial('partials/components/badge', ['label' => $asset['status_label'], 'tone' => $asset['status_tone']]) ?>
                            </div>
                            <h3 class="asset-card-title"><?= e($asset['name']) ?></h3>
                        </header>
                        <dl class="asset-card-meta">
                            <div><dt><?= lucide('tag', 'h-3.5 w-3.5') ?>หมวด</dt><dd><?= e($asset['category_name']) ?></dd></div>
                            <div><dt><?= lucide('map-pin', 'h-3.5 w-3.5') ?>สถานที่</dt><dd><?= e($asset['location_label']) ?></dd></div>
                            <div><dt><?= lucide('user', 'h-3.5 w-3.5') ?>ผู้ดูแล</dt><dd><?= e($asset['custodian_name'] ?: '-') ?></dd></div>
                        </dl>
                        <footer class="asset-card-actions">
                            <a class="asset-card-link" href="<?= e(url('/asset-registry/' . $asset['id'])) ?>">รายละเอียด</a>
                            <a class="asset-card-cta" href="<?= e(url('/tickets/create?asset_id=' . $asset['id'])) ?>"><?= lucide('zap', 'h-3.5 w-3.5') ?>แจ้งปัญหา</a>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
            <?= render_partial('partials/components/pagination', ['pagination' => $pagination]) ?>
        <?php endif; ?>
    </section>
</section>
