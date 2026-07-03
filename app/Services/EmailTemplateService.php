<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Repositories\EmailTemplateRepository;

class EmailTemplateService
{
    public const TEMPLATE_REGISTRY = [
        'ticket_created' => [
            'label' => 'Ticket ใหม่รออนุมัติ',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'ticket_approved' => [
            'label' => 'Ticket ได้รับการอนุมัติ',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'ticket_rejected' => [
            'label' => 'Ticket ถูกปฏิเสธ',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'ticket_assigned' => [
            'label' => 'มีการมอบหมายงานช่าง',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'ticket_status_changed' => [
            'label' => 'สถานะงานเปลี่ยน (started/resolved/completed/reopened/cancelled)',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'ticket_event' => [
            'label' => 'อีเมล ticket event เริ่มต้น (fallback)',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'comment_event' => [
            'label' => 'มี comment ใหม่หรือแก้ไข',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'sla_breached' => [
            'label' => 'SLA เกินกำหนด',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
        'system_announcement' => [
            'label' => 'ประกาศจากผู้ดูแลระบบ (broadcast)',
            'fields' => ['heading', 'intro', 'footer_note'],
        ],
    ];

    private ?array $overridesCache = null;

    public function __construct(private EmailTemplateRepository $templates)
    {
    }

    private function override(string $templateKey, string $fieldKey, string $default): string
    {
        if ($this->overridesCache === null) {
            $this->overridesCache = $this->templates->getAllOverrides();
        }

        $value = $this->overridesCache[$templateKey][$fieldKey] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $default;
    }

    public function refreshOverrides(): void
    {
        $this->overridesCache = null;
    }

    public function buildTicketEvent(array $context, array $recipient, string $eventType, string $title, string $message): array
    {
        $ticketNo = (string) ($context['ticket_no'] ?? '-');
        $ticketId = (int) ($context['id'] ?? 0);
        $ticketTitle = (string) ($context['title'] ?? '-');
        $ticketUrl = $ticketId > 0 ? url('/tickets/' . $ticketId) : url('/tickets');
        $templateKey = $this->ticketEventTemplateKey($eventType);
        $subject = '[' . (string) setting('app_name', config('app.name', 'Repair System')) . '] ' . $title . ' - ' . $ticketNo;

        return $this->renderNotificationTemplate([
            'subject' => $subject,
            'heading' => $this->override($templateKey, 'heading', $title),
            'intro' => $this->override($templateKey, 'intro', 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ'),
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => $ticketUrl,
            'button_label' => 'เปิดดู Ticket',
            'sections' => [
                ['label' => 'Ticket No', 'value' => $ticketNo],
                ['label' => 'หัวข้อ', 'value' => $ticketTitle],
                ['label' => 'เหตุการณ์', 'value' => humanize_label($eventType)],
                ['label' => 'สถานะล่าสุด', 'value' => ticket_status_label_th((string) ($context['status'] ?? '-'))],
            ],
            'footer_note' => $this->override($templateKey, 'footer_note', 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติจากระบบแจ้งซ่อม'),
            'payload' => [
                'template' => $templateKey,
                'event_type' => $eventType,
                'ticket_id' => $ticketId,
                'ticket_no' => $ticketNo,
                'recipient_id' => (int) ($recipient['id'] ?? 0),
            ],
        ]);
    }

    private function ticketEventTemplateKey(string $eventType): string
    {
        return match ($eventType) {
            'ticket.created' => 'ticket_created',
            'ticket.approved' => 'ticket_approved',
            'ticket.rejected' => 'ticket_rejected',
            'ticket.assigned' => 'ticket_assigned',
            'ticket.accepted', 'ticket.started', 'ticket.resolved', 'ticket.completed', 'ticket.reopened', 'ticket.cancelled' => 'ticket_status_changed',
            default => 'ticket_event',
        };
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

        $defaultFooter = $isInternal ? 'อีเมลนี้เกี่ยวข้องกับ internal note ภายในทีม' : 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติจากระบบแจ้งซ่อม';

        return $this->renderNotificationTemplate([
            'subject' => '[' . (string) setting('app_name', config('app.name', 'Repair System')) . '] ' . $title . ' - ' . $ticketNo,
            'heading' => $this->override('comment_event', 'heading', $title),
            'intro' => $this->override('comment_event', 'intro', 'มีความเคลื่อนไหวใหม่ใน comment ของ ticket'),
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => $ticketUrl,
            'button_label' => 'เปิดดู Comment',
            'sections' => [
                ['label' => 'Ticket No', 'value' => $ticketNo],
                ['label' => 'หัวข้อ', 'value' => (string) ($context['title'] ?? '-')],
                ['label' => 'Action', 'value' => humanize_label($action)],
                ['label' => 'Visibility', 'value' => $isInternal ? 'Internal note' : 'Public comment'],
                ['label' => 'Preview', 'value' => $preview !== '' ? $preview : '-'],
            ],
            'footer_note' => $this->override('comment_event', 'footer_note', $defaultFooter),
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
            'heading' => $this->override('sla_breached', 'heading', $title),
            'intro' => $this->override('sla_breached', 'intro', 'ระบบตรวจพบ ticket ที่เกินกำหนด SLA'),
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => $ticketUrl,
            'button_label' => 'ตรวจสอบ Ticket',
            'sections' => [
                ['label' => 'Ticket No', 'value' => $ticketNo],
                ['label' => 'หัวข้อ', 'value' => (string) ($context['title'] ?? '-')],
                ['label' => 'Metric', 'value' => $metricLabel],
                ['label' => 'สถานะล่าสุด', 'value' => ticket_status_label_th((string) ($context['status'] ?? '-'))],
            ],
            'footer_note' => $this->override('sla_breached', 'footer_note', 'กรุณาติดตามรายการนี้โดยเร็วเพื่อไม่ให้กระทบ SLA เพิ่มเติม'),
            'payload' => [
                'template' => 'sla_breached',
                'ticket_id' => $ticketId,
                'ticket_no' => $ticketNo,
                'metric_type' => $metricType,
                'recipient_id' => (int) ($recipient['id'] ?? 0),
            ],
        ]);
    }

    public function buildSystemAnnouncement(array $recipient, string $title, string $message): array
    {
        $appName = (string) setting('app_name', config('app.name', 'Repair System'));
        $subject = '[' . $appName . '] ' . $title;

        return $this->renderNotificationTemplate([
            'subject' => $subject,
            'heading' => $this->override('system_announcement', 'heading', $title),
            'intro' => $this->override('system_announcement', 'intro', 'มีประกาศจากผู้ดูแลระบบ'),
            'message' => $message,
            'recipient_name' => (string) ($recipient['full_name'] ?? $recipient['email'] ?? 'ผู้ใช้งาน'),
            'ticket_url' => url('/dashboard'),
            'button_label' => 'เปิดระบบ',
            'sections' => [],
            'footer_note' => $this->override('system_announcement', 'footer_note', 'อีเมลฉบับนี้ถูกส่งโดยผู้ดูแลระบบ'),
            'payload' => [
                'template' => 'system_announcement',
                'recipient_id' => (int) ($recipient['id'] ?? 0),
            ],
        ]);
    }

    public function buildPasswordReset(array $user, string $resetUrl, string $expiresAt): array
    {
        $appName = (string) setting('app_name', config('app.name', 'Repair System'));
        $logoUrl = branding_logo_url();
        $subject = '[' . $appName . '] ลิงก์ตั้งรหัสผ่านใหม่';
        $expiresAtLabel = $this->formatDateTime($expiresAt);
        $html = View::capture('emails/html/password-reset', [
            'appName' => $appName,
            'logoUrl' => $logoUrl,
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

    /** Preview/test-send sample of the password-reset email using the current admin as recipient. */
    public function buildSamplePasswordReset(array $viewer): array
    {
        return $this->buildPasswordReset(
            [
                'id' => (int) ($viewer['id'] ?? 0),
                'full_name' => (string) ($viewer['full_name'] ?? 'ผู้ดูแลระบบ'),
                'email' => (string) ($viewer['email'] ?? 'admin@example.com'),
            ],
            url('/reset-password?email=admin%40example.com&token=preview-token'),
            date('Y-m-d H:i:s', time() + 3600)
        );
    }

    /** Preview/test-send sample of a ticket-event email using the current admin as recipient. */
    public function buildSampleTicketEvent(array $viewer): array
    {
        return $this->buildTicketEvent(
            [
                'id' => 1,
                'ticket_no' => (string) setting('ticket_prefix', 'MT') . '-PREVIEW-0001',
                'title' => 'ตัวอย่างงานซ่อมจากระบบ',
                'status' => 'approved',
            ],
            [
                'id' => (int) ($viewer['id'] ?? 0),
                'full_name' => (string) ($viewer['full_name'] ?? 'ผู้ดูแลระบบ'),
                'email' => (string) ($viewer['email'] ?? 'admin@example.com'),
            ],
            'ticket.approved',
            'มี Ticket ที่อนุมัติแล้ว',
            'Ticket ตัวอย่างถูกอนุมัติและพร้อมมอบหมายช่าง'
        );
    }

    private function renderNotificationTemplate(array $data): array
    {
        $appName = (string) setting('app_name', config('app.name', 'Repair System'));
        $logoUrl = branding_logo_url();
        $html = View::capture('emails/html/notification', [
            'appName' => $appName,
            'logoUrl' => $logoUrl,
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


    private function formatDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('d/m/Y H:i', $timestamp);
    }
}
