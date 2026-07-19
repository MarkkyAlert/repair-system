<?php
/**
 * ตัวห่อ (wrapper) มาตรฐานของ data-table บนเว็บ — แหล่งเดียวที่กำหนดว่าตารางรายการฝั่ง admin/report จะเขียน markup อย่างไร.
 * ยึดตามแบบแผนที่ใช้อยู่แล้วในหน้า audit/roles/settings/reports:
 *   .table-wrap (กล่องเลื่อน) > table.data-table > <caption> แบบ sr-only ถ้ามี > $slot.
 *
 * @var string      $caption  ข้อความ caption เพื่อการเข้าถึง (render เป็น <caption class="sr-only">). ไม่บังคับ.
 * @var string      $slot     markup ของ <thead>/<tbody> ที่ render มาก่อนแล้ว (จับด้วย ob_start()/ob_get_clean()).
 *
 * ตารางใน PDF/email ตั้งใจไม่ให้ผ่านตัวนี้ — เพราะเป็นบริบทการ render คนละแบบ.
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
