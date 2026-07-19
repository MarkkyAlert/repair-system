<?php
/**
 * Breadcrumb (แถบนำทางบอกตำแหน่งหน้า) สำหรับหน้าลึก ๆ (หน้ารายละเอียด / หน้า admin ที่ซ้อนกัน).
 *
 * วิธีใช้:
 *   render_partial('partials/components/breadcrumb', [
 *       'items' => [
 *           ['label' => 'Admin', 'href' => '/admin'],
 *           ['label' => 'Email Templates', 'href' => '/admin/email-templates'],
 *           ['label' => 'Ticket Approved'],          // รายการสุดท้าย: ไม่ใส่ href = หน้าปัจจุบัน
 *       ],
 *   ]);
 */
$items = is_array($items ?? null) ? $items : [];
if ($items === []) {
    return;
}
$lastIndex = count($items) - 1;
?>
<nav class="breadcrumb" aria-label="เส้นทางนำทาง">
    <ol>
        <?php foreach ($items as $index => $item):
            $label = (string) ($item['label'] ?? '');
            $href = (string) ($item['href'] ?? '');
            $isLast = $index === $lastIndex;
        ?>
            <?php if ($index > 0): ?>
                <li aria-hidden="true">/</li>
            <?php endif; ?>
            <?php if ($isLast || $href === ''): ?>
                <li aria-current="page"><?= e($label) ?></li>
            <?php else: ?>
                <li><a href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
