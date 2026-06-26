SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS export_jobs;
DROP TABLE IF EXISTS email_queue;
DROP TABLE IF EXISTS notification_recipients;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS ticket_ratings;
DROP TABLE IF EXISTS ticket_sla_tracks;
DROP TABLE IF EXISTS ticket_activity_logs;
DROP TABLE IF EXISTS ticket_attachments;
DROP TABLE IF EXISTS ticket_comments;
DROP TABLE IF EXISTS work_orders;
DROP TABLE IF EXISTS ticket_approvals;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS asset_qr_tokens;
DROP TABLE IF EXISTS assets;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS asset_categories;
DROP TABLE IF EXISTS ticket_categories;
DROP TABLE IF EXISTS priorities;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS departments;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_code (code),
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    building VARCHAR(150) NULL,
    floor VARCHAR(50) NULL,
    room VARCHAR(50) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_code (code),
    KEY idx_locations_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE priorities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    level TINYINT UNSIGNED NOT NULL,
    color VARCHAR(30) NULL,
    response_time_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    resolution_time_minutes INT UNSIGNED NOT NULL DEFAULT 480,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_priorities_code (code),
    UNIQUE KEY uq_priorities_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_categories_code (code),
    KEY idx_ticket_categories_parent (parent_id),
    CONSTRAINT fk_ticket_categories_parent FOREIGN KEY (parent_id) REFERENCES ticket_categories (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_categories_code (code),
    KEY idx_asset_categories_parent (parent_id),
    CONSTRAINT fk_asset_categories_parent FOREIGN KEY (parent_id) REFERENCES asset_categories (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_changed_at DATETIME NULL,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    role ENUM('requester','manager','technician','admin') NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    avatar VARCHAR(255) NULL,
    last_login_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    remember_token VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active),
    KEY idx_users_department (department_id),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    token CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_email (email),
    UNIQUE KEY uq_password_resets_token (token),
    KEY idx_password_resets_email_created (email, created_at),
    KEY idx_password_resets_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_code VARCHAR(60) NOT NULL,
    name VARCHAR(200) NOT NULL,
    serial_number VARCHAR(100) NULL,
    asset_category_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    custodian_user_id BIGINT UNSIGNED NULL,
    brand VARCHAR(100) NULL,
    model VARCHAR(100) NULL,
    vendor VARCHAR(150) NULL,
    purchase_date DATE NULL,
    warranty_expires_at DATE NULL,
    status ENUM('active','maintenance','retired','disposed') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_asset_code (asset_code),
    UNIQUE KEY uq_assets_serial_number (serial_number),
    KEY idx_assets_category (asset_category_id),
    KEY idx_assets_department (department_id),
    KEY idx_assets_location (location_id),
    KEY idx_assets_custodian (custodian_user_id),
    CONSTRAINT fk_assets_category FOREIGN KEY (asset_category_id) REFERENCES asset_categories (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assets_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assets_custodian FOREIGN KEY (custodian_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_qr_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    token CHAR(32) NOT NULL,
    generated_by BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_scanned_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_qr_tokens_token (token),
    KEY idx_asset_qr_tokens_asset (asset_id),
    KEY idx_asset_qr_tokens_generated_by (generated_by),
    CONSTRAINT fk_asset_qr_tokens_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_asset_qr_tokens_generated_by FOREIGN KEY (generated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_no VARCHAR(30) NOT NULL,
    submission_token CHAR(64) NULL,
    title VARCHAR(200) NOT NULL,
    description LONGTEXT NOT NULL,
    requester_id BIGINT UNSIGNED NOT NULL,
    requester_department_id BIGINT UNSIGNED NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NULL,
    ticket_category_id BIGINT UNSIGNED NOT NULL,
    priority_id BIGINT UNSIGNED NOT NULL,
    assigned_manager_id BIGINT UNSIGNED NULL,
    assigned_technician_id BIGINT UNSIGNED NULL,
    approval_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'pending',
    status ENUM('submitted','pending_approval','approved','assigned','accepted','in_progress','on_hold','resolved','completed','rejected','cancelled','closed') NOT NULL DEFAULT 'submitted',
    channel ENUM('web','qr','phone','email','walk_in') NOT NULL DEFAULT 'web',
    impact_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    urgency_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    assigned_at DATETIME NULL,
    started_at DATETIME NULL,
    first_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    closed_at DATETIME NULL,
    response_due_at DATETIME NULL,
    resolution_due_at DATETIME NULL,
    resolution_summary TEXT NULL,
    closure_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tickets_ticket_no (ticket_no),
    UNIQUE KEY uq_tickets_submission_token (submission_token),
    KEY idx_tickets_requester (requester_id),
    KEY idx_tickets_department (requester_department_id),
    KEY idx_tickets_location (location_id),
    KEY idx_tickets_asset (asset_id),
    KEY idx_tickets_category (ticket_category_id),
    KEY idx_tickets_priority (priority_id),
    KEY idx_tickets_manager (assigned_manager_id),
    KEY idx_tickets_technician (assigned_technician_id),
    KEY idx_tickets_status (status, approval_status),
    KEY idx_tickets_requested_at (requested_at),
    CONSTRAINT fk_tickets_requester FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_requester_department FOREIGN KEY (requester_department_id) REFERENCES departments (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_category FOREIGN KEY (ticket_category_id) REFERENCES ticket_categories (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_priority FOREIGN KEY (priority_id) REFERENCES priorities (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_manager FOREIGN KEY (assigned_manager_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_technician FOREIGN KEY (assigned_technician_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    approver_id BIGINT UNSIGNED NOT NULL,
    action ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    note TEXT NULL,
    acted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_approvals_ticket (ticket_id),
    KEY idx_ticket_approvals_ticket (ticket_id),
    KEY idx_ticket_approvals_approver (approver_id),
    CONSTRAINT fk_ticket_approvals_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_approvals_approver FOREIGN KEY (approver_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_order_no VARCHAR(30) NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    technician_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    status ENUM('assigned','accepted','in_progress','paused','completed','cancelled') NOT NULL DEFAULT 'assigned',
    instructions TEXT NULL,
    diagnosis_summary TEXT NULL,
    resolution_summary TEXT NULL,
    labor_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_orders_work_order_no (work_order_no),
    UNIQUE KEY uq_work_orders_ticket_id (ticket_id),
    KEY idx_work_orders_technician (technician_id),
    KEY idx_work_orders_assigned_by (assigned_by),
    KEY idx_work_orders_status (status),
    CONSTRAINT fk_work_orders_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_work_orders_technician FOREIGN KEY (technician_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_work_orders_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    submission_token CHAR(64) NULL,
    body LONGTEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_comments_submission_token (submission_token),
    KEY idx_ticket_comments_ticket (ticket_id),
    KEY idx_ticket_comments_user (user_id),
    KEY idx_ticket_comments_parent (parent_id),
    CONSTRAINT fk_ticket_comments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_comments_parent FOREIGN KEY (parent_id) REFERENCES ticket_comments (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    disk_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ticket_attachments_ticket (ticket_id),
    KEY idx_ticket_attachments_comment (comment_id),
    KEY idx_ticket_attachments_uploaded_by (uploaded_by),
    CONSTRAINT fk_ticket_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_attachments_comment FOREIGN KEY (comment_id) REFERENCES ticket_comments (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    from_status VARCHAR(50) NULL,
    to_status VARCHAR(50) NULL,
    details LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ticket_activity_logs_ticket (ticket_id),
    KEY idx_ticket_activity_logs_actor (actor_id),
    KEY idx_ticket_activity_logs_action (action),
    CONSTRAINT fk_ticket_activity_logs_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_activity_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_sla_tracks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    metric_type ENUM('response','resolution') NOT NULL,
    target_at DATETIME NOT NULL,
    achieved_at DATETIME NULL,
    breached_at DATETIME NULL,
    status ENUM('pending','met','breached') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_sla_tracks_ticket_metric (ticket_id, metric_type),
    KEY idx_ticket_sla_tracks_ticket (ticket_id),
    KEY idx_ticket_sla_tracks_metric (metric_type, status),
    CONSTRAINT fk_ticket_sla_tracks_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_ratings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    requester_id BIGINT UNSIGNED NOT NULL,
    technician_id BIGINT UNSIGNED NULL,
    score TINYINT UNSIGNED NOT NULL,
    feedback TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticket_ratings_ticket_id (ticket_id),
    KEY idx_ticket_ratings_requester (requester_id),
    KEY idx_ticket_ratings_technician (technician_id),
    CONSTRAINT fk_ticket_ratings_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_ratings_requester FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_ratings_technician FOREIGN KEY (technician_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    payload LONGTEXT NULL,
    related_type VARCHAR(100) NULL,
    related_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_type (type),
    KEY idx_notifications_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_recipients (notification_id, user_id),
    KEY idx_notification_recipients_user_read (user_id, is_read),
    CONSTRAINT fk_notification_recipients_notification FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notification_recipients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email VARCHAR(190) NOT NULL,
    to_name VARCHAR(150) NULL,
    subject VARCHAR(255) NOT NULL,
    body_html LONGTEXT NULL,
    body_text LONGTEXT NULL,
    payload LONGTEXT NULL,
    status ENUM('queued','processing','sent','failed') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    error_message TEXT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_queue_status_available (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE export_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(100) NOT NULL,
    format ENUM('csv','xlsx','pdf') NOT NULL DEFAULT 'xlsx',
    filters LONGTEXT NULL,
    status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
    file_name VARCHAR(255) NULL,
    file_path VARCHAR(255) NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_export_jobs_status (status),
    KEY idx_export_jobs_requested_by (requested_by),
    CONSTRAINT fk_export_jobs_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(150) NOT NULL,
    setting_value LONGTEXT NULL,
    value_type ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_settings_key (setting_key),
    KEY idx_system_settings_updated_by (updated_by),
    CONSTRAINT fk_system_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(150) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    context LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_user (user_id),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_action (action),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempted_login VARCHAR(255) NOT NULL,
    matched_user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    failure_reason VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_created (created_at),
    KEY idx_login_attempts_login (attempted_login(64)),
    KEY idx_login_attempts_ip (ip_address),
    KEY idx_login_attempts_success (success),
    CONSTRAINT fk_login_attempts_user FOREIGN KEY (matched_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
