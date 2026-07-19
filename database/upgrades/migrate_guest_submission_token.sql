-- ============================================================
-- กันงานแขกถูกส่งซ้ำ ด้วย submission_token (UNIQUE)
-- ------------------------------------------------------------
-- กัน double-submit/replay ไม่ให้สร้าง guest request ซ้ำที่ระดับ DB
-- เสริมกับ one-time session form token ใน ScanController อีกชั้น
-- ให้สอดคล้องกับ tickets/ticket_comments ที่มี uq_submission_token อยู่แล้ว
-- NULL ซ้ำได้หลายแถว (แถวเก่าก่อน migration ไม่ถูกบล็อก)
-- ============================================================
ALTER TABLE guest_ticket_requests
    ADD COLUMN submission_token VARCHAR(64) NULL AFTER request_no,
    ADD UNIQUE KEY uq_gtr_submission_token (submission_token);
