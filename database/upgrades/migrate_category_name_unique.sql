-- ============================================================
-- บังคับชื่อหมวดหมู่ (ticket/asset) ห้ามซ้ำ ที่ระดับ DB (UNIQUE)
-- ------------------------------------------------------------
-- เดิมความไม่ซ้ำของ "ชื่อ" หมวดหมู่พึ่งการเช็คในโค้ดก่อน INSERT อย่างเดียว (masterValueExists)
-- ซึ่งเป็น TOCTOU: ผู้ดูแลสองคนกดสร้างชื่อเดียวกันพร้อมกัน ต่างก็ผ่านการเช็ค (ยังไม่มีแถว) แล้ว
-- INSERT ทั้งคู่ → ได้หมวดหมู่ชื่อซ้ำ (คอลัมน์ code ต่างกันจึงไม่ชน uq_code). โค้ดตั้งใจให้ชื่อไม่ซ้ำ
-- อยู่แล้ว (มีข้อความ "ชื่อ...มีอยู่แล้ว" รอรับ unique violation) แต่ขาด constraint ฝั่ง DB — เพิ่มให้ครบ
--
-- ⚠️ ถ้าฐานข้อมูลเดิมมีชื่อหมวดหมู่ซ้ำอยู่แล้ว ALTER นี้จะล้ม (Duplicate entry) — ต้องรวม/แก้ชื่อที่ซ้ำ
--    ให้ไม่ซ้ำก่อน แล้วค่อยรันไฟล์นี้. ตรวจก่อนด้วย:
--      SELECT name, COUNT(*) c FROM ticket_categories GROUP BY name HAVING c > 1;
--      SELECT name, COUNT(*) c FROM asset_categories  GROUP BY name HAVING c > 1;
-- (การเทียบชื่อใช้ collation utf8mb4_unicode_ci = ไม่สนตัวพิมพ์เล็ก/ใหญ่ ตรงกับที่โค้ดเช็ค)
-- ============================================================
ALTER TABLE ticket_categories
    ADD UNIQUE KEY uq_ticket_categories_name (name);

ALTER TABLE asset_categories
    ADD UNIQUE KEY uq_asset_categories_name (name);
