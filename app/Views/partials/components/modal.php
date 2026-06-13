<div class="modal-shell">
    <div>
        <p class="panel-kicker"><?= e($kicker ?? 'Modal') ?></p>
        <h3 class="modal-title"><?= e($title ?? 'Modal Title') ?></h3>
        <?php if (!empty($description)): ?>
            <p class="modal-copy"><?= e($description) ?></p>
        <?php endif; ?>
    </div>
    <div>
        <?= $slot ?? '' ?>
    </div>
</div>
