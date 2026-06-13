INSERT INTO users (
    id,
    username,
    email,
    password_hash,
    full_name,
    phone,
    role,
    department_id,
    avatar,
    is_active,
    remember_token,
    created_at,
    updated_at
) VALUES
    (1, 'requester', 'requester@example.com', '$2y$10$pWQkE8MfhGYIJMtYqGvL9O49oQCaM8t1H/x5ncR.dYJIramnTFnkO', 'สมชาย ผู้แจ้ง', '0810000001', 'requester', NULL, NULL, 1, NULL, NOW(), NOW()),
    (2, 'manager', 'manager@example.com', '$2y$10$gnmJ9ZfE6l2W.Jib1cHUkuRPfmsBs5Ur5Ummyl/ZkO.HtzjGD7oCi', 'สุภาวดี ผู้จัดการ', '0810000002', 'manager', NULL, NULL, 1, NULL, NOW(), NOW()),
    (3, 'technician', 'technician@example.com', '$2y$10$r6.6lsq9bRkmneKDT5Seo.GtlpfQ4KSH.h.6b2D4D6zI25yLM7kuq', 'วิทยา ช่างเทคนิค', '0810000003', 'technician', NULL, NULL, 1, NULL, NOW(), NOW()),
    (4, 'admin', 'admin@example.com', '$2y$10$PLWWK0gSe/2jfAp3UpgcCuBYw2BCA.NZ0HmxAfAQKfWdaR7LJLFq2', 'ผู้ดูแลระบบ', '0810000004', 'admin', NULL, NULL, 1, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    full_name = VALUES(full_name),
    phone = VALUES(phone),
    role = VALUES(role),
    is_active = VALUES(is_active),
    updated_at = VALUES(updated_at);
