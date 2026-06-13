<?php $tone = $tone ?? 'safe'; ?>
<span class="sla-badge sla-<?= e($tone) ?>">
    <?= lucide('bell', 'sla-icon') ?>
    <span><?= e($label ?? 'เหลือ 12 ชม.') ?></span>
</span>
