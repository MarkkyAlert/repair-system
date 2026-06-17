<section class="stack-lg">
    <section class="panel-card">
        <div class="panel-head">
            <h2 class="panel-title">เพิ่มทรัพย์สินใหม่</h2>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับไปหน้าทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
            </div>
        </div>
        <p id="asset-form-help" class="body-text">กรอกข้อมูลพื้นฐานของทรัพย์สิน ระบบจะสร้าง QR สำหรับสแกนแจ้งซ่อมให้อัตโนมัติหลังบันทึก</p>
        <div class="asset-qr-preview-card">
            <div class="asset-qr-preview-box" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
                <span></span>
            </div>
            <div class="asset-qr-preview-copy">
                <strong>หลังบันทึก ระบบจะสร้าง QR สำหรับติดบนอุปกรณ์</strong>
                <p>ผู้ใช้สามารถสแกน QR เพื่อเปิดหน้าแจ้งซ่อมของทรัพย์สินนี้ได้ทันที</p>
            </div>
        </div>
    </section>

    <section class="panel-card stack-md">
        <?php if (!empty($errorMessage)): ?>
            <div class="auth-alert auth-alert-danger" role="alert">
                <span class="auth-alert-icon" aria-hidden="true">!</span>
                <div>
                    <strong>ไม่สามารถบันทึกทรัพย์สินได้</strong>
                    <p><?= e((string) $errorMessage) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/asset-registry')) ?>" class="stack-lg" aria-describedby="asset-form-help">
            <?= csrf_field() ?>

            <div class="action-bar asset-create-action-bar">
                <div class="action-bar-left">
                    <div>
                        <strong>พร้อมบันทึกเมื่อกรอกข้อมูลจำเป็นครบ</strong>
                        <p class="helper-text">ระบบจะสร้าง QR สำหรับสแกนแจ้งซ่อมให้อัตโนมัติ</p>
                    </div>
                </div>
                <div class="action-bar-right">
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกทรัพย์สิน', 'variant' => 'primary']) ?>
                    <?= render_partial('partials/components/button', ['label' => 'ยกเลิก', 'variant' => 'secondary', 'href' => '/asset-registry']) ?>
                </div>
            </div>

            <section class="asset-form-section" aria-labelledby="asset-main-section-title">
                <div class="asset-form-section-head">
                    <div>
                        <h3 id="asset-main-section-title">ข้อมูลหลัก</h3>
                        <p>ระบุรหัส ชื่อ และหมายเลขเครื่องเพื่อใช้ค้นหาและอ้างอิงในงานซ่อม</p>
                    </div>
                </div>
                <div class="asset-form-grid">
                    <div class="field-group">
                        <label for="asset_code" class="field-label">รหัสทรัพย์สิน <span class="field-required" aria-hidden="true">*</span><span class="sr-only">จำเป็นต้องกรอก</span></label>
                        <input id="asset_code" name="asset_code" type="text" class="input" maxlength="60" placeholder="เช่น AST-AC-0001" required value="<?= e((string) ($form['defaults']['asset_code'] ?? '')) ?>" aria-describedby="asset-code-help">
                        <p id="asset-code-help" class="field-hint">ตัวอย่าง: AST-AC-0001, AST-PRN-0002, AST-RTR-0003 ถ้าไม่แน่ใจ ให้ใช้รหัสตามป้ายทรัพย์สินขององค์กร</p>
                    </div>
                    <div class="field-group">
                        <label for="name" class="field-label">ชื่อทรัพย์สิน <span class="field-required" aria-hidden="true">*</span><span class="sr-only">จำเป็นต้องกรอก</span></label>
                        <input id="name" name="name" type="text" class="input" maxlength="200" required value="<?= e((string) ($form['defaults']['name'] ?? '')) ?>" aria-describedby="asset-name-help">
                        <p id="asset-name-help" class="field-hint">ใช้ชื่อที่ทีมหน้างานเข้าใจง่าย เช่น เครื่องปรับอากาศห้องประชุม</p>
                    </div>
                    <div class="field-group">
                        <label for="serial_number" class="field-label">หมายเลขเครื่อง / Serial</label>
                        <input id="serial_number" name="serial_number" type="text" class="input" value="<?= e((string) ($form['defaults']['serial_number'] ?? '')) ?>" aria-describedby="serial-number-help">
                        <p id="serial-number-help" class="field-hint">กรอกหมายเลขจากป้ายเครื่องหรือเอกสารซื้อ เพื่อช่วยตรวจสอบประกันและอะไหล่</p>
                    </div>
                </div>
            </section>

            <section class="asset-form-section" aria-labelledby="asset-location-section-title">
                <div class="asset-form-section-head">
                    <div>
                        <h3 id="asset-location-section-title">หมวดหมู่และสถานที่</h3>
                        <p>ช่วยให้ผู้ใช้เลือกทรัพย์สินได้เร็วขึ้นเมื่อแจ้งซ่อมจากสถานที่จริง</p>
                    </div>
                </div>
                <div class="asset-form-grid">
                    <div class="field-group">
                        <label for="asset_category_id" class="field-label">หมวดหมู่ <span class="field-required" aria-hidden="true">*</span><span class="sr-only">จำเป็นต้องกรอก</span></label>
                        <select id="asset_category_id" name="asset_category_id" class="input" required aria-describedby="asset-category-help">
                            <option value="">เลือกหมวดหมู่</option>
                            <?php foreach (($form['categories'] ?? []) as $category): ?>
                                <option value="<?= e((string) $category['id']) ?>"<?= (string) ($form['defaults']['asset_category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="asset-category-help" class="field-hint">ใช้จัดกลุ่มทรัพย์สิน เช่น เครื่องปรับอากาศ ระบบเครือข่าย หรือเครื่องพิมพ์</p>
                    </div>
                    <div class="field-group">
                        <label for="department_id" class="field-label">แผนก</label>
                        <select id="department_id" name="department_id" class="input" aria-describedby="department-help">
                            <option value="">ไม่ระบุแผนก</option>
                            <?php foreach (($form['departments'] ?? []) as $department): ?>
                                <option value="<?= e((string) $department['id']) ?>"<?= (string) ($form['defaults']['department_id'] ?? '') === (string) $department['id'] ? ' selected' : '' ?>><?= e($department['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="department-help" class="field-hint">ระบุแผนกเจ้าของทรัพย์สินหรือแผนกที่ใช้งานหลัก</p>
                    </div>
                    <div class="field-group">
                        <label for="location_id" class="field-label">สถานที่ <span class="field-required" aria-hidden="true">*</span><span class="sr-only">จำเป็นต้องกรอก</span></label>
                        <select id="location_id" name="location_id" class="input" required aria-describedby="location-help">
                            <option value="">เลือกสถานที่</option>
                            <?php foreach (($form['locations'] ?? []) as $location): ?>
                                <option value="<?= e((string) $location['id']) ?>"<?= (string) ($form['defaults']['location_id'] ?? '') === (string) $location['id'] ? ' selected' : '' ?>><?= e($location['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="location-help" class="field-hint">ใช้แสดงตำแหน่งติดตั้งบนหน้าแจ้งซ่อมและ QR ของทรัพย์สิน</p>
                    </div>
                </div>
            </section>

            <section class="asset-form-section" aria-labelledby="asset-owner-section-title">
                <div class="asset-form-section-head">
                    <div>
                        <h3 id="asset-owner-section-title">ผู้ดูแลและสถานะ</h3>
                        <p>กำหนดคนรับผิดชอบและสถานะปัจจุบันของทรัพย์สิน</p>
                    </div>
                </div>
                <div class="asset-form-grid">
                    <div class="field-group">
                        <label for="custodian_user_id" class="field-label">ผู้ดูแล</label>
                        <select id="custodian_user_id" name="custodian_user_id" class="input" aria-describedby="custodian-help">
                            <option value="">ไม่ระบุผู้ดูแล</option>
                            <?php foreach (($form['custodians'] ?? []) as $custodian): ?>
                                <option value="<?= e((string) $custodian['id']) ?>"<?= (string) ($form['defaults']['custodian_user_id'] ?? '') === (string) $custodian['id'] ? ' selected' : '' ?>><?= e($custodian['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="custodian-help" class="field-hint">เลือกผู้รับผิดชอบหลัก เพื่อให้ทีมรู้ว่าใครดูแลทรัพย์สินนี้</p>
                    </div>
                    <div class="field-group">
                        <label for="status" class="field-label">สถานะ</label>
                        <select id="status" name="status" class="input" aria-describedby="asset-status-help">
                            <?php foreach (($form['statusOptions'] ?? []) as $option): ?>
                                <option value="<?= e($option['value']) ?>"<?= (string) ($form['defaults']['status'] ?? 'active') === (string) $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="asset-status-help" class="field-hint">ใช้บอกว่าสินทรัพย์พร้อมใช้งาน อยู่ระหว่างซ่อม หรือเลิกใช้งานแล้ว</p>
                    </div>
                </div>
            </section>

            <section class="asset-form-section" aria-labelledby="asset-purchase-section-title">
                <div class="asset-form-section-head">
                    <div>
                        <h3 id="asset-purchase-section-title">ข้อมูลอุปกรณ์และประกัน</h3>
                        <p>ข้อมูลเหล่านี้ช่วยทีมตรวจสอบรุ่น อะไหล่ ผู้ขาย และวันหมดประกัน</p>
                    </div>
                </div>
                <div class="asset-form-grid">
                    <div class="field-group">
                        <label for="brand" class="field-label">ยี่ห้อ</label>
                        <input id="brand" name="brand" type="text" class="input" value="<?= e((string) ($form['defaults']['brand'] ?? '')) ?>" aria-describedby="brand-help">
                        <p id="brand-help" class="field-hint">ระบุยี่ห้อของอุปกรณ์ ถ้ามี</p>
                    </div>
                    <div class="field-group">
                        <label for="model" class="field-label">รุ่น</label>
                        <input id="model" name="model" type="text" class="input" value="<?= e((string) ($form['defaults']['model'] ?? '')) ?>" aria-describedby="model-help">
                        <p id="model-help" class="field-hint">ระบุรุ่นหรือรหัสรุ่นเพื่อช่วยค้นหาอะไหล่และคู่มือ</p>
                    </div>
                    <div class="field-group">
                        <label for="vendor" class="field-label">ผู้ขาย / ผู้ให้บริการ</label>
                        <input id="vendor" name="vendor" type="text" class="input" value="<?= e((string) ($form['defaults']['vendor'] ?? '')) ?>" aria-describedby="vendor-help">
                        <p id="vendor-help" class="field-hint">ใช้ติดต่อผู้ขายเมื่อต้องเคลมประกันหรือขอเอกสารเพิ่มเติม</p>
                    </div>
                    <div class="field-group">
                        <label for="purchase_date" class="field-label">วันที่ซื้อ</label>
                        <input id="purchase_date" name="purchase_date" type="date" class="input" value="<?= e((string) ($form['defaults']['purchase_date'] ?? '')) ?>" aria-describedby="purchase-date-help">
                        <p id="purchase-date-help" class="field-hint">ระบุวันที่ซื้อเพื่อใช้ดูอายุการใช้งานของทรัพย์สิน</p>
                    </div>
                    <div class="field-group">
                        <label for="warranty_expires_at" class="field-label">วันหมดประกัน</label>
                        <input id="warranty_expires_at" name="warranty_expires_at" type="date" class="input" value="<?= e((string) ($form['defaults']['warranty_expires_at'] ?? '')) ?>" aria-describedby="warranty-help">
                        <p id="warranty-help" class="field-hint">ช่วยให้ทีมรู้ว่าควรซ่อมเองหรือส่งเคลมประกัน</p>
                    </div>
                </div>
            </section>

            <section class="asset-form-section" aria-labelledby="asset-notes-section-title">
                <div class="asset-form-section-head">
                    <div>
                        <h3 id="asset-notes-section-title">หมายเหตุ</h3>
                        <p>บันทึกข้อมูลเพิ่มเติมที่ช่วยให้ทีมดูแลทรัพย์สินนี้ได้ต่อเนื่อง</p>
                    </div>
                </div>
                <div class="field-group">
                    <label for="notes" class="field-label">หมายเหตุเพิ่มเติม</label>
                    <textarea id="notes" name="notes" class="input" rows="4" aria-describedby="notes-help"><?= e((string) ($form['defaults']['notes'] ?? '')) ?></textarea>
                    <p id="notes-help" class="field-hint">เช่น รอบบำรุงรักษา ข้อควรระวัง หรือข้อมูลการติดตั้งเฉพาะจุด</p>
                </div>
            </section>
        </form>
    </section>
</section>
