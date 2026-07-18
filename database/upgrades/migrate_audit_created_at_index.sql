-- ============================================================
-- Audit log pagination performance — index บน created_at
-- ------------------------------------------------------------
-- AuditLogRepository::paginate ORDER BY created_at DESC, id DESC
-- แต่ schema เดิมมี index แค่ user/entity/action → filesort เมื่อ log โต.
-- composite (created_at, id) รองรับทั้ง ORDER BY และ filter ช่วงวันที่.
-- ============================================================
ALTER TABLE audit_logs
    ADD KEY idx_audit_logs_created_at (created_at, id);
