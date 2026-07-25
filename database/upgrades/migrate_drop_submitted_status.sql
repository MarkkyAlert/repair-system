-- รันครั้งเดียวบนฐานข้อมูลเดิมหลัง deploy โค้ดที่ถอด tickets.status = 'submitted'
-- เส้นทางสร้าง ticket จริงบันทึก pending_approval พร้อม approval_status=pending ใน transaction เดียวอยู่แล้ว
-- submitted จึงเป็นเพียงค่า default เก่าที่ไม่มี workflow ใช้งาน
-- ถ้ายังไม่ได้รัน migrate_drop_on_hold_paused.sql ให้รันไฟล์นั้นก่อน แล้วค่อยรันไฟล์นี้
--
-- สำรองฐานข้อมูลก่อนรันบนระบบใช้งานจริงเสมอ
-- ดูแถวที่จะถูกย้ายก่อนรัน:
-- SELECT id, ticket_no, status, approval_status, approved_at
-- FROM tickets
-- WHERE status = 'submitted';
--
-- STEP 1 — ย้ายข้อมูลเก่าให้ตรงกับสถานะเริ่มต้นที่ระบบใช้งานจริงก่อนหด ENUM
-- UPDATE นี้ idempotent: รันซ้ำแล้วไม่แตะแถวที่ย้ายไปแล้ว
START TRANSACTION;

UPDATE tickets
SET status = 'pending_approval',
    approval_status = 'pending',
    approved_at = NULL,
    updated_at = NOW()
WHERE status = 'submitted';

COMMIT;

-- STEP 2 — หด ENUM หลังไม่มีแถว submitted เหลืออยู่แล้ว
ALTER TABLE tickets
    MODIFY COLUMN status ENUM(
        'pending_approval','approved','assigned','accepted','in_progress',
        'resolved','completed','rejected','cancelled','closed'
    ) NOT NULL DEFAULT 'pending_approval';
