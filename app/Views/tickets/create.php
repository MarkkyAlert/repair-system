<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'แจ้งซ่อมใหม่',
        'title' => 'สร้างรายการแจ้งซ่อม',
        'description' => 'อธิบายอาการให้ชัด ทีมจะประเมินและเข้าซ่อมได้เร็วขึ้น',
        'actions' => render_partial('partials/components/button', ['label' => 'กลับหน้ารายการ', 'variant' => 'secondary', 'href' => '/tickets']),
    ]) ?>

    <div class="content-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ข้อมูลผู้แจ้ง</h2>
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('user', 'h-4 w-4') ?></span>
            </div>
            <dl class="detail-list">
                <dt>ชื่อ-นามสกุล</dt>
                <dd><?= e($form['defaults']['requester_name'] ?? '-') ?></dd>
                <dt>อีเมล</dt>
                <dd><?= e($form['defaults']['requester_email'] ?? '-') ?></dd>
            </dl>
            <p class="field-hint">ข้อมูลผู้แจ้งอ้างอิงจากบัญชีที่ล็อกอินอยู่</p>
        </section>

        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">หลังจากกดส่ง</h2>
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('zap', 'h-4 w-4') ?></span>
            </div>
            <ol class="ticket-flow-list">
                <li><span>1</span><div><strong>ระบบสร้าง Ticket</strong><span>พร้อมเลขอ้างอิงอัตโนมัติ</span></div></li>
                <li><span>2</span><div><strong>ส่งให้หัวหน้างานอนุมัติ</strong><span>เข้าคิว approval ของแผนกที่เกี่ยวข้อง</span></div></li>
                <li><span>3</span><div><strong>เริ่มนับ SLA</strong><span>มี activity log บันทึกทุกความเคลื่อนไหว</span></div></li>
            </ol>
        </section>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">กรอกข้อมูลปัญหา</h2>
                <p class="field-hint">ฟิลด์ที่มี <span class="required">*</span> จำเป็นต้องระบุ</p>
            </div>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="auth-alert auth-alert-danger">
                <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                <p><?= e((string) $errorMessage) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/tickets')) ?>" class="stack-lg">
            <?= csrf_field() ?>
            <input type="hidden" name="submission_token" value="<?= e((string) ($form['defaults']['submission_token'] ?? '')) ?>">

            <div class="field-group">
                <label for="title" class="field-label">หัวข้อปัญหา <span class="required">*</span></label>
                <input id="title" name="title" type="text" class="input" required maxlength="200" value="<?= e((string) ($form['defaults']['title'] ?? '')) ?>" placeholder="เช่น Router ห้อง Server มีอาการ packet loss">
                <p class="field-hint">เขียนสั้นๆ ให้ทีมเข้าใจปัญหาจาก list ได้ทันที</p>
            </div>

            <div class="field-group">
                <label for="description" class="field-label">รายละเอียด <span class="required">*</span></label>
                <textarea id="description" name="description" class="input" rows="6" required placeholder="อธิบายอาการ, สภาพแวดล้อม, ผลกระทบที่เกิดขึ้น และสิ่งที่ลองทำมาแล้ว"><?= e((string) ($form['defaults']['description'] ?? '')) ?></textarea>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="priority_id" class="field-label">ระดับความสำคัญ <span class="required">*</span></label>
                    <select id="priority_id" name="priority_id" class="input" required>
                        <option value="">เลือกระดับ</option>
                        <?php foreach (($form['priorities'] ?? []) as $priority): ?>
                            <option value="<?= e((string) $priority['id']) ?>"<?= (string) ($form['defaults']['priority_id'] ?? '') === (string) $priority['id'] ? ' selected' : '' ?>><?= e($priority['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label for="ticket_category_id" class="field-label">หมวดหมู่งาน <span class="required">*</span></label>
                    <select id="ticket_category_id" name="ticket_category_id" class="input" required>
                        <option value="">เลือกหมวดหมู่</option>
                        <?php foreach (($form['categories'] ?? []) as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>"<?= (string) ($form['defaults']['ticket_category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="location_id" class="field-label">สถานที่ <span class="required">*</span></label>
                    <select id="location_id" name="location_id" class="input" required>
                        <option value="">เลือกสถานที่</option>
                        <?php foreach (($form['locations'] ?? []) as $location): ?>
                            <option value="<?= e((string) $location['id']) ?>"<?= (string) ($form['defaults']['location_id'] ?? '') === (string) $location['id'] ? ' selected' : '' ?>><?= e($location['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label for="asset_id" class="field-label">อุปกรณ์/ทรัพย์สิน</label>
                    <select id="asset_id" name="asset_id" class="input">
                        <option value="">ไม่ระบุ</option>
                        <?php foreach (($form['assets'] ?? []) as $asset): ?>
                            <option value="<?= e((string) $asset['id']) ?>"<?= (string) ($form['defaults']['asset_id'] ?? '') === (string) $asset['id'] ? ' selected' : '' ?>><?= e($asset['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">ถ้าระบุได้ ช่างจะเข้าหน้างานได้เร็วขึ้น</p>
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="impact_level" class="field-label">ผลกระทบ (Impact)</label>
                    <select id="impact_level" name="impact_level" class="input">
                        <?php foreach (($form['impactOptions'] ?? []) as $option): ?>
                            <option value="<?= e($option['value']) ?>"<?= (string) ($form['defaults']['impact_level'] ?? 'medium') === (string) $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">ปัญหานี้กระทบกี่คน / กี่หน่วยงาน?</p>
                </div>

                <div class="field-group">
                    <label for="urgency_level" class="field-label">ความเร่งด่วน (Urgency)</label>
                    <select id="urgency_level" name="urgency_level" class="input">
                        <?php foreach (($form['urgencyOptions'] ?? []) as $option): ?>
                            <option value="<?= e($option['value']) ?>"<?= (string) ($form['defaults']['urgency_level'] ?? 'medium') === (string) $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">รอได้นานแค่ไหน? ยิ่งสูง = ต้องเข้าเร็ว</p>
                </div>
            </div>

            <div class="button-row">
                <?= render_partial('partials/components/button', [
                    'type' => 'submit',
                    'label' => 'ส่งคำขอแจ้งซ่อม',
                    'variant' => 'primary',
                    'icon' => 'send',
                    'iconPosition' => 'right',
                    'size' => 'lg',
                ]) ?>
                <?= render_partial('partials/components/button', [
                    'label' => 'ยกเลิก',
                    'variant' => 'ghost',
                    'href' => '/tickets',
                ]) ?>
            </div>
        </form>
    </section>
</section>
