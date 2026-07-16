<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use Throwable;

/**
 * Admin communications: system-wide broadcast announcements and SMTP test email.
 * Extracted from AdminService to keep messaging separate from settings/entity CRUD.
 */
class BroadcastService
{
    public function __construct(
        private NotificationService $notifications,
        private MailerService $mailer,
        private EmailTemplateService $emailTemplates,
        private AuditLogger $audit,
    ) {
    }

    public function sendBroadcast(array $viewer, array $input): array
    {
        assert_admin($viewer);

        $title = trim((string) ($input['title'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $roleFilter = trim((string) ($input['role_filter'] ?? ''));

        if ($title === '' || $message === '') {
            throw new DomainException('กรุณากรอกหัวข้อและข้อความ');
        }
        if (mb_strlen($title) > 200) {
            throw new DomainException('หัวข้อยาวเกินกำหนด (สูงสุด 200 ตัวอักษร)');
        }
        if (mb_strlen($message) > 2000) {
            throw new DomainException('ข้อความยาวเกินกำหนด (สูงสุด 2,000 ตัวอักษร)');
        }
        if ($roleFilter !== '' && !in_array($roleFilter, valid_roles(), true)) {
            throw new DomainException('Role filter ไม่ถูกต้อง');
        }

        // One-time idempotency token from the form — a retry / second tab replays it and is deduped so the
        // org isn't broadcast to twice. (safety-review R8-F2)
        $submissionToken = strtolower(trim((string) ($input['submission_token'] ?? '')));
        if (!is_submission_token($submissionToken)) {
            throw new DomainException('แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้าแล้วส่งใหม่');
        }

        $result = $this->notifications->notifySystemAnnouncement(
            $title,
            $message,
            (int) ($viewer['id'] ?? 0),
            $roleFilter !== '' ? $roleFilter : null,
            $submissionToken
        );

        // Audit the ACTUAL outcome — the trail must not say "sent" when a channel failed (it would contradict
        // what the controller shows the admin). Record + carry the failure flags. (error-review-3 O4)
        $inAppFailed = !empty($result['in_app_failed']);
        $emailFailed = !empty($result['email_failed']);
        $action = 'broadcast.sent';
        if (!empty($result['duplicate'])) {
            $action = 'broadcast.duplicate';
        } elseif ($inAppFailed && $emailFailed) {
            $action = 'broadcast.failed';
        } elseif ($inAppFailed || $emailFailed) {
            $action = 'broadcast.partial';
        }

        $this->audit->record($viewer, $action, 'system', null, [
            'title' => $title,
            'role_filter' => $roleFilter !== '' ? $roleFilter : 'all',
            'in_app_count' => $result['in_app_count'] ?? 0,
            'email_count' => $result['email_count'] ?? 0,
            'in_app_failed' => $inAppFailed,
            'email_failed' => $emailFailed,
        ]);

        return $result;
    }

    public function sendTestEmail(array $viewer, array $input): void
    {
        assert_admin($viewer);

        $email = strtolower(trim((string) ($input['to_email'] ?? '')));
        $template = trim((string) ($input['template'] ?? 'password_reset'));

        if (!is_valid_email($email)) {
            throw new DomainException('กรุณาระบุอีเมลปลายทางให้ถูกต้อง');
        }

        if (!in_array($template, ['password_reset', 'notification'], true)) {
            throw new DomainException('Template อีเมลไม่ถูกต้อง');
        }

        $message = $template === 'password_reset'
            ? $this->emailTemplates->buildSamplePasswordReset($viewer)
            : $this->emailTemplates->buildSampleTicketEvent($viewer);
        $message['to_email'] = $email;
        $message['to_name'] = (string) ($viewer['full_name'] ?? 'Admin');

        try {
            $this->mailer->send($message);
        } catch (Throwable $exception) {
            // the admin sees a generic message; the real cause (SMTP refused, bad driver, timeout) must be logged
            // with the mail settings so the diagnostic is actionable — it was discarded before. (error-review F7)
            log_caught_exception('email.test.failed', $exception, [
                'driver' => (string) config('mail.driver', 'log'),
                'host' => (string) config('mail.host', ''),
                'port' => (string) config('mail.port', ''),
            ]);
            throw new DomainException('ส่งอีเมลทดสอบไม่สำเร็จ: กรุณาตรวจสอบค่า SMTP/MAIL_DRIVER และลองใหม่');
        }

        // In production the audit trail must not retain the raw recipient address — mask it (the template +
        // driver are enough to confirm what was sent). Dev keeps the full address for debugging. (error-review-4 F5)
        $auditEmail = (string) config('app.env', 'production') === 'production'
            ? MailerService::maskEmail($email)
            : $email;
        $this->audit->record($viewer, 'email_test.sent', 'email', null, [
            'to_email' => $auditEmail,
            'template' => $template,
            'driver' => (string) config('mail.driver', 'log'),
        ]);
    }
}
