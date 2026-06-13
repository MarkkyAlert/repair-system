<?php $tone = $tone ?? 'default'; ?>
<?php $defaultTitle = match ($tone) {
    'danger' => 'เกิดข้อผิดพลาด',
    'warning' => 'แจ้งเตือน',
    'info' => 'ข้อมูล',
    default => 'สำเร็จ',
}; ?>
<?php $title = $title ?? $defaultTitle; ?>
<?php $iconName = match ($tone) {
    'danger' => 'triangle-alert',
    'warning' => 'triangle-alert',
    'info' => 'info',
    default => 'check-circle',
}; ?>
<div class="toast toast-<?= e($tone) ?>" data-toast role="status">
    <span class="toast-indicator"><?= lucide($iconName, 'h-5 w-5') ?></span>
    <div class="toast-body">
        <p class="toast-title"><?= e($title) ?></p>
        <p class="toast-message"><?= e($message ?? '') ?></p>
    </div>
    <button type="button" class="toast-close" aria-label="ปิดการแจ้งเตือน" data-toast-close>&times;</button>
</div>
