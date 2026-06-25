<section class="stack-lg">
    <h1 class="sr-only">ตั้งค่าการแจ้งเตือน — เลือกช่องทางและประเภทที่ต้องการรับ</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'บัญชีผู้ใช้งาน',
        'title' => 'ตั้งค่าการแจ้งเตือน',
        'description' => 'เลือกประเภทและช่องทางการแจ้งเตือนที่ต้องการรับ ค่าเริ่มต้นคือเปิดทั้งหมด',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าบัญชี',
            'variant' => 'secondary',
            'href' => '/profile',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <?php if (!empty($successMessage)): ?>
        <div class="auth-alert auth-alert-success" role="status">
            <span class="auth-alert-icon"><?= lucide('check-circle', 'h-4 w-4') ?></span>
            <p><?= e((string) $successMessage) ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="auth-alert auth-alert-danger" role="alert">
            <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
            <p><?= e((string) $errorMessage) ?></p>
        </div>
    <?php endif; ?>

    <section class="panel-card stack-md panel-narrow">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ช่องทางและประเภทการแจ้งเตือน</h2>
                <p class="field-hint">เลือก ✅ เพื่อรับ หรือเอาออกเพื่อปิดเฉพาะรายการ</p>
            </div>
            <span class="metric-icon metric-icon-md"><?= lucide('bell', 'h-5 w-5') ?></span>
        </div>

        <form method="post" action="<?= e(url('/profile/notifications')) ?>" class="stack-md" data-loading-submit>
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="insight-table">
                    <thead>
                    <tr>
                        <th>ประเภทการแจ้งเตือน</th>
                        <th style="text-align:center">In-app (กระดิ่ง)</th>
                        <th style="text-align:center">Email</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preferences as $type => $pref): ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $pref['label']) ?></strong>
                                <p class="helper-text mono"><?= e($type) ?></p>
                            </td>
                            <td style="text-align:center">
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="pref[<?= e($type) ?>][in_app]" value="1"<?= !empty($pref['in_app']) ? ' checked' : '' ?>>
                                </label>
                            </td>
                            <td style="text-align:center">
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="pref[<?= e($type) ?>][email]" value="1"<?= !empty($pref['email']) ? ' checked' : '' ?>>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="button-row">
                <?= render_partial('partials/components/button', [
                    'type' => 'submit',
                    'label' => 'บันทึกการตั้งค่า',
                    'variant' => 'primary',
                    'icon' => 'check-circle',
                ]) ?>
                <?= render_partial('partials/components/button', [
                    'label' => 'ยกเลิก',
                    'variant' => 'ghost',
                    'href' => '/profile',
                ]) ?>
            </div>
        </form>
    </section>
</section>
