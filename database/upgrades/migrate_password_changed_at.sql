-- เพิ่มคอลัมน์ password_changed_at ให้ตาราง users เพื่อรองรับการยกเลิก session หลังเปลี่ยนรหัสผ่าน
-- รันบน template database ที่สร้างก่อน 2026-06-25
-- แถวเก่าเก็บ NULL ไว้ เพื่อให้ session ที่ออกก่อน patch นี้ยังล็อกอินค้างต่อได้

ALTER TABLE users
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
