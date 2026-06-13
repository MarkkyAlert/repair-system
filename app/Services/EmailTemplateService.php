<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\View;

class EmailTemplateService
{
    public function buildTicketEvent(array $context, array $recipient, string $eventType, string $title, string $message): array
    {
        $ticketNo = (string) ($context['ticket_no'] ?? '-');
        $ticketId = (int) ($context['id'] ?? 0);
        $ticketTitle = (string) ($context['title'] ?? '-');
        $ticketUrl = $ticketId > 0 ? url('/tickets/' . $ticketId) : url('/tickets');
        $subject = '[' . (string) setting('app_name', config('app.name', 'Repair System')) . '] ' . $title . ' - ' . $ticketNo;

        return $this->renderNotificationTemplate([
            'subject' => $subject,
            'heading' => $title,
            'intro' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => $ticketUrl,
            'button_label' => 'เปิดดู Ticket',
            'sections' => [
                ['label' => 'Ticket No', 'value' => $ticketNo],
                ['label' => 'หัวข้อ', 'value' => $ticketTitle],
                ['label' => 'เหตุการณ์', 'value' => $this->labelize($eventType)],
                ['label' => 'สถานะล่าสุด', 'value' => $this->labelize((string) ($context['status'] ?? '-'))],
            ],
            'footer_note' => 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติจากระบบแจ้งซ่อม',
            'payload' => [
                'template' => 'ticket_event',
                'event_type' => $eventType,
                'ticket_id' => $ticketId,
                'ticket_no' => $ticketNo,
                'recipient_id' => (int) ($recipient['id'] ?? 0),
            ],
        ]);
    }

    public function buildCommentEvent(array $context, array $recipient, int $commentId, bool $isInternal, string $body, string $action, string $title, string $message): array
    {
        $ticketNo = (string) ($context['ticket_no'] ?? '-');
        $ticketId = (int) ($context['id'] ?? 0);
        $ticketUrl = $ticketId > 0 ? url('/tickets/' . $ticketId) : url('/tickets');
        $preview = trim($body);
        if ($preview !== '') {
            $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 200) : substr($preview, 0, 200);
        }

        return $this->renderNotificationTemplate([
            'subject' => '[' . (string) setting('app_name', config('app.name', 'Repair System')) . '] ' . $title . ' - ' . $ticketNo,
            'heading' => $title,
            'intro' => 'มีความเคลื่อนไหวใหม่ใน comment ของ ticket',
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => $ticketUrl,
            'button_label' => 'เปิดดู Comment',
            'sections' => [
                ['label' => 'Ticket No', 'value' => $ticketNo],
                ['label' => 'หัวข้อ', 'value' => (string) ($context['title'] ?? '-')],
                ['label' => 'Action', 'value' => $this->labelize($action)],
                ['label' => 'Visibility', 'value' => $isInternal ? 'Internal note' : 'Public comment'],
                ['label' => 'Preview', 'value' => $preview !== '' ? $preview : '-'],
            ],
            'footer_note' => $isInternal ? 'อีเมลนี้เกี่ยวข้องกับ internal note ภายในทีม' : 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติจากระบบแจ้งซ่อม',
            'payload' => [
                'template' => 'comment_event',
                'ticket_id' => $ticketId,
                'ticket_no' => $ticketNo,
                'comment_id' => $commentId,
                'action' => $action,
                'is_internal' => $isInternal,
                'recipient_id' => (int) ($recipient['id'] ?? 0),
            ],
        ]);
    }

    public function buildSlaBreached(array $context, array $recipient, string $metricType, string $title, string $message): array
    {
        $ticketNo = (string) ($context['ticket_no'] ?? '-');
        $ticketId = (int) ($context['id'] ?? 0);
        $ticketUrl = $ticketId > 0 ? url('/tickets/' . $ticketId) : url('/tickets');
        $metricLabel = $metricType === 'response' ? 'Response SLA' : 'Resolution SLA';

        return $this->renderNotificationTemplate([
            'subject' => '[' . (string) setting('app_name', config('app.name', 'Repair System')) . '] ' . $title . ' - ' . $ticketNo,
            'heading' => $title,
            'intro' => 'ระบบตรวจพบ ticket ที่เกินกำหนด SLA',
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => $ticketUrl,
            'button_label' => 'ตรวจสอบ Ticket',
            'sections' => [
                ['label' => 'Ticket No', 'value' => $ticketNo],
                ['label' => 'หัวข้อ', 'value' => (string) ($context['title'] ?? '-')],
                ['label' => 'Metric', 'value' => $metricLabel],
                ['label' => 'สถานะล่าสุด', 'value' => $this->labelize((string) ($context['status'] ?? '-'))],
            ],
            'footer_note' => 'กรุณาติดตามรายการนี้โดยเร็วเพื่อไม่ให้กระทบ SLA เพิ่มเติม',
            'payload' => [
                'template' => 'sla_breached',
                'ticket_id' => $ticketId,
                'ticket_no' => $ticketNo,
                'metric_type' => $metricType,
                'recipient_id' => (int) ($recipient['id'] ?? 0),
            ],
        ]);
    }

    public function buildPasswordReset(array $user, string $resetUrl, string $expiresAt): array
    {
        $appName = (string) setting('app_name', config('app.name', 'Repair System'));
        $subject = '[' . $appName . '] ลิงก์ตั้งรหัสผ่านใหม่';
        $expiresAtLabel = $this->formatDateTime($expiresAt);
        $html = View::capture('emails/html/password-reset', [
            'appName' => $appName,
            'recipientName' => (string) ($user['full_name'] ?? $user['email'] ?? 'ผู้ใช้งาน'),
            'resetUrl' => $resetUrl,
            'expiresAt' => $expiresAtLabel,
        ]);
        $text = View::capture('emails/text/password-reset', [
            'appName' => $appName,
            'recipientName' => (string) ($user['full_name'] ?? $user['email'] ?? 'ผู้ใช้งาน'),
            'resetUrl' => $resetUrl,
            'expiresAt' => $expiresAtLabel,
        ]);

        return [
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'payload' => [
                'template' => 'password_reset',
                'recipient_id' => (int) ($user['id'] ?? 0),
                'reset_url' => $resetUrl,
                'expires_at' => $expiresAt,
            ],
        ];
    }

    private function renderNotificationTemplate(array $data): array
    {
        $appName = (string) setting('app_name', config('app.name', 'Repair System'));
        $html = View::capture('emails/html/notification', [
            'appName' => $appName,
            'recipientName' => $data['recipient_name'],
            'heading' => $data['heading'],
            'intro' => $data['intro'],
            'message' => $data['message'],
            'ticketUrl' => $data['ticket_url'],
            'buttonLabel' => $data['button_label'],
            'sections' => $data['sections'],
            'footerNote' => $data['footer_note'],
        ]);
        $text = View::capture('emails/text/notification', [
            'appName' => $appName,
            'recipientName' => $data['recipient_name'],
            'heading' => $data['heading'],
            'intro' => $data['intro'],
            'message' => $data['message'],
            'ticketUrl' => $data['ticket_url'],
            'buttonLabel' => $data['button_label'],
            'sections' => $data['sections'],
            'footerNote' => $data['footer_note'],
        ]);

        return [
            'subject' => (string) $data['subject'],
            'body_html' => $html,
            'body_text' => $text,
            'payload' => $data['payload'],
        ];
    }

    private function labelize(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', strtolower($value)));
        if ($value === '') {
            return '-';
        }

        return ucwords($value);
    }

    private function formatDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('d/m/Y H:i', $timestamp);
    }
}
