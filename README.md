# Repair System — ระบบแจ้งซ่อมและบำรุงรักษาในองค์กร

ระบบรับแจ้งซ่อม ติดตามงานช่าง วัด SLA ดูแลทรัพย์สิน (พร้อม QR) และออกรายงานผู้บริหาร — ครบในระบบเดียว
ออกแบบให้ติดตั้งบน **shared hosting / cPanel** ได้โดยไม่ต้องเป็นนักพัฒนา

> 📦 **ซื้อไปติดตั้งเอง?** ไปที่ **[INSTALL.md](INSTALL.md)** — คู่มือติดตั้งแบบจับมือทำทีละขั้นสำหรับ IT support

---

## 1. ภาพรวม (Overview)

- **ระบบคืออะไร:** ศูนย์กลางรับแจ้งซ่อม/งานบำรุงรักษาขององค์กร ตั้งแต่แจ้ง → มอบหมายช่าง → ติดตาม SLA → ปิดงาน → ให้คะแนน → ออกรายงาน
- **เหมาะกับใคร:** องค์กรขนาดเล็ก–กลาง (โรงเรียน โรงพยาบาล โรงงาน สำนักงาน อาคาร) ที่อยากเลิกแจ้งซ่อมผ่าน LINE/กระดาษ
- **ผู้ใช้ 4 กลุ่ม:**
  - **ผู้แจ้ง (requester)** — แจ้งซ่อม ดูสถานะงานตัวเอง
  - **ช่าง (technician)** — รับงาน อัปเดตสถานะ บันทึกเวลาทำงาน
  - **หัวหน้า (manager)** — มอบหมาย/ย้ายงาน ดูภาพรวมทีม
  - **ผู้ดูแลระบบ (admin)** — ตั้งค่าทั้งหมด จัดการผู้ใช้ ดูรายงาน
  - **แขก (guest)** — สแกน QR ที่เครื่อง แจ้งซ่อมได้โดยไม่ต้องมีบัญชี

## 2. ฟีเจอร์หลัก (จาก source จริง)

- **แจ้งซ่อม + ติดตามสถานะ** พร้อมแนบรูป, ประวัติงาน, การเปิดงานซ้ำ (reopen), ให้คะแนนความพึงพอใจ (CSAT)
- **SLA อัตโนมัติ** — คำนวณกำหนดเวลาตาม priority/หมวดหมู่ แจ้งเตือนเมื่อใกล้/เกินกำหนด (ต้องตั้ง cron)
- **ทรัพย์สิน + QR Code** — ทะเบียนทรัพย์สิน สร้าง QR ต่อเครื่อง สแกนแล้วแจ้งซ่อมได้ทันที (รวมโหมด guest)
- **อีเมลแจ้งเตือน** — คิวอีเมล + เทมเพลตที่แก้ข้อความเองได้ในหน้า admin
- **รายงาน 10 แบบ** — ภาพรวมผู้บริหาร, SLA, ผลงานช่าง, จุดเสียบ่อย, แนวโน้ม, งานค้าง, เปิดซ้ำ, CSAT, ความน่าเชื่อถือทรัพย์สิน — ส่งออก CSV / Excel / PDF (รองรับภาษาไทย)
- **สำรอง & กู้คืนข้อมูล** — สำรองอัตโนมัติ (cron) + ปุ่ม "สำรอง & ดาวน์โหลด" ในหน้า admin (ใช้ได้แม้โฮสต์ปิดสิทธิ์ shell)
- **ปรับแบรนด์เอง** — ตั้งชื่อระบบ/สโลแกน/โลโก้/เขตเวลา/เวลาทำการ ผ่านหน้า admin; เปลี่ยนสีธีมผ่านไฟล์เดียว (ไม่ต้อง build)
- **ความปลอดภัย** — เข้ารหัสรหัสผ่าน (bcrypt), ป้องกัน CSRF, จำกัดการล็อกอินถี่ (rate limit), security headers, session timeout
- **ตัวช่วยติดตั้ง** — Setup Wizard (สร้าง admin คนแรก) + หน้า `check-requirements.php` (ตรวจความพร้อมเซิร์ฟเวอร์ก่อนติดตั้ง)

## 3. Tech Stack

