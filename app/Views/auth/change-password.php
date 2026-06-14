<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'บัญชีผู้ใช้งาน',
        'title' => 'เปลี่ยนรหัสผ่าน',
        'description' => 'ยืนยันรหัสผ่านปัจจุบันก่อนกำหนดรหัสผ่านใหม่',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับ Dashboard',
            'variant' => 'secondary',
            'href' => '/dashboard',
            'icon' => 'chevrons-left',
        ]),
    ]) ?>

    <section class="panel-card stack-md" style="max-width:720px">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ตั้งรหัสผ่านใหม่</h2>
                <p class="field-hint">รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร และไม่เหมือนรหัสผ่านเดิม</p>
            </div>
            <span class="metric-icon" style="width:40px;height:40px;flex:0 0 40px"><?= lucide('key-round', 'h-5 w-5') ?></span>
        </div>

        <form method="post" action="<?= e(url('/change-password')) ?>" class="stack-md">
            <?= csrf_field() ?>
            <div class="field-group">
                <label for="current_password" class="field-label">รหัสผ่านปัจจุบัน <span class="required">*</span></label>
                <input id="current_password" name="current_password" type="password" class="input" required autocomplete="current-password">
            </div>
            <div class="content-grid">
                <div class="field-group">
                    <label for="password" class="field-label">รหัสผ่านใหม่ <span class="required">*</span></label>
                    <input id="password" name="password" type="password" class="input" required minlength="8" autocomplete="new-password">
                </div>
                <div class="field-group">
                    <label for="password_confirmation" class="field-label">ยืนยันรหัสผ่านใหม่ <span class="required">*</span></label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="input" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกรหัสผ่านใหม่', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
            </div>
        </form>
    </section>
</section>
