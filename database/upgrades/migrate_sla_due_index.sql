-- ============================================================
-- SLA breach cron performance — index บนคอลัมน์ที่ scan หา track เกินกำหนด
-- ------------------------------------------------------------
-- processOverdueBreaches → getPendingOverdueSlaBreaches กรอง ts.status='pending' + ts.target_at < NOW()
-- แล้ว ORDER BY ts.target_at, ts.id. เดิมมีแค่ (metric_type, status) → query เป็น type=ALL + filesort.
-- composite (status, target_at, id) ให้ range scan บน target_at ภายใต้ status='pending' และให้ลำดับ sort
-- มาเลย (ไม่ต้อง filesort). cron วนได้สูงสุด 500 track/รอบ จึงคุ้มที่จะ index.
-- ============================================================
ALTER TABLE ticket_sla_tracks
    ADD KEY idx_ticket_sla_tracks_due (status, target_at, id);