| ส่วน | เทคโนโลยี |
|---|---|
| ภาษา/ฝั่งเซิร์ฟเวอร์ | PHP 8.1+ (โครงสร้าง MVC เขียนเอง ไม่พึ่ง framework) |
| ฐานข้อมูล | MySQL / MariaDB (ผ่าน PDO) |
| หน้าเว็บ | PHP views (server-rendered) + Tailwind CSS (compile มาแล้ว) + JavaScript ล้วน + Chart.js |
| ไลบรารี | PHPMailer (อีเมล), PhpSpreadsheet (Excel), dompdf (PDF), endroid/qr-code (QR) |
| การยืนยันตัวตน | Session + bcrypt |
| การติดตั้ง | Shared hosting / VPS (Apache + mod_rewrite), cPanel · ไม่มี Docker/CI ที่จำเป็นต่อการรัน |

## 4. ความต้องการของระบบ (Prerequisites)

- **PHP 8.1 ขึ้นไป** พร้อมส่วนขยาย: `pdo_mysql, mbstring, gd, zip, zlib, dom, xml, simplexml, xmlreader, xmlwriter, libxml, fileinfo, iconv, ctype, filter, hash, openssl, json`
- **MySQL 5.7+ / MariaDB 10.3+** (charset `utf8mb4`)
- **เว็บเซิร์ฟเวอร์** ที่เปิด `mod_rewrite` และอ่าน `.htaccess` (Apache — มาตรฐาน cPanel)
- ตั้ง **cron ได้** (สำหรับ SLA + อีเมลอัตโนมัติ)

> เปิดหน้า `https://your-site/check-requirements.php` จะตรวจให้อัตโนมัติว่าเซิร์ฟเวอร์พร้อมไหม

## 5. เริ่มเร็วสำหรับนักพัฒนา (Dev Quick Start)

> สำหรับคน dev ที่รันบนเครื่องตัวเอง (XAMPP ฯลฯ) — **ผู้ซื้อทั่วไปให้ดู [INSTALL.md](INSTALL.md) แทน**

```bash
# 1. ติดตั้ง dependency (dev มี composer)
composer install

# 2. ตั้งค่า environment
cp .env.example .env          # แล้วแก้ค่า DB_* / APP_URL

# 3. สร้างฐานข้อมูล + นำเข้าโครงสร้าง (ผ่าน mysql หรือ phpMyAdmin)
mysql -u root your_db < database/schema.sql
mysql -u root your_db < database/seed_reference.sql
# (ทางเลือก) ข้อมูลตัวอย่างสำหรับทดลอง — ต้องตั้ง ALLOW_DEMO_DATA=true ก่อน
# mysql -u root your_db < database/seed_demo.sql

# 4. รันเซิร์ฟเวอร์ทดสอบ
php -S 127.0.0.1:8000 -t public public/index.php
# เปิด http://127.0.0.1:8000 → ระบบพาไป Setup Wizard
```

## 6. คำสั่งที่มีให้ (Scripts)

| คำสั่ง | ทำอะไร |
|---|---|
| `composer test` หรือ `php tests/run.php` | รันชุดทดสอบทั้งหมด |
| `./build-css.sh` (macOS/Linux) · `build-css.bat` (Windows) | สร้างไฟล์ CSS ใหม่หลังแก้ดีไซน์ (โหลดตัว Tailwind ให้เอง ไม่ต้องมี Node) |
| `bin/package-release.sh` | แพ็กไฟล์เป็น zip พร้อมขาย (รวม vendor/ ตัดข้อมูล/ประวัติ dev ออก) |
| `php bin/run-maintenance-cron.php` | งานเบื้องหลัง: คิด SLA + ส่งอีเมลในคิว (ตั้งเป็น cron) |
| `php bin/backup-database.php` | สำรองฐานข้อมูล (ตั้งเป็น cron รายวัน) |
| `vendor/bin/phpstan analyse` | ตรวจชนิดข้อมูลแบบ static (dev) |

## 7. โครงสร้างโฟลเดอร์

