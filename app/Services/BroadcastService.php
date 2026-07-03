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
        $this->assertAdmin($viewer);

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

        $result = $this->notifications->notifySystemAnnouncement(
            $title,
            $message,
            (int) ($viewer['id'] ?? 0),
            $roleFilter !== '' ? $roleFilter : null
        );

        $this->audit->record($viewer, 'broadcast.sent', 'system', null, [
            'title' => $title,
            'role_filter' => $roleFilter !== '' ? $roleFilter : 'all',
            'in_app_count' => $result['in_app_count'],
            'email_count' => $result['email_count'],
        ]);

        return $result;
    }

    public function sendTestEmail(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);

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
            throw new DomainException('ส่งอีเมลทดสอบไม่สำเร็จ: กรุณาตรวจสอบค่า SMTP/MAIL_DRIVER และลองใหม่');
        }

        $this->audit->record($viewer, 'email_test.sent', 'email', null, [
            'to_email' => $email,
            'template' => $template,
            'driver' => (string) config('mail.driver', 'log'),
        ]);
    }

    private function assertAdmin(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
        }
    }
}
