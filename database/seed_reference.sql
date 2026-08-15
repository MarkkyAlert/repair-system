-- ============================================================
-- ข้อมูลอ้างอิง / ข้อมูลตั้งต้น (master data) — นำเข้าบนระบบจริงได้อย่างปลอดภัย
-- ประกอบด้วย แผนก, สถานที่, ระดับความสำคัญ, หมวดหมู่งานซ่อม และหมวดหมู่ทรัพย์สิน
-- ลำดับการนำเข้า: schema.sql -> seed_reference.sql จากนั้นสร้าง admin ผ่าน /setup
-- (ผู้ใช้/ticket ตัวอย่างอยู่ใน seed_demo.sql — ห้ามนำเข้าไฟล์นั้นบนระบบจริง)
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM asset_categories;
DELETE FROM ticket_categories;
DELETE FROM priorities;
DELETE FROM locations;
DELETE FROM departments;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO departments (id, code, name, description, is_active, created_at, updated_at) VALUES
    (1, 'ADMIN', 'ฝ่ายบริหาร', 'ฝ่ายบริหารระบบและงานกลาง', 1, NOW(), NOW()),
    (2, 'FAC', 'ฝ่ายอาคารสถานที่', 'ฝ่ายอาคารสถานที่และซ่อมบำรุง', 1, NOW(), NOW()),
    (3, 'IT', 'ฝ่ายไอที', 'ฝ่ายเทคโนโลยีสารสนเทศ', 1, NOW(), NOW()),
    (4, 'OPS', 'ฝ่ายปฏิบัติการ', 'ฝ่ายปฏิบัติการและผู้แจ้งงาน', 1, NOW(), NOW());

INSERT INTO locations (id, code, name, building, floor, room, description, is_active, created_at, updated_at) VALUES
    (1, 'HQ-1F-REC', 'จุดพิมพ์เอกสารแผนกต้อนรับ', 'สำนักงานใหญ่', '1', 'แผนกต้อนรับ', 'จุดรับเอกสารและเครื่องพิมพ์หลัก', 1, NOW(), NOW()),
    (2, 'HQ-2F-SRV', 'ห้องเซิร์ฟเวอร์', 'สำนักงานใหญ่', '2', 'ห้องเซิร์ฟเวอร์', 'ห้องอุปกรณ์เครือข่ายและเซิร์ฟเวอร์', 1, NOW(), NOW()),
    (3, 'HQ-2F-MTG', 'ห้องประชุม 2A', 'สำนักงานใหญ่', '2', 'ห้องประชุม 2A', 'ห้องประชุมชั้น 2A', 1, NOW(), NOW()),
    (4, 'WH-A', 'คลังสินค้า A', 'อาคารคลังสินค้า', '1', 'โซน A', 'พื้นที่คลังสินค้าโซน A', 1, NOW(), NOW());

INSERT INTO priorities (id, code, name, level, color, response_time_minutes, resolution_time_minutes, sort_order, is_active, created_at, updated_at) VALUES
    (1, 'LOW', 'ต่ำ', 1, 'slate', 1440, 4320, 1, 1, NOW(), NOW()),
    (2, 'MEDIUM', 'ปานกลาง', 2, 'sky', 480, 1440, 2, 1, NOW(), NOW()),
    (3, 'HIGH', 'สูง', 3, 'amber', 120, 480, 3, 1, NOW(), NOW()),
    (4, 'URGENT', 'เร่งด่วน', 4, 'rose', 30, 240, 4, 1, NOW(), NOW());

INSERT INTO ticket_categories (id, parent_id, code, name, description, sort_order, is_active, created_at, updated_at) VALUES
    (1, NULL, 'ELECTRICAL', 'งานไฟฟ้า', 'งานไฟฟ้าและระบบจ่ายไฟ', 1, 1, NOW(), NOW()),
    (2, NULL, 'AIRCON', 'เครื่องปรับอากาศ', 'งานเครื่องปรับอากาศและระบบทำความเย็น', 2, 1, NOW(), NOW()),
    (3, NULL, 'NETWORK', 'ระบบเครือข่าย', 'งานเครือข่ายและอินเทอร์เน็ต', 3, 1, NOW(), NOW()),
    (4, NULL, 'PLUMBING', 'งานประปา', 'งานประปาและสุขาภิบาล', 4, 1, NOW(), NOW()),
    (5, NULL, 'EQUIPMENT', 'อุปกรณ์สำนักงาน', 'อุปกรณ์สำนักงานและเครื่องใช้ไฟฟ้า', 5, 1, NOW(), NOW()),
    (6, NULL, 'CIVIL', 'งานโครงสร้าง', 'งานโครงสร้างและซ่อมแซมพื้นที่', 6, 1, NOW(), NOW());

INSERT INTO asset_categories (id, parent_id, code, name, description, sort_order, is_active, created_at, updated_at) VALUES
    (1, NULL, 'PRINTER', 'เครื่องพิมพ์', 'เครื่องพิมพ์และอุปกรณ์งานพิมพ์', 1, 1, NOW(), NOW()),
    (2, NULL, 'ROUTER', 'อุปกรณ์เครือข่าย', 'อุปกรณ์เครือข่ายและ router', 2, 1, NOW(), NOW()),
    (3, NULL, 'AIRCON', 'เครื่องปรับอากาศ', 'เครื่องปรับอากาศ', 3, 1, NOW(), NOW()),
    (4, NULL, 'UPS', 'เครื่องสำรองไฟ', 'เครื่องสำรองไฟและพลังงาน', 4, 1, NOW(), NOW());
