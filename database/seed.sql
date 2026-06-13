SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM audit_logs;
DELETE FROM system_settings;
DELETE FROM export_jobs;
DELETE FROM email_queue;
DELETE FROM notification_recipients;
DELETE FROM notifications;
DELETE FROM ticket_ratings;
DELETE FROM ticket_sla_tracks;
DELETE FROM ticket_activity_logs;
DELETE FROM ticket_attachments;
DELETE FROM ticket_comments;
DELETE FROM work_orders;
DELETE FROM ticket_approvals;
DELETE FROM tickets;
DELETE FROM asset_qr_tokens;
DELETE FROM assets;
DELETE FROM password_resets;
DELETE FROM users;
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

INSERT INTO users (
    id,
    username,
    email,
    password_hash,
    full_name,
    phone,
    role,
    department_id,
    avatar,
    last_login_at,
    is_active,
    remember_token,
    created_at,
    updated_at
) VALUES
    (1, 'requester', 'requester@example.com', '$2y$10$pWQkE8MfhGYIJMtYqGvL9O49oQCaM8t1H/x5ncR.dYJIramnTFnkO', 'สมชาย ผู้แจ้ง', '0810000001', 'requester', 4, NULL, NULL, 1, NULL, NOW(), NOW()),
    (2, 'manager', 'manager@example.com', '$2y$10$gnmJ9ZfE6l2W.Jib1cHUkuRPfmsBs5Ur5Ummyl/ZkO.HtzjGD7oCi', 'สุภาวดี ผู้จัดการ', '0810000002', 'manager', 2, NULL, NULL, 1, NULL, NOW(), NOW()),
    (3, 'technician', 'technician@example.com', '$2y$10$r6.6lsq9bRkmneKDT5Seo.GtlpfQ4KSH.h.6b2D4D6zI25yLM7kuq', 'วิทยา ช่างเทคนิค', '0810000003', 'technician', 2, NULL, NULL, 1, NULL, NOW(), NOW()),
    (4, 'admin', 'admin@example.com', '$2y$10$PLWWK0gSe/2jfAp3UpgcCuBYw2BCA.NZ0HmxAfAQKfWdaR7LJLFq2', 'ผู้ดูแลระบบ', '0810000004', 'admin', 1, NULL, NULL, 1, NULL, NOW(), NOW());

INSERT INTO assets (
    id,
    asset_code,
    name,
    serial_number,
    asset_category_id,
    department_id,
    location_id,
    custodian_user_id,
    brand,
    model,
    vendor,
    purchase_date,
    warranty_expires_at,
    status,
    notes,
    created_at,
    updated_at
) VALUES
    (1, 'AST-PRN-0001', 'Receipt Printer Front Desk', 'PRN-2024-0001', 1, 4, 1, 1, 'HP', 'LaserJet Pro', 'Office Supplies Co.', '2024-01-15', '2027-01-15', 'active', 'เครื่องพิมพ์หลักบริเวณ reception', NOW(), NOW()),
    (2, 'AST-RTR-0001', 'Core Router Server Room', 'RTR-2023-0099', 2, 3, 2, NULL, 'Cisco', 'ISR 1000', 'Network Vendor Co.', '2023-09-10', '2026-09-10', 'active', 'อุปกรณ์เครือข่ายหลักของสำนักงาน', NOW(), NOW()),
    (3, 'AST-AC-0001', 'Meeting Room Air Conditioner', 'AC-2022-0201', 3, 2, 3, NULL, 'Daikin', 'Cassette Inverter', 'Cooling Expert Ltd.', '2022-03-20', '2025-03-20', 'maintenance', 'เริ่มมีอาการเย็นช้าช่วงบ่าย', NOW(), NOW());

