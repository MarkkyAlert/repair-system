<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use Throwable;

/**
 * การสื่อสารของ admin: การประกาศ broadcast ทั่วทั้งระบบ และการส่งอีเมลทดสอบ SMTP.
 * แยกออกมาจาก AdminService เพื่อให้ส่วนส่งข้อความแยกจาก CRUD ของ settings/entity.
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

        // idempotency token แบบใช้ครั้งเดียวจากฟอร์ม — การลองใหม่ / แท็บที่สอง จะส่งค่านี้ซ้ำแล้วถูกกรองซ้ำออก เพื่อไม่ให้
        // ทั้งองค์กรได้รับ broadcast ซ้ำสองครั้ง.
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

        // บันทึก audit ตามผลลัพธ์จริง — ร่องรอยต้องไม่บอกว่า "sent" ทั้งที่ช่องทางหนึ่งล้มเหลว (จะขัดกับ
        // สิ่งที่ controller แสดงให้ admin เห็น). บันทึก + ส่งต่อ flag ของความล้มเหลวไปด้วย.
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
            // admin เห็นข้อความกลาง ๆ; แต่ต้นเหตุจริง (SMTP ปฏิเสธ, driver ผิด, timeout) ต้องถูก log
            // พร้อมค่า mail settings เพื่อให้ข้อมูลวินิจฉัยนำไปแก้ได้ — เดิมมันถูกทิ้งไป.
            log_caught_exception('email.test.failed', $exception, [
                'driver' => (string) config('mail.driver', 'log'),
                'host' => (string) config('mail.host', ''),
                'port' => (string) config('mail.port', ''),
            ]);
            throw new DomainException('ส่งอีเมลทดสอบไม่สำเร็จ: กรุณาตรวจสอบค่า SMTP/MAIL_DRIVER และลองใหม่');
        }

        // บน production ร่องรอย audit ต้องไม่เก็บ address ผู้รับแบบดิบ — ปิดบัง (mask) มันซะ (template +
        // driver ก็พอยืนยันได้แล้วว่าส่งอะไรไป). Dev เก็บ address เต็มไว้เพื่อ debug.
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
