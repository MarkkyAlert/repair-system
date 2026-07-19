<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmailQueueRepository;
use App\Repositories\UserRepository;
use DomainException;
use Throwable;

class EmailQueueService
{
    public function __construct(
        private EmailQueueRepository $queue,
        private UserRepository $users,
        private EmailTemplateService $templates,
        private MailerService $mailer,
    ) {
    }

    public function queueTicketEventEmails(array $context, array $recipientIds, string $eventType, string $title, string $message): void
    {
        foreach ($this->users->findActiveUsersByIds($recipientIds) as $recipient) {
            $email = $this->templates->buildTicketEvent($context, $recipient, $eventType, $title, $message);
            $this->enqueueForRecipient($recipient, $email);
        }
    }

    public function queueCommentEventEmails(array $context, array $recipientIds, int $commentId, bool $isInternal, string $body, string $action, string $title, string $message): void
    {
        foreach ($this->users->findActiveUsersByIds($recipientIds) as $recipient) {
            $email = $this->templates->buildCommentEvent($context, $recipient, $commentId, $isInternal, $body, $action, $title, $message);
            $this->enqueueForRecipient($recipient, $email);
        }
    }

    public function queueSlaBreachedEmails(array $context, array $recipientIds, string $metricType, string $title, string $message): void
    {
        foreach ($this->users->findActiveUsersByIds($recipientIds) as $recipient) {
            $email = $this->templates->buildSlaBreached($context, $recipient, $metricType, $title, $message);
            $this->enqueueForRecipient($recipient, $email);
        }
    }

    public function queueSystemAnnouncementEmails(array $recipientIds, string $title, string $message): void
    {
        // สร้างข้อความของผู้รับทุกคนก่อน แล้ว enqueue เข้าคิวด้วย INSERT แบบหลาย row ที่จำกัดขนาด — เพราะการ broadcast ถึง
        // ทั้งองค์กรเดิมเป็น 1 INSERT ต่อผู้รับ 1 คน (2 การเขียนต่อผู้รับ รวมกับ notification).
        $payloads = [];
        foreach ($this->users->findActiveUsersByIds($recipientIds) as $recipient) {
            $email = $this->templates->buildSystemAnnouncement($recipient, $title, $message);
            $payloads[] = [
                'to_email' => (string) ($recipient['email'] ?? ''),
                'to_name' => (string) ($recipient['full_name'] ?? ''),
                'subject' => (string) ($email['subject'] ?? ''),
                'body_html' => (string) ($email['body_html'] ?? ''),
                'body_text' => (string) ($email['body_text'] ?? ''),
                'payload' => $email['payload'] ?? null,
                'max_attempts' => 3,
            ];
        }
        $this->queue->enqueueMany($payloads);
    }

    public function queuePasswordResetEmail(array $user, string $resetUrl, string $expiresAt): void
    {
        if (trim((string) ($user['email'] ?? '')) === '') {
            return;
        }

        $email = $this->templates->buildPasswordReset($user, $resetUrl, $expiresAt);
        $this->enqueueForRecipient($user, $email);
    }