INSERT INTO asset_qr_tokens (id, asset_id, token, generated_by, is_active, last_scanned_at, created_at, updated_at) VALUES
    (1, 1, '8f1b9c6d2a4e7f8091b2c3d4e5f60718', 4, 1, NOW(), NOW(), NOW()),
    (2, 2, '2c4d6e8f0a1b3c5d7e9f1029384756ab', 4, 1, NOW(), NOW(), NOW()),
    (3, 3, '4a7c9e1f2b3d5f60718293a4c5d6e7f8', 4, 1, NULL, NOW(), NOW());

INSERT INTO tickets (
    id,
    ticket_no,
    title,
    description,
    requester_id,
    requester_department_id,
    location_id,
    asset_id,
    ticket_category_id,
    priority_id,
    assigned_manager_id,
    assigned_technician_id,
    approval_status,
    status,
    channel,
    impact_level,
    urgency_level,
    requested_at,
    approved_at,
    assigned_at,
    started_at,
    first_response_at,
    resolved_at,
    completed_at,
    cancelled_at,
    closed_at,
    response_due_at,
    resolution_due_at,
    resolution_summary,
    closure_note,
    created_at,
    updated_at
) VALUES
    (1, 'MT-20260602-0001', 'Printer ไม่พิมพ์ใบรับของ', 'เครื่องพิมพ์หน้าจุดรับของเปิดติดแต่ไม่ยอมดึงกระดาษและแสดงไฟกระพริบสีแดง', 1, 4, 1, 1, 5, 2, 2, NULL, 'pending', 'pending_approval', 'web', 'medium', 'medium', '2026-06-02 08:30:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-02 16:30:00', '2026-06-03 08:30:00', NULL, NULL, NOW(), NOW()),
    (2, 'MT-20260602-0002', 'Router ห้อง Server มีอาการ packet loss', 'ผู้ใช้งานหลายแผนกแจ้งว่าเครือข่ายล่มเป็นช่วง ๆ และพบ packet loss ที่ core router', 1, 4, 2, 2, 3, 4, 2, 3, 'approved', 'in_progress', 'web', 'critical', 'critical', '2026-06-02 09:05:00', '2026-06-02 09:12:00', '2026-06-02 09:20:00', '2026-06-02 09:35:00', '2026-06-02 09:18:00', NULL, NULL, NULL, NULL, '2026-06-02 09:35:00', '2026-06-02 13:05:00', 'อยู่ระหว่างวิเคราะห์ interface uplink และตรวจสอบ firmware', NULL, NOW(), NOW()),
    (3, 'MT-20260602-0003', 'แอร์ห้องประชุมชั้น 2 ไม่เย็น', 'ห้องประชุมชั้น 2A อุณหภูมิสูงผิดปกติแม้เปิดเครื่องแล้วเกิน 30 นาที', 1, 4, 3, 3, 2, 3, 2, 3, 'approved', 'completed', 'qr', 'high', 'high', '2026-06-01 13:10:00', '2026-06-01 13:25:00', '2026-06-01 13:40:00', '2026-06-01 14:00:00', '2026-06-01 13:32:00', '2026-06-01 15:10:00', '2026-06-01 15:30:00', NULL, '2026-06-02 10:00:00', '2026-06-01 15:10:00', '2026-06-01 21:10:00', 'ล้างแผงคอยล์และเติมน้ำยาแอร์ ทำให้อุณหภูมิคงที่ตามปกติ', 'ผู้แจ้งยืนยันว่าห้องใช้งานได้แล้ว', NOW(), NOW());

INSERT INTO ticket_approvals (id, ticket_id, approver_id, action, note, acted_at, created_at) VALUES
    (1, 1, 2, 'pending', 'รอหัวหน้างานตรวจสอบความเร่งด่วนและอนุมัติ', NULL, NOW()),
    (2, 2, 2, 'approved', 'อนุมัติเร่งด่วนและมอบหมายให้ช่างเข้าตรวจสอบทันที', '2026-06-02 09:12:00', NOW()),
    (3, 3, 2, 'approved', 'อนุมัติและส่งทีมช่างเข้าหน้างานภายในวันเดียวกัน', '2026-06-01 13:25:00', NOW());

