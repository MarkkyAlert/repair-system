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

/**
 * ตัดคอมเมนต์ออกก่อนสแกน — เทสต์นี้วัด "คำที่ผู้ใช้เห็น" ไม่ใช่โน้ตของนักพัฒนา. คอมเมนต์ในโค้ดเรียกมันว่า
 * ticket ได้ตามสบาย เพราะตารางในฐานข้อมูลชื่อ tickets จริง ๆ คนที่มาแก้โค้ดต่อควรเห็นคำเดียวกับชื่อตาราง
 * (ถ้าไล่แปลคอมเมนต์ด้วยจะได้โน้ตที่อ่านยากขึ้นโดยไม่มีใครได้ประโยชน์). ไฟล์ view ที่มี HTML ปนอยู่ยังถูก
 * สแกนเต็ม เพราะ T_INLINE_HTML คือข้อความที่ผู้ใช้อ่าน
 */
function ttt_strip_comments(string $path, string $body): string
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'php') {
        $kept = '';
        foreach (token_get_all($body) as $token) {
            if (is_array($token)) {
                $kept .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $kept .= $token;
        }

        // คิวรียาว ๆ ถูกเขียนเป็น heredoc คอมเมนต์ SQL ข้างในจึงเป็น "ส่วนหนึ่งของสตริง" ในสายตา token_get_all
        // ต้องตัดทิ้งอีกชั้น ไม่งั้นโน้ตของนักพัฒนาใน SQL จะถูกนับเป็นข้อความที่ผู้ใช้เห็น
        return (string) preg_replace('/^[ \t]*--[ \t][^\n]*$/m', ' ', $kept);
    }
    if ($extension === 'sql') {
        return (string) preg_replace('/--[^\n]*/', ' ', $body);
    }

    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|\n)[ \t]*//[^\n]*#'], [' ', '$1'], $body);
}

/**
 * ไล่หาไฟล์ที่ยังมีคำต้องห้าม แล้วคืนเป็น path สั้น ๆ พร้อมข้อความตัวอย่างที่เจอ
 *
 * @param  array<int, string> $roots
 * @return array<int, string>
 */
function ttt_scan_sources(array $roots, string $pattern): array
{
    $offenders = [];
    foreach ($roots as $root) {
        if (is_file($root)) {
            $files = [$root];
        } elseif (is_dir($root)) {
            $files = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $files[] = $file->getPathname();
            }
        } else {
            continue;
        }

        foreach ($files as $path) {
            // ไฟล์ upgrade เป็นบันทึกประวัติศาสตร์ ต้องเอ่ยชื่อของเดิมเพื่อแปลงข้อมูล — carve-out เดียวกับ
            // ที่ on_hold_paused_removal_test ใช้
            if (str_contains($path, '/upgrades/')) {
                continue;
            }
            if (!in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), ['php', 'js', 'css', 'sql'], true)) {
                continue;
            }
            $body = ttt_strip_comments($path, (string) file_get_contents($path));
            if (preg_match($pattern, $body, $m) === 1) {
                $offenders[] = str_replace(BASE_PATH . '/', '', $path) . ' → "' . trim($m[0]) . '"';
            }
        }
    }

    return $offenders;
}

test('term(guard): ไม่มีคำว่า Ticket ตัวใหญ่หลงเหลือในของที่ส่งขาย', function (): void {
    // สนใจตัวพิมพ์ใหญ่-เล็ก และใช้ขอบเขตคำ จึงไม่แตะชื่อในโค้ดเลย: TicketService/TicketsController มีตัวอักษร
    // ต่อท้ายทันที ส่วน $ticketId, /tickets, ticket_prefix, .ticket-queue, data-ticket-* เป็นตัวเล็กทั้งหมด
    $offenders = ttt_scan_sources(
        [BASE_PATH . '/app', BASE_PATH . '/bin', BASE_PATH . '/public/assets/js', BASE_PATH . '/resources/css', BASE_PATH . '/database'],
        '/\bTickets?\b/'
    );

    assert_same([], $offenders, "ยังมีคำว่า Ticket ที่ผู้ใช้เห็น:\n" . implode("\n", $offenders));
});

test('term(guard): ไม่มีคำว่า ticket ตัวเล็กใช้เป็นคำนามในประโยคไทย', function (): void {
    // เคล็ดอยู่ที่ช่องว่าง — ประโยคไทยที่แทรกคำอังกฤษจะเว้นวรรครอบคำนั้นเสมอ ("ไม่พบ ticket ที่ต้องการ")
    // ส่วนตัวระบุในโค้ดจะติดกับเครื่องหมายอื่นทันที ($ticket['x'], id="ticket-filter-sla", ticket_prefix)
    // จึงแยกข้อความสำหรับคนอ่านออกจากโค้ดได้โดยไม่ต้องไล่ยกเว้นทีละชื่อ ยกเว้นอย่างเดียวคือ path ของ route
    // ("เช่นหน้า /tickets หรือ…") ซึ่งเป็นที่อยู่ของหน้า ไม่ใช่คำเรียกงาน
    $offenders = ttt_scan_sources(
        [BASE_PATH . '/app', BASE_PATH . '/bin', BASE_PATH . '/public/assets/js', BASE_PATH . '/database'],
        '/[ก-๙][\x20]{1,2}\bticket(s)?\b|(?<![\/\w_-])ticket(s)?\b[\x20]{1,2}[ก-๙]/iu'
    );

    assert_same([], $offenders, "ยังมีคำว่า ticket ปนอยู่ในประโยคไทย:\n" . implode("\n", $offenders));
});
