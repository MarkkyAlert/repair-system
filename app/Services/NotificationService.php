<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationRepository;
use App\Repositories\TicketRepository;
use DomainException;
use Throwable;

class NotificationService
{
    public function __construct(
        private NotificationRepository $notifications,
        private TicketRepository $tickets,
        private EmailQueueService $emails,
    ) {
    }

    public function getBellData(array $viewer): array
    {
        $userId = (int) ($viewer['id'] ?? 0);
        if ($userId <= 0) {
            return [
                'unreadCount' => 0,
                'items' => [],
            ];
        }

        return [
            'unreadCount' => $this->notifications->countUnreadNotifications($userId),
            'items' => $this->mapNotifications($this->notifications->getUserNotifications($userId, 5), $viewer),
        ];
    }

    public function getNotificationPageData(array $viewer, array $filters = []): array
    {
        $userId = $this->requireViewerId($viewer);
        $items = $this->mapNotifications($this->notifications->getUserNotifications($userId, 50), $viewer);
        $selectedFilter = $this->normalizeFilter((string) ($filters['filter'] ?? 'all'));
        $filteredItems = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->matchesFilter($item, $selectedFilter)
        ));

        return [
            'unreadCount' => $this->notifications->countUnreadNotifications($userId),
            'notifications' => $filteredItems,
            'groups' => $this->groupNotifications($filteredItems),
            'selectedFilter' => $selectedFilter,
            'filterOptions' => $this->buildFilterOptions($items, $selectedFilter),
        ];
    }

    public function getFeedData(array $viewer): array
    {
        return $this->getBellData($viewer);
    }

    public function markAsRead(int $notificationId, array $viewer): void
    {
        $this->notifications->markAsRead($this->requireViewerId($viewer), $notificationId);
    }

    public function markAllAsRead(array $viewer): void
    {
        $this->notifications->markAllAsRead($this->requireViewerId($viewer));
    }

    public function notifyTicketEvent(int $ticketId, string $eventType, int $actorId): void
    {
        $context = $this->tickets->findTicketNotificationContextById($ticketId);
        if ($context === null) {
            return;
        }

        [$title, $message, $recipients] = match ($eventType) {
            'ticket.created' => [
                'มี ticket ใหม่รออนุมัติ',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกสร้างใหม่และรอการอนุมัติ',
                [(int) ($context['assigned_manager_id'] ?? 0)],
            ],
            'ticket.approved' => [
                'Ticket ได้รับการอนุมัติ',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ได้รับการอนุมัติแล้ว และรอมอบหมายช่าง',
                [(int) ($context['requester_id'] ?? 0)],
            ],
            'ticket.rejected' => [
                'Ticket ถูกปฏิเสธ',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกปฏิเสธโดยผู้อนุมัติ',
                [(int) ($context['requester_id'] ?? 0)],
            ],
            'ticket.assigned' => [
                'มีการมอบหมายงานใหม่',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกมอบหมายให้ช่างเข้าดำเนินการแล้ว',
                [(int) ($context['assigned_technician_id'] ?? 0), (int) ($context['requester_id'] ?? 0)],
            ],
            'ticket.accepted' => [
                'ช่างรับงานแล้ว',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกช่างรับงานเรียบร้อยแล้ว',
                [(int) ($context['requester_id'] ?? 0), (int) ($context['assigned_manager_id'] ?? 0)],
            ],
            'ticket.started' => [
                'เริ่มดำเนินงานแล้ว',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' อยู่ระหว่างดำเนินงาน',
                [(int) ($context['requester_id'] ?? 0), (int) ($context['assigned_manager_id'] ?? 0)],
            ],
            'ticket.resolved' => [
                'งานถูกสรุปเป็น Resolved',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกสรุปผลการซ่อมแล้ว รอผู้แจ้งยืนยัน',
                [(int) ($context['requester_id'] ?? 0), (int) ($context['assigned_manager_id'] ?? 0)],
            ],
            'ticket.completed' => [
                'งานถูกยืนยันปิดแล้ว',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกผู้แจ้งยืนยันปิดงานเรียบร้อยแล้ว',
                [(int) ($context['assigned_technician_id'] ?? 0), (int) ($context['assigned_manager_id'] ?? 0)],
            ],
            default => ['', '', []],
        };

        if ($title === '') {
            return;
        }

        $recipientIds = $this->filterRecipientIds($recipients, $actorId);

        $this->dispatchNotification([
            'type' => $eventType,
            'title' => $title,
            'message' => $message,
            'payload' => json_encode([
                'ticket_id' => (int) ($context['id'] ?? $ticketId),
                'ticket_no' => (string) ($context['ticket_no'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'related_type' => 'ticket',
            'related_id' => $ticketId,
        ], $recipientIds);

        try {
            $this->emails->queueTicketEventEmails($context, $recipientIds, $eventType, $title, $message);
        } catch (Throwable) {
        }
    }

    public function notifyCommentEvent(int $ticketId, int $commentId, int $actorId, bool $isInternal, string $body, string $action): void
    {
        $context = $this->tickets->findTicketNotificationContextById($ticketId);
        if ($context === null) {
            return;
        }

        $recipients = $isInternal
            ? [(int) ($context['assigned_manager_id'] ?? 0), (int) ($context['assigned_technician_id'] ?? 0)]
            : [(int) ($context['requester_id'] ?? 0), (int) ($context['assigned_manager_id'] ?? 0), (int) ($context['assigned_technician_id'] ?? 0)];

        $preview = trim($body);
        if ($preview !== '') {
            $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 120) : substr($preview, 0, 120);
        }

        $title = match ($action) {
            'updated' => $isInternal ? 'มีการอัปเดต internal note' : 'มีการอัปเดต comment',
            'deleted' => $isInternal ? 'internal note ถูกลบ' : 'comment ถูกลบ',
            default => $isInternal ? 'มี internal note ใหม่' : 'มี comment ใหม่ใน ticket',
        };

        $message = 'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' มีการ' . match ($action) {
            'updated' => 'แก้ไข comment',
            'deleted' => 'ลบ comment',
            default => 'เพิ่ม comment ใหม่',
        };

        if ($preview !== '' && $action !== 'deleted') {
            $message .= ' · ' . $preview;
        }

        $recipientIds = $this->filterRecipientIds($recipients, $actorId);

        $this->dispatchNotification([
            'type' => 'ticket.comment.' . $action,
            'title' => $title,
            'message' => $message,
            'payload' => json_encode([
                'ticket_id' => (int) ($context['id'] ?? $ticketId),
                'ticket_no' => (string) ($context['ticket_no'] ?? ''),
                'comment_id' => $commentId,
                'is_internal' => $isInternal,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'related_type' => 'ticket',
            'related_id' => $ticketId,
        ], $recipientIds);

        try {
            $this->emails->queueCommentEventEmails($context, $recipientIds, $commentId, $isInternal, $body, $action, $title, $message);
        } catch (Throwable) {
        }
    }

    public function notifySlaBreached(int $ticketId, string $metricType): void
    {
        $context = $this->tickets->findTicketNotificationContextById($ticketId);
        if ($context === null) {
            return;
        }

        $metricLabel = $metricType === 'response' ? 'Response SLA' : 'Resolution SLA';

        $recipientIds = $this->filterRecipientIds([
            (int) ($context['requester_id'] ?? 0),
            (int) ($context['assigned_manager_id'] ?? 0),
            (int) ($context['assigned_technician_id'] ?? 0),
        ], 0);

        $title = $metricLabel . ' เกินกำหนด';
        $message = 'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' มีสถานะเกินกำหนดตาม ' . $metricLabel;

        $this->dispatchNotification([
            'type' => 'ticket.sla_breached.' . $metricType,
            'title' => $title,
            'message' => $message,
            'payload' => json_encode([
                'ticket_id' => (int) ($context['id'] ?? $ticketId),
                'ticket_no' => (string) ($context['ticket_no'] ?? ''),
                'metric_type' => $metricType,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'related_type' => 'ticket',
            'related_id' => $ticketId,
        ], $recipientIds);

        try {
            $this->emails->queueSlaBreachedEmails($context, $recipientIds, $metricType, $title, $message);
        } catch (Throwable) {
        }
    }

    private function dispatchNotification(array $payload, array $recipientIds): void
    {
        if ($recipientIds === []) {
            return;
        }

        try {
            $this->notifications->createNotification($payload, $recipientIds);
        } catch (Throwable) {
        }
    }

    private function filterRecipientIds(array $recipientIds, int $actorId): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $recipientIds),
            static fn (int $id): bool => $id > 0 && $id !== $actorId
        )));
    }

    private function mapNotifications(array $notifications, array $viewer): array
    {
        $ticketIds = array_map(
            static fn (array $item): int => (string) ($item['related_type'] ?? '') === 'ticket' ? (int) ($item['related_id'] ?? 0) : 0,
            $notifications
        );
        $contexts = $this->notifications->getTicketContextsByIds($ticketIds);

        return array_map(
            fn (array $item): array => $this->mapNotification($item, $viewer, $contexts[(int) ($item['related_id'] ?? 0)] ?? []),
            $notifications
        );
    }

    private function mapNotification(array $notification, array $viewer, array $ticketContext): array
    {
        $payload = json_decode((string) ($notification['payload'] ?? ''), true);
        $relatedType = (string) ($notification['related_type'] ?? '');
        $relatedId = (int) ($notification['related_id'] ?? 0);
        $type = (string) ($notification['type'] ?? 'notification');
        $createdAt = (string) ($notification['created_at'] ?? '');
        $linkUrl = url('/notifications');

        if ($relatedType === 'ticket' && $relatedId > 0) {
            $linkUrl = url('/tickets/' . $relatedId);
        }

        return [
            'id' => (int) ($notification['id'] ?? 0),
            'type' => $type,
            'title' => (string) ($notification['title'] ?? ''),
            'message' => (string) ($notification['message'] ?? ''),
            'is_read' => (bool) ($notification['is_read'] ?? false),
            'created_at' => $this->formatDateTime($createdAt),
            'relative_time' => $this->relativeTime($createdAt),
            'group_key' => $this->dateGroupKey($createdAt),
            'read_at' => $this->formatDateTime($notification['read_at'] ?? null),
            'link_url' => $linkUrl,
            'payload' => is_array($payload) ? $payload : [],
        ] + $this->notificationPresentation($type, $viewer, $ticketContext);
    }

    private function notificationPresentation(string $type, array $viewer, array $ticket): array
    {
        if (str_contains($type, '.sla_breached.')) {
            return ['category' => 'sla', 'category_label' => 'SLA', 'tone' => 'danger', 'icon' => 'triangle-alert', 'action_label' => 'ตรวจสอบ SLA'];
        }

        if (str_contains($type, '.comment.')) {
            return ['category' => 'comment', 'category_label' => 'บทสนทนา', 'tone' => 'info', 'icon' => 'message-circle', 'action_label' => 'ดูบทสนทนา'];
        }

        $role = (string) ($viewer['role'] ?? 'guest');
        $status = (string) ($ticket['status'] ?? '');

        if (in_array($role, ['manager', 'admin'], true) && $status === 'pending_approval') {
            return ['category' => 'action', 'category_label' => 'รออนุมัติ', 'tone' => 'warning', 'icon' => 'clipboard-list', 'action_label' => 'ตรวจสอบและอนุมัติ'];
        }
        if (in_array($role, ['manager', 'admin'], true) && $status === 'approved') {
            return ['category' => 'action', 'category_label' => 'รอมอบหมาย', 'tone' => 'warning', 'icon' => 'wrench', 'action_label' => 'มอบหมายช่าง'];
        }
        if ($role === 'technician' && $status === 'assigned') {
            return ['category' => 'action', 'category_label' => 'งานใหม่', 'tone' => 'info', 'icon' => 'wrench', 'action_label' => 'รับงาน'];
        }
        if ($role === 'requester' && $status === 'resolved') {
            return ['category' => 'action', 'category_label' => 'รอตรวจรับ', 'tone' => 'success', 'icon' => 'check-circle', 'action_label' => 'ตรวจรับงาน'];
        }

        return match ($type) {
            'ticket.rejected' => ['category' => 'workflow', 'category_label' => 'Workflow', 'tone' => 'danger', 'icon' => 'triangle-alert', 'action_label' => 'ดูเหตุผล'],
            default => ['category' => 'workflow', 'category_label' => 'Workflow', 'tone' => 'default', 'icon' => 'activity', 'action_label' => 'เปิด Ticket'],
        };
    }

    private function normalizeFilter(string $filter): string
    {
        return in_array($filter, ['all', 'unread', 'action', 'sla', 'comment'], true) ? $filter : 'all';
    }

    private function matchesFilter(array $item, string $filter): bool
    {
        return match ($filter) {
            'unread' => empty($item['is_read']),
            'action' => (string) ($item['category'] ?? '') === 'action',
            'sla' => (string) ($item['category'] ?? '') === 'sla',
            'comment' => (string) ($item['category'] ?? '') === 'comment',
            default => true,
        };
    }

    private function buildFilterOptions(array $items, string $selectedFilter): array
    {
        $definitions = [
            'all' => 'ทั้งหมด',
            'unread' => 'ยังไม่อ่าน',
            'action' => 'ต้องดำเนินการ',
            'sla' => 'SLA',
            'comment' => 'บทสนทนา',
        ];

        return array_map(fn (string $key, string $label): array => [
            'key' => $key,
            'label' => $label,
            'count' => count(array_filter($items, fn (array $item): bool => $this->matchesFilter($item, $key))),
            'is_active' => $key === $selectedFilter,
            'url' => url('/notifications' . ($key === 'all' ? '' : '?filter=' . $key)),
        ], array_keys($definitions), array_values($definitions));
    }

    private function groupNotifications(array $items): array
    {
        $groups = [];
        $labels = ['today' => 'วันนี้', 'yesterday' => 'เมื่อวาน', 'earlier' => 'ก่อนหน้านี้'];

        foreach ($items as $item) {
            $key = (string) ($item['group_key'] ?? 'earlier');
            $groups[$key] ??= ['key' => $key, 'label' => $labels[$key] ?? 'ก่อนหน้านี้', 'items' => []];
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    private function dateGroupKey(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return 'earlier';
        }

        $date = date('Y-m-d', $timestamp);
        if ($date === date('Y-m-d')) {
            return 'today';
        }

        return $date === date('Y-m-d', strtotime('-1 day')) ? 'yesterday' : 'earlier';
    }

    private function relativeTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $this->formatDateTime($value);
        }

        $seconds = max(0, time() - $timestamp);
        if ($seconds < 60) {
            return 'เมื่อสักครู่';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' นาทีที่แล้ว';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . ' ชั่วโมงที่แล้ว';
        }
        if ($seconds < 172800) {
            return 'เมื่อวาน';
        }

        return $this->formatDateTime($value);
    }

    private function requireViewerId(array $viewer): int
    {
        $userId = (int) ($viewer['id'] ?? 0);
        if ($userId <= 0) {
            throw new DomainException('กรุณาเข้าสู่ระบบก่อนใช้งาน notifications');
        }

        return $userId;
    }

    private function formatDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date('d/m/Y H:i', $timestamp);
    }
}
