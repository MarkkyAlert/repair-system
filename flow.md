# คู่มือทดสอบ Flow หลัก – ระบบ Repair System

เอกสารนี้อธิบายว่า **ระบบนี้คืออะไร**, **ผู้ใช้แต่ละบทบาท (role) ทำอะไรได้บ้าง**, และ **วิธีทดสอบ flow หลัก ทีละขั้นแบบมือใหม่ก็ทำตามได้** ตั้งแต่ติดตั้งจนปิดงานจบลูป

> เป้าหมาย: อ่านจบไฟล์เดียวแล้วเปิดเครื่องทดสอบระบบได้เอง พร้อมรู้ว่าแต่ละหน้าทำอะไรและคาดหวังผลแบบไหน

---

## 1. ระบบนี้คืออะไร

**Repair System (ระบบแจ้งซ่อม / Maintenance Request System)** เป็นเว็บแอปภายในองค์กรสำหรับจัดการงานแจ้งซ่อม–ซ่อมบำรุงทรัพย์สิน (assets) เช่น เครื่องพิมพ์ เครื่องปรับอากาศ อุปกรณ์เครือข่าย ฯลฯ

ฟีเจอร์หลักของระบบ:

- **Ticket Lifecycle**: ผู้ใช้แจ้งงาน → หัวหน้าอนุมัติ → มอบหมายช่าง → ช่างรับงาน/ลงมือ → ปิดงาน + ให้คะแนน
- **Assets & QR Code**: ลงทะเบียนทรัพย์สิน พิมพ์ QR Code ติดที่อุปกรณ์ สแกนเพื่อแจ้งซ่อมได้ทันที
- **SLA Tracking**: คำนวณเวลาตอบกลับ (response) และเวลาแก้ไข (resolution) อัตโนมัติตาม priority พร้อมเตือนเมื่อใกล้/เลย deadline
- **Notifications**: แจ้งเตือนในระบบ (bell icon) และ email queue
- **Reports & Export**: รายงานสรุปงาน export เป็น Excel/PDF ได้
- **Admin Panel**: จัดการผู้ใช้ แผนก หมวดหมู่ และ system settings
- **Print Job Order**: พิมพ์ใบงานช่างพร้อม QR กลับเข้า ticket

### Tech Stack โดยย่อ

- PHP 8.1+ (วิ่งบน XAMPP/Apache), MySQL
- Composer (`phpmailer`, `phpoffice/phpspreadsheet`, `dompdf`, `endroid/qr-code`)
- Tailwind CSS (build ด้วย `tools/tailwindcss`)
- Routing แบบเขียนเอง อยู่ที่ `config/routes.php`

---

## 2. Role และสิ่งที่แต่ละ Role ทำได้

ระบบมี 4 บทบาทหลัก (กำหนดที่คอลัมน์ `users.role`)

| Role | คำอธิบาย | สิ่งที่ทำได้ |
|---|---|---|
| **requester** | ผู้แจ้งงาน (พนักงานทั่วไป / ผู้ใช้งานปลายทาง) | สร้าง ticket, ติดตามสถานะของตัวเอง, คอมเมนต์ (เห็นเฉพาะ comment ที่ไม่ใช่ internal), ยืนยันปิดงาน + ให้คะแนนช่าง, สแกน QR เพื่อแจ้งซ่อม |
| **manager** | หัวหน้างาน / หัวหน้าฝ่าย | เห็น ticket ทุกใบในแผนกที่ดูแล (หรือทั้งหมดถ้าระบบกำหนด), **อนุมัติ / ปฏิเสธ** ticket, **มอบหมายช่าง (assign technician)**, คอมเมนต์ภายใน (internal), ดูรายงาน |
| **technician** | ช่างซ่อม | เห็นงานที่ได้รับมอบหมาย, **รับงาน (accept)**, **เริ่มงาน (start)**, **บันทึกการวินิจฉัย + สรุปการซ่อม (resolve)**, อัปโหลดไฟล์/ภาพหลักฐาน, คอมเมนต์ภายใน |
| **admin** | ผู้ดูแลระบบ | ทุกสิทธิ์ของ role อื่น + เข้า **/admin** เพื่อจัดการ users, departments, ticket/asset categories, system settings, จัดการ assets และ regenerate QR token |

