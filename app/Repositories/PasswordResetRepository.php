<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

class PasswordResetRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function deleteByEmail(string $email): bool
    {
        $stmt = $this->db->prepare('DELETE FROM password_resets WHERE email = :email');
        return $stmt->execute(['email' => $email]);
    }

    public function create(string $email, string $tokenHash, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO password_resets (email, token, created_at, expires_at)
             VALUES (:email, :token, :created_at, :expires_at)'
        );

        return $stmt->execute([
            'email' => $email,
            'token' => $tokenHash,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
        ]);
    }

    public function replaceForEmail(string $email, string $tokenHash, string $expiresAt): void
    {
        try {
            $this->db->beginTransaction();

            $deleteStmt = $this->db->prepare('DELETE FROM password_resets WHERE email = :email');
            $deleteStmt->execute(['email' => $email]);

            $insertStmt = $this->db->prepare(
                'INSERT INTO password_resets (email, token, created_at, expires_at)
                 VALUES (:email, :token, :created_at, :expires_at)'
            );
            $insertStmt->execute([
                'email' => $email,
                'token' => $tokenHash,
                'created_at' => date('Y-m-d H:i:s'),
                'expires_at' => $expiresAt,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function findLatestByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT email, token, created_at, expires_at
             FROM password_resets
             WHERE email = :email
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function resetPasswordUsingToken(string $email, string $tokenHash, string $passwordHash): string
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'SELECT id, token, expires_at
                 FROM password_resets
                 WHERE email = :email
                 ORDER BY created_at DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['email' => $email]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($reset === null) {
                $this->db->rollBack();
                return 'missing';
            }

            if (strtotime((string) $reset['expires_at']) < time()) {
                $this->deleteByEmail($email);
                $this->db->commit();
                return 'expired';
            }

            if (!hash_equals((string) $reset['token'], $tokenHash)) {
                $this->db->rollBack();
                return 'invalid';
            }

            // Revoke every remember-me session in the same transaction: a reset is the "I lost control of this
            // account" path, so any outstanding remember cookie (possibly the attacker's) must stop working the
            // instant the password changes — NULL token means findByRememberToken can never match again.
            $userStmt = $this->db->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     password_changed_at = :password_changed_at,
                     remember_token = NULL,
                     remember_token_expires_at = NULL,
                     updated_at = :updated_at
                 WHERE email = :email
                 LIMIT 1'
            );
            $now = date('Y-m-d H:i:s');
            $userStmt->execute([
                'password_hash' => $passwordHash,
                'password_changed_at' => $now,
                'updated_at' => $now,
                'email' => $email,
            ]);

            if ($userStmt->rowCount() !== 1) {
                throw new DomainException('ไม่พบบัญชีผู้ใช้นี้');
            }

            $deleteStmt = $this->db->prepare('DELETE FROM password_resets WHERE id = :id');
            $deleteStmt->execute(['id' => (int) $reset['id']]);
            $this->db->commit();

            return 'success';
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($exception instanceof DomainException) {
                throw $exception;
            }

            throw new RuntimeException('ไม่สามารถตั้งรหัสผ่านใหม่ได้ กรุณาลองอีกครั้ง', 0, $exception);
        }
    }
}
