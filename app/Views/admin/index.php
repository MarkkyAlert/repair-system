<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'จัดการระบบ',
        'title' => 'ตั้งค่าและดูแลระบบ',
        'description' => 'ตั้งค่า master data, ผู้ใช้, แผนก, หมวดหมู่ และ SLA',
        'actions' => '<span class="badge badge-default">เฉพาะผู้ดูแลระบบ</span>',
    ]) ?>

    <div class="stat-grid admin-stat-scroll">
        <?= render_partial('partials/components/card', ['title' => 'ผู้ใช้งาน', 'value' => (string) count($users ?? []), 'meta' => 'บัญชีทั้งหมดในระบบ', 'tone' => 'default', 'icon' => 'users']) ?>
        <?= render_partial('partials/components/card', ['title' => 'แผนก', 'value' => (string) count($departments ?? []), 'meta' => 'หน่วยงานในองค์กร', 'tone' => 'info', 'icon' => 'building']) ?>
        <?= render_partial('partials/components/card', ['title' => 'สถานที่', 'value' => (string) count($locations ?? []), 'meta' => 'จุดติดตั้ง/แจ้งซ่อม', 'tone' => 'info', 'icon' => 'map-pin']) ?>
        <?= render_partial('partials/components/card', ['title' => 'Priority/SLA', 'value' => (string) count($priorities ?? []), 'meta' => 'ระดับความเร่งด่วน', 'tone' => 'danger', 'icon' => 'clock']) ?>
        <?= render_partial('partials/components/card', ['title' => 'หมวดหมู่งาน', 'value' => (string) count($categories ?? []), 'meta' => 'ประเภทของ ticket', 'tone' => 'warning', 'icon' => 'tag']) ?>
        <?= render_partial('partials/components/card', ['title' => 'หมวดหมู่ Asset', 'value' => (string) count($assetCategories ?? []), 'meta' => 'ประเภททรัพย์สิน', 'tone' => 'default', 'icon' => 'layers']) ?>
        <?= render_partial('partials/components/card', ['title' => 'การตั้งค่า', 'value' => (string) count($settings ?? []), 'meta' => 'system settings', 'tone' => 'success', 'icon' => 'settings']) ?>
        <?= render_partial('partials/components/card', ['title' => 'Audit Log', 'value' => (string) (int) (($auditLogs['total'] ?? 0)), 'meta' => 'admin actions', 'tone' => 'info', 'icon' => 'file-text']) ?>
    </div>

    <nav class="admin-tabs" role="tablist" aria-label="หมวดการตั้งค่า">
        <a href="#tab-users" class="admin-tab is-active" role="tab" aria-selected="true" aria-controls="tab-users"><?= lucide('users', 'h-4 w-4') ?><span>ผู้ใช้งาน</span></a>
        <a href="#tab-departments" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-departments"><?= lucide('building', 'h-4 w-4') ?><span>แผนก</span></a>
        <a href="#tab-locations" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-locations"><?= lucide('map-pin', 'h-4 w-4') ?><span>สถานที่</span></a>
        <a href="#tab-priorities" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-priorities"><?= lucide('clock', 'h-4 w-4') ?><span>Priority/SLA</span></a>
        <a href="#tab-categories" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-categories"><?= lucide('tag', 'h-4 w-4') ?><span>หมวดหมู่งาน</span></a>
        <a href="#tab-asset-categories" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-asset-categories"><?= lucide('layers', 'h-4 w-4') ?><span>หมวดหมู่ Asset</span></a>
        <a href="#tab-roles" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-roles"><?= lucide('shield-check', 'h-4 w-4') ?><span>สิทธิ์ตาม Role</span></a>
        <a href="#tab-audit" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-audit"><?= lucide('file-text', 'h-4 w-4') ?><span>Audit Log</span></a>
        <a href="#tab-email" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-email"><?= lucide('send', 'h-4 w-4') ?><span>Email</span></a>
        <a href="#tab-settings" class="admin-tab" role="tab" aria-selected="false" aria-controls="tab-settings"><?= lucide('settings', 'h-4 w-4') ?><span>การตั้งค่า</span></a>
    </nav>

    <section id="tab-users" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการผู้ใช้งาน</h2>
                <p class="field-hint">สร้างบัญชีใหม่ หรือคลิกชื่อผู้ใช้เพื่อแก้ไขข้อมูลเดิม</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($users ?? [])) ?> บัญชี</span>
        </div>

        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">สร้างบัญชีผู้ใช้งาน</span>
                    <span class="collapsible-subtitle">กำหนดข้อมูลบัญชี บทบาท และรหัสผ่านเริ่มต้น</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
                <form method="post" action="<?= e(url('/admin/users')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_username">ชื่อผู้ใช้ <span class="required">*</span></label>
                            <input id="new_username" class="input" type="text" name="username" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9._-]+" autocomplete="off">
                            <p class="field-hint">ใช้ a-z, 0-9, จุด, ขีดกลาง หรือขีดล่าง</p>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_full_name">ชื่อ-นามสกุล <span class="required">*</span></label>
                            <input id="new_full_name" class="input" type="text" name="full_name" required>
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_email">อีเมล <span class="required">*</span></label>
                            <input id="new_email" class="input" type="email" name="email" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_phone">เบอร์โทร</label>
                            <input id="new_phone" class="input" type="text" name="phone">
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_department">แผนก</label>
                            <select id="new_department" class="input" name="department_id">
                                <option value="">ไม่ระบุ</option>
                                <?php foreach (($departmentOptions ?? []) as $department): ?>
                                    <option value="<?= e((string) ($department['id'] ?? 0)) ?>"><?= e((string) ($department['name'] ?? '-')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_role">บทบาท (Role)</label>
                            <select id="new_role" class="input" name="role">
                                <?php $createRoleLabels = ['requester' => 'ผู้แจ้ง', 'manager' => 'หัวหน้างาน', 'technician' => 'ช่างเทคนิค', 'admin' => 'ผู้ดูแลระบบ']; ?>
                                <?php foreach (($roles ?? []) as $role): ?>
                                    <option value="<?= e((string) $role) ?>"<?= $role === 'requester' ? ' selected' : '' ?>><?= e($createRoleLabels[$role] ?? ucwords((string) $role)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="content-grid">
                        <div class="field-group">
                            <label class="field-label" for="new_password">รหัสผ่านเริ่มต้น <span class="required">*</span></label>
                            <input id="new_password" class="input" type="password" name="password" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="new_password_confirmation">ยืนยันรหัสผ่าน <span class="required">*</span></label>
                            <input id="new_password_confirmation" class="input" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>เปิดใช้งานบัญชีทันที</span>
                    </label>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'สร้างบัญชีผู้ใช้งาน', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>

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

    <section id="tab-departments" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <h2 class="panel-title">จัดการแผนก</h2>
            <span class="badge badge-info"><?= e((string) count($departments ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มแผนกใหม่</span>
                    <span class="collapsible-subtitle">สร้างหน่วยงานสำหรับผูกกับผู้ใช้, Asset และรายงาน</span>
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
                            <form method="post" action="<?= e(url('/admin/departments/' . $depId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบแผนกนี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                <?= csrf_field() ?>
                                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบแผนก', 'variant' => 'danger', 'icon' => 'trash']) ?>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-locations" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการสถานที่</h2>
                <p class="field-hint">สถานที่ที่ใช้ในฟอร์ม Ticket และ Asset Registry</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($locations ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มสถานที่ใหม่</span>
                    <span class="collapsible-subtitle">สร้างจุดติดตั้งหรือพื้นที่สำหรับเลือกใน Ticket/Asset</span>
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
                'description' => 'เพิ่มสถานที่เพื่อให้ผู้ใช้เลือกตอนเปิด Ticket หรือบันทึก Asset',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($locations ?? []) as $location): ?>
                    <?php $locationId = (int) ($location['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('map-pin', 'h-4 w-4') ?></span>
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
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-priorities" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการ Priority และ SLA พื้นฐาน</h2>
                <p class="field-hint">แก้ชื่อ สี และเวลาตอบกลับ/แก้ไขของ 4 ระดับเดิม โดยไม่เปลี่ยน code หรือ level</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($priorities ?? [])) ?> ระดับ</span>
        </div>
        <?php if (($priorities ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clock',
                'title' => 'ยังไม่มี Priority',
                'description' => 'ควร seed priority พื้นฐานก่อนใช้งานระบบ Ticket',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($priorities ?? []) as $priority): ?>
                    <?php $priorityId = (int) ($priority['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('clock', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) ($priority['name'] ?? '-')) ?></span>
                                <span class="collapsible-subtitle"><?= e((string) ($priority['code'] ?? '-')) ?> · Level <?= e((string) ($priority['level'] ?? '-')) ?> · ตอบใน <?= e((string) ($priority['response_hours'] ?? 0)) ?> ชม. · แก้ใน <?= e((string) ($priority['resolution_hours'] ?? 0)) ?> ชม.</span>
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
                                        <label class="field-label">Code (เปลี่ยนไม่ได้)</label>
                                        <input class="input" type="text" value="<?= e((string) ($priority['code'] ?? '')) ?>" disabled>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Level (เปลี่ยนไม่ได้)</label>
                                        <input class="input" type="text" value="<?= e((string) ($priority['level'] ?? '')) ?>" disabled>
                                    </div>
                                </div>
                                <div class="content-grid">
                                    <div class="field-group">
                                        <label class="field-label" for="priority_name_<?= $priorityId ?>">ชื่อ Priority <span class="required">*</span></label>
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
                                    <span>เปิดใช้งาน Priority นี้ในฟอร์ม Ticket</span>
                                </label>
                                <div class="button-row">
                                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก Priority/SLA', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

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
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('plus', 'h-4 w-4') ?></span>
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
                            <form method="post" action="<?= e(url('/admin/categories/' . $catId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบหมวดหมู่งานนี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                <?= csrf_field() ?>
                                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบหมวดหมู่งาน', 'variant' => 'danger', 'icon' => 'trash']) ?>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-asset-categories" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการหมวดหมู่ Asset</h2>
                <p class="field-hint">ประเภททรัพย์สินที่ใช้ในฟอร์มเพิ่มและแก้ไข Asset</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($assetCategories ?? [])) ?> รายการ</span>
        </div>
        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('plus', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">เพิ่มหมวดหมู่ Asset ใหม่</span>
                    <span class="collapsible-subtitle">สร้างประเภททรัพย์สินสำหรับทะเบียน Asset</span>
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
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เพิ่มหมวดหมู่ Asset', 'variant' => 'primary', 'icon' => 'plus']) ?>
                    </div>
                </form>
            </div>
        </details>
        <?php if (($assetCategories ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'layers',
                'title' => 'ยังไม่มีหมวดหมู่ Asset',
                'description' => 'เมื่อมีหมวดหมู่ Asset รายการจะปรากฏที่นี่ พร้อมให้แก้ไขหรือปิดใช้งานได้',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach (($assetCategories ?? []) as $assetCategory): ?>
                    <?php $assetCatId = (int) ($assetCategory['id'] ?? 0); ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('layers', 'h-4 w-4') ?></span>
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
                            <form method="post" action="<?= e(url('/admin/asset-categories/' . $assetCatId . '/delete')) ?>" class="button-row" onsubmit="return confirm('ยืนยันการลบหมวดหมู่ Asset นี้? ลบได้เฉพาะรายการที่ยังไม่ถูกใช้งาน หากถูกใช้งานแล้วให้ปิดใช้งานแทน');">
                                <?= csrf_field() ?>
                                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ลบหมวดหมู่ Asset', 'variant' => 'danger', 'icon' => 'trash']) ?>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="tab-roles" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Preview สิทธิ์ตาม Role</h2>
                <p class="field-hint">สรุปความสามารถจาก workflow และ routes ที่ระบบใช้อยู่จริง เป็นเอกสารอ่านอย่างเดียวสำหรับผู้ซื้อ template</p>
            </div>
            <span class="badge badge-default">Read-only</span>
        </div>

        <?php $rolePreviewData = $rolePreview ?? ['roles' => [], 'capabilities' => []]; ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>ความสามารถ</th>
                    <?php foreach (($rolePreviewData['roles'] ?? []) as $roleCode => $roleLabel): ?>
                        <th><?= e((string) $roleLabel) ?><br><span class="helper-text"><?= e((string) $roleCode) ?></span></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($rolePreviewData['capabilities'] ?? []) as $capability): ?>
                    <tr>
                        <td><?= e((string) ($capability['label'] ?? '-')) ?></td>
                        <?php foreach (($rolePreviewData['roles'] ?? []) as $roleCode => $roleLabel): ?>
                            <?php $allowed = in_array((string) $roleCode, (array) ($capability['roles'] ?? []), true); ?>
                            <td><?= $allowed ? '<span class="badge badge-success">ทำได้</span>' : '<span class="badge badge-default">ไม่ได้</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="tab-audit" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Audit Log</h2>
                <p class="field-hint">อ่านอย่างเดียว: ดูว่าใครแก้ข้อมูลผู้ใช้ master data, settings, logo และอีเมลทดสอบเมื่อไหร่</p>
            </div>
            <span class="badge badge-info"><?= e((string) (int) (($auditLogs['total'] ?? 0))) ?> รายการ</span>
        </div>

        <?php
        $auditFilters = $auditFilters ?? [];
        $auditOptions = $auditFilterOptions ?? ['actions' => [], 'entityTypes' => []];
        ?>
        <form method="get" action="<?= e(url('/admin#tab-audit')) ?>" class="panel-card stack-md" style="background:rgba(14,165,233,.05);border:1px solid rgba(14,165,233,.18)">
            <div class="content-grid">
                <div class="field-group">
                    <label class="field-label" for="audit_action">Action</label>
                    <select id="audit_action" class="input" name="action">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (($auditOptions['actions'] ?? []) as $action): ?>
                            <option value="<?= e((string) $action) ?>"<?= (string) ($auditFilters['action'] ?? '') === (string) $action ? ' selected' : '' ?>><?= e((string) $action) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label class="field-label" for="audit_entity_type">Entity</label>
                    <select id="audit_entity_type" class="input" name="entity_type">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (($auditOptions['entityTypes'] ?? []) as $entityType): ?>
                            <option value="<?= e((string) $entityType) ?>"<?= (string) ($auditFilters['entity_type'] ?? '') === (string) $entityType ? ' selected' : '' ?>><?= e((string) $entityType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="content-grid">
                <div class="field-group">
                    <label class="field-label" for="audit_user_id">ผู้ใช้</label>
                    <select id="audit_user_id" class="input" name="user_id">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (($users ?? []) as $auditUser): ?>
                            <option value="<?= e((string) ($auditUser['id'] ?? 0)) ?>"<?= (int) ($auditFilters['user_id'] ?? 0) === (int) ($auditUser['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($auditUser['full_name'] ?? $auditUser['username'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="content-grid">
                    <div class="field-group">
                        <label class="field-label" for="audit_date_from">จากวันที่</label>
                        <input id="audit_date_from" class="input" type="date" name="date_from" value="<?= e((string) ($auditFilters['date_from'] ?? '')) ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="audit_date_to">ถึงวันที่</label>
                        <input id="audit_date_to" class="input" type="date" name="date_to" value="<?= e((string) ($auditFilters['date_to'] ?? '')) ?>">
                    </div>
                </div>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'กรอง Audit Log', 'variant' => 'primary', 'icon' => 'filter']) ?>
                <?= render_partial('partials/components/button', ['href' => '/admin#tab-audit', 'label' => 'ล้าง filter', 'variant' => 'secondary', 'icon' => 'x']) ?>
            </div>
        </form>

        <?php if (empty($auditLogs['items'])): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'file-text',
                'title' => 'ยังไม่มี Audit Log',
                'description' => 'ระบบจะเริ่มบันทึกเมื่อมี admin action สำคัญหลังจากเปิดใช้ฟีเจอร์นี้',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>ผู้ใช้</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>IP</th>
                        <th>รายละเอียด</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($auditLogs['items'] ?? []) as $log): ?>
                        <?php
                        $context = json_decode((string) ($log['context'] ?? ''), true);
                        $contextSummary = is_array($context)
                            ? implode(' · ', array_slice(array_map(
                                static fn ($key, $value): string => $key . ': ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value),
                                array_keys($context),
                                $context
                            ), 0, 4))
                            : '';
                        ?>
                        <tr>
                            <td><?= e(human_date((string) ($log['created_at'] ?? ''))) ?></td>
                            <td><?= e((string) ($log['user_name'] ?? $log['username'] ?? 'System')) ?></td>
                            <td><span class="badge badge-info"><?= e((string) ($log['action'] ?? '-')) ?></span></td>
                            <td><?= e((string) ($log['entity_type'] ?? '-')) ?><?php if (!empty($log['entity_id'])): ?> #<?= e((string) $log['entity_id']) ?><?php endif; ?></td>
                            <td><?= e((string) ($log['ip_address'] ?? '-')) ?></td>
                            <td><span class="helper-text"><?= e($contextSummary !== '' ? $contextSummary : '-') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $auditPage = max(1, (int) ($auditLogs['page'] ?? 1));
            $auditTotalPages = max(1, (int) ($auditLogs['totalPages'] ?? 1));
            $auditQuery = $_GET ?? [];
            unset($auditQuery['audit_page']);
            $auditPageUrl = static function (int $target) use ($auditQuery): string {
                return url('/admin?' . http_build_query($auditQuery + ['audit_page' => $target]) . '#tab-audit');
            };
            ?>
            <?php if ($auditTotalPages > 1): ?>
                <nav class="pagination" aria-label="Audit pagination">
                    <span class="pagination-summary"><?= e((string) (int) ($auditLogs['total'] ?? 0)) ?> รายการ · หน้า <?= e((string) $auditPage) ?>/<?= e((string) $auditTotalPages) ?></span>
                    <a class="page-link<?= $auditPage <= 1 ? ' is-disabled' : '' ?>" href="<?= e($auditPageUrl(max(1, $auditPage - 1))) ?>"><?= lucide('chevron-left', 'h-4 w-4') ?></a>
                    <?php for ($target = max(1, $auditPage - 2); $target <= min($auditTotalPages, $auditPage + 2); $target++): ?>
                        <a class="page-link<?= $target === $auditPage ? ' is-active' : '' ?>" href="<?= e($auditPageUrl($target)) ?>"><?= e((string) $target) ?></a>
                    <?php endfor; ?>
                    <a class="page-link<?= $auditPage >= $auditTotalPages ? ' is-disabled' : '' ?>" href="<?= e($auditPageUrl(min($auditTotalPages, $auditPage + 1))) ?>"><?= lucide('chevron-right', 'h-4 w-4') ?></a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section id="tab-email" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Email Template และ SMTP Test</h2>
                <p class="field-hint">ดูตัวอย่างอีเมลจริงของระบบและส่งทดสอบผ่าน MailerService เดิม โดยไม่แสดงรหัสผ่าน SMTP</p>
            </div>
            <span class="badge badge-info"><?= e((string) (($mailDiagnostics['driver'] ?? 'log'))) ?></span>
        </div>

        <?php $mail = $mailDiagnostics ?? []; ?>
        <div class="content-grid">
            <div class="panel-card stack-md" style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.2)">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title" style="font-size:1rem">Mail Config</h3>
                        <p class="field-hint">ค่าที่อ่านจาก `.env`/config ปัจจุบัน</p>
                    </div>
                </div>
                <dl class="description-list">
                    <dt>Driver</dt><dd><?= e((string) ($mail['driver'] ?? 'log')) ?></dd>
                    <dt>Host/Port</dt><dd><?= e((string) ($mail['host'] ?? '-')) ?>:<?= e((string) ($mail['port'] ?? '-')) ?></dd>
                    <dt>Encryption</dt><dd><?= e((string) (($mail['encryption'] ?? '') !== '' ? $mail['encryption'] : 'none')) ?></dd>
                    <dt>From</dt><dd><?= e((string) ($mail['from_name'] ?? '-')) ?> &lt;<?= e((string) ($mail['from_address'] ?? '-')) ?>&gt;</dd>
                    <dt>Reply-To</dt><dd><?= e((string) (($mail['reply_to_address'] ?? '') !== '' ? $mail['reply_to_address'] : '-')) ?></dd>
                    <?php if (!empty($mail['is_log_driver'])): ?>
                        <dt>Log Path</dt><dd><code><?= e((string) ($mail['log_path'] ?? 'storage/mail-logs')) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="panel-card stack-md" style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.2)">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title" style="font-size:1rem">ส่งอีเมลทดสอบ</h3>
                        <p class="field-hint">ถ้าใช้ driver log ระบบจะเขียนไฟล์ JSON ใน mail log path</p>
                    </div>
                </div>
                <form method="post" action="<?= e(url('/admin/email/test')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label class="field-label" for="test_to_email">อีเมลปลายทาง <span class="required">*</span></label>
                        <input id="test_to_email" class="input" type="email" name="to_email" required placeholder="owner@example.com" value="<?= e((string) ($currentUser['email'] ?? '')) ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="test_template">Template</label>
                        <select id="test_template" class="input" name="template">
                            <option value="password_reset">Password Reset</option>
                            <option value="notification">Notification / Ticket Event</option>
                        </select>
                    </div>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ส่งอีเมลทดสอบ', 'variant' => 'primary', 'icon' => 'send']) ?>
                    </div>
                </form>
            </div>
        </div>

        <?php foreach (($emailPreviews ?? []) as $templateKey => $preview): ?>
            <details class="collapsible"<?= $templateKey === 'password_reset' ? ' open' : '' ?>>
                <summary class="collapsible-summary">
                    <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide($templateKey === 'password_reset' ? 'key-round' : 'bell', 'h-4 w-4') ?></span>
                    <div class="collapsible-summary-main">
                        <span class="collapsible-title"><?= e((string) ($preview['label'] ?? $templateKey)) ?></span>
                        <span class="collapsible-subtitle"><?= e((string) ($preview['subject'] ?? '-')) ?></span>
                    </div>
                    <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                </summary>
                <div class="collapsible-body stack-md">
                    <iframe title="<?= e((string) ($preview['label'] ?? $templateKey)) ?> preview" sandbox style="width:100%;height:520px;border:1px solid rgba(148,163,184,.35);border-radius:16px;background:#fff" srcdoc="<?= e((string) ($preview['body_html'] ?? '')) ?>"></iframe>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="collapsible-title">Text fallback</span>
                            <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                        </summary>
                        <div class="collapsible-body">
                            <pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:.82rem"><?= e((string) ($preview['body_text'] ?? '')) ?></pre>
                        </div>
                    </details>
                </div>
            </details>
        <?php endforeach; ?>
    </section>

    <section id="tab-settings" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">การตั้งค่ากลางของระบบ</h2>
                <p class="field-hint">ตั้งค่าพื้นฐานด้วยฟอร์มอ่านง่าย เพื่อลดการแก้ JSON หรือ key/value ดิบผิดพลาด</p>
            </div>
        </div>

        <?php $systemForm = $systemSettingForm ?? []; ?>
        <div class="panel-card stack-md" style="background:rgba(14,165,233,.05);border:1px solid rgba(14,165,233,.2)">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title" style="font-size:1rem">ตั้งค่าระบบหลัก</h3>
                    <p class="field-hint">ชื่อระบบ, timezone, ticket prefix และเวลาทำการ</p>
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
                        <label class="field-label" for="system_timezone">Timezone <span class="required">*</span></label>
                        <select id="system_timezone" class="input" name="default_timezone" required>
                            <?php foreach (($systemForm['timezoneOptions'] ?? [['value' => 'Asia/Bangkok', 'label' => 'Asia/Bangkok']]) as $option): ?>
                                <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($systemForm['default_timezone'] ?? 'Asia/Bangkok') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="content-grid">
                    <div class="field-group">
                        <label class="field-label" for="system_ticket_prefix">Ticket Prefix <span class="required">*</span></label>
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
                <div class="button-row">
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกการตั้งค่าระบบ', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                </div>
            </form>
        </div>

        <?php $currentLogoUrl = branding_logo_url(); ?>
        <div class="panel-card stack-md" style="background:rgba(99,102,241,.05);border:1px dashed rgba(99,102,241,.35)">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title" style="font-size:1rem">โลโก้องค์กร</h3>
                    <p class="field-hint">รองรับ PNG, JPEG, WebP, SVG ขนาดไม่เกิน 1MB · จะแสดงในหัวเว็บ หน้า login และอีเมลแจ้งเตือน</p>
                </div>
                <?php if ($currentLogoUrl !== null): ?>
                    <img src="<?= e($currentLogoUrl) ?>" alt="โลโก้ปัจจุบัน" style="height:48px;max-width:160px;object-fit:contain;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:8px;padding:4px 8px;">
                <?php else: ?>
                    <span class="badge badge-default">ยังไม่ได้ตั้งค่า</span>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('/admin/settings/logo')) ?>" enctype="multipart/form-data" class="stack-md">
                <?= csrf_field() ?>
                <div class="field-group">
                    <label class="field-label" for="logo">เลือกไฟล์โลโก้ใหม่</label>
                    <input id="logo" class="input" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
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
                <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('settings', 'h-4 w-4') ?></span>
                <div class="collapsible-summary-main">
                    <span class="collapsible-title">Advanced Settings</span>
                    <span class="collapsible-subtitle">สำหรับ key/value เพิ่มเติมที่ไม่ได้อยู่ในฟอร์มหลัก</span>
                </div>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </summary>
            <div class="collapsible-body">
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
            </div>
        </details>
    </section>
</section>

<script>
(() => {
    const tabs = document.querySelectorAll('.admin-tab');
    const panels = document.querySelectorAll('.admin-tab-panel');
    if (!tabs.length) return;

    const activate = (hash) => {
        tabs.forEach((t) => {
            const active = t.getAttribute('href') === hash;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach((p) => {
            p.classList.toggle('is-active', '#' + p.id === hash);
        });
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const hash = tab.getAttribute('href');
            activate(hash);
            history.replaceState(null, '', hash);
        });
    });

    activate(location.hash || '#tab-users');
})();
</script>
