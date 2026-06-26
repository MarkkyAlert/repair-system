<?php
/**
 * Shared themed confirm dialog. Replaces native confirm() across the app.
 *
 * Usage:
 *   render_partial('partials/components/confirm-modal', [
 *       'id'            => 'bulk-approve-confirm',          // required, unique
 *       'title'         => 'ยืนยันการอนุมัติแบบกลุ่ม',
 *       'icon'          => 'check-circle',                   // lucide icon (optional)
 *       'lead'          => 'การกระทำนี้ไม่สามารถย้อนกลับได้',
 *       'tone'          => 'primary',                        // primary | danger
 *       'confirm_label' => 'ยืนยัน',
 *       'cancel_label'  => 'ยกเลิก',
 *       'summary_slot'  => '<dl class="confirm-modal-summary">...</dl>',  // optional HTML
 *   ]);
 *
 * Triggered by any element with [data-confirm-modal-trigger="<id>"]. The global
 * handler in public/assets/js/app.js finds the parent form (if any) and submits
 * it via requestSubmit() to fire the submit event correctly.
 */
$id            = (string) ($id ?? '');
$title         = (string) ($title ?? 'ยืนยันการดำเนินการ');
$icon          = (string) ($icon ?? 'alert-circle');
$lead          = (string) ($lead ?? '');
$tone          = (string) ($tone ?? 'primary');
$confirmLabel  = (string) ($confirm_label ?? 'ยืนยัน');
$cancelLabel   = (string) ($cancel_label ?? 'ยกเลิก');
$summarySlot   = (string) ($summary_slot ?? '');
$confirmClass  = $tone === 'danger' ? 'btn btn-danger' : 'btn btn-primary';
$toneClass     = $tone === 'danger' ? ' is-danger' : '';
?>
<div id="<?= e($id) ?>" class="confirm-modal<?= $toneClass ?>" hidden role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-title">
    <div class="confirm-modal-backdrop" data-modal-close></div>
    <div class="confirm-modal-card">
        <div class="confirm-modal-head">
            <h3 id="<?= e($id) ?>-title" class="confirm-modal-title">
                <?= lucide($icon, 'h-5 w-5') ?>
                <?= e($title) ?>
            </h3>
        </div>
        <div class="confirm-modal-body">
            <?php if ($lead !== ''): ?>
                <p class="confirm-modal-lead"><?= e($lead) ?></p>
            <?php endif; ?>
            <?= $summarySlot ?>
        </div>
        <div class="confirm-modal-foot">
            <button type="button" class="btn btn-ghost" data-modal-close><?= e($cancelLabel) ?></button>
            <button type="button" class="<?= e($confirmClass) ?>" data-modal-submit>
                <?= lucide($icon, 'h-4 w-4') ?> <?= e($confirmLabel) ?>
            </button>
        </div>
    </div>
</div>