### สถานะของ Ticket (เพื่อให้เข้าใจเวลาทดสอบ)

| status | ความหมาย |
|---|---|
| `pending_approval` | สร้างแล้ว รออนุมัติจาก manager |
| `approved` | manager อนุมัติแล้ว ยังไม่มอบหมายช่าง |
| `rejected` | manager ปฏิเสธ ปิดเคสไม่ดำเนินการต่อ |
| `assigned` | มอบหมายช่างแล้ว รอช่างรับงาน |
| `in_progress` | ช่างเริ่มลงมือซ่อมแล้ว |
| `resolved` | ช่างสรุปผลซ่อมเสร็จ รอผู้แจ้งยืนยัน |
| `completed` | ผู้แจ้งยืนยันปิดงานและให้คะแนน |

---

## 3. ติดตั้งและเตรียมสภาพแวดล้อมก่อนทดสอบ

### 3.1 สิ่งที่ต้องมี

- **XAMPP** (รวม Apache + MySQL + PHP 8.2) ติดตั้งที่ `/Applications/XAMPP`
- โปรเจกต์อยู่ที่ `/Applications/XAMPP/xamppfiles/htdocs/maintenance` ทำให้เปิดผ่าน `http://localhost/maintenance/`
- (ทางเลือก) MailHog/Mailpit สำหรับดู email ที่ส่งออก (พอร์ต `1025` ใน `.env.example`)

### 3.2 ขั้นตอนติดตั้ง

1. **เปิด XAMPP** → Start Apache + MySQL
2. **คัดลอกไฟล์ env**
   ```bash
   cd /Applications/XAMPP/xamppfiles/htdocs/maintenance
   cp .env.example .env
   ```
   แก้ `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD` ให้ตรงกับ MySQL ของคุณ (XAMPP default = `root` ไม่มีรหัสผ่าน)

3. **สร้างฐานข้อมูล**
   - เปิด `http://localhost/phpmyadmin`
   - สร้าง database ชื่อ `repair_system` (charset `utf8mb4`)
   - Import ไฟล์ตามลำดับ:
     1. `database/schema.sql` (สร้างตาราง)
     2. `database/seed.sql` (ใส่ข้อมูลตัวอย่าง: users, assets, ticket ตัวอย่าง 3 ใบ ฯลฯ)

   > ⚠️ **`seed.sql` เป็นข้อมูล DEMO สำหรับทดสอบบนเครื่อง local เท่านั้น** — ทุกบัญชีใช้รหัสผ่านที่เปิดเผยในเอกสารนี้
   >
   > **ใช้งานจริง (production):** import แค่ `database/schema.sql` แล้วสร้าง admin เองผ่าน `/setup` wizard — **อย่า import `seed.sql`**
   > ถ้าเผลอ import demo seed ขึ้นเครื่องจริงแล้ว ให้เปลี่ยนรหัสผ่านทุกบัญชีทันทีก่อนเปิดใช้งาน

4. **ติดตั้ง dependencies (PHP)**
   ```bash
   /Applications/XAMPP/xamppfiles/bin/php /usr/local/bin/composer install
   # หรือถ้ามี composer global แล้ว
   composer install
   ```

5. **Build CSS** (ครั้งแรกเท่านั้น ถ้ามีของอยู่แล้วข้ามได้)
   ```bash
   ./build-css.sh
   ```

6. เปิดเบราว์เซอร์ที่ `http://localhost/maintenance/` ระบบจะ redirect ไปหน้า login

### 3.3 บัญชีทดสอบ (จาก `database/seed.sql`)

