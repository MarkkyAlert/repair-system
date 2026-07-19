-- ============================================================
-- กันการส่งซ้ำของงานแขก (idempotency) ผ่าน submission_token (UNIQUE)
-- ------------------------------------------------------------
-- กัน double-submit/replay สร้าง guest request ซ้ำ ที่ระดับ DB
-- (defense-in-depth คู่กับ one-time session form token ใน ScanController).
-- ให้สอดคล้องกับ tickets/ticket_comments ที่มี uq_submission_token อยู่แล้ว.
-- NULL หลายแถวได้ (แถวเก่าก่อน migration ไม่ถูกบล็อก).
-- ============================================================
ALTER TABLE guest_ticket_requests
    ADD COLUMN submission_token VARCHAR(64) NULL AFTER request_no,
    ADD UNIQUE KEY uq_gtr_submission_token (submission_token);
