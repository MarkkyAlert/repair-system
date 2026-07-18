<?php
$hasPreview = is_array($preview ?? null);
$valid = $preview['valid'] ?? [];
$invalid = $preview['invalid'] ?? [];
$total = (int) ($preview['total'] ?? 0);
?>
<section class="stack-lg">
    <h1 class="sr-only">นำเข้าผู้ใช้จากไฟล์ CSV</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ผู้ดูแลระบบ',
        'title' => $hasPreview ? 'ตรวจสอบก่อนนำเข้าผู้ใช้' : 'นำเข้าผู้ใช้จาก CSV',
        'description' => $hasPreview
            ? 'ตรวจรายการที่ระบบเตรียมไว้ ก่อนสร้างบัญชีจริง'
            : 'อัปโหลดไฟล์ CSV เพื่อสร้างหลายบัญชีพร้อมกัน',
        'breadcrumbs' => [
            ['label' => 'ตั้งค่าระบบ', 'href' => '/admin'],
            ['label' => 'นำเข้าผู้ใช้ (CSV)'],
        ],
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าตั้งค่า',
            'variant' => 'secondary',
            'href' => '/admin',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="auth-alert auth-alert-danger" role="alert">
            <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
            <p><?= e((string) $errorMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$hasPreview): ?>
        <section class="panel-card stack-md">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">ขั้นตอนนำเข้า</h2>
                    <p class="field-hint">ดาวน์โหลดเทมเพลต → กรอกข้อมูล → อัปโหลด → ตรวจสอบตัวอย่าง → ยืนยัน</p>
                </div>
                <?= render_partial('partials/components/button', [
                    'label' => 'ดาวน์โหลดเทมเพลต CSV',
                    'variant' => 'secondary',
                    'href' => '/admin/users/import/template.csv',
                    'icon' => 'arrow-right',
                    'iconPosition' => 'right',
                ]) ?>
            </div>

            <ol class="ticket-flow-list">
                <li><span>1</span><div><strong>คอลัมน์ที่จำเป็น</strong><span>username, email, full_name, role (ที่เหลือเว้นได้)</span></div></li>
                <li><span>2</span><div><strong>บทบาทที่ใช้ได้</strong><span>requester / manager / technician / admin</span></div></li>
                <li><span>3</span><div><strong>เว้นรหัสผ่านไว้</strong><span>ระบบจะสร้างให้และส่งอีเมลตั้งรหัสผ่านอัตโนมัติ</span></div></li>
                <li><span>4</span><div><strong>สูงสุด <?= e((string) (int) config('uploads.import_user_max_rows', 50)) ?> ผู้ใช้ต่อรอบ</strong><span>ไฟล์ขนาดไม่เกิน 1MB</span></div></li>
            </ol>

            <form method="post" action="<?= e(url('/admin/users/import')) ?>" enctype="multipart/form-data" class="stack-md" data-loading-submit>
                <?= csrf_field() ?>
                <div class="field-group">
                    <label for="csv" class="field-label">ไฟล์ CSV <span class="required">*</span></label>
                    <div class="file-field" data-file-field>
                        <input id="csv" name="csv" type="file" accept=".csv,text/csv" class="file-field-input" required>
                        <label for="csv" class="file-field-button"><?= lucide('upload', 'button-icon') ?><span>เลือกไฟล์ CSV</span></label>
                        <span class="file-field-name" data-file-field-name data-empty="ยังไม่ได้เลือกไฟล์" aria-live="polite">ยังไม่ได้เลือกไฟล์</span>
                    </div>
                    <p class="field-hint">รองรับเฉพาะนามสกุล .csv (UTF-8 พร้อม BOM)</p>
                </div>
                <div class="button-row">
                    <?= render_partial('partials/components/button', [
                        'type' => 'submit',
                        'label' => 'อัปโหลดและตรวจสอบ',
                        'variant' => 'primary',
                        'icon' => 'arrow-right',
                        'iconPosition' => 'right',
                    ]) ?>
                </div>
            </form>
        </section>
    <?php else: ?>
        <div class="stat-grid stat-grid-3">
            <?= render_partial('partials/components/card', ['title' => 'ทั้งหมด', 'value' => (string) $total, 'meta' => 'แถวที่อ่านจากไฟล์', 'tone' => 'default', 'icon' => 'users']) ?>
            <?= render_partial('partials/components/card', ['title' => 'พร้อมนำเข้า', 'value' => (string) count($valid), 'meta' => 'ผ่านการตรวจสอบ', 'tone' => 'success', 'icon' => 'check-circle']) ?>
            <?= render_partial('partials/components/card', ['title' => 'มีปัญหา', 'value' => (string) count($invalid), 'meta' => 'ต้องแก้ไฟล์ก่อน', 'tone' => 'danger', 'icon' => 'triangle-alert']) ?>
        </div>

        <?php if ($invalid !== []): ?>
            <section class="panel-card stack-md">
                <div class="panel-head">
                    <div>
                        <h2 class="panel-title">แถวที่มีปัญหา</h2>
                        <p class="field-hint">แก้ไฟล์ตามข้อผิดพลาด แล้วอัปโหลดใหม่</p>
                    </div>
                    <span class="badge badge-danger"><?= e((string) count($invalid)) ?> แถว</span>
                </div>
                <div class="table-wrap">
                    <table class="insight-table">
                        <thead><tr><th>บรรทัด</th><th>username</th><th>email</th><th>full_name</th><th>ข้อผิดพลาด</th></tr></thead>
                        <tbody>
                        <?php foreach ($invalid as $row): ?>
                            <tr>
                                <td><code class="mono"><?= e((string) $row['line']) ?></code></td>
                                <td><code class="mono"><?= e((string) $row['username']) ?></code></td>
                                <td><?= e((string) $row['email']) ?></td>
                                <td><?= e((string) $row['full_name']) ?></td>
                                <td>
                                    <ul style="margin:0;padding-left:1rem">
                                        <?php foreach ($row['errors'] as $err): ?>
                                            <li><span class="helper-text"><?= e((string) $err) ?></span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($valid !== []): ?>
            <section class="panel-card stack-md">
                <div class="panel-head">
                    <div>
                        <h2 class="panel-title">แถวที่พร้อมนำเข้า</h2>
                        <p class="field-hint">บัญชีที่ไม่มีรหัสผ่านจะถูกส่งอีเมลตั้งรหัสผ่านอัตโนมัติ</p>
                    </div>
                    <span class="badge badge-success"><?= e((string) count($valid)) ?> แถว</span>
                </div>
                <div class="table-wrap">
                    <table class="insight-table">
                        <thead><tr><th>บรรทัด</th><th>username</th><th>email</th><th>full_name</th><th>role</th><th>password</th></tr></thead>
                        <tbody>
                        <?php foreach ($valid as $row): ?>
                            <tr>
                                <td><code class="mono"><?= e((string) $row['line']) ?></code></td>
                                <td><code class="mono"><?= e((string) $row['username']) ?></code></td>
                                <td><?= e((string) $row['email']) ?></td>
                                <td><?= e((string) $row['full_name']) ?></td>
                                <td><span class="badge badge-info"><?= e((string) $row['role']) ?></span></td>
                                <td>
                                    <?php if (!empty($row['auto_password'])): ?>
                                        <span class="badge badge-warning">auto + อีเมลตั้งรหัส</span>
                                    <?php else: ?>
                                        <span class="badge badge-default">กำหนดเอง</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="post" action="<?= e(url('/admin/users/import/execute')) ?>" data-loading-submit data-confirm-submit="ยืนยันนำเข้า <?= count($valid) ?> ผู้ใช้?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="import_token" value="<?= e($importToken ?? '') ?>">

                    <div class="button-row">
                        <?= render_partial('partials/components/button', [
                            'type' => 'submit',
                            'label' => 'ยืนยันนำเข้า ' . count($valid) . ' ผู้ใช้',
                            'variant' => 'primary',
                            'icon' => 'check-circle',
                            'size' => 'lg',
                        ]) ?>
                        <?= render_partial('partials/components/button', [
                            'label' => 'ยกเลิก / อัปโหลดไฟล์ใหม่',
                            'variant' => 'ghost',
                            'href' => '/admin/users/import',
                            'icon' => 'x',
                        ]) ?>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel-card stack-md">
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'triangle-alert',
                    'title' => 'ไม่มีแถวที่พร้อมนำเข้า',
                    'description' => 'แก้ไฟล์ CSV ตามข้อผิดพลาดด้านบน แล้วอัปโหลดใหม่',
                ]) ?>
                <div class="button-row">
                    <?= render_partial('partials/components/button', ['label' => 'อัปโหลดไฟล์ใหม่', 'variant' => 'primary', 'href' => '/admin/users/import', 'icon' => 'arrow-right', 'iconPosition' => 'right']) ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
