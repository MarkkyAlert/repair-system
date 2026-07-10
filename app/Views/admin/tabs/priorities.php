    <section id="tab-priorities" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการความสำคัญและ SLA</h2>
                <p class="field-hint">สร้าง/แก้/ลบระดับความสำคัญและเวลา SLA · ลบได้เฉพาะระดับที่ยังไม่ถูกใช้กับ ticket</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($priorities ?? [])) ?> ระดับ</span>
        </div>

        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มระดับความสำคัญใหม่</span>
                    <span class="collapsible-subtitle">กำหนดรหัส, ระดับ, ชื่อ, สี และ SLA เริ่มต้น</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/priorities')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_priority_code">รหัสความสำคัญ <span class="required">*</span></label>
                            <input id="new_priority_code" class="input" type="text" name="code" required maxlength="50" placeholder="เช่น EMERGENCY">
                            <p class="field-hint">A-Z, 0-9, ขีดกลาง, ขีดล่าง · ความยาว 2-50 ตัว</p>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_priority_level">ระดับ (1-99) <span class="required">*</span></label>
                            <input id="new_priority_level" class="input" type="number" name="level" required min="1" max="99" value="5">
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_priority_name">ชื่อความสำคัญ <span class="required">*</span></label>
                            <input id="new_priority_name" class="input" type="text" name="name" required maxlength="100">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_priority_color">สี</label>
                            <select id="new_priority_color" class="input" name="color">
                                <?php foreach (['slate', 'sky', 'amber', 'rose', 'emerald', 'violet'] as $color): ?>
                                    <option value="<?= e($color) ?>"><?= e($color) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_priority_response">เวลาตอบกลับ (ชม.)</label>
                            <input id="new_priority_response" class="input" type="number" min="0" step="0.25" name="response_hours" value="1">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_priority_resolution">เวลาแก้ไข (ชม.)</label>
                            <input id="new_priority_resolution" class="input" type="number" min="0" step="0.25" name="resolution_hours" value="8">
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_priority_sort">ลำดับการแสดง</label>
                            <input id="new_priority_sort" class="input" type="number" min="1" name="sort_order" value="1">
                        </div>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>เปิดใช้งานทันที</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เพิ่มความสำคัญ', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>

        <?php if (($priorities ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clock',
                'title' => 'ยังไม่มีระดับความสำคัญ',
                'description' => 'ควร seed priority พื้นฐานก่อนใช้งานระบบ Ticket',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($priorities ?? []) as $priority): ?>
                    <?php $priorityId = (int) ($priority['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon metric-icon-sm"><?= lucide('clock', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($priority['name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle"><?= e((string) ($priority['code'] ?? '-')) ?> · ระดับ <?= e((string) ($priority['level'] ?? '-')) ?> · ตอบใน <?= e((string) ($priority['response_hours'] ?? 0)) ?> ชม. · แก้ใน <?= e((string) ($priority['resolution_hours'] ?? 0)) ?> ชม.</span>
                            </div>
                            <div class="collapsible-meta">
                                <span class="badge badge-<?= !empty($priority['is_active']) ? 'success' : 'default' ?>"><?= !empty($priority['is_active']) ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></span>
                                <span class="badge badge-default"><?= e((string) ($priority['color'] ?? 'slate')) ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/priorities/' . $priorityId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="priority_code_<?= $priorityId ?>">รหัส (เปลี่ยนไม่ได้)</label>
                                        <input id="priority_code_<?= $priorityId ?>" class="input" type="text" value="<?= e((string) ($priority['code'] ?? '')) ?>" disabled>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="priority_level_<?= $priorityId ?>">ระดับ (เปลี่ยนไม่ได้)</label>
                                        <input id="priority_level_<?= $priorityId ?>" class="input" type="text" value="<?= e((string) ($priority['level'] ?? '')) ?>" disabled>
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="priority_name_<?= $priorityId ?>">ชื่อความสำคัญ <span class="required">*</span></label>
                                        <input id="priority_name_<?= $priorityId ?>" class="input" type="text" name="name" required value="<?= e((string) ($priority['name'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="priority_color_<?= $priorityId ?>">สี</label>
                                        <select id="priority_color_<?= $priorityId ?>" class="input" name="color">
                                            <?php foreach (['slate', 'sky', 'amber', 'rose', 'emerald', 'violet'] as $color): ?>
                                                <option value="<?= e($color) ?>"<?= (string) ($priority['color'] ?? '') === $color ? ' selected' : '' ?>><?= e($color) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="priority_response_<?= $priorityId ?>">เวลาตอบกลับ (ชม.)</label>
                                        <input id="priority_response_<?= $priorityId ?>" class="input" type="number" min="0" step="0.25" name="response_hours" value="<?= e((string) ($priority['response_hours'] ?? 0)) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="priority_resolution_<?= $priorityId ?>">เวลาแก้ไข (ชม.)</label>
                                        <input id="priority_resolution_<?= $priorityId ?>" class="input" type="number" min="0" step="0.25" name="resolution_hours" value="<?= e((string) ($priority['resolution_hours'] ?? 0)) ?>">
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="priority_sort_<?= $priorityId ?>">ลำดับการแสดง</label>
                                        <input id="priority_sort_<?= $priorityId ?>" class="input" type="number" min="1" name="sort_order" value="<?= e((string) ($priority['sort_order'] ?? 1)) ?>">
                                    </div>
                                </div>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_active" value="1"<?= !empty($priority['is_active']) ? ' checked' : '' ?>>
                                    <span>เปิดใช้งานความสำคัญนี้ในฟอร์ม Ticket</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกความสำคัญ/SLA', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                            <div class="delete-zone">
                                <form method="post" action="<?= e(url('/admin/priorities/' . $priorityId . '/delete')) ?>" class="button-row" data-confirm-submit="ยืนยันการลบระดับความสำคัญนี้? ลบได้เฉพาะระดับที่ยังไม่ถูกใช้กับ ticket หากถูกใช้งานแล้วให้ปิดใช้งานแทน">
                                    <?= csrf_field() ?>
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบความสำคัญ', 'variant' => 'danger', 'icon' => 'trash']) ?>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
