    <section id="tab-asset-categories" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการหมวดหมู่ทรัพย์สิน</h2>
                <p class="field-hint">ประเภททรัพย์สินที่ใช้ในฟอร์มเพิ่มและแก้ไขทรัพย์สิน</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($assetCategories ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มหมวดหมู่ทรัพย์สินใหม่</span>
                    <span class="collapsible-subtitle">สร้างประเภททรัพย์สินสำหรับทะเบียนทรัพย์สิน</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/asset-categories')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_asset_cat_code">รหัสหมวด <span class="required">*</span></label>
                            <input id="new_asset_cat_code" class="input" type="text" name="code" required placeholder="เช่น LAPTOP">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_asset_cat_name">ชื่อหมวด <span class="required">*</span></label>
                            <input id="new_asset_cat_name" class="input" type="text" name="name" required placeholder="เช่น Notebook">
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new_asset_cat_desc">รายละเอียด</label>
                        <textarea id="new_asset_cat_desc" class="input" name="description" rows="3"></textarea>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_asset_cat_sort">ลำดับการแสดง</label>
                            <input id="new_asset_cat_sort" class="input" type="number" min="1" name="sort_order" value="1">
                        </div>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>เปิดใช้งานหมวดนี้ทันที</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เพิ่มหมวดหมู่ทรัพย์สิน', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>
        <?php if (($assetCategories ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'layers',
                'title' => 'ยังไม่มีหมวดหมู่ทรัพย์สิน',
                'description' => 'เมื่อมีหมวดหมู่ทรัพย์สิน รายการจะปรากฏที่นี่ พร้อมให้แก้ไขหรือปิดใช้งานได้',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($assetCategories ?? []) as $assetCategory): ?>
                    <?php $assetCatId = (int) ($assetCategory['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon metric-icon-sm"><?= lucide('layers', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($assetCategory['name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle"><?= e((string) ($assetCategory['code'] ?? '-')) ?> · ลำดับ <?= e((string) ($assetCategory['sort_order'] ?? 1)) ?></span>
                            </div>
                            <div class="collapsible-meta">
                                <span class="badge badge-<?= !empty($assetCategory['is_active']) ? 'success' : 'default' ?>"><?= !empty($assetCategory['is_active']) ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/asset-categories/' . $assetCatId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="asset_cat_code_<?= $assetCatId ?>">รหัสหมวด <span class="required">*</span></label>
                                        <input id="asset_cat_code_<?= $assetCatId ?>" class="input" type="text" name="code" required value="<?= e((string) ($assetCategory['code'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="asset_cat_name_<?= $assetCatId ?>">ชื่อหมวด <span class="required">*</span></label>
                                        <input id="asset_cat_name_<?= $assetCatId ?>" class="input" type="text" name="name" required value="<?= e((string) ($assetCategory['name'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="asset_cat_desc_<?= $assetCatId ?>">รายละเอียด</label>
                                    <textarea id="asset_cat_desc_<?= $assetCatId ?>" class="input" name="description" rows="3"><?= e((string) ($assetCategory['description'] ?? '')) ?></textarea>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="asset_cat_sort_<?= $assetCatId ?>">ลำดับการแสดง</label>
                                        <input id="asset_cat_sort_<?= $assetCatId ?>" class="input" type="number" min="1" name="sort_order" value="<?= e((string) ($assetCategory['sort_order'] ?? 1)) ?>">
                                    </div>
                                </div>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_active" value="1"<?= !empty($assetCategory['is_active']) ? ' checked' : '' ?>>
                                    <span>เปิดใช้งานหมวดนี้</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                            <div class="delete-zone">
                                <form method="post" action="<?= e(url('/admin/asset-categories/' . $assetCatId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบหมวดหมู่ทรัพย์สินนี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                    <?= csrf_field() ?>
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบหมวดหมู่ทรัพย์สิน', 'variant' => 'danger', 'icon' => 'trash']) ?>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