| Role | Username | Email | Password |
|---|---|---|---|
| Requester | `requester` | `requester@example.com` | `requester123` |
| Manager | `manager` | `manager@example.com` | `manager123` |
| Technician | `technician` | `technician@example.com` | `tech12345` |
| Admin | `admin` | `admin@example.com` | `admin12345` |

> 🔒 **รหัสผ่านด้านบนเป็น demo ที่เปิดเผยในเอกสาร** — สำหรับทดสอบบน local เท่านั้น **ห้ามนำ demo seed นี้ขึ้น production**; ถ้าจำเป็นต้องใช้ ให้เปลี่ยนรหัสทุกบัญชีก่อนเปิดใช้จริง (ดูคำเตือนใน `database/seed.sql` และขั้นตอน production ด้านบน)

> เคล็ดลับ: เปิด 4 หน้าต่างเบราว์เซอร์ (หรือใช้ Incognito/โปรไฟล์ต่างกัน) ล็อกอิน 4 บัญชีพร้อมกัน จะทดสอบ flow ระหว่าง role ได้ลื่นมาก

---

## 4. Flow หลักที่ต้องทดสอบ (ทีละขั้นสำหรับมือใหม่)

แนะนำให้ทำตามลำดับด้านล่าง เพราะแต่ละ flow ต่อยอดกัน

---

### Flow 1: เข้าสู่ระบบ + ออกจากระบบ (Authentication)

**เป้าหมาย**: ตรวจว่า login, session, logout ทำงานปกติ

1. เปิด `http://localhost/maintenance/login`
2. กรอก **Username หรือ Email** = `requester` และ **Password** = `requester123` → กด **เข้าสู่ระบบ**
3. ระบบ redirect ไปที่ `/dashboard` พร้อมแสดงชื่อ "สมชาย ผู้แจ้ง" ที่มุมขวาบน
4. ทดสอบ logout: กดเมนูผู้ใช้ → **ออกจากระบบ** ระบบกลับมาที่หน้า login
5. **ทดสอบ rate limit**: กรอกรหัสผ่านผิด ๆ หลายครั้ง (5+) จะเจอข้อความ "คุณพยายามเข้าสู่ระบบเกินกำหนด..." รอประมาณ 60 วินาทีค่อยลองใหม่
6. **ทดสอบ forgot password**:
   - หน้า login → คลิก **ลืมรหัสผ่าน?**
   - กรอก `requester@example.com` → กด ส่งลิงก์
   - ลิงก์ reset จะถูก queue เข้า `email_queue` ในฐานข้อมูล (ถ้า `MAIL_DRIVER=log` จะไม่ส่งจริง สามารถ copy URL จาก log/db เพื่อทดสอบ)
   - เปิดลิงก์ → ตั้งรหัสผ่านใหม่ (≥ 8 ตัวอักษร) → login ด้วยรหัสใหม่

**คาดหวัง**: ✅ login สำเร็จ, ✅ logout เคลียร์ session, ✅ rate limit ทำงาน, ✅ reset password อัปเดต `password_hash` ในตาราง `users`

---

### Flow 2: ผู้แจ้ง (Requester) สร้างรายการแจ้งซ่อม

**เป้าหมาย**: สร้าง ticket ใหม่ลงระบบเข้าสถานะ `pending_approval`

1. Login เป็น `requester` / `requester123`
2. เข้าหน้า `/tickets` → กดปุ่ม **+ แจ้งปัญหาใหม่** (ไปที่ `/tickets/create`)
3. กรอกฟอร์ม:
   - **หัวข้อ**: เช่น "คอมพิวเตอร์เปิดไม่ติด"
   - **รายละเอียด**: อธิบายอาการ
   - **หมวดหมู่**: เลือก `Electrical` / `Network` / ฯลฯ
   - **ลำดับความสำคัญ**: เลือก `Medium` (หรือ Urgent ถ้าอยากดู SLA สั้น ๆ)
   - **สถานที่**: เลือกจาก dropdown
   - **Asset** (ถ้ามี): เลือกอุปกรณ์ที่เกี่ยวข้อง เช่น `AST-PRN-0001`
   - **ผลกระทบ / ความเร่งด่วน**: ตามจริง
