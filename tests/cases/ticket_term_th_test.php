<?php
declare(strict_types=1);

use App\Services\EmailTemplateService;
use App\Services\NotificationService;

// ระบบเป็นภาษาไทยทั้งหมด แต่คำนามหลักของมันถูกเรียกว่า "Ticket" อยู่ราว 120 จุดที่ผู้ใช้เห็น ทั้งที่แถบเมนู
// ด้านข้างเรียกว่า "รายการแจ้งซ่อม" มาตั้งแต่ต้น — แม่บ้านหรือช่างที่เปิดใช้ครั้งแรกไม่รู้ว่ามันคืออะไร
// เจ้าของสั่ง (2026-08-15) ให้ใช้คำว่า "งานแจ้งซ่อม" ทุกที่ที่ผู้ใช้เห็น ซึ่งกลับมติเดิม (2026-07-01) ที่เคยให้คง
// Ticket ไว้เป็นคำอังกฤษคำเดียวในระบบ
//
// เทสต์นี้ล็อกฝั่ง "คำใหม่ต้องอยู่จริง" ส่วนการกันคำเก่าไหลกลับอยู่ในเทสต์สแกนของอีกคอมมิต

test('term: ป้ายชนิดการแจ้งเตือนและทะเบียนเทมเพลตอีเมลใช้คำว่า "งานแจ้งซ่อม"', function (): void {
    foreach (['ticket_pending_approval', 'ticket_approved', 'ticket_rejected', 'ticket_status_changed'] as $type) {
        $label = (string) (NotificationService::NOTIFICATION_TYPES[$type] ?? '');
        assert_true(
            str_contains($label, 'งานแจ้งซ่อม'),
            "ป้ายของการแจ้งเตือน {$type} ต้องเรียกว่างานแจ้งซ่อม (ได้: {$label})"
        );
    }

    foreach (['ticket_created', 'ticket_approved', 'ticket_rejected'] as $template) {
        $label = (string) (EmailTemplateService::TEMPLATE_REGISTRY[$template]['label'] ?? '');
        assert_true(
            str_contains($label, 'งานแจ้งซ่อม'),
            "ป้ายเทมเพลตอีเมล {$template} ต้องเรียกว่างานแจ้งซ่อม (ได้: {$label})"
        );
    }
});

test('term: หัวข้ออีเมลและแถวรายละเอียดที่ผู้รับเห็น ใช้คำไทย', function (): void {
    // หัวเรื่องการแจ้งเตือนถูกนำไปประกอบเป็นหัวข้ออีเมลโดยตรง (buildTicketEvent) — ถ้าคำเก่าหลงเหลืออยู่
    // ผู้รับจะเห็นคำว่า Ticket ในกล่องจดหมาย ซึ่งเป็นจุดที่แก้ย้อนหลังไม่ได้เมื่อส่งออกไปแล้ว
    $mail = tvm_container()->get(EmailTemplateService::class)->buildSampleTicketEvent([
        'id' => 4,
        'full_name' => 'ผู้ดูแลระบบ',
        'email' => 'admin@example.test',
    ]);

    assert_true(str_contains((string) $mail['subject'], 'งานแจ้งซ่อม'), 'หัวข้ออีเมลต้องใช้คำว่างานแจ้งซ่อม: ' . $mail['subject']);
    assert_false(str_contains((string) $mail['subject'], 'Ticket'), 'หัวข้ออีเมลต้องไม่มีคำว่า Ticket: ' . $mail['subject']);

    $body = (string) ($mail['body_html'] ?? '') . (string) ($mail['body_text'] ?? '');
    assert_true(str_contains($body, 'เลขที่งานแจ้งซ่อม'), 'แถวเลขที่ในอีเมลต้องเป็นคำไทย');
    assert_false(str_contains($body, 'Ticket No'), 'อีเมลต้องไม่มีป้าย "Ticket No" หลงเหลือ');
});

test('term: ข้อความในกระดิ่งแจ้งเตือนตรงกันทั้งฝั่ง PHP และ JS', function (): void {
    // กระดิ่งถูก render สองที่: ครั้งแรกจาก PHP ตอนโหลดหน้า แล้ว JS วาดทับเองทุกครั้งที่ poll ข้อมูลใหม่
    // ไม่มีอะไรผูกสองไฟล์นี้ไว้ด้วยกัน แก้ที่เดียวแล้วกระดิ่งจะพูดคนละคำกับหน้าเว็บหลัง poll รอบแรก
    // (ผู้ใช้จะเห็นคำเปลี่ยนไปเองโดยไม่ได้ทำอะไร) เทสต์นี้บังคับให้ทั้งคู่ใช้ข้อความชุดเดียวกัน
    $root = dirname(__DIR__, 2);
    $php = (string) file_get_contents($root . '/app/Views/partials/components/notification-bell.php');
    $js = (string) file_get_contents($root . '/public/assets/js/app.js');

    foreach (['ไม่มีงานแจ้งซ่อมที่มีอัปเดตในขณะนี้', 'เปิดงานแจ้งซ่อม'] as $shared) {
        assert_true(str_contains($php, $shared), "notification-bell.php ต้องมีข้อความ \"{$shared}\"");
        assert_true(str_contains($js, $shared), "app.js (ตัวที่วาดกระดิ่งซ้ำ) ต้องมีข้อความ \"{$shared}\" ชุดเดียวกัน");
    }
});
