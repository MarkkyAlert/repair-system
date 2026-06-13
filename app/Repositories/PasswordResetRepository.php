<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

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
}