4. กด **บันทึก / ส่งคำขอ**
5. ระบบ redirect ไปหน้า `/tickets/{id}` ของ ticket ที่เพิ่งสร้าง โดย:
   - สถานะแสดง **รออนุมัติ (pending_approval)**
   - กิจกรรม (activity log) แสดง `ticket_submitted`
   - manager ของแผนกที่เกี่ยวข้องจะได้ notification ใหม่
6. กลับไปหน้า `/tickets` ต้องเห็น ticket ใหม่ใน list

**ตรวจในฐานข้อมูล** (optional): 
```sql
SELECT id, ticket_no, status, approval_status FROM tickets ORDER BY id DESC LIMIT 1;
SELECT * FROM ticket_activity_logs WHERE ticket_id = <id ใหม่>;
SELECT * FROM notifications ORDER BY id DESC LIMIT 1;
```

**คาดหวัง**: ✅ ticket ใหม่ถูกบันทึก, ✅ มี activity log, ✅ มี notification ส่งถึง manager

---

### Flow 3: หัวหน้างาน (Manager) อนุมัติ / ปฏิเสธ

**เป้าหมาย**: เปลี่ยน ticket จาก `pending_approval` → `approved` หรือ `rejected`

1. เปิดอีกเบราว์เซอร์/incognito → login เป็น `manager` / `manager123`
2. ไอคอนกระดิ่ง (🔔) ที่มุมขวาบนจะมีตัวเลขแจ้งเตือนใหม่
3. คลิก notification → ระบบพาไปที่ ticket ที่ผู้แจ้งเพิ่งสร้าง
4. ที่หน้า detail จะเห็นการ์ด/ปุ่ม **อนุมัติ** กับ **ปฏิเสธ**
5. **เคสอนุมัติ**:
   - ใส่หมายเหตุ เช่น "อนุมัติเร่งด่วน" → กด **อนุมัติ**
   - สถานะเปลี่ยนเป็น `approved` พร้อมเวลา `approved_at`
   - มี notification ใหม่ส่งถึงผู้แจ้ง (และ technician ที่เกี่ยวข้อง)
6. **เคสปฏิเสธ** (ลองกับ ticket อีกใบ):
   - ใส่เหตุผล เช่น "นอก scope ฝ่าย" → กด **ปฏิเสธ**
   - สถานะเปลี่ยนเป็น `rejected` ผู้แจ้งจะได้ notification

**คาดหวัง**: ✅ ticket เปลี่ยนสถานะ, ✅ มี record ใน `ticket_approvals`, ✅ activity log เพิ่ม `ticket_approved` หรือ `ticket_rejected`, ✅ ผู้แจ้งได้รับ notification

---

### Flow 4: Manager มอบหมายช่าง (Assign Technician)

**เป้าหมาย**: ผูก ticket ที่ approved กับ technician เปลี่ยนเป็น `assigned`

1. ยังคงเป็น `manager` เปิด ticket ที่อนุมัติแล้ว
2. หาเซกชัน **มอบหมายช่าง** หรือปุ่ม **Assign**
3. เลือกช่างจาก dropdown (เช่น `วิทยา ช่างเทคนิค`)
4. ใส่ **คำสั่งงาน (instructions)** เช่น "ตรวจ uplink และเปลี่ยนสายหากจำเป็น"
5. กด **มอบหมาย**
6. ระบบ:
   - สร้าง record ใน `work_orders` (มี `work_order_no` เช่น `WO-20260602-0002`)
   - เปลี่ยนสถานะ ticket เป็น `assigned`
   - ส่ง notification ไปถึงช่าง

**คาดหวัง**: ✅ work_order ใหม่ใน DB, ✅ ticket.status = `assigned`, ✅ ticket.assigned_technician_id ตรงกับช่างที่เลือก

---

