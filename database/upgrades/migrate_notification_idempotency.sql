-- กัน broadcast ส่งซ้ำ (idempotency): สุ่ม submission_token ต่อหนึ่งฟอร์ม broadcast + UNIQUE key ที่ยอมให้
-- เป็น null ได้ พอ retry หรือเปิดแท็บที่สองด้วย token เดิม มันจะไม่ทำอะไร (no-op) ไม่ส่งประกาศซ้ำ
-- (ทั้งในแอปและ email) ให้ทั้งองค์กร ส่วน notification ที่ไม่ใช่ broadcast เก็บ token เป็น NULL — UNIQUE แบบ null ยอมให้มีหลายแถว
ALTER TABLE notifications
    ADD COLUMN submission_token CHAR(64) NULL AFTER related_id,
    ADD UNIQUE KEY uq_notifications_submission_token (submission_token);
