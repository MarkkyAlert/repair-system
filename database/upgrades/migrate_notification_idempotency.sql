-- Broadcast idempotency (safety-review R8-F2): a random submission_token per broadcast form + a nullable
-- UNIQUE key, so a retry / second tab with the same token is a no-op instead of re-sending the announcement
-- (in-app + email) to the whole org. Non-broadcast notifications keep token NULL (nullable UNIQUE allows many).
ALTER TABLE notifications
    ADD COLUMN submission_token CHAR(64) NULL AFTER related_id,
    ADD UNIQUE KEY uq_notifications_submission_token (submission_token);
