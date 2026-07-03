<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationPreferenceRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UserRepository;
use DomainException;
use Throwable;

class NotificationService
{
    public const NOTIFICATION_TYPES = [
        'ticket_approved' => 'Ticket ได้รับการอนุมัติ / รออนุมัติ',
        'ticket_rejected' => 'Ticket ถูกปฏิเสธ',
        'ticket_status_changed' => 'สถานะ Ticket เปลี่ยน',
        'comment_added' => 'มี comment ใหม่ใน Ticket',
        'sla_breached' => 'SLA เกินกำหนด',
        'system_announcement' => 'ประกาศจากผู้ดูแลระบบ',
    ];

    public const NOTIFICATION_TYPE_HINTS = [
        'ticket_status_changed' => 'assigned · started · resolved · completed · reopened · cancelled',
    ];

    public const NOTIFICATION_TYPE_OFF_IMPACT = [
        'ticket_approved' => 'ปิดแล้วจะไม่ทราบทันทีว่า ticket ของคุณได้รับการอนุมัติหรือไม่',
        'ticket_rejected' => 'ปิดแล้วจะไม่ทราบเมื่อ ticket ถูกปฏิเสธ — อาจตกหล่นการแก้ไข',
        'ticket_status_changed' => 'ปิดแล้วจะไม่ทราบเมื่อช่างเริ่มงาน / สรุปงาน / ปิดงาน',
        'comment_added' => 'ปิดแล้วจะไม่ทราบเมื่อมีคนตอบกลับใน ticket ของคุณ',
        'sla_breached' => 'แนะนำให้เปิดไว้ — ปิดแล้วจะไม่ทราบเมื่องานเกินกำหนด SLA',
        'system_announcement' => 'ปิดแล้วจะไม่ได้รับประกาศจากผู้ดูแล เช่น maintenance / นโยบายใหม่',
    ];

    public function __construct(
        private NotificationRepository $notifications,
        private TicketRepository $tickets,
        private EmailQueueService $emails,
        private NotificationPreferenceRepository $preferences,
        private UserRepository $users,
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

        $items = $this->aggregateNotifications(
            $this->mapNotifications($this->notifications->getUserNotifications($userId, 30), $viewer)
        );

        return [
            'unreadCount' => $this->notifications->countUnreadNotifications($userId),
            'actionCount' => $this->countActionItems($items),
            'items' => array_slice($this->sortNotifications($items), 0, 5),
        ];
    }

