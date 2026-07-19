-- optimistic-lock version (การล็อกแบบมองโลกในแง่ดี) สำหรับการแก้ไข user: ฟอร์ม admin ส่งทุกฟิลด์พร้อม
-- original_version ที่ซ่อนไว้; updateUser เพิ่มค่า version เฉพาะ WHERE version ที่ตรงกัน ดังนั้นฟอร์มเก่า (Admin B
-- เซฟจาก snapshot เก่า) จะถูกปฏิเสธ แทนที่จะเขียนทับการเปลี่ยนแปลงใหม่กว่าของ Admin A แบบเงียบ ๆ. เลียนแบบ
-- คอลัมน์ `version` ของ assets/ticket_comments. แถวที่มีอยู่เดิมได้ version = 1.
ALTER TABLE users
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active;
