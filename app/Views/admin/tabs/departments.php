    <section id="tab-departments" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <h2 class="panel-title">จัดการแผนก</h2>
            <span class="badge badge-info"><?= e((string) count($departments ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มแผนกใหม่</span>
                    <span class="collapsible-subtitle">สร้างหน่วยงานสำหรับผูกกับผู้ใช้, ทรัพย์สิน และรายงาน</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/departments')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_dep_code">รหัสแผนก <span class="required">*</span></label>
                            <input id="new_dep_code" class="input" type="text" name="code" required placeholder="เช่น IT">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_dep_name">ชื่อแผนก <span class="required">*</span></label>
                            <input id="new_dep_name" class="input" type="text" name="name" required placeholder="เช่น ฝ่ายเทคโนโลยีสารสนเทศ">
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new_dep_desc">รายละเอียด</label>
                        <textarea id="new_dep_desc" class="input" name="description" rows="3"></textarea>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>เปิดใช้งานแผนกนี้ทันที</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เพิ่มแผนก', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>
        <?php if (($departments ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'building',
                'title' => 'ยังไม่มีข้อมูลแผนก',
                'description' => 'เมื่อมีข้อมูล master ของแผนก รายการจะปรากฏที่นี่',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($departments ?? []) as $department): ?>
                    <?php $depId = (int) ($department['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon metric-icon-sm"><?= lucide('building', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($department['name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle">รหัส: <?= e((string) ($department['code'] ?? '-')) ?></span>
                            </div>
                            <div class="collapsible-meta">
                                <span class="badge badge-<?= !empty($department['is_active']) ? 'success' : 'default' ?>"><?= !empty($department['is_active']) ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/departments/' . $depId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="dep_code_<?= $depId ?>">รหัสแผนก <span class="required">*</span></label>
                                        <input id="dep_code_<?= $depId ?>" class="input" type="text" name="code" required value="<?= e((string) ($department['code'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="dep_name_<?= $depId ?>">ชื่อแผนก <span class="required">*</span></label>
                                        <input id="dep_name_<?= $depId ?>" class="input" type="text" name="name" required value="<?= e((string) ($department['name'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="dep_desc_<?= $depId ?>">รายละเอียด</label>
                                    <textarea id="dep_desc_<?= $depId ?>" class="input" name="description" rows="3"><?= e((string) ($department['description'] ?? '')) ?></textarea>
                                </div>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_active" value="1"<?= !empty($department['is_active']) ? ' checked' : '' ?>>
                                    <span>เปิดใช้งานแผนกนี้</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                            <div class="delete-zone">
                                <form method="post" action="<?= e(url('/admin/departments/' . $depId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบแผนกนี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                    <?= csrf_field() ?>
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบแผนก', 'variant' => 'danger', 'icon' => 'trash']) ?>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