### Flow 5: ช่าง (Technician) รับงาน → เริ่มงาน → สรุปงาน

**เป้าหมาย**: ครอบคลุม transition `assigned → in_progress → resolved`

1. Login เป็น `technician` / `tech12345`
2. หน้า `/dashboard` หรือ `/tickets` จะกรองงานที่ได้รับมอบหมายให้อัตโนมัติ
3. เปิด ticket ที่ได้รับมอบหมาย

**5.1 รับงาน (accept)**
- กดปุ่ม **รับงาน**
- ตัวเลือก: ใส่ accept note เช่น "กำลังเดินทางไปที่หน้างาน"
- ระบบบันทึก `work_orders.accepted_at`

**5.2 เริ่มลงมือ (start)**
- กดปุ่ม **เริ่มงาน**
- สถานะ ticket → `in_progress`, `started_at` ถูกบันทึก
- SLA `response` metric จะถูก mark เป็น `met` ถ้าทันเวลา

**5.3 สรุปผลซ่อม (resolve)**
- กดปุ่ม **สรุปการซ่อม**
- กรอก:
  - **Diagnosis Summary** (สาเหตุที่พบ): เช่น "interface uplink มี error counter สูง"
  - **Resolution Summary** (วิธีแก้): เช่น "เปลี่ยนสาย uplink และรีบูตอุปกรณ์"
  - **Labor Minutes**: เวลาที่ใช้ เป็นนาที เช่น `60`
- อัปโหลดรูป/ไฟล์หลักฐาน (ทางเลือก)
- กด **บันทึก/สรุปงาน**
- สถานะ → `resolved`, `resolved_at` ถูกบันทึก
- ผู้แจ้งจะได้ notification ว่า "งานพร้อมให้ตรวจสอบและปิด"

**คาดหวัง**: ✅ work_order.status = `completed`, ✅ ticket.status = `resolved`, ✅ มี activity log ครบ 3 step, ✅ ผู้แจ้งได้ notification

---

### Flow 6: ผู้แจ้งยืนยันปิดงาน + ให้คะแนน (Complete + Rating)

**เป้าหมาย**: ปิด lifecycle สมบูรณ์ที่ `completed`

1. กลับเป็น `requester`
2. เข้า ticket ที่สถานะ `resolved`
3. เห็นการ์ด **ยืนยันปิดงาน**
4. กรอก:
   - **คะแนน 1–5 ดาว**
   - **ความคิดเห็น / ข้อเสนอแนะ** (optional)
   - **บันทึกปิดงาน (closure note)**: เช่น "ใช้งานได้ปกติ"
5. กด **ยืนยันปิดงาน**
6. สถานะ → `completed`, `completed_at` ถูกบันทึก, `closed_at` ถูกบันทึก
7. ตาราง `ticket_ratings` มี record ใหม่

**คาดหวัง**: ✅ ticket.status = `completed`, ✅ ticket_ratings มีคะแนนที่ให้, ✅ SLA `resolution` ถูก mark `met` หรือ `breached` ตามเวลาจริง

---

### Flow 7: คอมเมนต์ (Comments) – ภายใน vs สาธารณะ

**เป้าหมาย**: ทดสอบกล่องคอมเมนต์, internal flag, แก้ไข/ลบ

1. เปิด ticket ใด ๆ ที่ยังไม่ปิด
2. **เป็น requester**: พิมพ์คอมเมนต์ → กด **ส่ง**
   - ไม่มีตัวเลือก "internal" (requester เห็นได้แค่คอมเมนต์สาธารณะ)
3. **เป็น manager หรือ technician**: พิมพ์คอมเมนต์ → ติ๊ก **คอมเมนต์ภายใน (internal)** → ส่ง
   - คอมเมนต์ภายในจะแสดงเฉพาะ role ที่ไม่ใช่ requester
