-- Add password_changed_at column to users to support session invalidation after password change.
-- Apply on template databases created before 2026-06-25.
-- Legacy rows keep NULL so existing sessions issued before this patch stay logged in.

ALTER TABLE users
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
