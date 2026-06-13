<div class="data-table-shell">
    <?php if (!empty($title)): ?>
        <h3 class="table-title"><?= e($title) ?></h3>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table">
            <?= $slot ?? '' ?>
        </table>
    </div>
</div>
