-- รันครั้งเดียวบนฐานข้อมูลที่สร้างก่อนแพตช์ logic-integrity (ความถูกต้องเชิงตรรกะ)

ALTER TABLE tickets
    ADD COLUMN submission_token CHAR(64) NULL AFTER ticket_no,
    ADD UNIQUE KEY uq_tickets_submission_token (submission_token);

ALTER TABLE ticket_comments
    ADD COLUMN submission_token CHAR(64) NULL AFTER parent_id,
    ADD UNIQUE KEY uq_ticket_comments_submission_token (submission_token);

DELETE older
FROM ticket_approvals older
INNER JOIN ticket_approvals newer
    ON newer.ticket_id = older.ticket_id
   AND newer.id > older.id;

ALTER TABLE ticket_approvals
    ADD UNIQUE KEY uq_ticket_approvals_ticket (ticket_id);

DELETE older
FROM password_resets older
INNER JOIN password_resets newer
    ON newer.email = older.email
   AND newer.id > older.id;

ALTER TABLE password_resets
    ADD UNIQUE KEY uq_password_resets_email (email),
    ADD UNIQUE KEY uq_password_resets_token (token);
