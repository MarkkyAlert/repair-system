    <section id="tab-settings" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">การตั้งค่ากลางของระบบ</h2>
                <p class="field-hint">ตั้งค่าพื้นฐานด้วยฟอร์มอ่านง่าย เพื่อลดการแก้ JSON หรือคีย์/ค่าดิบผิดพลาด</p>
            </div>
        </div>

        <?php $systemForm = $systemSettingForm ?? []; ?>
        <div class="panel-card panel-card-sky stack-md">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title panel-title-lg">ตั้งค่าระบบหลัก</h3>
                    <p class="field-hint">ชื่อระบบ, เขตเวลา, คำนำหน้า Ticket และเวลาทำการ</p>
                </div>
            </div>
            <form method="post" action="<?= e(url('/admin/system-settings')) ?>" class="stack-md">
                <?= csrf_field() ?>
                <div class="content-grid">
                    <div class="field-group">
                        <label class="field-label" for="system_app_name">ชื่อระบบ <span class="required">*</span></label>
                        <input id="system_app_name" class="input" type="text" name="app_name" required value="<?= e((string) ($systemForm['app_name'] ?? 'Repair System')) ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="system_timezone">เขตเวลา <span class="required">*</span></label>
                        <select id="system_timezone" class="input" name="default_timezone" required>
                            <?php foreach (($systemForm['timezoneOptions'] ?? [['value' => 'Asia/Bangkok', 'label' => 'Asia/Bangkok']]) as $option): ?>
                                <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($systemForm['default_timezone'] ?? 'Asia/Bangkok') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="content-grid">
                    <div class="field-group">
                        <label class="field-label" for="system_app_tagline">คำโปรยใต้ชื่อระบบ</label>
                        <input id="system_app_tagline" class="input" type="text" name="app_tagline" maxlength="120" value="<?= e((string) ($systemForm['app_tagline'] ?? 'Maintenance Operations')) ?>">
                        <p class="field-hint">ข้อความบรรทัดเล็กใต้ชื่อระบบบนแถบเมนูและหัวอีเมล — เว้นว่างได้ถ้าไม่ต้องการแสดง</p>
                    </div>
                </div>
                <div class="content-grid">
                    <div class="field-group">
                        <label class="field-label" for="system_ticket_prefix">คำนำหน้า Ticket <span class="required">*</span></label>
                        <input id="system_ticket_prefix" class="input" type="text" name="ticket_prefix" required minlength="2" maxlength="12" pattern="[A-Za-z0-9_-]{2,12}" value="<?= e((string) ($systemForm['ticket_prefix'] ?? 'MT')) ?>">
                        <p class="field-hint">ใช้ A-Z, 0-9, ขีดกลาง หรือขีดล่าง เช่น MT</p>
                    </div>
                </div>
                <div class="content-grid">
                    <div class="field-group">
                        <label class="field-label" for="system_business_start">เวลาเริ่มทำการ <span class="required">*</span></label>
                        <input id="system_business_start" class="input" type="time" name="business_start" required value="<?= e((string) ($systemForm['business_start'] ?? '08:30')) ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="system_business_end">เวลาสิ้นสุดทำการ <span class="required">*</span></label>
                        <input id="system_business_end" class="input" type="time" name="business_end" required value="<?= e((string) ($systemForm['business_end'] ?? '17:30')) ?>">
                    </div>
                </div>
                <p class="field-hint">เวลาทำการใช้สำหรับอ้างอิง/แสดงผล — ปัจจุบันการนับ SLA (เวลาตอบกลับ/แก้ไข) นับต่อเนื่องแบบ 24 ชั่วโมง ไม่หักเวลานอกเวลาทำการ</p>
                <div class="button-row">
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกการตั้งค่าระบบ', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                </div>
            </form>
        </div>

        <?php $currentLogoUrl = branding_logo_url(); ?>
        <div class="panel-card panel-card-indigo-dashed stack-md">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title panel-title-lg">โลโก้องค์กร</h3>
                    <p class="field-hint">รองรับ PNG, JPEG, WebP ขนาดไม่เกิน 1MB · จะแสดงในหัวเว็บ หน้า login และอีเมลแจ้งเตือน</p>
                </div>
                <?php if ($currentLogoUrl !== null): ?>
                    <img src="<?= e($currentLogoUrl) ?>" alt="โลโก้ปัจจุบัน" class="logo-preview">
                <?php else: ?>
                    <span class="badge badge-default">ยังไม่ได้ตั้งค่า</span>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('/admin/settings/logo')) ?>" enctype="multipart/form-data" class="stack-md">
                <?= csrf_field() ?>
                <div class="field-group">
                    <label class="field-label" for="logo">เลือกไฟล์โลโก้ใหม่</label>
                    <input id="logo" class="input" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                </div>
                <div class="button-row">
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'อัปโหลดโลโก้', 'variant' => 'primary', 'icon' => 'upload']) ?>
                </div>
            </form>
            <?php if ($currentLogoUrl !== null): ?>
                <form method="post" action="<?= e(url('/admin/settings/logo')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <input type="hidden" name="remove_logo" value="1">
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบโลโก้ปัจจุบัน', 'variant' => 'secondary', 'icon' => 'trash']) ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มการตั้งค่าขั้นสูง</span>
                    <span class="collapsible-subtitle">กำหนดคีย์/ค่า/ชนิด สำหรับการตั้งค่าเพิ่มเติมที่ไม่ได้อยู่ในฟอร์มหลัก</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/settings')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="setting_key">คีย์ <span class="required">*</span></label>
                            <input id="setting_key" class="input" type="text" name="setting_key" required placeholder="เช่น app_name">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="setting_value_type">ชนิดข้อมูล</label>
                            <select id="setting_value_type" class="input" name="value_type">
                                <option value="string">string – ข้อความ</option>
                                <option value="int">int – จำนวนเต็ม</option>
                                <option value="bool">bool – true/false</option>
                                <option value="json">json – ข้อมูล JSON</option>
                            </select>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="setting_value">ค่า <span class="required">*</span></label>
                        <textarea id="setting_value" class="input" name="setting_value" rows="4" required placeholder='string: "Repair System"   bool: 1 หรือ 0   json: {"key":"value"}'></textarea>
                        <p class="field-hint">สำหรับ json ต้องเป็น valid JSON เท่านั้น</p>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" id="setting_is_public" name="is_public" value="1">
                        <span>เปิดเผยค่านี้ให้ผู้ใช้งานทั่วไปเห็น</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกการตั้งค่า', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>

        <?php if (!empty($settings)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ตารางการตั้งค่าขั้นสูง</caption>
                    <thead>
                    <tr>
                        <th>คีย์</th>
                        <th>ค่า</th>
                        <th>ชนิด</th>
                        <th>เปิดเผย</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($settings as $setting): ?>
                        <tr>
                            <td><code><?= e((string) ($setting['setting_key'] ?? '')) ?></code></td>
                            <td><pre class="code-inline"><?= e((string) ($setting['setting_value'] ?? '')) ?></pre></td>
                            <td><span class="badge badge-default"><?= e((string) ($setting['value_type'] ?? 'string')) ?></span></td>
                            <td><?= !empty($setting['is_public']) ? '<span class="badge badge-success">ใช่</span>' : '<span class="badge badge-default">ไม่</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'settings',
                'title' => 'ยังไม่มี settings เพิ่มเติม',
                'description' => 'คุณสามารถเพิ่ม setting ใหม่จากฟอร์มด้านบนได้ทันที',
            ]) ?>
        <?php endif; ?>

        <?php if ($canLoadDemo ?? false): ?>
        <div class="panel-card panel-card-sky stack-md">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title panel-title-lg">ข้อมูลตัวอย่าง (Demo Data)</h3>
                    <p class="field-hint">เติมแผนก/หมวดหมู่/ทรัพย์สิน/Ticket ตัวอย่างเพื่อทดลองระบบ — ใช้ได้เฉพาะระบบที่ยังไม่มี Ticket</p>
                </div>
            </div>
            <form method="post" action="<?= e(url('/admin/demo-data/load')) ?>" class="stack-md">
                <?= csrf_field() ?>
                <div class="button-row">
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'โหลดข้อมูลตัวอย่าง', 'variant' => 'primary', 'icon' => 'download']) ?>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </section>
