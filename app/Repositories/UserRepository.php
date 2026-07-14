<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use PDOException;

class UserRepository
{
    private const SELECT_COLUMNS = 'id, username, email, password_hash, password_changed_at, full_name, phone, role, department_id, avatar, is_active, remember_token, last_login_at, created_at, updated_at';

    public function __construct(private PDO $db)
    {
    }

    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
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
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Batch existence check for import validation — 1 query แทน 2N queries (findByLogin/findByEmail ต่อแถว).
     * คืน 2 set (lowercase): 'logins' = username/email ที่มีอยู่ (findByLogin match username OR email),
     * 'emails' = email ที่มีอยู่ (findByEmail match email).
     *
     * @param string[] $usernames
     * @param string[] $emails
     * @return array{logins: array<string, bool>, emails: array<string, bool>}
     */
    public function existingLoginsAndEmails(array $usernames, array $emails): array
    {
        $norm = static fn (array $v): array => array_values(array_unique(array_filter(
            array_map(static fn ($x): string => strtolower(trim((string) $x)), $v),
            static fn (string $x): bool => $x !== ''
        )));
        $usernames = $norm($usernames);
        $emails = $norm($emails);
        $needles = array_values(array_unique(array_merge($usernames, $emails)));
        if ($needles === []) {
            return ['logins' => [], 'emails' => []];
        }

        // ไม่ใช้ LOWER(column) ใน WHERE — collation utf8mb4_unicode_ci case-insensitive อยู่แล้ว
        // ทำให้ unique index username/email ใช้ได้ (sargable); normalize เป็น lowercase ฝั่ง PHP
        $placeholders = implode(',', array_fill(0, count($needles), '?'));
        $stmt = $this->db->prepare(
            "SELECT username, email
             FROM users
             WHERE username IN ($placeholders) OR email IN ($placeholders)"
        );
        $stmt->execute(array_merge($needles, $needles));

        $logins = [];
        $existingEmails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $username = strtolower((string) ($row['username'] ?? ''));
            $email = strtolower((string) ($row['email'] ?? ''));
            if ($username !== '') {
                $logins[$username] = true;
            }
            if ($email !== '') {
                $logins[$email] = true;      // findByLogin match email ด้วย
                $existingEmails[$email] = true;
            }
        }

        return ['logins' => $logins, 'emails' => $existingEmails];
    }

    /**
     * Batch resolve login → user id (สำหรับ asset import custodian lookup, แทน findByLogin ต่อแถว).
     * แมปทั้ง username และ email → id (findByLogin match ทั้งคู่).
     *
     * @param string[] $logins
     * @return array<string, int> login(lowercase) => user id
     */
    public function findIdsByLogins(array $logins): array
    {
        $logins = array_values(array_unique(array_filter(
            array_map(static fn ($x): string => strtolower(trim((string) $x)), $logins),
            static fn (string $x): bool => $x !== ''
        )));
        if ($logins === []) {
            return [];
        }

        // sargable — ไม่ใช้ LOWER(column) (collation case-insensitive); normalize ฝั่ง PHP
        $placeholders = implode(',', array_fill(0, count($logins), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, username, email
             FROM users
             WHERE username IN ($placeholders) OR email IN ($placeholders)"
        );
        $stmt->execute(array_merge($logins, $logins));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $username = strtolower((string) ($row['username'] ?? ''));
            $email = strtolower((string) ($row['email'] ?? ''));
            if ($username !== '') {
                $map[$username] = $id;
            }
            if ($email !== '') {
                $map[$email] = $id;
            }
        }

        return $map;
    }

    public function findById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
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
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users
             WHERE remember_token = :token
               AND is_active = 1
               AND remember_token_expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['token' => $tokenHash]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateRememberToken(int $userId, ?string $tokenHash, ?string $expiresAt = null): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET remember_token = :token, remember_token_expires_at = :expires_at, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'token' => $tokenHash,
            'expires_at' => $expiresAt,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }

    /**
     * Clear the remember token ONLY for the row whose stored hash equals $tokenHash. Used on logout so a caller
     * cannot revoke someone else's persistent login by forging a cookie with another user's id — a mismatched
     * (or forged) hash matches no row and clears nothing. (logic-review F1)
     */
    public function clearRememberTokenByHash(string $tokenHash): void
    {
        if ($tokenHash === '') {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL, updated_at = :updated_at WHERE remember_token = :token'
        );
        $stmt->execute([
            'updated_at' => date('Y-m-d H:i:s'),
            'token' => $tokenHash,
        ]);
    }

    public function findActiveUserIds(?string $role = null): array
    {
        $sql = 'SELECT id FROM users WHERE is_active = 1';
        $params = [];
        if ($role !== null && $role !== '') {
            $sql .= ' AND role = :role';
            $params['role'] = $role;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    /** True when at least one active admin exists — the first-run setup gate's "is this already provisioned?" check. */
    public function hasActiveAdmin(): bool
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() > 0;
    }

    /** The earliest active admin as [id, username, email], or null when there is none. */
    public function firstActiveAdmin(): ?array
    {
        $row = $this->db->query(
            "SELECT id, username, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
        // Revoke every remember-me session in the SAME statement as the password write, so the two can never
        // diverge: a separate revoke call could fail after the password already changed, leaving an old cookie
        // still able to log in. One UPDATE = password change and token revocation are atomic. (logic-review F1)
        $stmt = $this->db->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 password_changed_at = :password_changed_at,
                 remember_token = NULL,
                 remember_token_expires_at = NULL,
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
            if (is_duplicate_key_error($exception)) {
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
