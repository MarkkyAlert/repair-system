# Third-Party Licenses / ลิขสิทธิ์ซอฟต์แวร์บุคคลที่สาม

ระบบ Repair System ใช้ไลบรารีโอเพนซอร์สต่อไปนี้ ซึ่งแต่ละตัวมีสัญญาอนุญาต (license)
ของตนเอง ผู้ซื้อและผู้ใช้ต้องคงข้อความแสดงลิขสิทธิ์เดิมของไลบรารีเหล่านี้ไว้
(ไฟล์ license ต้นฉบับอยู่ในโฟลเดอร์ `vendor/` ของแต่ละแพ็กเกจ)

ไลบรารีทั้งหมดนี้ใช้สัญญาอนุญาตแบบ **MIT, BSD, หรือ LGPL** ซึ่งอนุญาตให้รวมและ
แจกจ่ายในซอฟต์แวร์เชิงพาณิชย์ได้ โดยไม่บังคับให้เปิดเผยซอร์สโค้ดของแอปพลิเคชัน

## Dependencies (Production)

| Package | License | Source |
|---|---|---|
| `bacon/bacon-qr-code` | BSD-2-Clause | https://github.com/Bacon/BaconQrCode |
| `composer/pcre` | MIT | https://github.com/composer/pcre |
| `dasprid/enum` | BSD-2-Clause | https://github.com/DASPRiD/Enum |
| `dompdf/dompdf` | LGPL-2.1 | https://github.com/dompdf/dompdf |
| `dompdf/php-font-lib` | LGPL-2.1-or-later | https://github.com/dompdf/php-font-lib |
| `dompdf/php-svg-lib` | LGPL-3.0-or-later | https://github.com/dompdf/php-svg-lib |
| `endroid/qr-code` | MIT | https://github.com/endroid/qr-code |
| `maennchen/zipstream-php` | MIT | https://github.com/maennchen/ZipStream-PHP |
| `markbaker/complex` | MIT | https://github.com/MarkBaker/PHPComplex |
| `markbaker/matrix` | MIT | https://github.com/MarkBaker/PHPMatrix |
| `masterminds/html5` | MIT | https://github.com/Masterminds/html5-php |
| `phpmailer/phpmailer` | LGPL-2.1-only | https://github.com/PHPMailer/PHPMailer |
| `phpoffice/phpspreadsheet` | MIT | https://github.com/PHPOffice/PhpSpreadsheet |
| `psr/simple-cache` | MIT | https://github.com/php-fig/simple-cache |
| `sabberworm/php-css-parser` | MIT | https://github.com/MyIntervals/PHP-CSS-Parser |
| `thecodingmachine/safe` | MIT | https://github.com/thecodingmachine/safe |

## Dependencies (Development only — ไม่รวมในการใช้งานจริง)

| Package | License | Source |
|---|---|---|
| `phpstan/phpstan` | MIT | https://github.com/phpstan/phpstan |
| `smalot/pdfparser` | LGPL-3.0 | https://github.com/smalot/pdfparser |
| `symfony/polyfill-mbstring` | MIT | https://github.com/symfony/polyfill-mbstring |

## หมายเหตุเกี่ยวกับ LGPL

ไลบรารี `dompdf/*` และ `phpmailer/phpmailer` ใช้สัญญาอนุญาตแบบ LGPL ซึ่งอนุญาตให้
ใช้ในซอฟต์แวร์เชิงพาณิชย์ได้โดยไม่ต้องเปิดเผยซอร์สโค้ดของแอปพลิเคชัน โดยมีเงื่อนไขหลักคือ
ต้องคงไฟล์ license เดิมไว้ และผู้ใช้ปลายทางต้องสามารถเปลี่ยน/อัปเดตไลบรารีเหล่านี้ได้
(ซึ่งทำได้ผ่าน Composer หรือการแทนที่ไฟล์ในโฟลเดอร์ `vendor/`)

---

*รายการนี้สร้างจาก `composer.lock` — เมื่ออัปเดต dependency ควรตรวจสอบและปรับปรุงรายการนี้ให้ตรง*
