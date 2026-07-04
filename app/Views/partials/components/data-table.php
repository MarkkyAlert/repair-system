<?php
/**
 * Canonical web data-table wrapper — the single source for how admin/report list tables are marked up.
 * Matches the convention already used across audit/roles/settings/reports:
 *   .table-wrap (scroll container) > table.data-table > optional sr-only <caption> > $slot.
 *
 * @var string      $caption  Accessible caption text (rendered as <caption class="sr-only">). Optional.
 * @var string      $slot     Pre-rendered <thead>/<tbody> markup (capture with ob_start()/ob_get_clean()).
 *
 * PDF/email tables are intentionally NOT routed through this — different rendering context.
 */
?>
<div class="table-wrap">
    <table class="data-table">
        <?php if (!empty($caption)): ?>
            <caption class="sr-only"><?= e($caption) ?></caption>
        <?php endif; ?>
        <?= $slot ?? '' ?>
    </table>
</div>
