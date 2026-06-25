<section class="stack-lg">
    <h1 class="sr-only">ข้อมูลบัญชีของฉัน — แก้ไขชื่อ อีเมล และเบอร์โทรศัพท์</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'บัญชีผู้ใช้งาน',
        'title' => 'ข้อมูลบัญชีของฉัน',
        'description' => 'แก้ไขชื่อ-นามสกุล อีเมล และเบอร์โทรศัพท์ของคุณ',
        'actions' => render_partial('partials/components/button', [
            'label' => 'เปลี่ยนรหัสผ่าน',
            'variant' => 'secondary',
            'href' => '/change-password',
            'icon' => 'key-round',
        ]),
    ]) ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="toast-stack" aria-live="polite">
            <?= render_partial('partials/components/toast', ['tone' => 'danger', 'message' => (string) $errorMessage]) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($successMessage)): ?>
        <div class="toast-stack" aria-live="polite">
            <?= render_partial('partials/components/toast', ['tone' => 'success', 'message' => (string) $successMessage]) ?>
        </div>
    <?php endif; ?>

    <section class="panel-card stack-md panel-narrow">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">รายละเอียดบัญชี</h2>
                <p class="field-hint">ชื่อผู้ใช้และสิทธิ์ของคุณกำหนดโดยผู้ดูแลระบบ ไม่สามารถแก้ไขจากที่นี่ได้</p>
            </div>
            <span class="metric-icon metric-icon-md"><?= lucide('user', 'h-5 w-5') ?></span>
        </div>

        <div class="content-grid">
            <div class="field-group">
                <label class="field-label">ชื่อผู้ใช้ (Username)</label>
                <input class="input" type="text" value="<?= e($profile['username'] ?? '') ?>" disabled>
            </div>
            <div class="field-group">
                <label class="field-label">บทบาท (Role)</label>
                <input class="input" type="text" value="<?= e($profile['role'] ?? 'guest') ?>" disabled>
            </div>
        </div>

        <form method="post" action="<?= e(url('/profile')) ?>" class="stack-md" data-loading-submit data-warn-unsaved>
            <?= csrf_field() ?>
            <div class="field-group">
                <label for="full_name" class="field-label">ชื่อ-นามสกุล <span class="required">*</span></label>
                <input id="full_name" name="full_name" type="text" class="input" required maxlength="200" value="<?= e($profile['full_name'] ?? '') ?>">
            </div>
            <div class="content-grid">
                <div class="field-group">
                    <label for="email" class="field-label">อีเมล <span class="required">*</span></label>
                    <input id="email" name="email" type="email" class="input" required value="<?= e($profile['email'] ?? '') ?>">
                </div>
                <div class="field-group">
                    <label for="phone" class="field-label">เบอร์โทรศัพท์</label>
                    <input id="phone" name="phone" type="tel" class="input" maxlength="30" value="<?= e($profile['phone'] ?? '') ?>" placeholder="เช่น 0812345678">
                    <p class="field-hint">ใช้สำหรับการแจ้งเตือนและติดต่อภายในองค์กร</p>
                </div>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึกข้อมูลบัญชี', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ยกเลิก', 'variant' => 'secondary', 'href' => '/dashboard']) ?>
            </div>
        </form>
    </section>

    <section class="panel-card stack-md panel-narrow">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ความปลอดภัย</h2>
                <p class="field-hint">เปลี่ยนรหัสผ่านเป็นระยะเพื่อความปลอดภัยของบัญชี</p>
            </div>
            <span class="metric-icon metric-icon-md"><?= lucide('shield-check', 'h-5 w-5') ?></span>
        </div>
        <div class="button-row">
            <?= render_partial('partials/components/button', ['label' => 'เปลี่ยนรหัสผ่าน', 'variant' => 'secondary', 'href' => '/change-password', 'icon' => 'key-round']) ?>
        </div>
    </section>
</section>
