<?php
$fieldLabels = [
    'heading' => ['label' => 'หัวข้อ', 'hint' => 'ข้อความหัวเรื่องใหญ่ในเนื้ออีเมล หากเว้นว่างระบบจะใช้ค่าเริ่มต้นตามเหตุการณ์'],
    'intro' => ['label' => 'คำนำ', 'hint' => 'ข้อความแนะนำสั้น ๆ ก่อนเนื้อหา'],
    'footer_note' => ['label' => 'ข้อความท้ายอีเมล', 'hint' => 'มักใช้ระบุที่มาของระบบ เช่น "ส่งจากระบบแจ้งซ่อม"'],
];
$hasOverride = ($values ?? []) !== [];
?>
<section class="stack-lg">
    <h1 class="sr-only">แก้ไขเทมเพลตอีเมล</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'เทมเพลตอีเมล',
        'title' => (string) ($meta['label'] ?? $templateKey),
        'description' => 'แก้ข้อความที่ผู้รับเห็นในอีเมล โดยไม่ต้องแก้โค้ด',
        'breadcrumbs' => [
            ['label' => 'ตั้งค่าระบบ', 'href' => '/admin'],
            ['label' => 'เทมเพลตอีเมล', 'href' => '/admin/email-templates'],
            ['label' => (string) ($meta['label'] ?? $templateKey)],
        ],
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับรายการเทมเพลต',
            'variant' => 'secondary',
            'href' => '/admin/email-templates',
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

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title"><code class="mono"><?= e($templateKey) ?></code></h2>
                <p class="field-hint">เว้นช่องว่างเพื่อใช้ค่าเริ่มต้น</p>
            </div>
            <?php if ($hasOverride): ?>
                <?= render_partial('partials/components/badge', ['label' => 'ปรับแต่งแล้ว', 'tone' => 'warning']) ?>
            <?php else: ?>
                <?= render_partial('partials/components/badge', ['label' => 'ค่าเริ่มต้น', 'tone' => 'default']) ?>
            <?php endif; ?>
        </div>

        <form method="post" action="<?= e(url('/admin/email-templates/' . rawurlencode($templateKey))) ?>" class="stack-md" data-loading-submit data-warn-unsaved>
            <?= csrf_field() ?>

            <?php foreach (($meta['fields'] ?? []) as $fieldKey): ?>
                <?php $info = $fieldLabels[$fieldKey] ?? ['label' => $fieldKey, 'hint' => '']; ?>
                <div class="field-group">
                    <label for="field-<?= e($fieldKey) ?>" class="field-label"><?= e($info['label']) ?></label>
                    <textarea
                        id="field-<?= e($fieldKey) ?>"
                        name="<?= e($fieldKey) ?>"
                        class="input"
                        rows="<?= $fieldKey === 'intro' || $fieldKey === 'footer_note' ? 3 : 2 ?>"
                        placeholder="<?= e((string) ($defaults[$fieldKey] ?? '')) ?>"
                    ><?= e((string) ($values[$fieldKey] ?? '')) ?></textarea>
                    <p class="field-hint">
                        <?= e($info['hint']) ?>
                        · ค่าเริ่มต้น: <em><?= e((string) ($defaults[$fieldKey] ?? '-')) ?></em>
                    </p>
                </div>
            <?php endforeach; ?>

            <div class="button-row">
                <?= render_partial('partials/components/button', [
                    'type' => 'submit',
                    'label' => 'บันทึก',
                    'variant' => 'primary',
                    'icon' => 'check-circle',
                ]) ?>
                <?= render_partial('partials/components/button', [
                    'label' => 'ยกเลิก',
                    'variant' => 'ghost',
                    'href' => '/admin/email-templates',
                ]) ?>
            </div>
        </form>

        <?php if ($hasOverride): ?>
            <form method="post" action="<?= e(url('/admin/email-templates/' . rawurlencode($templateKey) . '/reset')) ?>" onsubmit="return confirm('คืนค่า template นี้เป็นค่าเริ่มต้น? การแก้ไขทั้งหมดจะหายไป');">
                <?= csrf_field() ?>
                <?= render_partial('partials/components/button', [
                    'type' => 'submit',
                    'label' => 'คืนค่าเริ่มต้น',
                    'variant' => 'danger',
                    'icon' => 'refresh-cw',
                    'size' => 'sm',
                ]) ?>
            </form>
        <?php endif; ?>
    </section>
</section>
