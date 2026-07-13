-- ============================================================
-- As-reported analytics — per-cycle SLA + rating snapshots (F1 Phase 2)
-- ------------------------------------------------------------
-- เพิ่มคอลัมน์ `cycle` ให้ ticket_sla_tracks + ticket_ratings เพื่อให้ตัวเลขรายงานของ "งวดอดีต" นิ่ง (immutable):
--   เดิม การเปิดงานซ้ำ (reopen) จะ reset แถว SLA และการให้คะแนนใหม่จะเขียนทับคะแนนเดิม → SLA/ความพึงพอใจ
--   ของเดือนที่ปิดไปแล้ว "เปลี่ยนย้อนหลัง". ตอนนี้ reopen/re-rate จะเพิ่ม "รอบใหม่ (cycle N+1)" แทนการทับของเดิม
--   รายงานจึงอ่านค่าของรอบที่ปิดจริงในแต่ละงวด และภาพที่แคปเดือนเก่าไว้อ่านค่าเดิมตลอด.
--
-- ปลอดภัยกับข้อมูลเดิม: แถวเก่าทุกแถวได้ cycle = 1 อัตโนมัติ (DEFAULT 1) และ UNIQUE เดิมยังคุมได้เท่าเดิม
-- (แต่ละ ticket มี response/resolution อย่างละแถว, cycle=1 → ไม่ชนกัน). รันครั้งเดียวบน DB ที่สร้างก่อน patch นี้;
-- DB ใหม่ที่สร้างจาก schema.sql มีคอลัมน์นี้อยู่แล้ว ไม่ต้องรันซ้ำ.
-- ============================================================

ALTER TABLE ticket_sla_tracks
    ADD COLUMN cycle INT UNSIGNED NOT NULL DEFAULT 1 AFTER metric_type,
    DROP INDEX uq_ticket_sla_tracks_ticket_metric,
    ADD UNIQUE KEY uq_ticket_sla_tracks_ticket_metric (ticket_id, metric_type, cycle);

ALTER TABLE ticket_ratings
    ADD COLUMN cycle INT UNSIGNED NOT NULL DEFAULT 1 AFTER technician_id,
    DROP INDEX uq_ticket_ratings_ticket_id,
    ADD UNIQUE KEY uq_ticket_ratings_ticket_id (ticket_id, cycle);
