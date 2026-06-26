<?php
$eyebrow = $eyebrow ?? 'ศูนย์ควบคุมงานซ่อมบำรุง';
$title = $title ?? '';
$description = $description ?? '';
$actions = $actions ?? '';
$breadcrumbs = is_array($breadcrumbs ?? null) ? $breadcrumbs : [];
?>
<?php if ($breadcrumbs !== []): ?>
    <?= render_partial('partials/components/breadcrumb', ['items' => $breadcrumbs]) ?>
<?php endif; ?>
<section class="page-hero">
    <div class="page-hero-copy">
        <p class="page-hero-eyebrow"><?= e($eyebrow) ?></p>
        <h2 class="page-hero-title"><?= e($title) ?></h2>
        <?php if ($description !== ''): ?><p class="page-hero-description"><?= e($description) ?></p><?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?><div class="page-hero-actions"><?= $actions ?></div><?php endif; ?>
</section>
