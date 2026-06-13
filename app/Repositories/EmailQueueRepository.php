<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

class EmailQueueRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function enqueue(array $payload): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO email_queue (
                to_email,
                to_name,
                subject,
                body_html,
                body_text,
                payload,
                status,
                attempts,
                max_attempts,
                error_message,
                available_at,
                sent_at,
                failed_at,
                created_at,
                updated_at
            ) VALUES (
                :to_email,
                :to_name,
                :subject,
                :body_html,
                :body_text,
                :payload,
                :status,
                :attempts,
                :max_attempts,
                :error_message,
                :available_at,
                :sent_at,
                :failed_at,
                :created_at,
                :updated_at
            )'
        );
        $stmt->execute([
            'to_email' => (string) ($payload['to_email'] ?? ''),
            'to_name' => (string) ($payload['to_name'] ?? ''),
            'subject' => (string) ($payload['subject'] ?? ''),
            'body_html' => $payload['body_html'] ?? null,
            'body_text' => $payload['body_text'] ?? null,
            'payload' => is_array($payload['payload'] ?? null)
                ? json_encode($payload['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : ($payload['payload'] ?? null),
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => max(1, (int) ($payload['max_attempts'] ?? 3)),
            'error_message' => null,
            'available_at' => (string) ($payload['available_at'] ?? $now),
            'sent_at' => null,
            'failed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function claimDueEmails(int $limit, string $processingExpiredBefore): array
    {
        $limit = max(1, min($limit, 100));
        $availableAt = date('Y-m-d H:i:s');
        $claimedAt = $availableAt;

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "SELECT id, to_email, to_name, subject, body_html, body_text, payload, status, attempts, max_attempts, error_message, available_at, sent_at, failed_at, created_at, updated_at
                 FROM email_queue
                 WHERE (
                     (status = 'queued' AND available_at <= :available_at)
                     OR (status = 'processing' AND updated_at <= :processing_expired_before)
                 )
                 ORDER BY available_at ASC, id ASC
                 LIMIT $limit
                 FOR UPDATE"
            );
            $stmt->execute([
                'available_at' => $availableAt,
                'processing_expired_before' => $processingExpiredBefore,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                $this->db->commit();
                return [];
            }

            $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $update = $this->db->prepare(
                "UPDATE email_queue
                 SET status = 'processing',
                     attempts = attempts + 1,
                     error_message = NULL,
                     updated_at = ?
                 WHERE id IN ($placeholders)"
            );
            $update->execute([$claimedAt, ...$ids]);

            $this->db->commit();

            return array_map(static function (array $row) use ($claimedAt): array {
                $row['status'] = 'processing';
                $row['attempts'] = ((int) ($row['attempts'] ?? 0)) + 1;
                $row['updated_at'] = $claimedAt;
                return $row;
            }, $rows);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function markSent(int $emailId): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE email_queue
             SET status = :status,
                 sent_at = :sent_at,
                 failed_at = NULL,
                 error_message = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'sent',
            'sent_at' => $now,
            'updated_at' => $now,
            'id' => $emailId,
        ]);
    }

    public function releaseForRetry(int $emailId, string $errorMessage, int $retryDelaySeconds): void
    {
        $now = date('Y-m-d H:i:s');
        $availableAt = date('Y-m-d H:i:s', time() + max(60, $retryDelaySeconds));
        $stmt = $this->db->prepare(
            'UPDATE email_queue
             SET status = :status,
                 error_message = :error_message,
                 available_at = :available_at,
                 failed_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'queued',
            'error_message' => $this->truncateError($errorMessage),
            'available_at' => $availableAt,
            'updated_at' => $now,
            'id' => $emailId,
        ]);
    }

    public function markFailed(int $emailId, string $errorMessage): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE email_queue
             SET status = :status,
                 error_message = :error_message,
                 failed_at = :failed_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'failed',
            'error_message' => $this->truncateError($errorMessage),
            'failed_at' => $now,
            'updated_at' => $now,
            'id' => $emailId,
        ]);
    }

    private function truncateError(string $errorMessage): string
    {
        $errorMessage = trim($errorMessage);
        if ($errorMessage === '') {
            return 'Unknown mailer error';
        }

        return function_exists('mb_substr') ? mb_substr($errorMessage, 0, 1000) : substr($errorMessage, 0, 1000);
    }
}
