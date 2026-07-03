    <section id="tab-locations" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการสถานที่</h2>
                <p class="field-hint">สถานที่ที่ใช้ในฟอร์ม Ticket และทะเบียนทรัพย์สิน</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($locations ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มสถานที่ใหม่</span>
                    <span class="collapsible-subtitle">สร้างจุดติดตั้งหรือพื้นที่สำหรับเลือกใน Ticket/ทรัพย์สิน</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/locations')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_location_code">รหัสสถานที่ <span class="required">*</span></label>
                            <input id="new_location_code" class="input" type="text" name="code" required placeholder="เช่น HQ-1F-REC">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_location_name">ชื่อสถานที่ <span class="required">*</span></label>
                            <input id="new_location_name" class="input" type="text" name="name" required placeholder="เช่น Reception Printer Area">
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_location_building">อาคาร</label>
                            <input id="new_location_building" class="input" type="text" name="building">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_location_floor">ชั้น</label>
                            <input id="new_location_floor" class="input" type="text" name="floor">
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new_location_room">ห้อง/โซน</label>
                        <input id="new_location_room" class="input" type="text" name="room">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new_location_desc">รายละเอียด</label>
                        <textarea id="new_location_desc" class="input" name="description" rows="3"></textarea>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>เปิดใช้งานสถานที่นี้ทันที</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เพิ่มสถานที่', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>
        <?php if (($locations ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'map-pin',
                'title' => 'ยังไม่มีสถานที่',
                'description' => 'เพิ่มสถานที่เพื่อให้ผู้ใช้เลือกตอนเปิด Ticket หรือบันทึกทรัพย์สิน',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($locations ?? []) as $location): ?>
                    <?php $locationId = (int) ($location['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon metric-icon-sm"><?= lucide('map-pin', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($location['name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle"><?= e((string) ($location['code'] ?? '-')) ?> · <?= e(trim((string) ($location['building'] ?? '') . ' ' . (string) ($location['floor'] ?? '') . ' ' . (string) ($location['room'] ?? '')) ?: '-') ?></span>
                            </div>
                            <div class="collapsible-meta">
                                <span class="badge badge-<?= !empty($location['is_active']) ? 'success' : 'default' ?>"><?= !empty($location['is_active']) ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/locations/' . $locationId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="location_code_<?= $locationId ?>">รหัสสถานที่ <span class="required">*</span></label>
                                        <input id="location_code_<?= $locationId ?>" class="input" type="text" name="code" required value="<?= e((string) ($location['code'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="location_name_<?= $locationId ?>">ชื่อสถานที่ <span class="required">*</span></label>
                                        <input id="location_name_<?= $locationId ?>" class="input" type="text" name="name" required value="<?= e((string) ($location['name'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="location_building_<?= $locationId ?>">อาคาร</label>
                                        <input id="location_building_<?= $locationId ?>" class="input" type="text" name="building" value="<?= e((string) ($location['building'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="location_floor_<?= $locationId ?>">ชั้น</label>
                                        <input id="location_floor_<?= $locationId ?>" class="input" type="text" name="floor" value="<?= e((string) ($location['floor'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="location_room_<?= $locationId ?>">ห้อง/โซน</label>
                                    <input id="location_room_<?= $locationId ?>" class="input" type="text" name="room" value="<?= e((string) ($location['room'] ?? '')) ?>">
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="location_desc_<?= $locationId ?>">รายละเอียด</label>
                                    <textarea id="location_desc_<?= $locationId ?>" class="input" name="description" rows="3"><?= e((string) ($location['description'] ?? '')) ?></textarea>
                                </div>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_active" value="1"<?= !empty($location['is_active']) ? ' checked' : '' ?>>
                                    <span>เปิดใช้งานสถานที่นี้</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                            <div class="delete-zone">
                                <form method="post" action="<?= e(url('/admin/locations/' . $locationId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบสถานที่นี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                    <?= csrf_field() ?>
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบสถานที่', 'variant' => 'danger', 'icon' => 'trash']) ?>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
