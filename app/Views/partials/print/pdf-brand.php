<?php
// แถบแบรนด์องค์กรที่ใช้ร่วมกันบนหัวของ PDF ทุกใบ (ทั้ง ticket + ไฟล์ export รายงานทุกชนิด). อ่านค่า app_name +
// app_tagline + โลโก้ที่อัปโหลดจากหน้า Admin System Settings ดังนั้นลูกค้าที่เปลี่ยนแบรนด์ผ่าน UI ก็จะได้
// เอกสารที่เปลี่ยนแบรนด์ตามไปด้วย — โดยไม่ต้องแก้ PHP ทีละ view. ใช้ inline style ในตัวเอง (สำหรับ dompdf) วางอยู่เหนือ
// ส่วนหัวตกแต่งที่บอกชนิดรายงาน (kicker) ของเอกสารแต่ละใบ.
$pdfBrandName = trim((string) setting('app_name', config('app.name', 'Repair System')));
$pdfBrandTagline = trim((string) setting('app_tagline', ''));
$pdfBrandLogo = branding_logo_data_uri();
?>
<div style="margin-bottom: 6px; line-height: 1.25;">
    <?php if ($pdfBrandLogo !== null): ?><img src="<?= e($pdfBrandLogo) ?>" alt="" style="max-height: 22px; max-width: 150px; vertical-align: middle; margin-right: 7px;"><?php endif; ?><span style="font-size: 11px; font-weight: bold; color: inherit;"><?= e($pdfBrandName) ?></span><?php if ($pdfBrandTagline !== ''): ?><span style="font-size: 10px; color: inherit; opacity: .85;"> · <?= e($pdfBrandTagline) ?></span><?php endif; ?>
</div>
