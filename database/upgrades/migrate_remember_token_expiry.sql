-- เพิ่มวันหมดอายุฝั่ง server ให้ remember-me token (เพดานตายตัวที่ไม่ขึ้นกับ cookie ของ browser).
-- ตอนนี้ findByRememberToken ต้องการ remember_token_expires_at > NOW() และ NULL ถือว่าหมดอายุ
-- ดังนั้น remember-me session ใดก็ตามที่ออกก่อน patch นี้จะถูกยกเลิกหนึ่งครั้ง (ผู้ใช้ต้องล็อกอินใหม่).
-- รันบน template database ที่สร้างก่อน patch นี้.

ALTER TABLE users
    ADD COLUMN remember_token_expires_at DATETIME NULL AFTER remember_token;
