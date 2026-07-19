<?php
declare(strict_types=1);

// ป้ายข้อความ (label) ภาษาไทยรวมไว้ที่เดียว เป็นแหล่งอ้างอิงเดียวที่ service กับ view ใช้ร่วมกัน
// แต่ละฟังก์ชัน label จะคืนป้ายไทยของค่า enum ที่รู้จัก ถ้าเจอค่าที่ไม่มีใน map ก็ถอยไปใช้
// humanize_label() แทน เก็บเป็น global helper ให้เข้าชุดกับ
// human_date() ที่มีอยู่เดิม (app/Helpers/view.php) และโหลดผ่าน app/Helpers/helpers.php

if (!function_exists('humanize_label')) {
    /**
     * ตัวจัดรูปแบบสำรองแบบทั่วไป: "pending_approval" -> "Pending Approval"
     * มาแทน labelize() ตัว private ที่เคยก๊อปซ้ำ ๆ อยู่ใน
     * TicketService, ReportService และ AssetService
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
    /** role บัญชีผู้ใช้ที่มอบหมายได้ 4 แบบ (สะท้อนค่า ENUM ของ users.role) — แหล่งเดียวสำหรับตรวจสอบ/วนลูป role */
    function valid_roles(): array
    {
        return \App\Support\Role::assignable();
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

if (!function_exists('ticket_status_tone')) {
    /** สี badge ตามสถานะ ticket เป็นแหล่งเดียว (TicketService::statusTone เรียกต่อจากตัวนี้). */
    function ticket_status_tone(string $status): string
    {
        return match ($status) {
            'resolved', 'completed' => 'success',
            'pending_approval', 'on_hold' => 'warning',
            'rejected', 'cancelled' => 'danger',
            'approved', 'assigned', 'accepted', 'in_progress', 'submitted' => 'info',
            default => 'default',
        };
    }
}

if (!function_exists('guest_request_status_label_th')) {
    function guest_request_status_label_th(string $status): string
    {
        static $map = [
            'new' => 'รอการตรวจสอบ',
            'converted' => 'รับเรื่องแล้ว',
            'rejected' => 'ไม่รับเรื่อง',
        ];

        return $map[$status] ?? humanize_label($status);
    }
}

if (!function_exists('ticket_status_values')) {
    /**
     * รายการค่ามาตรฐานของ enum tickets.status แบบเรียงลำดับ เป็นแหล่งอ้างอิงเดียวสำหรับ
     * whitelist ตอนตรวจสอบและ dropdown ตัวกรอง (ต้องตรงกับ schema.sql + [[ticket_status_label_th]])
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

if (!function_exists('ticket_terminal_statuses')) {
    /**
     * สถานะ ticket ที่วงจรชีวิตงานจบแล้ว นาฬิกา SLA หยุดนับ ไม่ถูกนับในคิว "งานที่เปิดอยู่"
     * และนับเป็นงานปิดในรายงาน หมายเหตุ: นี่คือชุดสถานะที่จบในเชิงวงจรชีวิต (รวม 'resolved' ด้วย)
     * ตั้งใจให้ต่างจากชุด "ยังต้องให้ผู้แจ้งดำเนินการ" ที่ฝั่งผู้แจ้งเห็น
     * (AdminRepository::hasOpenRequesterTickets สำหรับผู้แจ้ง 'resolved' ยังถือว่าเปิดอยู่)
     * และต่างจากเงื่อนไข workflow แยกรายการใน TicketService (canReopen/canDuplicate/...)
     * ห้ามยุบชุดพวกนั้นมารวมเป็นรายการเดียวกันนี้
     */
    function ticket_terminal_statuses(): array
    {
        return ['resolved', 'completed', 'rejected', 'cancelled', 'closed'];
    }
}

if (!function_exists('ticket_terminal_statuses_sql')) {
    /** สถานะสิ้นสุดในรูปรายการ SQL ที่ครอบ quote คั่นด้วยจุลภาค สำหรับใช้ในส่วน `status IN (...)` */
    function ticket_terminal_statuses_sql(): string
    {
        return "'" . implode("','", ticket_terminal_statuses()) . "'";
    }
}

if (!function_exists('ticket_resolved_statuses')) {
    /**
     * สถานะที่นับเป็นงานที่แก้เสร็จ/ทำเสร็จ ("ปิดงาน") ในการสรุปยอดรายงาน เป็นชุดย่อยของงานที่สำเร็จ
     * ตั้งใจไม่รวม 'rejected'/'cancelled' (จบแล้วแต่ไม่ได้แก้สำเร็จ) เลยแคบกว่า
     * ticket_terminal_statuses() ห้ามรวมสองชุดนี้เข้าด้วยกัน มันตอบคำถามทางธุรกิจคนละอย่าง
     */
    function ticket_resolved_statuses(): array
    {
        return ['resolved', 'completed', 'closed'];
    }
}

if (!function_exists('ticket_resolved_statuses_sql')) {
    /** สถานะที่แก้สำเร็จในรูปรายการ SQL ที่ครอบ quote คั่นด้วยจุลภาค สำหรับใช้ในส่วน `status IN (...)` */
    function ticket_resolved_statuses_sql(): string
    {
        return "'" . implode("','", ticket_resolved_statuses()) . "'";
    }
}

if (!function_exists('ticket_status_options')) {
    /**
     * ตัวเลือก dropdown ตัวกรองตามสถานะ ticket ([{value,label}]) พร้อมป้ายไทยจาก
     * ticket_status_label_th() ส่ง $includeAll เพื่อเติมรายการ "ทุกสถานะ" ไว้ด้านหน้า
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
    /** ใช้ priority_code เป็น key (LOW/MEDIUM/HIGH/URGENT) */
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
    /** Impact / urgency ตั้งใจให้เป็นสองภาษา (คงไว้ตามเดิม): "ปานกลาง (Medium)" */
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

if (!function_exists('severity_values')) {
    /** ค่ามาตรฐานของ enum impact_level/urgency_level (ต้องให้ตรงกับ schema.sql) */
    function severity_values(): array
    {
        return ['low', 'medium', 'high', 'critical'];
    }
}

if (!function_exists('work_order_status_label_th')) {
    /** enum ของ work_orders.status: assigned/accepted/in_progress/paused/completed/cancelled */
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

if (!function_exists('asset_status_values')) {
    /** ค่ามาตรฐานของ enum assets.status — แหล่งเดียวสำหรับการตรวจสอบ + ตัวสร้างตัวเลือก */
    function asset_status_values(): array
    {
        return ['active', 'maintenance', 'retired', 'disposed'];
    }
}

if (!function_exists('asset_status_label_th')) {
    /** enum ของ assets.status: active/maintenance/retired/disposed */
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
