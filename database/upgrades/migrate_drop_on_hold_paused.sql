-- รันหลัง deploy โค้ดที่เอาสถานะ on_hold (tickets.status) และ paused (work_orders.status) ออกแล้ว
-- สองสถานะนี้ประกาศไว้ใน enum + มีป้ายภาษาไทย แต่ไม่มีเส้นทางไหนในระบบเดินไปถึงได้เลย (ไม่เคยมีปุ่ม/transition พักงาน)
-- จึงถอดออกให้ enum ตรงกับสถานะที่ใช้งานจริง ถ้าลูกค้าต้องการฟีเจอร์พักงานในอนาคตค่อยออกแบบใหม่พร้อม requirement จริง
--
-- สำรองฐานข้อมูลก่อนรันบนระบบใช้งานจริงเสมอ
-- ต้องรัน STEP 1 ก่อน STEP 2 เพราะ ALTER แบบ strict-mode จะล้มเหลวถ้ายังมีแถวที่ถือค่า enum ที่กำลังจะลบ
--
-- ดูก่อนว่ามีแถวค้างสถานะเหล่านี้อยู่ไหม (ปกติควรได้ 0 เพราะโค้ดเขียนค่าพวกนี้ไม่ได้อยู่แล้ว):
-- SELECT 'ticket on_hold' AS kind, COUNT(*) FROM tickets WHERE status = 'on_hold'
-- UNION ALL
-- SELECT 'work_order paused', COUNT(*) FROM work_orders WHERE status = 'paused';

-- STEP 1 — ย้ายแถวที่อาจค้างค่าเก่าให้เป็น in_progress (สถานะ "กำลังดำเนินการ" ที่ใกล้ที่สุด) ก่อนหด enum
--          idempotent: ถ้าไม่มีแถวไหนค้างอยู่แล้ว UPDATE จะกระทบ 0 แถว
UPDATE tickets SET status = 'in_progress', updated_at = NOW() WHERE status = 'on_hold';
UPDATE work_orders SET status = 'in_progress', updated_at = NOW() WHERE status = 'paused';

-- STEP 2 — หด enum ให้เหลือเฉพาะสถานะที่ใช้งานจริง (ต้องตรงกับ database/schema.sql)
ALTER TABLE tickets
    MODIFY COLUMN status ENUM(
        'submitted','pending_approval','approved','assigned','accepted',
        'in_progress','resolved','completed','rejected','cancelled','closed'
    ) NOT NULL DEFAULT 'submitted';

ALTER TABLE work_orders
    MODIFY COLUMN status ENUM(
        'assigned','accepted','in_progress','completed','cancelled'
    ) NOT NULL DEFAULT 'assigned';
