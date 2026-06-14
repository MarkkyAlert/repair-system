<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, full_name, phone, role, department_id, avatar, is_active, remember_token, created_at, updated_at
             FROM users
             WHERE username = :username OR email = :email
             LIMIT 1'
        );
        $stmt->execute([
            'username' => $login,
            'email' => $login,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, full_name, phone, role, department_id, avatar, is_active, remember_token, created_at, updated_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, full_name, phone, role, department_id, avatar, is_active, remember_token, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findActiveUsersByIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, username, email, full_name, phone, role, department_id, is_active
             FROM users
             WHERE is_active = 1
               AND email <> ''
               AND id IN ($placeholders)
             ORDER BY full_name ASC, id ASC"
        );
        $stmt->execute($userIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id
             LIMIT 1'
        );

        return $stmt->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
