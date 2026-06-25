<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use PDOException;

class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, password_changed_at, full_name, phone, role, department_id, avatar, is_active, remember_token, last_login_at, created_at, updated_at
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
            'SELECT id, username, email, password_hash, password_changed_at, full_name, phone, role, department_id, avatar, is_active, remember_token, last_login_at, created_at, updated_at
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
            'SELECT id, username, email, password_hash, password_changed_at, full_name, phone, role, department_id, avatar, is_active, remember_token, last_login_at, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByRememberToken(string $tokenHash): ?array
    {
        if ($tokenHash === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, password_changed_at, full_name, phone, role, department_id, avatar, is_active, remember_token, last_login_at, created_at, updated_at
             FROM users
             WHERE remember_token = :token
               AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['token' => $tokenHash]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateRememberToken(int $userId, ?string $tokenHash): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET remember_token = :token, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'token' => $tokenHash,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
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

    public function updateLastLoginAt(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = :now WHERE id = :id LIMIT 1');
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $userId]);
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 password_changed_at = :password_changed_at,
                 updated_at = :updated_at
             WHERE id = :id
             LIMIT 1'
        );

        $now = date('Y-m-d H:i:s');
        return $stmt->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'password_changed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateProfile(int $userId, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 email = :email,
                 phone = :phone,
                 updated_at = :updated_at
             WHERE id = :id
             LIMIT 1'
        );

        try {
            return $stmt->execute([
                'id' => $userId,
                'full_name' => (string) ($data['full_name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'phone' => (string) ($data['phone'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $message = strtolower($exception->getMessage());
                if (str_contains($message, 'email')) {
                    throw new DomainException('อีเมลนี้มีอยู่ในระบบแล้ว');
                }
            }
            throw $exception;
        }
    }

    public function emailExistsForOtherUser(string $email, int $excludeUserId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM users
             WHERE email = :email AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([
            'email' => $email,
            'id' => $excludeUserId,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}
