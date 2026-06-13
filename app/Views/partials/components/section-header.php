<?php
$eyebrow = $eyebrow ?? '';
$title = $title ?? '';
$description = $description ?? '';
$actions = $actions ?? '';
?>
<div class="section-header">
    <div>
        <?php if ($eyebrow !== ''): ?><p class="panel-kicker"><?= e($eyebrow) ?></p><?php endif; ?>
        <h2 class="panel-title"><?= e($title) ?></h2>
        <?php if ($description !== ''): ?><p class="section-description"><?= e($description) ?></p><?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?><div class="button-row"><?= $actions ?></div><?php endif; ?>
</div>
