-- Phase B migrations: notification preferences + email template overrides
-- Apply once on databases that already passed migrate_must_have_integrity + migrate_logic_integrity

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(60) NOT NULL,
    channel ENUM('email','in_app') NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_type, channel),
    CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_template_overrides (
    template_key VARCHAR(80) NOT NULL,
    field_key VARCHAR(40) NOT NULL,
    field_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_key, field_key),
    CONSTRAINT fk_etpl_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_ticket_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_no VARCHAR(30) NOT NULL,
    asset_id BIGINT UNSIGNED NULL,
    location_id BIGINT UNSIGNED NULL,
    guest_name VARCHAR(150) NOT NULL,
    guest_email VARCHAR(190) NULL,
    guest_phone VARCHAR(30) NULL,
    title VARCHAR(200) NOT NULL,
    description LONGTEXT NOT NULL,
    submitted_ip VARCHAR(45) NULL,
    status ENUM('new','converted','rejected') NOT NULL DEFAULT 'new',
    converted_ticket_id BIGINT UNSIGNED NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gtr_request_no (request_no),
    KEY idx_gtr_status (status, created_at),
    CONSTRAINT fk_gtr_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_gtr_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL,
    CONSTRAINT fk_gtr_ticket FOREIGN KEY (converted_ticket_id) REFERENCES tickets (id) ON DELETE SET NULL,
    CONSTRAINT fk_gtr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
