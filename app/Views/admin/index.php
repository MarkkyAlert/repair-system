<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'จัดการระบบ',
        'title' => 'ตั้งค่าและดูแลระบบ',
        'description' => 'ตั้งค่า master data, ผู้ใช้, แผนก, หมวดหมู่ และ SLA',
        'actions' => '<span class="badge badge-default">เฉพาะผู้ดูแลระบบ</span>',
    ]) ?>

    <div class="stat-grid">
        <?= render_partial('partials/components/card', ['title' => 'ผู้ใช้งาน', 'value' => (string) count($users ?? []), 'meta' => 'บัญชีทั้งหมดในระบบ', 'tone' => 'default', 'icon' => 'users']) ?>
        <?= render_partial('partials/components/card', ['title' => 'แผนก', 'value' => (string) count($departments ?? []), 'meta' => 'หน่วยงานในองค์กร', 'tone' => 'info', 'icon' => 'building']) ?>
        <?= render_partial('partials/components/card', ['title' => 'หมวดหมู่งาน', 'value' => (string) count($categories ?? []), 'meta' => 'ประเภทของ ticket', 'tone' => 'warning', 'icon' => 'tag']) ?>
        <?= render_partial('partials/components/card', ['title' => 'การตั้งค่า', 'value' => (string) count($settings ?? []), 'meta' => 'system settings', 'tone' => 'success', 'icon' => 'settings']) ?>
    </div>

    <nav class="admin-tabs" aria-label="หมวดการตั้งค่า">
        <a href="#tab-users" class="admin-tab is-active"><?= lucide('users', 'h-4 w-4') ?><span>ผู้ใช้งาน</span></a>
        <a href="#tab-departments" class="admin-tab"><?= lucide('building', 'h-4 w-4') ?><span>แผนก</span></a>
        <a href="#tab-categories" class="admin-tab"><?= lucide('tag', 'h-4 w-4') ?><span>หมวดหมู่</span></a>
        <a href="#tab-settings" class="admin-tab"><?= lucide('settings', 'h-4 w-4') ?><span>การตั้งค่า</span></a>
    </nav>

    <section id="tab-users" class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการผู้ใช้งาน</h2>
                <p class="field-hint">คลิกชื่อผู้ใช้เพื่อขยายและแก้ไขข้อมูล แล้วกดบันทึก</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($users ?? [])) ?> บัญชี</span>
        </div>

        <?php if (($users ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'users',
                'title' => 'ยังไม่มีข้อมูลผู้ใช้งาน',
                'description' => 'เมื่อมีบัญชีในระบบ รายการจะปรากฏที่นี่ให้แก้ไข role และ department ได้',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($users ?? []) as $user): ?>
                    <?php $userId = (int) ($user['id'] ?? 0); ?>
                    <?php $deptName = '-'; foreach (($departmentOptions ?? []) as $d) { if ((string) ($d['id'] ?? '') === (string) ($user['department_id'] ?? '')) { $deptName = (string) ($d['name'] ?? '-'); break; } } ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="user-chip-avatar"><?= e(strtoupper(function_exists('mb_substr') ? mb_substr(trim((string) ($user['full_name'] ?? 'U')), 0, 1) : substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($user['full_name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle"><?= e((string) ($user['email'] ?? '-')) ?> · <?= e($deptName) ?></span>
                            </div>
                            <div class="collapsible-meta">
                                <span class="badge badge-<?= !empty($user['is_active']) ? 'success' : 'default' ?>"><?= !empty($user['is_active']) ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?></span>
                                <span class="badge badge-info"><?= e(ucfirst((string) ($user['role'] ?? 'requester'))) ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/users/' . $userId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="full_name_<?= $userId ?>">ชื่อ-นามสกุล <span class="required">*</span></label>
                                        <input id="full_name_<?= $userId ?>" class="input" type="text" name="full_name" required value="<?= e((string) ($user['full_name'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="email_<?= $userId ?>">อีเมล <span class="required">*</span></label>
                                        <input id="email_<?= $userId ?>" class="input" type="email" name="email" required value="<?= e((string) ($user['email'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="phone_<?= $userId ?>">เบอร์โทร</label>
                                        <input id="phone_<?= $userId ?>" class="input" type="text" name="phone" value="<?= e((string) ($user['phone'] ?? '')) ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">ชื่อผู้ใช้ (เปลี่ยนไม่ได้)</label>
                                        <input class="input" type="text" value="<?= e((string) ($user['username'] ?? '')) ?>" disabled>
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="department_<?= $userId ?>">แผนก</label>
                                        <select id="department_<?= $userId ?>" class="input" name="department_id">
                                            <option value="">ไม่ระบุ</option>
                                            <?php foreach (($departmentOptions ?? []) as $department): ?>
                                                <option value="<?= e((string) ($department['id'] ?? 0)) ?>"<?= (string) ($user['department_id'] ?? '') === (string) ($department['id'] ?? '') ? ' selected' : '' ?>><?= e((string) ($department['name'] ?? '-')) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="role_<?= $userId ?>">บทบาท (Role)</label>
                                        <select id="role_<?= $userId ?>" class="input" name="role">
                                            <?php $roleLabels = ['requester' => 'ผู้แจ้ง', 'manager' => 'หัวหน้างาน', 'technician' => 'ช่างเทคนิค', 'admin' => 'ผู้ดูแลระบบ']; ?>
                                            <?php foreach (($roles ?? []) as $role): ?>
                                                <option value="<?= e((string) $role) ?>"<?= (string) ($user['role'] ?? '') === (string) $role ? ' selected' : '' ?>><?= e($roleLabels[$role] ?? ucwords((string) $role)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_active" value="1"<?= !empty($user['is_active']) ? ' checked' : '' ?>>
                                    <span>เปิดใช้งานบัญชีนี้</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกการเปลี่ยนแปลง', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-departments" class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">จัดการแผนก</h2>
            <span class="badge badge-info"><?= e((string) count($departments ?? [])) ?> รายการ</span>
        </div>
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
                            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('building', 'h-4 w-4') ?></span>
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
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-categories" class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ตั้งค่าหมวดหมู่และ SLA</h2>
                <p class="field-hint">กำหนดเวลาตอบรับและเวลาแก้ไขของแต่ละประเภทงาน (หน่วย: ชั่วโมง)</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($categories ?? [])) ?> รายการ</span>
        </div>
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
                            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('tag', 'h-4 w-4') ?></span>
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
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-settings" class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">การตั้งค่ากลางของระบบ</h2>
                <p class="field-hint">ค่าตั้งค่าที่ระบบใช้ เช่น ชื่อแอป, timezone, prefix ของ ticket</p>
            </div>
        </div>

        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่ม Setting ใหม่</span>
                    <span class="collapsible-subtitle">กำหนด key/value/type สำหรับ config ที่ระบบใช้</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/settings')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="setting_key">Key <span class="required">*</span></label>
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
                        <span>เปิดเผยค่านี้ให้ผู้ใช้งานทั่วไปเห็น (public)</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก Setting', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>

        <?php if (!empty($settings)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Key</th>
                        <th>ค่า</th>
                        <th>ชนิด</th>
                        <th>เปิดเผย</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($settings as $setting): ?>
                        <tr>
                            <td><code><?= e((string) ($setting['setting_key'] ?? '')) ?></code></td>
                            <td><pre style="margin:0;font-size:.78rem;white-space:pre-wrap;word-break:break-word"><?= e((string) ($setting['setting_value'] ?? '')) ?></pre></td>
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
    </section>
</section>

<script>
(() => {
    const tabs = document.querySelectorAll('.admin-tab');
    if (!tabs.length) return;
    const setActive = (hash) => {
        tabs.forEach((t) => t.classList.toggle('is-active', t.getAttribute('href') === hash));
    };
    tabs.forEach((tab) => tab.addEventListener('click', () => setActive(tab.getAttribute('href'))));
    if (location.hash) setActive(location.hash);
})();
</script>


