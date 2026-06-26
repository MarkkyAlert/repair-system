<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class LoginAttemptRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function record(
        string $attemptedLogin,
        ?int $matchedUserId,
        string $ipAddress,
        ?string $userAgent,
        bool $success,
        ?string $failureReason = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (attempted_login, matched_user_id, ip_address, user_agent, success, failure_reason, created_at)
             VALUES (:login, :user_id, :ip, :ua, :success, :reason, NOW())'
        );
        $stmt->execute([
            'login' => mb_substr($attemptedLogin, 0, 255),
            'user_id' => $matchedUserId,
            'ip' => mb_substr($ipAddress, 0, 45),
            'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'success' => $success ? 1 : 0,
            'reason' => $failureReason !== null ? mb_substr($failureReason, 0, 100) : null,
        ]);
    }

    public function getRecent(int $limit = 30, ?bool $successFilter = null): array
    {
        $where = '';
        $params = [];
        if ($successFilter !== null) {
            $where = ' WHERE la.success = :success';
            $params['success'] = $successFilter ? 1 : 0;
        }

        $sql = 'SELECT la.id, la.attempted_login, la.matched_user_id, la.ip_address, la.user_agent,
                       la.success, la.failure_reason, la.created_at,
                       u.username AS user_username, u.full_name AS user_full_name
                FROM login_attempts la
                LEFT JOIN users u ON u.id = la.matched_user_id'
              . $where
              . ' ORDER BY la.created_at DESC, la.id DESC LIMIT ' . max(1, min(200, $limit));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countRecentFailures(int $sinceMinutes = 60): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND created_at >= (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->execute(['minutes' => max(1, $sinceMinutes)]);

        return (int) $stmt->fetchColumn();
    }
}
