-- เพิ่มคอลัมน์ password_changed_at ให้ตาราง users เพื่อรองรับการยกเลิก session (session invalidation) หลังเปลี่ยนรหัสผ่าน.
-- รันบน template database ที่สร้างก่อน 2026-06-25.
-- แถวเก่า (legacy) เก็บ NULL ไว้ เพื่อให้ session ที่มีอยู่ซึ่งออกก่อน patch นี้ยังล็อกอินค้างอยู่.

ALTER TABLE users
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
