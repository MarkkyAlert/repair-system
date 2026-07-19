-- การซ่อม integrity (ความถูกต้องของข้อมูล) ที่จำเป็น สำหรับ template database ที่สร้างก่อน 2026-06-16.
-- ตรวจสอบข้อมูลเดิมก่อนรันบนฐานข้อมูลลูกค้าที่ใช้งานจริง (live).

DELETE FROM ticket_attachments
WHERE disk_path IN (
    'uploads/tickets/20260602_printer_error_1.jpg',
    'uploads/tickets/20260602_router_log_1.txt'
);

INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, breached_at, status, created_at)
SELECT t.id, 'response', t.response_due_at, t.first_response_at,
       CASE WHEN t.first_response_at IS NOT NULL AND t.first_response_at > t.response_due_at THEN t.first_response_at ELSE NULL END,
       CASE
           WHEN t.first_response_at IS NULL THEN 'pending'
           WHEN t.first_response_at > t.response_due_at THEN 'breached'
           ELSE 'met'
       END,
       NOW()
FROM tickets t
LEFT JOIN ticket_sla_tracks s ON s.ticket_id = t.id AND s.metric_type = 'response'
WHERE s.id IS NULL AND t.response_due_at IS NOT NULL;

INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, breached_at, status, created_at)
SELECT t.id, 'resolution', t.resolution_due_at, t.resolved_at,
       CASE WHEN t.resolved_at IS NOT NULL AND t.resolved_at > t.resolution_due_at THEN t.resolved_at ELSE NULL END,
       CASE
           WHEN t.resolved_at IS NULL THEN 'pending'
           WHEN t.resolved_at > t.resolution_due_at THEN 'breached'
           ELSE 'met'
       END,
       NOW()
FROM tickets t
LEFT JOIN ticket_sla_tracks s ON s.ticket_id = t.id AND s.metric_type = 'resolution'
WHERE s.id IS NULL AND t.resolution_due_at IS NOT NULL;

UPDATE ticket_sla_tracks
SET status = CASE
        WHEN achieved_at IS NULL THEN 'pending'
        WHEN achieved_at > target_at THEN 'breached'
        ELSE 'met'
    END,
    breached_at = CASE
        WHEN achieved_at IS NOT NULL AND achieved_at > target_at THEN COALESCE(breached_at, achieved_at)
        ELSE breached_at
    END
WHERE achieved_at IS NOT NULL;

ALTER TABLE ticket_sla_tracks
    ADD UNIQUE KEY uq_ticket_sla_tracks_ticket_metric (ticket_id, metric_type);
