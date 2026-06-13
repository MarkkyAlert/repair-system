<?php
$tone = $tone ?? 'default';
$icon = $icon ?? match ($tone) {
    'success' => 'check-circle',
    'warning' => 'clock',
    'danger' => 'triangle-alert',
    'info' => 'activity',
    default => 'layers',
};
$href = $href ?? null;
$tag = is_string($href) && $href !== '' ? 'a' : 'article';
$attributes = $tag === 'a' ? ' href="' . e(url($href)) . '"' : '';
?>
<<?= $tag ?> class="metric-card metric-card-<?= e($tone) ?>"<?= $attributes ?>>
    <div class="metric-card-head">
        <span class="metric-icon"><?= lucide($icon, 'h-5 w-5') ?></span>
        <?php if (!empty($trend)): ?>
            <span class="metric-trend">
                <?= lucide('trending-up', 'h-3 w-3') ?>
                <?= e($trend) ?>
            </span>
        <?php endif; ?>
    </div>
    <p class="metric-title"><?= e($title ?? '') ?></p>
    <p class="metric-value"><?= e($value ?? '') ?></p>
    <p class="metric-meta"><?= e($meta ?? '') ?></p>
</<?= $tag ?>>
