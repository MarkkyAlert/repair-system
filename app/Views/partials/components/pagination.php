<?php
$page = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$total = max(0, (int) ($pagination['total'] ?? 0));
// Base query params + path come from the caller when provided (keeps this a reusable component,
// no hidden $_GET/request() coupling); otherwise fall back to the current request. `page` is owned here.
$query = $query ?? ($_GET ?? []);
unset($query['page']);
$path = $path ?? (string) (request()?->path ?? '/');
$pageUrl = static function (int $target) use ($query, $path): string {
    return url($path . '?' . http_build_query($query + ['page' => $target]));
};
?>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="การแบ่งหน้า">
    <span class="pagination-summary"><?= e((string) $total) ?> รายการ · หน้า <?= e((string) $page) ?>/<?= e((string) $totalPages) ?></span>
    <a class="page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= e($pageUrl(max(1, $page - 1))) ?>" aria-label="หน้าก่อนหน้า"<?= $page <= 1 ? ' aria-disabled="true"' : '' ?>><?= lucide('chevron-left', 'h-4 w-4') ?></a>
    <?php for ($target = max(1, $page - 2); $target <= min($totalPages, $page + 2); $target++): ?>
        <a class="page-link<?= $target === $page ? ' is-active' : '' ?>" href="<?= e($pageUrl($target)) ?>" aria-label="<?= e($target === $page ? 'หน้าปัจจุบัน หน้าที่ ' . $target : 'ไปหน้าที่ ' . $target) ?>"<?= $target === $page ? ' aria-current="page"' : '' ?>><?= e((string) $target) ?></a>
    <?php endfor; ?>
    <a class="page-link<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= e($pageUrl(min($totalPages, $page + 1))) ?>" aria-label="หน้าถัดไป"<?= $page >= $totalPages ? ' aria-disabled="true"' : '' ?>><?= lucide('chevron-right', 'h-4 w-4') ?></a>
</nav>
<?php endif; ?>
