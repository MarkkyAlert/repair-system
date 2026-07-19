<?php
/**
 * กล่องยืนยันที่จัดธีมไว้แล้ว ใช้ร่วมกันทั้งแอปแทน confirm() ของเบราว์เซอร์
 *
 * วิธีใช้:
 *   render_partial('partials/components/confirm-modal', [
 *       'id'            => 'bulk-approve-confirm',          // จำเป็น และต้องไม่ซ้ำ
 *       'title'         => 'ยืนยันการอนุมัติแบบกลุ่ม',
 *       'icon'          => 'check-circle',                   // icon จาก lucide (ไม่บังคับ)
 *       'lead'          => 'การกระทำนี้ไม่สามารถย้อนกลับได้',
 *       'tone'          => 'primary',                        // primary | danger
 *       'confirm_label' => 'ยืนยัน',
 *       'cancel_label'  => 'ยกเลิก',
 *       'summary_slot'  => '<dl class="confirm-modal-summary">...</dl>',  // HTML (ไม่บังคับ)
 *   ]);
 *
 * เปิดกล่องนี้ด้วย element ที่มี [data-confirm-modal-trigger="<id>"] ตัว handler กลาง
 * ใน public/assets/js/app.js จะหา form แม่ (ถ้ามี) แล้ว submit ให้
 * ผ่าน requestSubmit() เพื่อให้ event submit ยิงครบตามปกติ
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
