-- Optimistic-lock version for user edits (logic-review R7-F3): the admin user form submits all fields with
-- a hidden original_version; updateUser bumps version only WHERE version matches, so a stale form (Admin B
-- saved from an old snapshot) is rejected instead of silently overwriting Admin A's newer change. Mirrors the
-- assets/ticket_comments `version` column. Existing rows get version = 1.
ALTER TABLE users
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active;
