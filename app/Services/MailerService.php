<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class MailerService
{
    /**
     * ชื่อผู้ส่งอีเมล: ถ้าตั้ง MAIL_FROM_NAME ไว้ (ไม่ว่าง) ใช้ค่านั้น — ให้ ops override ได้ เช่นเพื่อจัด DMARC/ชื่อเฉพาะ;
     * ถ้าเว้นว่าง ให้ตามชื่อระบบที่แอดมินตั้งใน Admin (setting app_name) — template-review F2. เป็น single source
     * ที่ทั้ง send() และหน้า diagnostics ของแอดมินใช้ร่วมกัน กันชื่อผู้ส่งเพี้ยนจากค่า template หลัง rebrand.
     */
    public static function resolveFromName(string $configuredFromName, string $appName): string
    {
        $configured = trim($configuredFromName);

        return $configured !== '' ? $configured : trim($appName);
    }

    public function send(array $message): void
    {
        $driver = strtolower((string) config('mail.driver', 'log'));

        if ($driver === 'log') {
            $this->logMessage($message);
            return;
        }

        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';

        if ($driver === 'smtp') {
            $mailer->isSMTP();
            $mailer->Host = (string) config('mail.host', '127.0.0.1');
            $mailer->Port = (int) config('mail.port', 25);
            // กัน worker/test-email ค้างนานถ้า SMTP ปลายทางช้า/ไม่ตอบ (default PHPMailer = 300s)
            $mailer->Timeout = max(5, (int) config('mail.timeout', 15));
            $mailer->SMTPAuth = (string) config('mail.username', '') !== '';
            $mailer->Username = (string) config('mail.username', '');
            $mailer->Password = (string) config('mail.password', '');

            $encryption = strtolower(trim((string) config('mail.encryption', '')));
            if ($encryption !== '') {
                $mailer->SMTPSecure = $encryption;
            }
        } else {
            $mailer->isMail();
        }

        $fromAddress = (string) config('mail.from_address', 'noreply@example.com');
        $fromName = self::resolveFromName(
            (string) config('mail.from_name', ''),
            (string) setting('app_name', config('app.name', 'Repair System'))
        );
        $mailer->setFrom($fromAddress, $fromName);

        $replyToAddress = trim((string) config('mail.reply_to_address', ''));
        if ($replyToAddress !== '') {
            $mailer->addReplyTo($replyToAddress, (string) config('mail.reply_to_name', $fromName));
        }

        $toEmail = trim((string) ($message['to_email'] ?? ''));
        if ($toEmail === '') {
            throw new RuntimeException('Missing recipient email address.');
        }

        $mailer->addAddress($toEmail, (string) ($message['to_name'] ?? ''));
        $mailer->Subject = (string) ($message['subject'] ?? '');
        $mailer->isHTML(true);
        $mailer->Body = (string) ($message['body_html'] ?? '');
        $mailer->AltBody = (string) ($message['body_text'] ?? strip_tags((string) ($message['body_html'] ?? '')));
        $mailer->send();
    }

    private function logMessage(array $message): void
    {
        $directory = rtrim((string) config('mail.log_path', storage_path('mail-logs')), '/');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create mail log directory.');
        }

        $timestamp = date('Ymd-His');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) ($message['subject'] ?? 'mail')));
        $slug = trim((string) $slug, '-');
        $file = $directory . '/' . $timestamp . '-' . ($slug !== '' ? $slug : 'mail') . '.json';

        $payload = [
            'to_email' => (string) ($message['to_email'] ?? ''),
            'to_name' => (string) ($message['to_name'] ?? ''),
            'subject' => (string) ($message['subject'] ?? ''),
            'body_html' => (string) ($message['body_html'] ?? ''),
            'body_text' => (string) ($message['body_text'] ?? ''),
            'payload' => is_string($message['payload'] ?? null)
                ? json_decode((string) $message['payload'], true)
                : ($message['payload'] ?? null),
            'logged_at' => date('c'),
        ];

        $written = file_put_contents($file, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            throw new RuntimeException('Unable to write mail log file.');
        }
    }
}
