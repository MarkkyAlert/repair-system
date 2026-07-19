-- log ความพยายามล็อกอิน — เก็บทั้งที่สำเร็จและล้มเหลว ไว้ตามรอยด้านความปลอดภัย
-- รันหลัง schema หลัก เป็น idempotent (รันซ้ำได้ผลเดิม) — ข้ามให้ถ้าตารางมีอยู่แล้ว

CREATE TABLE IF NOT EXISTS login_attempts (
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