    public function getNotificationPageData(array $viewer, array $filters = []): array
    {
        $userId = $this->requireViewerId($viewer);
        $result = $this->notifications->getUserNotificationsPage($userId, max(1, (int) ($filters['page'] ?? 1)), 25);
        $eventItems = $this->mapNotifications($result['items'], $viewer);
        $items = $this->aggregateNotifications($eventItems);
        $selectedFilter = $this->normalizeFilter((string) ($filters['filter'] ?? 'all'));
        $filteredItems = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->matchesFilter($item, $selectedFilter)
        ));

        return [
            'unreadCount' => $this->notifications->countUnreadNotifications($userId),
            'unreadThreadCount' => count(array_filter($items, static fn (array $item): bool => empty($item['is_read']))),
            'actionCount' => $this->countActionItems($items),
            'threadCount' => count($items),
            'notifications' => $filteredItems,
            'groups' => $this->groupNotifications($filteredItems),
            'selectedFilter' => $selectedFilter,
            'filterOptions' => $this->buildFilterOptions($items, $selectedFilter),
            'pagination' => $result,
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

    public function markTicketAsRead(int $ticketId, array $viewer): void
    {
        $this->notifications->markTicketNotificationsAsRead($this->requireViewerId($viewer), $ticketId);
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
                $this->tickets->findActiveApproverIds(),
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
            'ticket.reopened' => [
                'มีการส่งงานกลับไปแก้ไขซ้ำ',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกผู้แจ้งส่งกลับไปดำเนินการซ้ำแล้ว',
                [(int) ($context['assigned_technician_id'] ?? 0), (int) ($context['assigned_manager_id'] ?? 0)],
            ],
            'ticket.cancelled' => [
                'Ticket ถูกยกเลิก',
                'Ticket ' . (string) ($context['ticket_no'] ?? '-') . ' ถูกยกเลิกโดยผู้แจ้ง',
                (int) ($context['assigned_manager_id'] ?? 0) > 0
                    ? [(int) ($context['assigned_manager_id'] ?? 0)]
                    : $this->tickets->findActiveApproverIds(),
            ],
            default => ['', '', []],
        };

        if ($title === '') {
            return;
        }

        $recipientIds = $this->filterRecipientIds($recipients, $actorId);
        $notificationType = $this->notificationTypeFor($eventType);
        $inAppRecipients = $this->filterByPreference($recipientIds, $notificationType, 'in_app');
        $emailRecipients = $this->filterByPreference($recipientIds, $notificationType, 'email');

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
        ], $inAppRecipients);

        try {
            $this->emails->queueTicketEventEmails($context, $emailRecipients, $eventType, $title, $message);
        } catch (Throwable $exception) {
            error_log('[notify.email.ticket] ' . $eventType . ' ticket ' . $ticketId . ': ' . $exception->getMessage());
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
        $inAppRecipients = $this->filterByPreference($recipientIds, 'comment_added', 'in_app');
        $emailRecipients = $this->filterByPreference($recipientIds, 'comment_added', 'email');

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
        ], $inAppRecipients);

        try {
            $this->emails->queueCommentEventEmails($context, $emailRecipients, $commentId, $isInternal, $body, $action, $title, $message);
        } catch (Throwable $exception) {
            error_log('[notify.email.comment] ' . $action . ' comment ' . $commentId . ' ticket ' . $ticketId . ': ' . $exception->getMessage());
        }
    }

    public function notifySystemAnnouncement(string $title, string $message, int $actorId, ?string $roleFilter = null): array
    {
        $recipientIds = $this->users->findActiveUserIds($roleFilter);
        $recipientIds = $this->filterRecipientIds($recipientIds, $actorId);

        if ($recipientIds === []) {
            return ['in_app_count' => 0, 'email_count' => 0];
        }

        $inAppRecipients = $this->filterByPreference($recipientIds, 'system_announcement', 'in_app');
        $emailRecipients = $this->filterByPreference($recipientIds, 'system_announcement', 'email');

        $this->dispatchNotification([
            'type' => 'system.announcement',
            'title' => $title,
            'message' => $message,
            'payload' => json_encode([
                'announcement' => true,
                'role_filter' => $roleFilter,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'related_type' => 'system',
            'related_id' => null,
        ], $inAppRecipients);

        try {
            $this->emails->queueSystemAnnouncementEmails($emailRecipients, $title, $message);
        } catch (Throwable $exception) {
            error_log('[notify.email.broadcast] ' . $exception->getMessage());
        }

        return [
            'in_app_count' => count($inAppRecipients),
            'email_count' => count($emailRecipients),
        ];
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
        $inAppRecipients = $this->filterByPreference($recipientIds, 'sla_breached', 'in_app');
        $emailRecipients = $this->filterByPreference($recipientIds, 'sla_breached', 'email');

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
        ], $inAppRecipients);

        try {
            $this->emails->queueSlaBreachedEmails($context, $emailRecipients, $metricType, $title, $message);
        } catch (Throwable $exception) {
            error_log('[notify.email.sla] ' . $metricType . ' ticket ' . $ticketId . ': ' . $exception->getMessage());
        }
    }

    private function dispatchNotification(array $payload, array $recipientIds): void
    {
        if ($recipientIds === []) {
            return;
        }

        try {
            $this->notifications->createNotification($payload, $recipientIds);
        } catch (Throwable $exception) {
            error_log('[notify.dispatch] type=' . (string) ($payload['type'] ?? '?') . ' related=' . (string) ($payload['related_type'] ?? '?') . ':' . (string) ($payload['related_id'] ?? '?') . ' err=' . $exception->getMessage());
        }
    }

    private function filterRecipientIds(array $recipientIds, int $actorId): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $recipientIds),
            static fn (int $id): bool => $id > 0 && $id !== $actorId
        )));
    }

    private function notificationTypeFor(string $eventType): string
    {
        if (str_starts_with($eventType, 'ticket.comment.')) {
            return 'comment_added';
        }
        if (str_starts_with($eventType, 'ticket.sla_breached.')) {
            return 'sla_breached';
        }

        return match ($eventType) {
            'ticket.approved' => 'ticket_approved',
            'ticket.created' => 'ticket_approved',
            'ticket.rejected' => 'ticket_rejected',
            default => 'ticket_status_changed',
        };
    }

    private function filterByPreference(array $recipientIds, string $notificationType, string $channel): array
    {
        if ($recipientIds === []) {
            return [];
        }

        // Batch-load explicitly-disabled recipients in one query (opt-out model: no row = enabled)
        // instead of calling isEnabled() once per recipient (N+1 — worst on broadcast to all users).
        $disabled = array_flip($this->preferences->disabledUserIds($recipientIds, $notificationType, $channel));

        return array_values(array_filter(
            $recipientIds,
            static fn (int $userId): bool => !isset($disabled[$userId])
        ));
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
            'ticket_id' => $relatedType === 'ticket' ? $relatedId : 0,
            'ticket_no' => (string) ($ticketContext['ticket_no'] ?? ($payload['ticket_no'] ?? '')),
            'ticket_title' => (string) ($ticketContext['title'] ?? ''),
            'type' => $type,
            'title' => (string) ($notification['title'] ?? ''),
            'message' => (string) ($notification['message'] ?? ''),
            'is_read' => (bool) ($notification['is_read'] ?? false),
            'created_at' => $this->formatDateTime($createdAt),
            'created_at_raw' => $createdAt,
            'created_timestamp' => strtotime($createdAt) ?: 0,
            'relative_time' => $this->relativeTime($createdAt),
            'group_key' => $this->dateGroupKey($createdAt),
            'read_at' => $this->formatDateTime($notification['read_at'] ?? null),
            'link_url' => $linkUrl,
            'payload' => is_array($payload) ? $payload : [],
        ] + $this->notificationPresentation($type, $viewer, $ticketContext);
    }

    private function notificationPresentation(string $type, array $viewer, array $ticket): array
    {
        $role = (string) ($viewer['role'] ?? 'guest');
        $status = (string) ($ticket['status'] ?? '');
        $sla = $this->currentSlaState($ticket);

        if (!empty($sla['is_overdue'])) {
            return ['category' => 'sla', 'category_label' => 'เกิน SLA', 'tone' => 'danger', 'icon' => 'triangle-alert', 'action_label' => 'ตรวจสอบ SLA', 'priority_rank' => 0, 'deadline_label' => $sla['label']];
        }

        if (is_manager_or_admin($role) && $status === 'pending_approval') {
            return ['category' => 'action', 'category_label' => 'รออนุมัติ', 'tone' => 'warning', 'icon' => 'clipboard-list', 'action_label' => 'ตรวจสอบและอนุมัติ', 'priority_rank' => 10, 'deadline_label' => $sla['label']];
        }
        if (is_manager_or_admin($role) && $status === 'approved') {
            return ['category' => 'action', 'category_label' => 'รอมอบหมาย', 'tone' => 'warning', 'icon' => 'wrench', 'action_label' => 'มอบหมายช่าง', 'priority_rank' => 11, 'deadline_label' => $sla['label']];
        }
        if ($role === 'technician' && $status === 'assigned') {
            return ['category' => 'action', 'category_label' => 'งานใหม่', 'tone' => 'info', 'icon' => 'wrench', 'action_label' => 'รับงาน', 'priority_rank' => 12, 'deadline_label' => $sla['label']];
        }
        if ($role === 'requester' && $status === 'resolved') {
            return ['category' => 'action', 'category_label' => 'รอตรวจรับ', 'tone' => 'success', 'icon' => 'check-circle', 'action_label' => 'ตรวจรับงาน', 'priority_rank' => 13, 'deadline_label' => $sla['label']];
        }

        if (str_contains($type, '.sla_breached.')) {
            return ['category' => 'sla', 'category_label' => 'SLA', 'tone' => 'danger', 'icon' => 'triangle-alert', 'action_label' => 'ตรวจสอบ SLA', 'priority_rank' => 20, 'deadline_label' => $sla['label']];
        }

        if (str_contains($type, '.comment.')) {
            return ['category' => 'comment', 'category_label' => 'บทสนทนา', 'tone' => 'info', 'icon' => 'message-circle', 'action_label' => 'ดูบทสนทนา', 'priority_rank' => 30, 'deadline_label' => ''];
        }

        return match ($type) {
            'ticket.rejected' => ['category' => 'workflow', 'category_label' => 'Workflow', 'tone' => 'danger', 'icon' => 'triangle-alert', 'action_label' => 'ดูเหตุผล', 'priority_rank' => 40, 'deadline_label' => ''],
            'ticket.cancelled' => ['category' => 'workflow', 'category_label' => 'Workflow', 'tone' => 'danger', 'icon' => 'x', 'action_label' => 'ดูเหตุผล', 'priority_rank' => 40, 'deadline_label' => ''],
            default => ['category' => 'workflow', 'category_label' => 'Workflow', 'tone' => 'default', 'icon' => 'activity', 'action_label' => 'เปิด Ticket', 'priority_rank' => 50, 'deadline_label' => ''],
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

        foreach ($this->sortNotifications($items) as $item) {
            $key = (string) ($item['group_key'] ?? 'earlier');
            $groups[$key] ??= ['key' => $key, 'label' => $labels[$key] ?? 'ก่อนหน้านี้', 'items' => []];
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    private function aggregateNotifications(array $items): array
    {
        $threads = [];

        foreach ($items as $item) {
            $ticketId = (int) ($item['ticket_id'] ?? 0);
            $key = $ticketId > 0 ? 'ticket:' . $ticketId : 'notification:' . (int) ($item['id'] ?? 0);

            if (!isset($threads[$key])) {
                $threads[$key] = $item + [
                    'event_count' => 0,
                    'unread_event_count' => 0,
                    'notification_ids' => [],
                ];
            }

            $threads[$key]['event_count']++;
            $threads[$key]['notification_ids'][] = (int) ($item['id'] ?? 0);
            if (empty($item['is_read'])) {
                $threads[$key]['unread_event_count']++;
            }
        }

        foreach ($threads as &$thread) {
            $thread['is_read'] = (int) ($thread['unread_event_count'] ?? 0) === 0;
            if ((int) ($thread['ticket_id'] ?? 0) > 0) {
                $ticketNo = trim((string) ($thread['ticket_no'] ?? ''));
                $ticketTitle = trim((string) ($thread['ticket_title'] ?? ''));
                $thread['title'] = $ticketNo !== '' ? $ticketNo . ($ticketTitle !== '' ? ' · ' . $ticketTitle : '') : (string) ($thread['title'] ?? 'Ticket');
                $thread['message'] = 'อัปเดตล่าสุด: ' . (string) ($thread['message'] ?? '');
            }
        }
        unset($thread);

        return array_values($threads);
    }

    private function sortNotifications(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            $rank = (int) ($left['priority_rank'] ?? 50) <=> (int) ($right['priority_rank'] ?? 50);
            if ($rank !== 0) {
                return $rank;
            }

            $unread = (int) empty($left['is_read']) <=> (int) empty($right['is_read']);
            if ($unread !== 0) {
                return -$unread;
            }

            return (int) ($right['created_timestamp'] ?? 0) <=> (int) ($left['created_timestamp'] ?? 0);
        });

        return $items;
    }

    private function countActionItems(array $items): int
    {
        return count(array_filter($items, static fn (array $item): bool => in_array((string) ($item['category'] ?? ''), ['action', 'sla'], true)));
    }

    private function currentSlaState(array $ticket): array
    {
        $status = (string) ($ticket['status'] ?? '');
        $terminal = in_array($status, ['resolved', 'completed', 'rejected', 'cancelled', 'closed'], true);
        $now = time();
        $responseDue = strtotime((string) ($ticket['response_due_at'] ?? ''));
        $resolutionDue = strtotime((string) ($ticket['resolution_due_at'] ?? ''));

        if (!$terminal && empty($ticket['resolved_at']) && $resolutionDue !== false && $resolutionDue < $now) {
            return ['is_overdue' => true, 'label' => 'Resolution SLA เกินกำหนด'];
        }
        if (!$terminal && empty($ticket['first_response_at']) && $responseDue !== false && $responseDue < $now) {
            return ['is_overdue' => true, 'label' => 'Response SLA เกินกำหนด'];
        }

        $target = !$terminal && empty($ticket['first_response_at']) ? $responseDue : $resolutionDue;
        if (!$terminal && $target !== false && $target > $now) {
            $minutes = (int) ceil(($target - $now) / 60);
            return ['is_overdue' => false, 'label' => $minutes < 60 ? 'เหลือ ' . $minutes . ' นาที' : 'เหลือ ' . (int) ceil($minutes / 60) . ' ชั่วโมง'];
        }

        return ['is_overdue' => false, 'label' => ''];
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
