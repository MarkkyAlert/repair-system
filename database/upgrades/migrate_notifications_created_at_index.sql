-- ============================================================
-- Notification feed/paginate performance — index บน created_at
-- ------------------------------------------------------------
-- NotificationRepository ดึงรายการ join กับ notification_recipients แล้ว
-- ORDER BY n.created_at DESC — เดิม notifications มี index แค่ type/related
-- → filesort เมื่อ notification โต. composite (created_at, id) รองรับ sort.
-- ============================================================
ALTER TABLE notifications
    ADD KEY idx_notifications_created_at (created_at, id);
