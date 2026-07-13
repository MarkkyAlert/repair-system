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
        foreach ($this->users->findActiveUsersByIds($recipientIds) as $recipient) {
            $email = $this->templates->buildSystemAnnouncement($recipient, $title, $message);
            $this->enqueueForRecipient($recipient, $email);
        }
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

        // claimDueEmails handles row locking; this guard keeps the worker out of normal HTTP request paths.
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
            // The attempts value the claim assigned to this job — the terminal updates below compare-and-set on
            // it so a stale worker can't clobber a row another worker has since reclaimed. See markSent().
            $claimAttempt = (int) ($job['attempts'] ?? 0);

            try {
                // RISK MAP: delivery is at-least-once BY DESIGN (accepted tradeoff). send() then markSent() —
                // a crash in between leaves the row 'processing', so it is reclaimed after the timeout and may
                // be sent again. Exactly-once would require provider-side idempotency; a rare duplicate email
                // is acceptable for this system and no data is corrupted.
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