4. ทดสอบ **แก้ไข** (ปุ่มดินสอ) → เปลี่ยนข้อความ → บันทึก
5. ทดสอบ **ลบ** (ปุ่มถังขยะ) → ยืนยัน → คอมเมนต์หายไป
6. ทดสอบ **reply (parent_id)**: ตอบกลับคอมเมนต์อื่น แล้วเช็คว่าระบบเก็บ `parent_id` ใน `ticket_comments`

**คาดหวัง**: ✅ คอมเมนต์ internal ไม่โผล่ฝั่ง requester, ✅ แก้/ลบสำเร็จเฉพาะคอมเมนต์ของตัวเอง (หรือเจ้าของกับ admin)

---

### Flow 8: Asset & QR Scan (สแกน QR แจ้งซ่อมเร็ว)

**เป้าหมาย**: ใช้ QR ของ asset เปิด ticket แบบ pre-fill

1. Login เป็น `admin` / `admin12345`
2. ไปที่ `/asset-registry` ดู list ทรัพย์สิน
3. **สร้าง asset ใหม่** (ทางเลือก):
   - `/asset-registry/create` → กรอก asset_code (เช่น `AST-TST-0001`), name, category, location, custodian → บันทึก
4. **พิมพ์ QR**:
   - คลิก asset → กดปุ่ม **พิมพ์ QR** (`/asset-registry/print` สำหรับชีตหลายชิ้น หรือ `/asset-registry/{id}/qr.png` สำหรับ PNG เดี่ยว)
5. **Regenerate token** (กรณีต้องการ rotate QR):
   - กด **Regenerate QR** → ระบบสร้าง token ใหม่ใน `asset_qr_tokens`, ของเก่าถูก `is_active = 0`
6. **ทดสอบสแกน QR**:
   - ใช้มือถือสแกน QR (หรือคัดลอก URL `/scan/{token}` ลงเบราว์เซอร์)
   - **ถ้ายังไม่ login**: เห็นหน้าข้อมูล asset แบบ guest พร้อมปุ่ม **เข้าสู่ระบบเพื่อแจ้งซ่อม**
   - **ถ้า login แล้ว**: เห็นปุ่ม **แจ้งซ่อมอุปกรณ์นี้** → กดแล้วจะไป `/tickets/create?asset_id=...` พร้อม pre-fill ฟิลด์
7. ส่ง ticket ตาม Flow 2 ได้เลย โดยมีลิงก์กลับไปยัง asset ใน detail

**คาดหวัง**: ✅ QR token ถูกบันทึก scan ล่าสุด (`last_scanned_at`), ✅ form pre-fill asset_id อัตโนมัติ

---

### Flow 9: Admin Panel (จัดการระบบ)

**เป้าหมาย**: ทดสอบ CRUD เบื้องต้นของ admin

1. Login เป็น `admin` → เข้า `/admin`
2. แท็บ/ส่วนที่ควรทดสอบ:
   - **Users**: เปลี่ยน role (`requester` ↔ `technician` ↔ `manager`), เปลี่ยนแผนก, toggle `is_active`
   - **Departments**: เปลี่ยนชื่อ/รหัส/เปิด-ปิดใช้งาน
   - **Ticket Categories**: เพิ่ม/แก้/ปิดใช้งานหมวด, ตั้งค่า SLA ของหมวด (ถ้ามี)
   - **Asset Categories**: เช่นเดียวกัน
   - **System Settings**: แก้ค่า `app_name`, `ticket_prefix`, `business_hours` แล้วกดบันทึก
3. หลังแก้ไข ลองออกจาก admin → เข้าหน้า ticket ใหม่ ดูว่า prefix/หมวดหมู่เปลี่ยนตามไหม
4. **ทดสอบสิทธิ์**: login เป็น `requester` แล้วเข้า `/admin` ตรง ๆ → ระบบ redirect/แสดง error เพราะไม่ใช่ admin

**คาดหวัง**: ✅ บันทึกค่า settings ใน `system_settings`, ✅ role อื่นเข้า `/admin` ไม่ได้

---

### Flow 10: Reports & Export

