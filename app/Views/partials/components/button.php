<?php
$variant = $variant ?? 'primary';
$type = $type ?? 'button';
$href = $href ?? null;
$label = $label ?? 'Button';
$icon = $icon ?? null;
$fullWidth = $fullWidth ?? false;
$size = $size ?? 'md';
$iconPosition = $iconPosition ?? 'left';
$disabled = (bool) ($disabled ?? false);
$ariaLabel = $ariaLabel ?? null;
$classes = 'btn btn-' . $variant . ' btn-' . $size . ($fullWidth ? ' btn-block' : '') . ($disabled ? ' is-disabled' : '');
$resolvedHref = null;

if (is_string($href) && $href !== '') {
    $resolvedHref = preg_match('#^https?://#i', $href) ? $href : url($href);
}
?>
<?php if ($resolvedHref): ?>
    <a href="<?= e($resolvedHref) ?>" class="<?= e($classes) ?>"<?= $ariaLabel ? ' aria-label="' . e($ariaLabel) . '"' : '' ?><?= $disabled ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
        <?php if ($icon && $iconPosition !== 'right'): ?><?= lucide($icon, 'button-icon') ?><?php endif; ?>
        <span><?= e($label) ?></span>
        <?php if ($icon && $iconPosition === 'right'): ?><?= lucide($icon, 'button-icon') ?><?php endif; ?>
    </a>
<?php else: ?>
    <button type="<?= e($type) ?>" class="<?= e($classes) ?>"<?= $ariaLabel ? ' aria-label="' . e($ariaLabel) . '"' : '' ?><?= $disabled ? ' disabled' : '' ?>>
        <?php if ($icon && $iconPosition !== 'right'): ?><?= lucide($icon, 'button-icon') ?><?php endif; ?>
        <span><?= e($label) ?></span>
        <?php if ($icon && $iconPosition === 'right'): ?><?= lucide($icon, 'button-icon') ?><?php endif; ?>
    </button>
<?php endif; ?>