```
├── app/                 โค้ดหลัก (MVC)
│   ├── Controllers/     รับ request → เรียก service → ตอบกลับ
│   ├── Services/        ตรรกะธุรกิจ (SLA, อีเมล, รายงาน, สำรองข้อมูล ฯลฯ)
│   ├── Repositories/    คุยกับฐานข้อมูล (SQL)
│   ├── Views/           หน้าเว็บ (PHP templates)
│   ├── Core/            ตัวกลาง (Router, Session, Auth, Response ฯลฯ)
│   └── Helpers/         ฟังก์ชันช่วยทั่วไป
├── bin/                 สคริปต์งานเบื้องหลัง (cron, backup, แพ็ก release)
├── config/              config.php + routes.php (รายการเส้นทาง URL)
├── database/            schema.sql, seed_reference.sql, seed_demo.sql + upgrades/
├── public/              โฟลเดอร์ที่เว็บเสิร์ฟ (index.php, assets, check-requirements.php)
├── resources/           source ของ CSS + ฟอนต์ไทย
├── storage/             ไฟล์อัปโหลด, log, สำรองข้อมูล (ต้องเขียนได้)
├── tests/               ชุดทดสอบ
├── .env.example         แม่แบบตั้งค่า (dev)
├── .env.production.example  แม่แบบตั้งค่าสำหรับใช้งานจริง
└── .htaccess            กติกาการเสิร์ฟ + กันไฟล์ลับหลุด
```

## 8. Environment Variables (อ้างอิงเต็มดูใน `.env.example`)

| ตัวแปร | บังคับ | คำอธิบาย | ตัวอย่าง |
|---|---|---|---|
| `APP_NAME` | ควรตั้ง | ชื่อระบบ (ตั้งใน admin ได้ทีหลัง) | `Repair System` |
| `APP_ENV` | ✅ | `local` หรือ `production` | `production` |
| `APP_DEBUG` | ✅ | `false` เสมอบนของจริง (กันข้อมูลหลุด) | `false` |
| `APP_URL` | ✅ | URL จริงของเว็บ (ไม่มี / ท้าย) | `https://repair.example.com` |
| `APP_TIMEZONE` | — | เขตเวลา | `Asia/Bangkok` |
| `ALLOW_DEMO_DATA` | — | `true` เฉพาะตอนอยากลองข้อมูลตัวอย่าง | `false` |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USERNAME` `DB_PASSWORD` | ✅ | ค่าฐานข้อมูล | `127.0.0.1` / `3306` / … |
| `MAIL_DRIVER` | ✅ | `log` (ทดสอบ) หรือ `smtp` (ส่งจริง) | `smtp` |
| `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_PASSWORD` `MAIL_ENCRYPTION` | ต้องมีถ้า smtp | ค่า SMTP ของผู้ให้บริการอีเมล | — |
| `SESSION_SECURE` | ✅ | `true` บน HTTPS | `true` |

> รายการเต็ม + คำอธิบายทุกค่า อยู่ใน **[.env.example](.env.example)** (มีคอมเมนต์ภาษาไทยทุกบรรทัด)

## 9. การทดสอบ (Testing)

```bash
php tests/run.php                 # ชุดทดสอบหลัก (หลายร้อยเคส)
vendor/bin/phpstan analyse --memory-limit=1G   # ตรวจชนิดข้อมูล (dev)
```
> มีชุด E2E (Playwright) ในโฟลเดอร์ `e2e/` สำหรับ dev (รัน `npm --prefix e2e test`)

## 10. การติดตั้งจริง / License / ข้อจำกัด

- **ติดตั้งจริง →** ดู **[INSTALL.md](INSTALL.md)** (สำหรับ IT support) · **ตั้งค่า admin →** [ADMIN-GUIDE.md](ADMIN-GUIDE.md) · **ปรับแต่ง →** [CUSTOMIZE.md](CUSTOMIZE.md) · **อ่านรายงาน →** [REPORT-GUIDE.md](REPORT-GUIDE.md)
- **ข้อจำกัดที่ควรรู้:**
  - ต้องตั้ง **cron** เพื่อให้ SLA + อีเมลทำงาน (ไม่ตั้ง = เงียบ ไม่ error)
  - ต้อง PHP 8.1+ และเปิดส่วนขยายตามข้อ 4
  - การสำรองอัตโนมัติเต็มรูปแบบต้องการโฮสต์ที่เปิด `proc_open`/`mysqldump`; ถ้าปิด ให้ใช้ปุ่ม "สำรอง & ดาวน์โหลด" ในหน้า admin แทน
- **License:** `TODO: เจ้าของกำหนดสัญญาอนุญาต (เช่น เชิงพาณิชย์/ต่อโดเมน) — ยังไม่มีไฟล์ LICENSE ใน repo`
- **Support:** `TODO: เจ้าของระบุช่องทางซัพพอร์ต (อีเมล/LINE/ระยะเวลารับประกัน)`
