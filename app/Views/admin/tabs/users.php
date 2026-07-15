    <section id="tab-users" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">จัดการผู้ใช้งาน</h2>
                <p class="field-hint">สร้างบัญชีใหม่ หรือคลิกชื่อผู้ใช้เพื่อแก้ไขข้อมูลเดิม</p>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', [
                    'label' => 'นำเข้าผู้ใช้ (CSV)',
                    'variant' => 'secondary',
                    'href' => '/admin/users/import',
                    'icon' => 'send',
                ]) ?>
                <span class="badge badge-info"><?= e((string) count($users ?? [])) ?> บัญชี</span>
            </div>
        </div>

        <div class="admin-search-box">
            <label for="user-search" class="sr-only">ค้นหาผู้ใช้งาน</label>
            <div class="admin-search-input-wrap">
                <?= lucide('search', 'h-4 w-4 admin-search-icon') ?>
                <input id="user-search" type="search" class="input admin-search-input" placeholder="ค้นหาชื่อ, อีเมล, แผนก หรือบทบาท..." data-search-target="#user-list">
            </div>
            <span class="helper-text admin-search-count"></span>
        </div>

        <details class="collapsible">
            <summary class="collapsible-summary">
                <span class="metric-icon metric-icon-sm"><?= lucide('plus', 'h-4 w-4') ?></span>
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
                            <label class="field-label" for="new_role">บทบาท</label>
                            <select id="new_role" class="input" name="role">
                                <?php foreach (($roles ?? []) as $role): ?>
                                    <option value="<?= e((string) $role) ?>"<?= $role === 'requester' ? ' selected' : '' ?>><?= e(role_label_th((string) $role)) ?></option>
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
            <div class="stack-md" id="user-list">
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
                                <span class="badge badge-info"><?= e(role_label_th((string) ($user['role'] ?? 'requester'))) ?></span>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body">
                            <form method="post" action="<?= e(url('/admin/users/' . $userId)) ?>" class="stack-md">
                                <?= csrf_field() ?>
                                <input type="hidden" name="original_version" value="<?= e((string) ($user['version'] ?? 1)) ?>">
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
                                        <label class="field-label" for="username_<?= $userId ?>">ชื่อผู้ใช้ (เปลี่ยนไม่ได้)</label>
                                        <input id="username_<?= $userId ?>" class="input" type="text" value="<?= e((string) ($user['username'] ?? '')) ?>" disabled>
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
                                        <label class="field-label" for="role_<?= $userId ?>">บทบาท</label>
                                        <select id="role_<?= $userId ?>" class="input" name="role">
                                            <?php foreach (($roles ?? []) as $role): ?>
                                                <option value="<?= e((string) $role) ?>"<?= (string) ($user['role'] ?? '') === (string) $role ? ' selected' : '' ?>><?= e(role_label_th((string) $role)) ?></option>
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
