-- รันหลัง deploy โค้ดที่ปล่อยงาน manager ตอนปิดบัญชีแล้ว เพื่อซ่อมข้อมูลเก่าที่เกิดก่อนแพตช์นี้
-- สำรองฐานข้อมูลก่อนรันบนระบบใช้งานจริง คำสั่งนี้ไม่ลบ ticket และไม่แตะงานที่จบแล้ว
--
-- ดูรายการที่จะถูกปล่อยก่อนรัน:
-- SELECT t.id, t.ticket_no, t.status, t.assigned_manager_id, manager.role, manager.is_active
-- FROM tickets AS t
-- LEFT JOIN users AS manager ON manager.id = t.assigned_manager_id
-- WHERE t.assigned_manager_id IS NOT NULL
--   AND t.status NOT IN ('completed', 'rejected', 'cancelled', 'closed')
--   AND (manager.id IS NULL OR manager.is_active <> 1 OR manager.role NOT IN ('manager', 'admin'));
--
-- UPDATE เดียวเป็น atomic และ idempotent: ถ้ารันซ้ำ งานที่ถูกปล่อยแล้วจะไม่ถูกแก้อีก
UPDATE tickets AS t
LEFT JOIN users AS manager ON manager.id = t.assigned_manager_id
SET t.assigned_manager_id = NULL,
    t.updated_at = NOW()
WHERE t.assigned_manager_id IS NOT NULL
  AND t.status NOT IN ('completed', 'rejected', 'cancelled', 'closed')
  AND (manager.id IS NULL OR manager.is_active <> 1 OR manager.role NOT IN ('manager', 'admin'));
