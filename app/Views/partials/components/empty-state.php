<div class="empty-state">
    <?php if (!empty($icon)): ?>
        <div class="empty-state-illustration">
            <span class="empty-state-illustration-blob empty-state-illustration-blob-1"></span>
            <span class="empty-state-illustration-blob empty-state-illustration-blob-2"></span>
            <span class="empty-state-illustration-icon"><?= lucide($icon, 'h-8 w-8') ?></span>
        </div>
    <?php endif; ?>
    <h3 class="empty-state-title"><?= e($title ?? 'ยังไม่มีข้อมูล') ?></h3>
    <?php if (!empty($description)): ?>
        <p class="empty-state-copy"><?= e($description) ?></p>
    <?php endif; ?>
    <?= $slot ?? '' ?>
</div>
