<section class="stack-lg">
    <h1 class="sr-only">แจ้งซ่อมใหม่ — กรอกข้อมูลปัญหาเพื่อเปิด Ticket</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'แจ้งซ่อมใหม่',
        'title' => 'แจ้งซ่อมใหม่',
        'description' => 'กรอกข้อมูลปัญหาให้ครบ เพื่อให้ทีมประเมิน จัดคิว และเริ่มดูแลได้เร็วขึ้น',
        'actions' => render_partial('partials/components/button', ['label' => 'กลับหน้ารายการ', 'variant' => 'secondary', 'href' => '/tickets']),
    ]) ?>

    <?php if (!empty($form['prefill']['source_ticket_id'])): ?>
        <div class="auth-alert auth-alert-info" role="status" aria-live="polite">
            <span class="auth-alert-icon"><?= lucide('copy', 'h-4 w-4') ?></span>
            <p>กำลังเตรียม Ticket ใหม่จาก <a href="<?= e(url('/tickets/' . (int) $form['prefill']['source_ticket_id'])) ?>"><strong><?= e((string) $form['prefill']['source_ticket_no']) ?></strong></a> โดยคัดลอกเฉพาะข้อมูลปัญหาเดิม</p>
        </div>
    <?php endif; ?>

    <div class="content-grid ticket-create-info-grid">
        <details class="panel-card stack-md ticket-create-info-card" open data-mobile-collapsible-info>
            <summary class="panel-head ticket-create-info-summary">
                <h2 class="panel-title">ข้อมูลผู้แจ้ง</h2>
                <span class="metric-icon metric-icon-sm ticket-create-info-icon"><?= lucide('user', 'h-4 w-4') ?></span>
                <span class="collapsible-chevron ticket-create-info-chevron" aria-hidden="true"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="ticket-create-info-body stack-md">
                <dl class="detail-list">
                    <dt>ชื่อ-นามสกุล</dt>
                    <dd><?= e($form['defaults']['requester_name'] ?? '-') ?></dd>
                    <dt>อีเมล</dt>
                    <dd><?= e($form['defaults']['requester_email'] ?? '-') ?></dd>
                </dl>
                <p class="field-hint">ข้อมูลผู้แจ้งอ้างอิงจากบัญชีที่ล็อกอินอยู่</p>
            </div>
        </details>

        <details class="panel-card stack-md ticket-create-info-card" open data-mobile-collapsible-info>
            <summary class="panel-head ticket-create-info-summary">
                <h2 class="panel-title">หลังจากกดส่ง</h2>
                <span class="metric-icon metric-icon-sm ticket-create-info-icon"><?= lucide('zap', 'h-4 w-4') ?></span>
                <span class="collapsible-chevron ticket-create-info-chevron" aria-hidden="true"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="ticket-create-info-body stack-md">
                <ol class="ticket-flow-list">
                    <li><span>1</span><div><strong>ระบบสร้าง Ticket</strong><span>พร้อมเลขอ้างอิงอัตโนมัติ</span></div></li>
                    <li><span>2</span><div><strong>ส่งให้หัวหน้างานอนุมัติ</strong><span>เข้าคิวอนุมัติของแผนกที่เกี่ยวข้อง</span></div></li>
                    <li><span>3</span><div><strong>เริ่มนับ SLA</strong><span>บันทึกประวัติทุกความเคลื่อนไหวของงาน</span></div></li>
                </ol>
            </div>
        </details>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">แจ้งซ่อมใหม่</h2>
                <p id="create-ticket-form-description" class="field-hint">กรอกข้อมูลที่จำเป็นให้ครบ เพื่อให้ทีมจัดคิว ประเมิน SLA และเริ่มดำเนินงานได้ทันที</p>
            </div>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="auth-alert auth-alert-danger" role="alert">
                <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                <p><?= e((string) $errorMessage) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/tickets')) ?>" class="stack-lg" enctype="multipart/form-data" aria-describedby="create-ticket-form-description" data-loading-submit data-warn-unsaved>
            <?= csrf_field() ?>
            <input type="hidden" name="submission_token" value="<?= e((string) ($form['defaults']['submission_token'] ?? '')) ?>">

            <div class="action-bar ticket-create-action-bar">
                <div class="action-bar-left">
                    <span class="metric-icon metric-icon-sm"><?= lucide('send', 'h-4 w-4') ?></span>
                    <div>
                        <strong>พร้อมส่งเมื่อกรอกข้อมูลจำเป็นครบ</strong>
                        <p class="helper-text">ระบบจะสร้าง Ticket และส่งเข้าสู่ขั้นตอนอนุมัติ</p>
                    </div>
                </div>
                <div class="action-bar-right">
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
            </div>

            <section class="ticket-form-section">
                <div class="ticket-form-section-head">
                    <div>
                        <h3>อาการและรายละเอียด</h3>
                        <p id="problem-section-help">สรุปปัญหาให้ชัด เพื่อให้ทีมประเมินและรับงานได้ถูกต้อง</p>
                    </div>
                    <span class="badge badge-danger">จำเป็น</span>
                </div>
                <div class="field-group">
                    <label for="title" class="field-label">หัวข้อปัญหา <span class="required">*</span></label>
                    <input id="title" name="title" type="text" class="input" required maxlength="200" value="<?= e((string) ($form['defaults']['title'] ?? '')) ?>" placeholder="เช่น อินเทอร์เน็ตห้อง Server หลุดบ่อย" aria-describedby="title-help">
                    <p id="title-help" class="field-hint">เขียนสั้น กระชับ และเห็นอาการจากรายการ Ticket ได้ทันที</p>
                </div>

                <div class="field-group">
                    <label for="description" class="field-label">รายละเอียด <span class="required">*</span></label>
                    <textarea id="description" name="description" class="input" rows="6" required placeholder="อธิบายอาการ สภาพแวดล้อม ผลกระทบที่เกิดขึ้น และสิ่งที่ลองทำมาแล้ว" aria-describedby="description-help"><?= e((string) ($form['defaults']['description'] ?? '')) ?></textarea>
                    <p id="description-help" class="field-hint">ใส่รายละเอียดที่ช่วยให้ทีมซ่อมเข้าใจบริบท เช่น เกิดเมื่อไร กระทบใครบ้าง และลองแก้อะไรแล้ว</p>
                </div>
            </section>

            <section class="ticket-form-section">
                <div class="ticket-form-section-head">
                    <div>
                        <h3>การจัดลำดับงาน</h3>
                        <p id="priority-section-help">เลือกความสำคัญและหมวดหมู่ เพื่อให้ทีมจัดคิวและคำนวณ SLA ได้ถูกต้อง</p>
                    </div>
                </div>
                <div class="ticket-form-grid">
                    <div class="field-group">
                        <label for="priority_id" class="field-label">
                            ระดับความสำคัญ <span class="required">*</span>
                            <button type="button" class="field-info-icon" data-info-toggle="priority-info" aria-expanded="false" aria-controls="priority-info" aria-label="ดูคำอธิบายระดับความสำคัญ">
                                <?= lucide('info', 'h-4 w-4') ?>
                            </button>
                        </label>
                        <select id="priority_id" name="priority_id" class="input" required aria-describedby="priority-help">
                            <option value="">เลือกระดับ</option>
                            <?php foreach (($form['priorities'] ?? []) as $priority): ?>
                                <option value="<?= e((string) $priority['id']) ?>"<?= (string) ($form['defaults']['priority_id'] ?? '') === (string) $priority['id'] ? ' selected' : '' ?>><?= e($priority['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="priority-info" class="field-info-popover" hidden>
                            <p><strong>ระดับความสำคัญ</strong> ใช้คำนวณ SLA และจัดลำดับงานในคิว</p>
                            <ul>
                                <li>เลือกระดับให้สอดคล้องกับ <em>ผลกระทบ × ความเร่งด่วน</em> ที่กรอกในส่วนล่าง</li>
                                <li>แต่ละระดับมีเวลา SLA ที่ admin ตั้งไว้ — ระบบจะเตือนเมื่อเกินกำหนด</li>
                                <li>ถ้าไม่แน่ใจ เลือก <em>Medium/ปานกลาง</em> ก่อน — manager สามารถปรับให้ได้ภายหลัง</li>
                            </ul>
                        </div>
                        <p id="priority-help" class="field-hint">เลือกระดับที่สะท้อนผลกระทบจริงของงานนี้</p>
                    </div>

                    <div class="field-group">
                        <label for="ticket_category_id" class="field-label">หมวดหมู่งาน <span class="required">*</span></label>
                        <select id="ticket_category_id" name="ticket_category_id" class="input" required aria-describedby="ticket-category-help">
                            <option value="">เลือกหมวดหมู่</option>
                            <?php foreach (($form['categories'] ?? []) as $category): ?>
                                <option value="<?= e((string) $category['id']) ?>"<?= (string) ($form['defaults']['ticket_category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="ticket-category-help" class="field-hint">ช่วยส่งงานไปยังกลุ่มดูแลที่เกี่ยวข้อง</p>
                    </div>
                </div>
            </section>

            <section class="ticket-form-section">
                <div class="ticket-form-section-head">
                    <div>
                        <h3>สถานที่และอุปกรณ์</h3>
                        <p id="location-section-help">ระบุตำแหน่งและทรัพย์สินที่เกี่ยวข้อง เพื่อให้ช่างเข้าหน้างานได้เร็วขึ้น</p>
                    </div>
                </div>
                <div class="ticket-form-grid">
                    <div class="field-group">
                        <label for="location_id" class="field-label">สถานที่ <span class="required">*</span></label>
                        <select id="location_id" name="location_id" class="input" required aria-describedby="location-help">
                            <option value="">เลือกสถานที่</option>
                            <?php foreach (($form['locations'] ?? []) as $location): ?>
                                <option value="<?= e((string) $location['id']) ?>"<?= (string) ($form['defaults']['location_id'] ?? '') === (string) $location['id'] ? ' selected' : '' ?>><?= e($location['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="location-help" class="field-hint">เลือกจุดที่เกิดปัญหาให้ใกล้เคียงที่สุด</p>
                    </div>

                    <div class="field-group">
                        <label for="asset_id" class="field-label">อุปกรณ์/ทรัพย์สิน</label>
                        <select id="asset_id" name="asset_id" class="input" aria-describedby="asset-help">
                            <option value="">ไม่ระบุ</option>
                            <?php foreach (($form['assets'] ?? []) as $asset): ?>
                                <option value="<?= e((string) $asset['id']) ?>"<?= (string) ($form['defaults']['asset_id'] ?? '') === (string) $asset['id'] ? ' selected' : '' ?>><?= e($asset['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="asset-help" class="field-hint">ถ้าระบุได้ ทีมจะตรวจประวัติอุปกรณ์และเตรียมอะไหล่ได้ดีขึ้น</p>
                    </div>
                </div>
            </section>

            <?php
            $impactCurrent = (string) ($form['defaults']['impact_level'] ?? 'medium');
            $urgencyCurrent = (string) ($form['defaults']['urgency_level'] ?? 'medium');
            $impactOpen = $impactCurrent !== 'medium' || $urgencyCurrent !== 'medium';
            ?>
            <details class="ticket-form-section ticket-form-section-collapsible"<?= $impactOpen ? ' open' : '' ?>>
                <summary class="ticket-form-section-head">
                    <div>
                        <h3>ผลกระทบและความเร่งด่วน</h3>
                        <p id="impact-section-help">ข้อมูลส่วนนี้ช่วยให้ทีมประเมินลำดับงานได้แม่นขึ้น (ไม่บังคับ)</p>
                    </div>
                    <span class="badge badge-default">ไม่บังคับ</span>
                    <span class="collapsible-chevron" aria-hidden="true"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                </summary>
                <div class="ticket-form-grid">
                    <div class="field-group">
                        <label for="impact_level" class="field-label">
                            ผลกระทบ (Impact)
                            <button type="button" class="field-info-icon" data-info-toggle="impact-info" aria-expanded="false" aria-controls="impact-info" aria-label="ดูคำอธิบายระดับ Impact">
                                <?= lucide('info', 'h-4 w-4') ?>
                            </button>
                        </label>
                        <select id="impact_level" name="impact_level" class="input" aria-describedby="impact-help">
                            <?php foreach (($form['impactOptions'] ?? []) as $option): ?>
                                <option value="<?= e($option['value']) ?>"<?= $impactCurrent === (string) $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="impact-info" class="field-info-popover" hidden>
                            <p><strong>Impact</strong> = ปัญหานี้กระทบใครบ้าง?</p>
                            <dl class="field-info-levels">
                                <dt>Low</dt><dd>คน 1 คน หรือเครื่องเดียว · ทำงานอย่างอื่นต่อได้</dd>
                                <dt>Medium</dt><dd>1 แผนก หรือผู้ใช้ 2-5 คน</dd>
                                <dt>High</dt><dd>หลายแผนก หรือผู้ใช้ 10+ คน</dd>
                                <dt>Critical</dt><dd>ทั้งองค์กร หรือบริการให้ลูกค้าสะดุด</dd>
                            </dl>
                        </div>
                        <p id="impact-help" class="field-hint">ปัญหานี้กระทบผู้ใช้กี่คนหรือกี่หน่วยงาน?</p>
                    </div>

                    <div class="field-group">
                        <label for="urgency_level" class="field-label">
                            ความเร่งด่วน (Urgency)
                            <button type="button" class="field-info-icon" data-info-toggle="urgency-info" aria-expanded="false" aria-controls="urgency-info" aria-label="ดูคำอธิบายระดับ Urgency">
                                <?= lucide('info', 'h-4 w-4') ?>
                            </button>
                        </label>
                        <select id="urgency_level" name="urgency_level" class="input" aria-describedby="urgency-help">
                            <?php foreach (($form['urgencyOptions'] ?? []) as $option): ?>
                                <option value="<?= e($option['value']) ?>"<?= $urgencyCurrent === (string) $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="urgency-info" class="field-info-popover" hidden>
                            <p><strong>Urgency</strong> = รอได้นานแค่ไหนก่อนต้องแก้?</p>
                            <dl class="field-info-levels">
                                <dt>Low</dt><dd>รอได้ 1+ สัปดาห์ · ไม่กระทบทันที</dd>
                                <dt>Medium</dt><dd>ควรแก้ภายใน 2-3 วัน</dd>
                                <dt>High</dt><dd>ต้องแก้ภายในวันนี้</dd>
                                <dt>Critical</dt><dd>ต้องแก้ภายในชั่วโมงนี้ — งานหยุดชะงัก</dd>
                            </dl>
                        </div>
                        <p id="urgency-help" class="field-hint">รอได้นานแค่ไหน? ค่ายิ่งสูง หมายถึงควรเข้าดำเนินการเร็วขึ้น</p>
                    </div>
                </div>
            </details>

            <section class="ticket-form-section">
                <div class="ticket-form-section-head">
                    <div>
                        <h3>รูปประกอบ</h3>
                        <p id="attachments-section-help">แนบรูปอาการเสียเพื่อช่วยให้ทีมตรวจสอบก่อนเข้าหน้างาน</p>
                    </div>
                    <span class="badge badge-default">ไม่บังคับ</span>
                </div>
                <div class="field-group">
                    <label for="attachments" class="field-label">รูปอาการเสีย</label>
                    <input id="attachments" name="attachments[]" type="file" class="input" accept="image/jpeg,image/png,image/webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,.pdf,.doc,.docx,.xls,.xlsx,.txt" multiple aria-describedby="attachments-help attachments-status" data-ticket-attachment-input>
                    <p id="attachments-help" class="field-hint">รองรับรูปภาพ (JPEG/PNG/WebP) และเอกสาร (PDF/Word/Excel/Text) สูงสุด 3 ไฟล์ ไฟล์ละไม่เกิน 5MB</p>
                    <div class="attachment-preview" data-ticket-attachment-root>
                        <p id="attachments-status" class="attachment-preview-status" role="status" aria-live="polite" data-ticket-attachment-status>ยังไม่ได้เลือกรูป</p>
                        <div class="attachment-preview-grid" role="list" aria-label="ตัวอย่างรูปที่เลือก" data-ticket-attachment-preview hidden></div>
                    </div>
                </div>
            </section>
        </form>
    </section>
</section>
