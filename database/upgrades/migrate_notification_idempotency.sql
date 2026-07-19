-- idempotency (รันซ้ำได้ผลเดิม) ของการ broadcast: สุ่ม submission_token ต่อหนึ่งฟอร์ม broadcast + UNIQUE key ที่
-- เป็น null ได้ ดังนั้นการ retry / เปิดแท็บที่สองด้วย token เดิมจะไม่ทำอะไร (no-op) แทนที่จะส่งประกาศซ้ำ
-- (ทั้งในแอป + email) ให้ทั้งองค์กร. notification ที่ไม่ใช่ broadcast เก็บ token เป็น NULL (UNIQUE ที่เป็น null ได้ อนุญาตหลายแถว).
ALTER TABLE notifications
    ADD COLUMN submission_token CHAR(64) NULL AFTER related_id,
    ADD UNIQUE KEY uq_notifications_submission_token (submission_token);
