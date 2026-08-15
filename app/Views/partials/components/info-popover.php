<?php
/**
 * ปุ่ม (i) พร้อมกล่องคำอธิบายที่กางออกมาใต้ช่องกรอก — ใช้อธิบายศัพท์ตรงจุดที่ผู้ใช้เจอมัน แทนที่จะให้ไปเปิดคู่มือ
 *
 * ตัวเปิด-ปิดอยู่ใน public/assets/js/app.js แล้ว (ผูกกับ [data-info-toggle] ทุกตัวบนหน้า) และสไตล์ .field-info-icon
 * / .field-info-popover ก็มีอยู่ใน CSS ที่ build แล้ว — partial นี้แค่รวมรูปแบบ markup ที่เคยเขียนมือซ้ำ ๆ ไว้ที่เดียว
 *
 * ห้ามใช้ในหน้าพิมพ์/PDF: ไฟล์ PDF ไม่มี JS ปุ่มจะค้างอยู่สถานะปิดและคำอธิบายจะไม่มีวันโผล่
 * ห้ามวางในบริเวณที่ aria-hidden หรือซ้อนอยู่ในลิงก์/ปุ่มอื่น (ปุ่มโฟกัสได้ในที่ซ่อน = ผิดหลัก a11y)
 *
 * @var string                     $id     id ของกล่องคำอธิบาย (ต้องไม่ซ้ำในหน้าเดียวกัน)
 * @var string                     $label  ชื่อสิ่งที่อธิบาย ใช้ประกอบ aria-label ของปุ่ม
 * @var string                     $lead   ประโยคเปิด (ตัวหนาคือ $label)
 * @var array<int, string>         $notes  บรรทัดขยายความ (ไม่บังคับ)
 * @var array<string, string>      $levels ตารางระดับ: หัวข้อ => คำอธิบาย (ไม่บังคับ)
 */
$id = (string) ($id ?? '');
$label = (string) ($label ?? '');
$lead = (string) ($lead ?? '');
$notes = is_array($notes ?? null) ? $notes : [];
$levels = is_array($levels ?? null) ? $levels : [];
?>
<button type="button" class="field-info-icon" data-info-toggle="<?= e($id) ?>" aria-expanded="false" aria-controls="<?= e($id) ?>" aria-label="ดูคำอธิบาย<?= e($label) ?>">
    <?= lucide('info', 'h-4 w-4') ?>
</button>
<div id="<?= e($id) ?>" class="field-info-popover" hidden>
    <p><strong><?= e($label) ?></strong> <?= e($lead) ?></p>
    <?php if ($notes !== []): ?>
        <ul>
            <?php foreach ($notes as $note): ?>
                <li><?= e((string) $note) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($levels !== []): ?>
        <dl class="field-info-levels">
            <?php foreach ($levels as $level => $meaning): ?>
                <dt><?= e((string) $level) ?></dt><dd><?= e((string) $meaning) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</div>
