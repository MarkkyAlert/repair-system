<?php
$page = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$total = max(0, (int) ($pagination['total'] ?? 0));
$query = $_GET ?? [];
unset($query['page']);
$pageUrl = static function (int $target) use ($query): string {
    $next = $query + ['page' => $target];
    return url((string) (request()?->path ?? '/') . '?' . http_build_query($next));
};
?>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pagination">
    <span class="pagination-summary"><?= e((string) $total) ?> รายการ · หน้า <?= e((string) $page) ?>/<?= e((string) $totalPages) ?></span>
    <a class="page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= e($pageUrl(max(1, $page - 1))) ?>" aria-label="หน้าก่อนหน้า"><?= lucide('chevron-left', 'h-4 w-4') ?></a>
    <?php for ($target = max(1, $page - 2); $target <= min($totalPages, $page + 2); $target++): ?>
        <a class="page-link<?= $target === $page ? ' is-active' : '' ?>" href="<?= e($pageUrl($target)) ?>"<?= $target === $page ? ' aria-current="page"' : '' ?>><?= e((string) $target) ?></a>
    <?php endfor; ?>
    <a class="page-link<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= e($pageUrl(min($totalPages, $page + 1))) ?>" aria-label="หน้าถัดไป"><?= lucide('chevron-right', 'h-4 w-4') ?></a>
</nav>
<?php endif; ?>
