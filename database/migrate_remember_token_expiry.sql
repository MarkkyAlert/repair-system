-- Add a server-side expiry to remember-me tokens (a hard cap independent of the browser cookie).
-- findByRememberToken now requires remember_token_expires_at > NOW(), and NULL is treated as expired,
-- so any remember-me session issued before this patch is invalidated once (the user re-logs in).
-- Apply on template databases created before this patch.

ALTER TABLE users
    ADD COLUMN remember_token_expires_at DATETIME NULL AFTER remember_token;