INSERT INTO work_orders (
    id,
    work_order_no,
    ticket_id,
    technician_id,
    assigned_by,
    status,
    instructions,
    diagnosis_summary,
    resolution_summary,
    labor_minutes,
    assigned_at,
    accepted_at,
    started_at,
    completed_at,
    created_at,
    updated_at
) VALUES
    (1, 'WO-20260602-0001', 2, 3, 2, 'in_progress', 'ตรวจสอบ uplink, log ของ interface และเปลี่ยนสายหากจำเป็น', 'พบ error counter บน uplink และกำลังทดสอบสายสำรอง', NULL, 45, '2026-06-02 09:20:00', '2026-06-02 09:24:00', '2026-06-02 09:35:00', NULL, NOW(), NOW()),
    (2, 'WO-20260601-0001', 3, 3, 2, 'completed', 'ตรวจระบบทำความเย็นและชุดคอยล์ พร้อมรายงานผลหลังซ่อม', 'พบคอยล์สกปรกและแรงดันน้ำยาไม่คงที่', 'ล้างคอยล์ เติมน้ำยา และทดสอบอุณหภูมิหลังซ่อมจนคงที่', 90, '2026-06-01 13:40:00', '2026-06-01 13:45:00', '2026-06-01 14:00:00', '2026-06-01 15:10:00', NOW(), NOW());

INSERT INTO ticket_comments (id, ticket_id, user_id, parent_id, body, is_internal, created_at, updated_at) VALUES
    (1, 2, 2, NULL, 'อนุมัติแล้วและมอบหมายให้ช่างเข้าตรวจสอบทันที เนื่องจากกระทบหลายแผนก', 1, '2026-06-02 09:15:00', '2026-06-02 09:15:00'),
    (2, 2, 3, 1, 'รับงานแล้ว กำลังตรวจ uplink และสวิตช์ต้นทาง', 1, '2026-06-02 09:26:00', '2026-06-02 09:26:00'),
    (3, 3, 1, NULL, 'หลังซ่อมห้องประชุมกลับมาเย็นตามปกติ ขอบคุณครับ', 0, '2026-06-01 16:00:00', '2026-06-01 16:00:00');

INSERT INTO ticket_attachments (id, ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at) VALUES
    (1, 1, NULL, 1, 'printer-error.jpg', '20260602_printer_error_1.jpg', 'uploads/tickets/20260602_printer_error_1.jpg', 'image/jpeg', 245312, NOW()),
    (2, 2, 2, 3, 'router-log.txt', '20260602_router_log_1.txt', 'uploads/tickets/20260602_router_log_1.txt', 'text/plain', 18342, NOW());

INSERT INTO ticket_activity_logs (id, ticket_id, actor_id, action, from_status, to_status, details, created_at) VALUES
    (1, 1, 1, 'ticket_submitted', NULL, 'pending_approval', 'ผู้แจ้งสร้างรายการแจ้งซ่อมผ่านหน้าเว็บ', '2026-06-02 08:30:00'),
    (2, 2, 2, 'ticket_approved', 'pending_approval', 'approved', 'หัวหน้างานอนุมัติงานด่วนเครือข่าย', '2026-06-02 09:12:00'),
    (3, 2, 2, 'technician_assigned', 'approved', 'assigned', 'มอบหมายงานให้ช่างเทคนิคประจำทีม', '2026-06-02 09:20:00'),
    (4, 2, 3, 'work_started', 'assigned', 'in_progress', 'ช่างเริ่มตรวจสอบอุปกรณ์เครือข่ายและ uplink', '2026-06-02 09:35:00'),
    (5, 3, 3, 'ticket_resolved', 'in_progress', 'resolved', 'แก้ไขระบบแอร์เรียบร้อยและทดสอบผ่าน', '2026-06-01 15:10:00'),
    (6, 3, 1, 'ticket_completed', 'resolved', 'completed', 'ผู้แจ้งยืนยันผลการซ่อมและปิดงาน', '2026-06-01 15:30:00');