**เป้าหมาย**: ดูรายงาน + export Excel/PDF

1. Login เป็น `manager` หรือ `admin`
2. เข้า `/reports`
3. เลือกช่วงวันที่ (เช่นเดือนปัจจุบัน), หมวดหมู่, สถานะ ฯลฯ → กด **กรอง**
4. หน้ารายงานแสดง: จำนวน ticket ตามสถานะ, SLA met/breached, top categories
5. กด **Export Excel** → ดาวน์โหลด `.xlsx` มาเปิดด้วย Excel/Numbers ตรวจคอลัมน์
6. กด **Export PDF** → ดาวน์โหลด `.pdf` ตรวจหน้าตา + ข้อมูลตรงกัน

**คาดหวัง**: ✅ ไฟล์ดาวน์โหลดได้, ✅ ข้อมูลตรงกับ filter, ✅ ภาษาไทยแสดงถูกต้อง

---

### Flow 11: Notifications (กระดิ่งแจ้งเตือน)

**เป้าหมาย**: ตรวจระบบ realtime notification (polling) ในเว็บ

1. เปิด 2 หน้าจอ: หน้าจอ A = `requester`, หน้าจอ B = `manager`
2. ที่ A: สร้าง ticket ใหม่ (Flow 2)
3. ที่ B (ภายในไม่กี่วินาที): กระดิ่งมุมขวาบนจะมีตัวเลข badge เพิ่ม
   - feed URL ที่ JS เรียก: `/notifications/feed` (ใช้ polling)
4. คลิกกระดิ่ง → ดู dropdown → คลิก item → ระบบ mark read 1 รายการ (POST `/notifications/{id}/read`)
5. ในหน้า `/notifications` กดปุ่ม **อ่านทั้งหมด** → mark all read (POST `/notifications/read-all`)

**คาดหวัง**: ✅ `notification_recipients.is_read` = 1 หลัง mark read, ✅ badge หายเมื่ออ่านครบ

---

### Flow 12: SLA และ Cron (เบื้องหลัง)

**เป้าหมาย**: ตรวจการคำนวณ SLA + ส่ง email queue

1. ดู ticket ใด ๆ ที่ `priority = High/Urgent` → ดูตาราง `ticket_sla_tracks` ว่ามี target_at ถูกตั้ง
2. ทดสอบ cron แบบ manual:
   ```bash
   /Applications/XAMPP/xamppfiles/bin/php bin/run-maintenance-cron.php
   ```
   - จะเห็น output `SLA processed: N` และ `Emails processed: N`
3. ทดสอบ email queue เดี่ยว:
   ```bash
   /Applications/XAMPP/xamppfiles/bin/php bin/process-email-queue.php
   ```
4. ตรวจ ticket ที่เลย deadline → ใน `ticket_sla_tracks.status` ควรเป็น `breached` และมี notification breach ส่งถึงผู้เกี่ยวข้อง
5. ตรวจ `email_queue.status` ว่าเปลี่ยนจาก `queued` → `sent` (ถ้า mail driver `log` จะเก็บใน log/storage)

**คาดหวัง**: ✅ cron รันได้ exit code 0, ✅ SLA breach ถูก mark, ✅ email queue ถูก process

> สามารถตั้ง cron จริงทุก 1–5 นาที โดยเพิ่มบรรทัดใน crontab:
> ```
> */5 * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/maintenance/bin/run-maintenance-cron.php
> ```

---

### Flow 13: พิมพ์ใบงาน (Job Order Print)

1. เปิด ticket ใด ๆ → กดปุ่ม **พิมพ์ใบงาน**
2. เลือกขนาดกระดาษ (A4 / A5)
3. หน้า `/tickets/{id}/print` แสดง layout สำหรับพิมพ์ พร้อม QR กลับมายัง ticket
4. กด Ctrl/Cmd+P เพื่อพิมพ์ หรือกดปุ่ม **ดาวน์โหลด PDF** เพื่อให้ระบบ generate ผ่าน dompdf
5. ตรวจ QR ในใบงานสแกนแล้วกลับมาที่ ticket เดียวกัน

