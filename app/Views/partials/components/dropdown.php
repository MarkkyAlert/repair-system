<div class="dropdown-shell">
    <?php if (!empty($title)): ?>
        <h3 class="modal-title"><?= e($title) ?></h3>
    <?php endif; ?>
    <div class="stack-md">
        <?= $slot ?? '' ?>
    </div>
</div>
