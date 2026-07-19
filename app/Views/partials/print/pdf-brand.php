<?php
// แถบแบรนด์องค์กร ใช้ร่วมกันบนหัว PDF ทุกใบ — ทั้ง ticket และไฟล์ export รายงานทุกชนิด. ดึงค่า app_name
// app_tagline และโลโก้ที่อัปโหลดจากหน้า Admin System Settings มาแสดง เปลี่ยนแบรนด์ผ่าน UI แล้ว
// เอกสารก็เปลี่ยนตามเลย ไม่ต้องไล่แก้ PHP ทีละ view. ใส่ style แบบ inline มาในตัวเองเพราะ render บน dompdf วางไว้เหนือ
// ส่วนหัวตกแต่ง (kicker) ที่บอกชนิดรายงานของแต่ละใบ.
$pdfBrandName = trim((string) setting('app_name', config('app.name', 'Repair System')));
$pdfBrandTagline = trim((string) setting('app_tagline', ''));
$pdfBrandLogo = branding_logo_data_uri();
?>
<div style="margin-bottom: 6px; line-height: 1.25;">
    <?php if ($pdfBrandLogo !== null): ?><img src="<?= e($pdfBrandLogo) ?>" alt="" style="max-height: 22px; max-width: 150px; vertical-align: middle; margin-right: 7px;"><?php endif; ?><span style="font-size: 11px; font-weight: bold; color: inherit;"><?= e($pdfBrandName) ?></span><?php if ($pdfBrandTagline !== ''): ?><span style="font-size: 10px; color: inherit; opacity: .85;"> · <?= e($pdfBrandTagline) ?></span><?php endif; ?>
</div>
