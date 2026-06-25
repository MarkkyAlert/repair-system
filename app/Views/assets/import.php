<?php
$hasPreview = is_array($preview ?? null);
$valid = $preview['valid'] ?? [];
$invalid = $preview['invalid'] ?? [];
$total = (int) ($preview['total'] ?? 0);
?>
<section class="stack-lg">
    <h1 class="sr-only">นำเข้าทรัพย์สินจากไฟล์ CSV</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ทะเบียนทรัพย์สิน',
        'title' => $hasPreview ? 'ตรวจสอบก่อนนำเข้า' : 'นำเข้าทรัพย์สินจาก CSV',
        'description' => $hasPreview
            ? 'ตรวจรายการที่ระบบเตรียมไว้ ก่อนยืนยันสร้างจริงในระบบ'
            : 'อัปโหลดไฟล์ CSV ที่ใช้รูปแบบเดียวกับ template ระบบ',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับรายการทรัพย์สิน',
            'variant' => 'secondary',
            'href' => '/asset-registry',
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
                    <p class="field-hint">ดาวน์โหลด template → กรอกข้อมูล → อัปโหลด → ตรวจ preview → ยืนยัน</p>
                </div>
                <?= render_partial('partials/components/button', [
                    'label' => 'ดาวน์โหลด template CSV',
                    'variant' => 'secondary',
                    'href' => '/asset-registry/import/template.csv',
                    'icon' => 'arrow-right',
                    'iconPosition' => 'right',
                ]) ?>
            </div>

            <ol class="ticket-flow-list">
                <li><span>1</span><div><strong>ใช้ template เท่านั้น</strong><span>Column ครบทุกตัว แม้บางตัวจะเว้นค่าได้</span></div></li>
                <li><span>2</span><div><strong>FK lookup ด้วย code</strong><span>category_code, location_code, department_code จากตั้งค่าระบบ และ custodian_username จากบัญชีผู้ใช้</span></div></li>
                <li><span>3</span><div><strong>วันที่</strong><span>รูปแบบ YYYY-MM-DD (เช่น 2024-01-15)</span></div></li>
                <li><span>4</span><div><strong>สูงสุด 500 รายการต่อรอบ</strong><span>ไฟล์ขนาดไม่เกิน 2MB</span></div></li>
            </ol>

            <form method="post" action="<?= e(url('/asset-registry/import')) ?>" enctype="multipart/form-data" class="stack-md" data-loading-submit>
                <?= csrf_field() ?>
                <div class="field-group">
                    <label for="csv" class="field-label">ไฟล์ CSV <span class="required">*</span></label>
                    <input id="csv" name="csv" type="file" accept=".csv,text/csv" class="input" required>
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
            <?= render_partial('partials/components/card', [
                'title' => 'ทั้งหมด',
                'value' => (string) $total,
                'meta' => 'แถวที่อ่านจากไฟล์',
                'tone' => 'default',
                'icon' => 'clipboard-list',
            ]) ?>
            <?= render_partial('partials/components/card', [
                'title' => 'พร้อมนำเข้า',
                'value' => (string) count($valid),
                'meta' => 'ผ่านการตรวจสอบทุก field',
                'tone' => 'success',
                'icon' => 'check-circle',
            ]) ?>
            <?= render_partial('partials/components/card', [
                'title' => 'มีปัญหา',
                'value' => (string) count($invalid),
                'meta' => 'ต้องแก้ไฟล์ก่อนนำเข้า',
                'tone' => 'danger',
                'icon' => 'triangle-alert',
            ]) ?>
        </div>

        <?php if ($invalid !== []): ?>
            <section class="panel-card stack-md">
                <div class="panel-head">
                    <div>
                        <h2 class="panel-title">แถวที่มีปัญหา</h2>
                        <p class="field-hint">แก้ไฟล์ CSV ตามข้อผิดพลาดด้านล่าง แล้วอัปโหลดใหม่</p>
                    </div>
                    <span class="badge badge-danger"><?= e((string) count($invalid)) ?> แถว</span>
                </div>
                <div class="table-wrap">
                    <table class="insight-table">
                        <thead>
                        <tr>
                            <th>บรรทัด</th>
                            <th>asset_code</th>
                            <th>name</th>
                            <th>ข้อผิดพลาด</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invalid as $row): ?>
                            <tr>
                                <td><code class="mono"><?= e((string) $row['line']) ?></code></td>
                                <td><code class="mono"><?= e((string) $row['asset_code']) ?></code></td>
                                <td><?= e((string) $row['name']) ?></td>
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
                        <p class="field-hint">กดยืนยันเพื่อสร้าง asset ใน DB และสร้าง QR token อัตโนมัติ</p>
                    </div>
                    <span class="badge badge-success"><?= e((string) count($valid)) ?> แถว</span>
                </div>
                <div class="table-wrap">
                    <table class="insight-table">
                        <thead>
                        <tr>
                            <th>บรรทัด</th>
                            <th>asset_code</th>
                            <th>name</th>
                            <th>category</th>
                            <th>location</th>
                            <th>status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($valid as $row): ?>
                            <tr>
                                <td><code class="mono"><?= e((string) $row['line']) ?></code></td>
                                <td><code class="mono"><?= e((string) $row['asset_code']) ?></code></td>
                                <td><?= e((string) $row['name']) ?></td>
                                <td><?= e((string) $row['asset_category_id']) ?></td>
                                <td><?= e((string) $row['location_id']) ?></td>
                                <td><?= e((string) $row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="post" action="<?= e(url('/asset-registry/import/execute')) ?>" data-loading-submit onsubmit="return confirm('ยืนยันนำเข้า <?= count($valid) ?> รายการ? ระบบจะสร้าง asset + QR token ใน DB ทันที');">
                    <?= csrf_field() ?>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', [
                            'type' => 'submit',
                            'label' => 'ยืนยันนำเข้า ' . count($valid) . ' รายการ',
                            'variant' => 'primary',
                            'icon' => 'check-circle',
                            'size' => 'lg',
                        ]) ?>
                        <?= render_partial('partials/components/button', [
                            'label' => 'ยกเลิก / อัปโหลดไฟล์ใหม่',
                            'variant' => 'ghost',
                            'href' => '/asset-registry/import',
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
                    'description' => 'แก้ไฟล์ CSV ตามข้อผิดพลาดด้านบน แล้วอัปโหลดใหม่อีกครั้ง',
                ]) ?>
                <div class="button-row">
                    <?= render_partial('partials/components/button', [
                        'label' => 'อัปโหลดไฟล์ใหม่',
                        'variant' => 'primary',
                        'href' => '/asset-registry/import',
                        'icon' => 'arrow-right',
                        'iconPosition' => 'right',
                    ]) ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
