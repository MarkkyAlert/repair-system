<?php
declare(strict_types=1);

// Centralized Thai display labels — single source of truth shared by services and views.
// Each label function returns the Thai label for a known enum value, falling back to
// humanize_label() for anything unmapped. Kept as global helpers to match the existing
// human_date() pattern (app/Helpers/view.php) and loaded via app/Helpers/helpers.php.

if (!function_exists('humanize_label')) {
    /**
     * Generic fallback formatter: "pending_approval" -> "Pending Approval".
     * Replaces the duplicated private labelize() previously copied across
     * TicketService, ReportService and AssetService.
     */
    function humanize_label(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '-';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }
}

if (!function_exists('role_label_th')) {
    function role_label_th(string $role): string
    {
        static $map = [
            'requester' => 'ผู้แจ้ง',
            'manager' => 'หัวหน้างาน',
            'technician' => 'ช่างเทคนิค',
            'admin' => 'ผู้ดูแลระบบ',
            'system' => 'ระบบ',
            'guest' => 'ผู้เยี่ยมชม',
            'user' => 'ผู้ใช้งาน',
        ];

        return $map[strtolower(trim($role))] ?? humanize_label($role);
    }
}

if (!function_exists('valid_roles')) {
    /** The four assignable account roles (mirrors the users.role ENUM). Single source for role validation/iteration. */
    function valid_roles(): array
    {
        return ['requester', 'manager', 'technician', 'admin'];
    }
}

if (!function_exists('ticket_status_label_th')) {
    function ticket_status_label_th(string $status): string
    {
        static $map = [
            'submitted' => 'ส่งคำขอแล้ว',
            'pending_approval' => 'รออนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'assigned' => 'มอบหมายแล้ว',
            'accepted' => 'รับงานแล้ว',
            'in_progress' => 'กำลังดำเนินการ',
            'on_hold' => 'พักงานชั่วคราว',
            'resolved' => 'รอตรวจรับ',
            'completed' => 'เสร็จสิ้น',
            'rejected' => 'ถูกปฏิเสธ',
            'cancelled' => 'ยกเลิกแล้ว',
            'closed' => 'ปิดงานแล้ว',
        ];

        return $map[$status] ?? humanize_label($status);
    }
}

if (!function_exists('ticket_status_values')) {
    /**
     * Canonical ordered list of tickets.status enum values — single source of truth for
     * validation whitelists and filter dropdowns (keep in sync with schema.sql + [[ticket_status_label_th]]).
     */
    function ticket_status_values(): array
    {
        return [
            'submitted',
            'pending_approval',
            'approved',
            'assigned',
            'accepted',
            'in_progress',
            'on_hold',
            'resolved',
            'completed',
            'rejected',
            'cancelled',
            'closed',
        ];
    }
}

if (!function_exists('ticket_status_options')) {
    /**
     * Ticket-status filter dropdown options ([{value,label}]) with Thai labels from
     * ticket_status_label_th(). Pass $includeAll to prepend an "all statuses" entry.
     *
     * @return array<int,array{value:string,label:string}>
     */
    function ticket_status_options(bool $includeAll = false, string $allLabel = 'ทุกสถานะ'): array
    {
        $options = array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => ticket_status_label_th($value),
        ], ticket_status_values());

        if ($includeAll) {
            array_unshift($options, ['value' => '', 'label' => $allLabel]);
        }

        return $options;
    }
}

if (!function_exists('approval_label_th')) {
    function approval_label_th(string $status): string
    {
        static $map = [
            'not_required' => 'ไม่ต้องอนุมัติ',
            'pending' => 'รออนุมัติ',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ถูกปฏิเสธ',
        ];

        return $map[$status] ?? humanize_label($status);
    }
}

if (!function_exists('channel_label_th')) {
    function channel_label_th(string $channel): string
    {
        static $map = [
            'web' => 'เว็บ',
            'qr' => 'สแกน QR',
            'phone' => 'โทรศัพท์',
            'email' => 'อีเมล',
            'walk_in' => 'แจ้งด้วยตนเอง',
        ];

        return $map[strtolower(trim($channel))] ?? humanize_label($channel);
    }
}

if (!function_exists('priority_label_th')) {
    /** Keyed by priority_code (LOW/MEDIUM/HIGH/URGENT). */
    function priority_label_th(string $code): string
    {
        static $map = [
            'LOW' => 'ต่ำ',
            'MEDIUM' => 'ปานกลาง',
            'HIGH' => 'สูง',
            'URGENT' => 'เร่งด่วน',
        ];

        return $map[strtoupper(trim($code))] ?? humanize_label($code);
    }
}

if (!function_exists('severity_label_th')) {
    /** Impact / urgency. Bilingual by design (kept as-is): "ปานกลาง (Medium)". */
    function severity_label_th(string $level): string
    {
        static $map = [
            'low' => 'ต่ำ (Low)',
            'medium' => 'ปานกลาง (Medium)',
            'high' => 'สูง (High)',
            'critical' => 'วิกฤต (Critical)',
        ];

        return $map[strtolower(trim($level))] ?? humanize_label($level);
    }
}

if (!function_exists('work_order_status_label_th')) {
    /** work_orders.status enum: assigned/accepted/in_progress/paused/completed/cancelled. */
    function work_order_status_label_th(string $status): string
    {
        static $map = [
            'assigned' => 'มอบหมายแล้ว',
            'accepted' => 'รับงานแล้ว',
            'in_progress' => 'กำลังดำเนินการ',
            'paused' => 'พักงานชั่วคราว',
            'completed' => 'เสร็จสิ้น',
            'cancelled' => 'ยกเลิกแล้ว',
        ];

        return $map[strtolower(trim($status))] ?? humanize_label($status);
    }
}

if (!function_exists('asset_status_label_th')) {
    /** assets.status enum: active/maintenance/retired/disposed. */
    function asset_status_label_th(string $status): string
    {
        static $map = [
            'active' => 'ใช้งานอยู่',
            'maintenance' => 'อยู่ระหว่างซ่อม',
            'retired' => 'เลิกใช้งาน',
            'disposed' => 'จำหน่าย/ทิ้ง',
        ];

        return $map[strtolower(trim($status))] ?? humanize_label($status);
    }
}
