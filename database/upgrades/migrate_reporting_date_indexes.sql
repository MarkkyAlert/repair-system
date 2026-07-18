-- ============================================================
-- Reporting date-window performance — indexes บนคอลัมน์ที่ใช้กรองช่วงวันที่ (perf-review F7)
-- ------------------------------------------------------------
-- 1) Dashboard "ค่าเฉลี่ยการปิดงานรายเดือน" กรอง tickets.resolved_at ตามช่วงปี
--    (getDashboardMonthlyResolutionAverages) — เดิม resolved_at ไม่มี index → full scan + filesort.
-- 2) รายงานความพึงพอใจ (CSAT) กรอง ticket_ratings.created_at ตามช่วง แล้ว join กลับ tickets
--    (ratingConditions) — เดิม created_at ไม่มี index. composite (created_at, ticket_id) รองรับทั้ง
--    range scan บน created_at และ covering join key ticket_id.
-- หน้า dashboard เป็น landing page (ทุกคนเปิด) และ CSAT เป็นรายงานผู้บริหาร จึงคุ้มที่จะ index.
-- ============================================================
ALTER TABLE tickets
    ADD KEY idx_tickets_resolved_at (resolved_at);

ALTER TABLE ticket_ratings
    ADD KEY idx_ticket_ratings_created_at (created_at, ticket_id);