    public function processDueEmails(?int $limit = null): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Email queue worker must be executed from CLI.');
        }

        // claimDueEmails จัดการ row locking ให้; guard ตัวนี้กันไม่ให้ worker ไปทำงานบน path ของ HTTP request ปกติ.
        $limit = $limit ?? (int) config('mail.queue_batch_size', 10);
        $processingExpiredBefore = date('Y-m-d H:i:s', time() - max(60, (int) config('mail.processing_timeout_seconds', 900)));
        $jobs = $this->queue->claimDueEmails($limit, $processingExpiredBefore);

        $processed = 0;
        $sent = 0;
        $retried = 0;
        $failed = 0;
        $items = [];

        foreach ($jobs as $job) {
            $processed++;
            $emailId = (int) ($job['id'] ?? 0);
            $subject = (string) ($job['subject'] ?? '');
            $recipient = (string) ($job['to_email'] ?? '');
            // ค่า attempts ที่ตอน claim กำหนดให้ job นี้ — การ update สถานะสุดท้ายด้านล่างจะ compare-and-set (ตรวจแล้วค่อยตั้งค่า) กับ
            // ค่านี้ เพื่อไม่ให้ worker ที่ค้าง (stale) เขียนทับ row ที่ worker อื่นเคลมไปแล้ว. ดู markSent().
            $claimAttempt = (int) ($job['attempts'] ?? 0);

            try {
                // RISK MAP: การส่งเป็นแบบ at-least-once (ส่งอย่างน้อยหนึ่งครั้ง) โดยตั้งใจออกแบบ (เป็น tradeoff ที่ยอมรับ). ลำดับคือ send() แล้ว markSent() —
                // ถ้า crash ระหว่างนั้น row จะค้างที่ 'processing' จึงถูกเคลมกลับมาใหม่หลัง timeout และอาจ
                // ถูกส่งซ้ำได้. การทำ exactly-once (ส่งครั้งเดียวเป๊ะ) ต้องอาศัย idempotency ฝั่ง provider; อีเมลซ้ำที่เกิดนาน ๆ ครั้ง
                // ยอมรับได้สำหรับระบบนี้ และไม่มีข้อมูลเสียหาย.
                $this->mailer->send($job);
                $this->queue->markSent($emailId, $claimAttempt);
                $sent++;
                $items[] = [
                    'id' => $emailId,
                    'to_email' => $recipient,
                    'subject' => $subject,
                    'status' => 'sent',
                ];
            } catch (Throwable $exception) {
                $attempts = (int) ($job['attempts'] ?? 0);
                $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
                $errorMessage = $exception->getMessage();

                if ($attempts >= $maxAttempts) {
                    $this->queue->markFailed($emailId, $errorMessage, $claimAttempt);
                    $failed++;
                    $items[] = [
                        'id' => $emailId,
                        'to_email' => $recipient,
                        'subject' => $subject,
                        'status' => 'failed',
                        'error' => $errorMessage,
                    ];
                    continue;
                }

                $this->queue->releaseForRetry($emailId, $errorMessage, (int) config('mail.retry_delay_seconds', 300), $claimAttempt);
                $retried++;
                $items[] = [
                    'id' => $emailId,
                    'to_email' => $recipient,
                    'subject' => $subject,
                    'status' => 'queued',
                    'error' => $errorMessage,
                ];
            }
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'retried' => $retried,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    public function listJobsPaginated(string $status, int $page, int $perPage = 25): array
    {
        $perPage = max(5, min($perPage, 100));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $totals = $this->queue->countByStatus();
        $matched = $this->queue->countJobs($status);
        $totalPages = $matched > 0 ? (int) ceil($matched / $perPage) : 1;

        return [
            'jobs' => $this->queue->listJobs($status, $perPage, $offset),
            'totals' => $totals,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $matched,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public function retryJob(int $emailId): void
    {
        if ($emailId <= 0) {
            throw new DomainException('ไม่พบรายการอีเมลที่ต้องการลองใหม่');
        }

        if (!$this->queue->requeueForRetry($emailId)) {
            throw new DomainException('ไม่สามารถลองส่งอีเมลใหม่ได้ (อาจกำลังประมวลผลอยู่หรืออยู่ในคิวอยู่แล้ว)');
        }
    }

    private function enqueueForRecipient(array $recipient, array $email): void
    {
        $this->queue->enqueue([
            'to_email' => (string) ($recipient['email'] ?? ''),
            'to_name' => (string) ($recipient['full_name'] ?? ''),
            'subject' => (string) ($email['subject'] ?? ''),
            'body_html' => (string) ($email['body_html'] ?? ''),
            'body_text' => (string) ($email['body_text'] ?? ''),
            'payload' => $email['payload'] ?? null,
            'max_attempts' => 3,
        ]);
    }
}