**คาดหวัง**: ✅ PDF ดาวน์โหลดได้, ✅ QR สแกนแล้วเปิด ticket ถูกต้อง

---

## 5. Checklist ทดสอบครบลูป (สำหรับการ smoke test 5 นาที)

ทำลำดับนี้แล้วจบครบลูปหลัก:

- [ ] Login ครบ 4 role ได้
- [ ] Requester สร้าง ticket ใหม่
- [ ] Manager อนุมัติ + Assign technician
- [ ] Technician รับงาน → เริ่ม → resolve
- [ ] Requester complete + ให้คะแนน 5 ดาว
- [ ] คอมเมนต์ภายใน 1 ครั้งจาก manager (requester ต้องไม่เห็น)
- [ ] สแกน QR ของ asset 1 ตัว แล้วสร้าง ticket จากการสแกน
- [ ] Admin เปลี่ยน setting `app_name` แล้วชื่อในหัวเว็บเปลี่ยน
- [ ] Export Excel จากหน้า Reports สำเร็จ
- [ ] รัน `bin/run-maintenance-cron.php` ได้โดยไม่มี error

---

## 6. ปัญหาที่พบบ่อย (Troubleshooting)

| อาการ | สาเหตุ / วิธีแก้ |
|---|---|
| เปิด `http://localhost/maintenance/` แล้ว 404 | ตรวจ `.htaccess` + เปิด `AllowOverride All` ใน Apache config, รีสตาร์ต Apache |
| Login ไม่ได้ ("ข้อผิดพลาดของระบบ") | ตรวจ `.env` ว่า `DB_*` ถูก, ตรวจว่า import `schema.sql` + `seed.sql` ครบ |
| CSS ไม่ขึ้น/หน้าเว็บเปลือย | รัน `./build-css.sh` ก่อน |
| `composer: command not found` | ใช้ `/Applications/XAMPP/xamppfiles/bin/php composer install` หรือดาวน์โหลด composer.phar |
| QR ไม่ขึ้น | ตรวจว่า `composer install` แล้วและมี `endroid/qr-code`, ตรวจ permission `public/uploads` |
| Email ไม่ส่ง | `MAIL_DRIVER=log` ส่งเข้า log ไม่ออกจริง ตั้ง MailHog (port 1025) แล้วเปลี่ยนเป็น smtp |
| SLA ไม่อัปเดต | ยังไม่ได้รัน `bin/run-maintenance-cron.php` |
| รหัสผ่าน reset แล้ว login ไม่ได้ | ตรวจรหัสยาว ≥ 8 ตัวอักษร และตรงกับ confirm |

---

## 7. ภาคผนวก: เส้นทาง URL หลักโดยย่อ

| URL | คำอธิบาย |
|---|---|
| `GET /login` | หน้า login |
| `GET /dashboard` | สรุปงานของผู้ใช้ตาม role |
| `GET /tickets` | รายการ ticket (filter ตาม role อัตโนมัติ) |
| `GET /tickets/create` | ฟอร์มสร้างใหม่ |
| `GET /tickets/{id}` | รายละเอียด ticket + action ปุ่มตามสถานะ |
| `POST /tickets/{id}/approve` `…/reject` `…/assign` `…/accept` `…/start` `…/resolve` `…/complete` | endpoints ของ lifecycle |
| `GET /asset-registry` `…/create` `…/{id}/edit` | จัดการทรัพย์สิน |
| `GET /scan/{token}` | สแกน QR เข้าหน้า asset |
| `GET /admin` | Admin panel (admin only) |
| `GET /reports` | รายงาน + export |
| `GET /notifications` | รายการแจ้งเตือน |

---

จบคู่มือ – ขอให้ทดสอบสนุก หากเจอ flow ไหนค้างให้กลับมาเช็คกับ section นี้ก่อนเสมอ
