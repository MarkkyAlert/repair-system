    <section id="tab-categories" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ตั้งค่าหมวดหมู่และ SLA</h2>
                <p class="field-hint">กำหนดเวลาตอบรับและเวลาแก้ไขของแต่ละประเภทงาน (หน่วย: ชั่วโมง)</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($categories ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มหมวดหมู่งานใหม่</span>
                    <span class="collapsible-subtitle">สร้างประเภทงานซ่อมพร้อม SLA override</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/categories')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_cat_code">รหัสหมวด <span class="required">*</span></label>
                            <input id="new_cat_code" class="input" type="text" name="code" required placeholder="เช่น AC">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_cat_name">ชื่อหมวด <span class="required">*</span></label>
                            <input id="new_cat_name" class="input" type="text" name="name" required placeholder="เช่น ระบบปรับอากาศ">
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new_cat_desc">รายละเอียด</label>
                        <textarea id="new_cat_desc" class="input" name="description" rows="3"></textarea>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_cat_sort">ลำดับการแสดง</label>
                            <input id="new_cat_sort" class="input" type="number" min="1" name="sort_order" value="1">
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_cat_resp">เวลาตอบกลับ (ชม.)</label>
                            <input id="new_cat_resp" class="input" type="number" min="0" step="0.25" name="response_hours" value="0">
                            <p class="field-hint">ใส่ 0 เพื่อให้ระบบใช้ SLA จาก priority ตามปกติ</p>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_cat_reso">เวลาแก้ไข (ชม.)</label>
                            <input id="new_cat_reso" class="input" type="number" min="0" step="0.25" name="resolution_hours" value="0">
                            <p class="field-hint">ใส่ 0 เพื่อให้ระบบใช้ SLA จาก priority ตามปกติ</p>
                        </div>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>เปิดใช้งานหมวดนี้ทันที</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เพิ่มหมวดหมู่งาน', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>
        <?php if (($categories ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'tag',
                'title' => 'ยังไม่มีหมวดหมู่งาน',
                'description' => 'เมื่อมีหมวดหมู่ในระบบจะปรากฏที่นี่ พร้อมให้แก้ SLA override ได้',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($categories ?? []) as $category): ?>
                    <?php $catId = (int) ($category['id'] ?? 0); ?>
                    <?php $sla = $categorySla[$catId] ?? ['response_hours' => 0, 'resolution_hours' => 0]; ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon metric-icon-sm"><?= lucide('tag', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($category['name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle"><?= e((string) ($category['code'] ?? '-')) ?> · ตอบใน <?= e((string) ($sla['response_hours'] ?? 0)) ?> ชม. · แก้ใน <?= e((string) ($sla['resolution_hours'] ?? 0)) ?> ชม.</span>
                            </div>
                            <div class="collapsible-meta">
                                <span class="badge badge-<?= !empty($category['is_active']) ? 'success' : 'default' ?>"><?= !empty($category['is_active']) ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/categories/' . $catId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="cat_code_<?= $catId ?>">รหัสหมวด <span class="required">*</span></label>
                                        <input id="cat_code_<?= $catId ?>" class="input" type="text" name="code" required value="<?= e((string) ($category['code'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="cat_name_<?= $catId ?>">ชื่อหมวด <span class="required">*</span></label>
                                        <input id="cat_name_<?= $catId ?>" class="input" type="text" name="name" required value="<?= e((string) ($category['name'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="cat_desc_<?= $catId ?>">รายละเอียด</label>
                                    <textarea id="cat_desc_<?= $catId ?>" class="input" name="description" rows="3"><?= e((string) ($category['description'] ?? '')) ?></textarea>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="cat_sort_<?= $catId ?>">ลำดับการแสดง</label>
                                        <input id="cat_sort_<?= $catId ?>" class="input" type="number" min="1" name="sort_order" value="<?= e((string) ($category['sort_order'] ?? 1)) ?>">
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="cat_resp_<?= $catId ?>">เวลาตอบกลับ (ชม.)</label>
                                        <input id="cat_resp_<?= $catId ?>" class="input" type="number" min="0" step="0.25" name="response_hours" value="<?= e((string) ($sla['response_hours'] ?? 0)) ?>">
                                        <p class="field-hint">ระยะเวลาสูงสุดที่ช่างต้องตอบรับงาน</p>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="cat_reso_<?= $catId ?>">เวลาแก้ไข (ชม.)</label>
                                        <input id="cat_reso_<?= $catId ?>" class="input" type="number" min="0" step="0.25" name="resolution_hours" value="<?= e((string) ($sla['resolution_hours'] ?? 0)) ?>">
                                        <p class="field-hint">ระยะเวลาสูงสุดที่งานต้องแก้เสร็จ</p>
                                    </div>
                                </div>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_active" value="1"<?= !empty($category['is_active']) ? ' checked' : '' ?>>
                                    <span>เปิดใช้งานหมวดนี้</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                            <div class="delete-zone">
                                <form method="post" action="<?= e(url('/admin/categories/' . $catId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบหมวดหมู่งานนี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                    <?= csrf_field() ?>
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบหมวดหมู่งาน', 'variant' => 'danger', 'icon' => 'trash']) ?>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
