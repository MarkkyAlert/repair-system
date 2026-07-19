-- optimistic-lock version (ล็อกแบบมองโลกในแง่ดี) ตอนแก้ไข user: ฟอร์ม admin ส่งทุกฟิลด์พร้อม
-- original_version ที่ซ่อนไว้ updateUser จะบวก version เฉพาะเมื่อ WHERE version ตรงกัน ฟอร์มเก่า (Admin B
-- เซฟจาก snapshot เดิม) เลยโดนปฏิเสธ ไม่ไปเขียนทับงานใหม่กว่าของ Admin A แบบเงียบ ๆ ลอกแนว
-- คอลัมน์ `version` ของ assets/ticket_comments มาใช้ แถวเดิมที่มีอยู่ได้ version = 1
ALTER TABLE users
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active;
