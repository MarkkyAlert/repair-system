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

    /**
     * enqueue ข้อความจำนวนมากด้วย multi-row INSERT ที่จำกัดขนาด (หนึ่ง statement ต่อ chunk) แทนที่จะ INSERT ทีละ
     * ข้อความ — สำหรับผู้ส่งแบบ fan-out เช่น broadcast ของระบบ ใช้ค่า default ของคอลัมน์ชุดเดียวกับ enqueue()
     *
     * @param array<int, array<string, mixed>> $payloads
     */
    public function enqueueMany(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $columns = 'to_email, to_name, subject, body_html, body_text, payload, status, attempts, max_attempts, error_message, available_at, sent_at, failed_at, created_at, updated_at';
        $tuple = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        foreach (array_chunk($payloads, 100) as $chunk) {
            $rows = implode(', ', array_fill(0, count($chunk), $tuple));
            $params = [];
            foreach ($chunk as $payload) {
                $params[] = (string) ($payload['to_email'] ?? '');
                $params[] = (string) ($payload['to_name'] ?? '');
                $params[] = (string) ($payload['subject'] ?? '');
                $params[] = $payload['body_html'] ?? null;
                $params[] = $payload['body_text'] ?? null;
                $params[] = is_array($payload['payload'] ?? null)
                    ? json_encode($payload['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : ($payload['payload'] ?? null);
                $params[] = 'queued';
                $params[] = 0;
                $params[] = max(1, (int) ($payload['max_attempts'] ?? 3));
                $params[] = null;
                $params[] = (string) ($payload['available_at'] ?? $now);
                $params[] = null;
                $params[] = null;
                $params[] = $now;
                $params[] = $now;
            }
            $this->db->prepare("INSERT INTO email_queue ($columns) VALUES $rows")->execute($params);
        }
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

    // การ update ขั้นสุดท้าย (markSent/releaseForRetry/markFailed) ต้องแตะเฉพาะแถวที่ worker ตัวนี้ (THIS) ยังถือครองอยู่
    // มันทำ compare-and-set บน claim: status='processing' AND attempts=:claim_attempt (ค่า attempts ที่
    // claim กำหนดไว้) ถ้ามี worker อื่น claim แถวนี้ไปใหม่หลังหมด stale timeout (attempts ถูกเพิ่มอีก)
    // การ update ขั้นสุดท้ายของ worker ที่ค้าง (stale) จะ match 0 แถวและไม่ทำอะไร (no-op) แทนที่จะไปทับ claim ใหม่ คืนค่า
    // ว่าแถวยังถูกถือครองอยู่หรือไม่ (rowCount > 0)
    public function markSent(int $emailId, int $claimAttempt): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE email_queue
             SET status = :status,
                 sent_at = :sent_at,
                 failed_at = NULL,
                 error_message = NULL,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = :expected_status
               AND attempts = :claim_attempt'
        );
        $stmt->execute([
            'status' => 'sent',
            'sent_at' => $now,
            'updated_at' => $now,
            'id' => $emailId,
            'expected_status' => 'processing',
            'claim_attempt' => $claimAttempt,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function releaseForRetry(int $emailId, string $errorMessage, int $retryDelaySeconds, int $claimAttempt): bool
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
             WHERE id = :id
               AND status = :expected_status
               AND attempts = :claim_attempt'
        );
        $stmt->execute([
            'status' => 'queued',
            'error_message' => $this->truncateError($errorMessage),
            'available_at' => $availableAt,
            'updated_at' => $now,
            'id' => $emailId,
            'expected_status' => 'processing',
            'claim_attempt' => $claimAttempt,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markFailed(int $emailId, string $errorMessage, int $claimAttempt): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE email_queue
             SET status = :status,
                 error_message = :error_message,
                 failed_at = :failed_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = :expected_status
               AND attempts = :claim_attempt'
        );
        $stmt->execute([
            'status' => 'failed',
            'error_message' => $this->truncateError($errorMessage),
            'failed_at' => $now,
            'updated_at' => $now,
            'id' => $emailId,
            'expected_status' => 'processing',
            'claim_attempt' => $claimAttempt,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function countByStatus(): array
    {
        $stmt = $this->db->query(
            "SELECT status, COUNT(*) AS total
             FROM email_queue
             GROUP BY status"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totals = ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if (isset($totals[$key])) {
                $totals[$key] = (int) $row['total'];
            }
        }

        return $totals;
    }

    public function listJobs(string $status, int $limit, int $offset): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $where = '';
        $params = [];
        if (in_array($status, ['queued', 'processing', 'sent', 'failed'], true)) {
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare(
            "SELECT id, to_email, to_name, subject, status, attempts, max_attempts, error_message, available_at, sent_at, failed_at, created_at, updated_at
             FROM email_queue
             $where
             ORDER BY id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countJobs(string $status): int
    {
        $where = '';
        $params = [];
        if (in_array($status, ['queued', 'processing', 'sent', 'failed'], true)) {
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM email_queue $where");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function requeueForRetry(int $emailId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "UPDATE email_queue
             SET status = 'queued',
                 attempts = 0,
                 error_message = NULL,
                 available_at = :available_at,
                 failed_at = NULL,
                 sent_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id
               AND status IN ('failed', 'sent')"
        );
        $stmt->execute([
            'available_at' => $now,
            'updated_at' => $now,
            'id' => $emailId,
        ]);

        return $stmt->rowCount() > 0;
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
