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
    (1, 'ADMIN', 'Administration', 'ฝ่ายบริหารระบบและงานกลาง', 1, NOW(), NOW()),
    (2, 'FAC', 'Facilities', 'ฝ่ายอาคารสถานที่และซ่อมบำรุง', 1, NOW(), NOW()),
    (3, 'IT', 'IT Support', 'ฝ่ายเทคโนโลยีสารสนเทศ', 1, NOW(), NOW()),
    (4, 'OPS', 'Operations', 'ฝ่ายปฏิบัติการและผู้แจ้งงาน', 1, NOW(), NOW());

INSERT INTO locations (id, code, name, building, floor, room, description, is_active, created_at, updated_at) VALUES
    (1, 'HQ-1F-REC', 'Reception Printer Area', 'Head Office', '1', 'Reception', 'จุดรับเอกสารและเครื่องพิมพ์หลัก', 1, NOW(), NOW()),
    (2, 'HQ-2F-SRV', 'Server Room', 'Head Office', '2', 'Server Room', 'ห้องอุปกรณ์เครือข่ายและเซิร์ฟเวอร์', 1, NOW(), NOW()),
    (3, 'HQ-2F-MTG', 'Meeting Room 2A', 'Head Office', '2', 'Meeting Room 2A', 'ห้องประชุมชั้น 2A', 1, NOW(), NOW()),
    (4, 'WH-A', 'Warehouse A', 'Warehouse', '1', 'Zone A', 'พื้นที่คลังสินค้าโซน A', 1, NOW(), NOW());

INSERT INTO priorities (id, code, name, level, color, response_time_minutes, resolution_time_minutes, sort_order, is_active, created_at, updated_at) VALUES
    (1, 'LOW', 'Low', 1, 'slate', 1440, 4320, 1, 1, NOW(), NOW()),
    (2, 'MEDIUM', 'Medium', 2, 'sky', 480, 1440, 2, 1, NOW(), NOW()),
    (3, 'HIGH', 'High', 3, 'amber', 120, 480, 3, 1, NOW(), NOW()),
    (4, 'URGENT', 'Urgent', 4, 'rose', 30, 240, 4, 1, NOW(), NOW());

INSERT INTO ticket_categories (id, parent_id, code, name, description, sort_order, is_active, created_at, updated_at) VALUES
    (1, NULL, 'ELECTRICAL', 'Electrical', 'งานไฟฟ้าและระบบจ่ายไฟ', 1, 1, NOW(), NOW()),
    (2, NULL, 'AIRCON', 'Air Conditioner', 'งานเครื่องปรับอากาศและระบบทำความเย็น', 2, 1, NOW(), NOW()),
    (3, NULL, 'NETWORK', 'Network', 'งานเครือข่ายและอินเทอร์เน็ต', 3, 1, NOW(), NOW()),
    (4, NULL, 'PLUMBING', 'Plumbing', 'งานประปาและสุขาภิบาล', 4, 1, NOW(), NOW()),
    (5, NULL, 'EQUIPMENT', 'Office Equipment', 'อุปกรณ์สำนักงานและเครื่องใช้ไฟฟ้า', 5, 1, NOW(), NOW()),
    (6, NULL, 'CIVIL', 'Civil', 'งานโครงสร้างและซ่อมแซมพื้นที่', 6, 1, NOW(), NOW());

INSERT INTO asset_categories (id, parent_id, code, name, description, sort_order, is_active, created_at, updated_at) VALUES
    (1, NULL, 'PRINTER', 'Printer', 'เครื่องพิมพ์และอุปกรณ์งานพิมพ์', 1, 1, NOW(), NOW()),
    (2, NULL, 'ROUTER', 'Router', 'อุปกรณ์เครือข่ายและ router', 2, 1, NOW(), NOW()),
    (3, NULL, 'AIRCON', 'Air Conditioner', 'เครื่องปรับอากาศ', 3, 1, NOW(), NOW()),
    (4, NULL, 'UPS', 'UPS', 'เครื่องสำรองไฟและพลังงาน', 4, 1, NOW(), NOW());
