<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class MailerService
{
    /**
     * ชื่อผู้ส่งอีเมล: ถ้าตั้ง MAIL_FROM_NAME ไว้ (ไม่ว่าง) ใช้ค่านั้น — ให้ ops override ได้ เช่นเพื่อจัด DMARC หรือชื่อเฉพาะ;
     * ถ้าเว้นว่าง ให้ตามชื่อระบบที่แอดมินตั้งใน Admin (setting app_name) เป็น single source
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

        // รองรับแค่ 'log' กับ 'smtp'. ค่าอื่นให้ปฏิเสธแบบดัง ๆ ไม่เงียบ ๆ ตกไปใช้ transport
        // mail() เริ่มต้นของ PHPMailer — เพราะถ้าพิมพ์ MAIL_DRIVER ผิด มันจะส่ง (หรือทิ้ง) เมลผ่าน
        // sendmail ที่ยังไม่ได้ตั้งค่า โดยไม่มีข้อมูลวินิจฉัยอะไรเลย.
        if (!in_array($driver, ['log', 'smtp'], true)) {
            throw new RuntimeException(sprintf('MAIL_DRIVER "%s" ไม่รองรับ — ตั้งค่าได้เฉพาะ log หรือ smtp', $driver));
        }

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
        // ต่อท้ายด้วยค่าสุ่ม เมล 2 ฉบับในวินาทีเดียวกันที่หัวข้อเหมือนกันจะได้ไฟล์ของตัวเองแยกกัน —
        // เพราะชื่อที่ใช้แค่ timestamp+slug จะเขียนทับฉบับแรกแบบเงียบ ๆ ข้อมูลหาย.
        $file = $directory . '/' . $timestamp . '-' . ($slug !== '' ? $slug : 'mail') . '-' . bin2hex(random_bytes(4)) . '.json';

        // บน production log driver ต้องไม่เก็บ PII (ข้อมูลระบุตัวตน): mask ผู้รับ และตัด body/payload ทิ้ง
        // เก็บไว้แค่หัวข้อของ template + เวลา พอยืนยันว่ามีการสร้างเมลขึ้น. บน dev/local
        // เก็บเนื้อหาเต็มไว้เพื่อ debug. เป็นการตัดสินใจของเจ้าของระบบ.
        if ((string) config('app.env', 'production') === 'production') {
            $payload = [
                'to_email' => self::maskEmail((string) ($message['to_email'] ?? '')),
                'subject' => (string) ($message['subject'] ?? ''),
                'body' => '[omitted in production]',
                'logged_at' => date('c'),
            ];
        } else {
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
        }

        // log driver เก็บเมลไว้บนดิสก์แบบไม่มีกำหนด; มันต้องไม่เก็บ password-reset token ที่ยังใช้ได้จริงเป็น
        // plaintext. เลยลบ (redact) reset token ทั้งแบบ path และ query ออกจาก record ที่ serialize แล้ว.
        $json = self::redactSecrets((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $written = file_put_contents($file, $json);
        if ($written === false) {
            throw new RuntimeException('Unable to write mail log file.');
        }
    }

    /**
     * ลบ password-reset token ออกจากข้อความที่กำลังจะถูก log: token เป็น segment หนึ่งของ path
     * (/reset-password/<token>?email=...) และในบาง flow ก็เป็น query parameter token= ด้วย.
     */
    /** ปิดบัง (mask) email สำหรับ log บน production: เก็บอักษรตัวแรกของ local + โดเมนไว้ ซ่อนที่เหลือ (a***@example.com). */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** ปิดบัง (mask) เบอร์โทรสำหรับ audit/log บน production: เก็บแค่ 4 หลักสุดท้าย (***5678); เบอร์ที่สั้นเกินไปกลายเป็น '***'. */
    public static function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }

        return '***' . substr($digits, -4);
    }

    public static function redactSecrets(string $text): string
    {
        $text = preg_replace('#(/reset-password/)[^/?"\'\s\\\\]+#', '${1}[REDACTED]', $text) ?? $text;
        $text = preg_replace('#([?&](?:token|reset_token)=)[^&"\'\s\\\\]+#i', '${1}[REDACTED]', $text) ?? $text;

        return $text;
    }
}