INSERT INTO ticket_sla_tracks (id, ticket_id, metric_type, target_at, achieved_at, breached_at, status, created_at) VALUES
    (1, 2, 'response', '2026-06-02 09:35:00', '2026-06-02 09:18:00', NULL, 'met', NOW()),
    (2, 2, 'resolution', '2026-06-02 13:05:00', NULL, NULL, 'pending', NOW()),
    (3, 3, 'response', '2026-06-01 15:10:00', '2026-06-01 13:32:00', NULL, 'met', NOW()),
    (4, 3, 'resolution', '2026-06-01 21:10:00', '2026-06-01 15:10:00', NULL, 'met', NOW());

INSERT INTO ticket_ratings (id, ticket_id, requester_id, technician_id, score, feedback, created_at, updated_at) VALUES
    (1, 3, 1, 3, 5, 'ช่างเข้าดำเนินการเร็ว อธิบายสาเหตุชัดเจน และงานเรียบร้อยมาก', NOW(), NOW());

INSERT INTO notifications (id, type, title, message, payload, related_type, related_id, created_at) VALUES
    (1, 'ticket.created', 'มีงานแจ้งซ่อมใหม่', 'มี ticket ใหม่รอการอนุมัติจากหัวหน้างาน', '{"ticket_no":"MT-20260602-0001"}', 'ticket', 1, NOW()),
    (2, 'ticket.assigned', 'มีการมอบหมายงานใหม่', 'คุณได้รับมอบหมายงานตรวจสอบอุปกรณ์เครือข่าย', '{"ticket_no":"MT-20260602-0002","work_order_no":"WO-20260602-0001"}', 'ticket', 2, NOW()),
    (3, 'ticket.completed', 'งานซ่อมเสร็จสิ้น', 'งานซ่อมแอร์ห้องประชุมเสร็จสิ้นและรอการให้คะแนน', '{"ticket_no":"MT-20260602-0003"}', 'ticket', 3, NOW());

INSERT INTO notification_recipients (id, notification_id, user_id, is_read, read_at, created_at) VALUES
    (1, 1, 2, 0, NULL, NOW()),
    (2, 2, 3, 0, NULL, NOW()),
    (3, 3, 1, 1, NOW(), NOW());

INSERT INTO email_queue (
    id,
    to_email,
    to_name,
    subject,
    body_html,
    body_text,
    payload,
    status,
    attempts,
    max_attempts,
    error_message,
    available_at,
    sent_at,
    failed_at,
    created_at,
    updated_at
) VALUES
    (1, 'manager@example.com', 'สุภาวดี ผู้จัดการ', 'แจ้งงานใหม่รออนุมัติ MT-20260602-0001', '<p>มีงานใหม่รออนุมัติ</p>', 'มีงานใหม่รออนุมัติ', '{"ticket_id":1}', 'queued', 0, 3, NULL, NOW(), NULL, NULL, NOW(), NOW());

INSERT INTO export_jobs (id, type, format, filters, status, file_name, file_path, requested_by, completed_at, error_message, created_at, updated_at) VALUES
    (1, 'ticket_summary', 'xlsx', '{"date_from":"2026-06-01","date_to":"2026-06-30"}', 'queued', NULL, NULL, 4, NULL, NULL, NOW(), NOW());

INSERT INTO system_settings (id, setting_key, setting_value, value_type, is_public, updated_by, created_at, updated_at) VALUES
    (1, 'app_name', 'Repair System', 'string', 1, 4, NOW(), NOW()),
    (2, 'default_timezone', 'Asia/Bangkok', 'string', 0, 4, NOW(), NOW()),
    (3, 'ticket_prefix', 'MT', 'string', 0, 4, NOW(), NOW()),
    (4, 'business_hours', '{"start":"08:30","end":"17:30"}', 'json', 0, 4, NOW(), NOW());

INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, ip_address, user_agent, context, created_at) VALUES
    (1, 4, 'seed_imported', 'system', 1, '127.0.0.1', 'mysql-client', '{"source":"database/seed.sql"}', NOW());
