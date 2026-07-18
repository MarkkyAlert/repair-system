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
--
-- ⚠️ Backfill รอบล่าสุด (ChatGPT R10-F2): งานที่ "ปิด–เปิดซ้ำ–ปิดใหม่" มาก่อนอัปเกรด จะเหลือ snapshot SLA/rating
--   แค่แถวเดียว (โค้ดเก่าเขียนทับในที่เดิม) ซึ่งสะท้อน "รอบล่าสุด". ถ้าปล่อยเป็น cycle 1 เฉย ๆ รายงานแนวโน้มจะ
--   เอาค่าของรอบล่าสุดไปแปะงวดแรก (เดือนที่ปิดครั้งแรก) และงวดล่าสุดกลายเป็นว่าง — ประเมินคุณภาพย้อนหลังผิดเดือน.
--   จึง backfill แถวที่รอดให้เป็น cycle ล่าสุด = 1 + จำนวนครั้งที่เปิดซ้ำ (ticket_reopened) ตรงกับที่ reopen รุ่นใหม่
--   ไล่เลข cycle. งานที่ไม่เคยเปิดซ้ำไม่ถูกแตะ (คง cycle 1). รอบเก่าที่ snapshot หายจริง → รายงานอ่านเป็น
--   "ไม่มีข้อมูล" (ถูกต้องกว่าการย้ายค่าไปผิดงวด).
-- ============================================================

-- ── ticket_sla_tracks ──────────────────────────────────────
ALTER TABLE ticket_sla_tracks
    ADD COLUMN cycle INT UNSIGNED NOT NULL DEFAULT 1 AFTER metric_type;

-- backfill: แถวที่รอดของงานที่เคยเปิดซ้ำ = รอบล่าสุด (1 + จำนวน ticket_reopened). งานที่ไม่เคยเปิดซ้ำไม่อยู่ใน
-- subquery จึงคง cycle 1. ทั้ง response + resolution ของ ticket เดียวเลื่อนไปรอบเดียวกัน (lockstep กับ schema ใหม่).
UPDATE ticket_sla_tracks ts
JOIN (
    SELECT r.ticket_id, 1 + COUNT(*) AS latest_cycle
    FROM ticket_activity_logs r
    WHERE r.action = 'ticket_reopened'
    GROUP BY r.ticket_id
) rc ON rc.ticket_id = ts.ticket_id
SET ts.cycle = rc.latest_cycle;

ALTER TABLE ticket_sla_tracks
    DROP INDEX uq_ticket_sla_tracks_ticket_metric,
    ADD UNIQUE KEY uq_ticket_sla_tracks_ticket_metric (ticket_id, metric_type, cycle);

-- ── ticket_ratings ─────────────────────────────────────────
ALTER TABLE ticket_ratings
    ADD COLUMN cycle INT UNSIGNED NOT NULL DEFAULT 1 AFTER technician_id;

-- backfill: เช่นเดียวกับ SLA — คะแนนที่รอดของงานที่เคยเปิดซ้ำ = รอบล่าสุด จึงอ่านตรงงวดที่ปิดจริงครั้งล่าสุด.
UPDATE ticket_ratings tr
JOIN (
    SELECT r.ticket_id, 1 + COUNT(*) AS latest_cycle
    FROM ticket_activity_logs r
    WHERE r.action = 'ticket_reopened'
    GROUP BY r.ticket_id
) rc ON rc.ticket_id = tr.ticket_id
SET tr.cycle = rc.latest_cycle;

ALTER TABLE ticket_ratings
    DROP INDEX uq_ticket_ratings_ticket_id,
    ADD UNIQUE KEY uq_ticket_ratings_ticket_id (ticket_id, cycle);
