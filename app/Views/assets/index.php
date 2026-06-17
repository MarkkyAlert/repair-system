<?php
$assetFilters = $filters ?? ['q' => '', 'category_id' => 0, 'location_id' => 0, 'status' => ''];
$assetFilterOptions = $filterOptions ?? ['categories' => [], 'locations' => [], 'statuses' => []];
$assetActiveFilters = $activeFilters ?? [];
$assetSearch = (string) ($assetFilters['q'] ?? '');
$assetCategoryId = (int) ($assetFilters['category_id'] ?? 0);
$assetLocationId = (int) ($assetFilters['location_id'] ?? 0);
$assetStatus = (string) ($assetFilters['status'] ?? '');
$isAssetFilterActive = $assetSearch !== '' || $assetCategoryId > 0 || $assetLocationId > 0 || $assetStatus !== '';
$isAssetAdvancedFilterActive = $assetCategoryId > 0 || $assetLocationId > 0 || $assetStatus !== '';
?>
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

        <form method="get" action="<?= e(url('/asset-registry')) ?>" class="asset-filter-toolbar">
            <div class="asset-filter-main">
                <div class="filter-search">
                    <?= lucide('search', 'h-4 w-4') ?>
                    <input type="search" name="q" value="<?= e($assetSearch) ?>" placeholder="ค้นหารหัส ชื่อ เลขซีเรียล ยี่ห้อ หรือรุ่น..." aria-label="ค้นหาทรัพย์สินจากรหัส ชื่อ เลขซีเรียล ยี่ห้อ หรือรุ่น">
                </div>
                <div class="asset-filter-actions">
                    <button type="submit" class="btn btn-secondary btn-md"><?= lucide('filter', 'button-icon') ?><span>ค้นหาและกรอง</span></button>
                    <?php if ($isAssetFilterActive): ?>
                        <a href="<?= e(url('/asset-registry')) ?>" class="btn btn-ghost btn-md"><?= lucide('x', 'button-icon') ?><span>ล้างตัวกรอง</span></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($assetActiveFilters !== []): ?>
                <div class="asset-filter-chips" aria-label="ตัวกรองทรัพย์สินที่กำลังใช้งาน">
                    <?php foreach ($assetActiveFilters as $chip): ?>
                        <span class="filter-chip"><?= e((string) $chip) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <details class="asset-filter-advanced"<?= $isAssetAdvancedFilterActive ? ' open' : '' ?>>
                <summary>
                    <span><?= lucide('filter', 'h-4 w-4') ?> ตัวกรองเพิ่มเติม</span>
                    <?php if ($isAssetAdvancedFilterActive): ?>
                        <span class="badge badge-info"><?= e((string) count($assetActiveFilters)) ?> ตัวกรอง</span>
                    <?php endif; ?>
                    <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                </summary>
                <div class="asset-filter-grid">
                    <div class="field-group">
                        <label class="field-label" for="asset-filter-category">หมวดหมู่</label>
                        <select id="asset-filter-category" name="category_id" class="input" aria-label="กรองตามหมวดหมู่ทรัพย์สิน">
                            <option value="">ทุกหมวดหมู่</option>
                            <?php foreach (($assetFilterOptions['categories'] ?? []) as $category): ?>
                                <option value="<?= e((string) $category['id']) ?>"<?= $assetCategoryId === (int) $category['id'] ? ' selected' : '' ?>><?= e((string) $category['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="asset-filter-location">สถานที่</label>
                        <select id="asset-filter-location" name="location_id" class="input" aria-label="กรองตามสถานที่">
                            <option value="">ทุกสถานที่</option>
                            <?php foreach (($assetFilterOptions['locations'] ?? []) as $location): ?>
                                <option value="<?= e((string) $location['id']) ?>"<?= $assetLocationId === (int) $location['id'] ? ' selected' : '' ?>><?= e((string) $location['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="asset-filter-status">สถานะ</label>
                        <select id="asset-filter-status" name="status" class="input" aria-label="กรองตามสถานะทรัพย์สิน">
                            <option value="">ทุกสถานะ</option>
                            <?php foreach (($assetFilterOptions['statuses'] ?? []) as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>"<?= $assetStatus === (string) $value ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </details>
        </form>

        <?php if ($assets === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'qr-code',
                'title' => $isAssetFilterActive ? 'ไม่พบทรัพย์สินตามเงื่อนไข' : 'ยังไม่มีทรัพย์สินในระบบ',
                'description' => $isAssetFilterActive ? 'ลองปรับคำค้นหรือล้างตัวกรองเพื่อดูทรัพย์สินที่เกี่ยวข้อง' : 'เพิ่มทรัพย์สินรายการแรกเพื่อสร้าง QR สำหรับสแกนแจ้งซ่อมได้ทันที',
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
