<?php
declare(strict_types=1);

// คำว่า SLA ปรากฏราว 110 จุดในระบบ แต่ไม่เคยถูกอธิบายไว้ที่ไหนเลยแม้แต่ครั้งเดียว — ไม่มีคำว่า "ข้อตกลงระดับ
// การให้บริการ" หรือคำขยายใด ๆ อยู่ในโค้ด เอกสาร หรือหน้าคู่มืออ่านรายงาน (ซึ่งนิยาม MTTR ไว้แต่ข้าม SLA)
// จุดที่โล้นที่สุดคือป้ายที่มีแต่ตัวย่อล้วน: ตัวกรองในคิวงาน หัวคอลัมน์ การ์ด "เกิน SLA" และหัวใบสั่งงานที่พิมพ์ออกมา
//
// เจ้าของเลือก (2026-08-15) ให้ "คงคำว่า SLA ไว้ แต่ต้องมีคำอธิบายตรงจุดที่ใช้" เทสต์นี้ล็อกว่าคำอธิบายยังอยู่
// และล็อกกลไกที่ใช้ให้ถูกที่ถูกทาง (ปุ่มกางคำอธิบายใช้ได้เฉพาะหน้าที่มี JS)

test('sla: จุดที่เคยมีแต่ตัวย่อ SLA ล้วน ๆ ตอนนี้มีคำอธิบายกำกับ', function (): void {
    $root = dirname(__DIR__, 2);

    $queue = (string) file_get_contents($root . '/app/Views/tickets/index.php');
    assert_true(str_contains($queue, "'id' => 'sla-filter-info'"), 'ตัวกรอง SLA ในคิวงานต้องมีปุ่มดูคำอธิบาย');
    assert_true(str_contains($queue, 'ข้อตกลงระดับการให้บริการ'), 'คำอธิบายต้องขยายตัวย่อให้เต็ม');
    assert_false(
        preg_match('/<label class="field-label" for="ticket-filter-sla">SLA<\/label>/', $queue) === 1,
        'ป้ายตัวกรองต้องไม่กลับไปเป็นตัวย่อโดด ๆ'
    );
    assert_true(str_contains($queue, '<span>SLA (กำหนดเวลา)</span>'), 'หัวคอลัมน์ต้องขยายความ (ใส่ปุ่มไม่ได้เพราะอยู่ในบริเวณ aria-hidden)');

    foreach ([
        'app/Views/reports/index.php' => 'งานที่เลยกำหนดเวลาที่ตกลงไว้ (SLA)',
        'app/Views/reports/sla-breach.php' => 'SLA = กำหนดเวลาตอบรับ/แก้ไข',
        'app/Views/reports/problem-hotspot.php' => 'เกิน SLA (กำหนดเวลาตอบรับ/แก้ไข)',
        'app/Views/reports/technician-performance.php' => 'SLA = กำหนดเวลาตอบรับ/แก้ไข',
        'app/Views/dashboard/index.php' => 'งานที่เลยกำหนดเวลาตอบรับหรือกำหนดเวลาซ่อมที่ตกลงไว้ (SLA)',
    ] as $rel => $needle) {
        assert_true(
            str_contains((string) file_get_contents($root . '/' . $rel), $needle),
            "{$rel} ต้องมีคำอธิบาย SLA กำกับ"
        );
    }
});

test('sla: หน้าพิมพ์ใช้ข้อความนิ่ง ไม่ใช่ปุ่มที่ต้องกด', function (): void {
    // ใบสั่งงานถูกพิมพ์ลงกระดาษหรือแปลงเป็น PDF ซึ่งไม่มี JS — ปุ่มกางคำอธิบายจะค้างสถานะปิดถาวร
    // คนอ่านกระดาษจึงต้องเห็นคำอธิบายอยู่แล้วตั้งแต่แรก
    $print = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/tickets/print.php');

    assert_true(str_contains($print, 'SLA = กำหนดเวลาตอบรับ/แก้ไข ตามระดับความสำคัญของงาน'), 'ใบสั่งงานที่พิมพ์ต้องมีคำอธิบาย SLA แบบข้อความนิ่ง');
    assert_false(str_contains($print, 'data-info-toggle'), 'หน้าพิมพ์ต้องไม่ใช้ปุ่มกางคำอธิบาย (ไม่มี JS ให้กด)');

    foreach (glob(dirname(__DIR__, 2) . '/app/Views/**/*pdf*.php') ?: [] as $pdfView) {
        assert_false(
            str_contains((string) file_get_contents($pdfView), 'data-info-toggle'),
            basename($pdfView) . ' เป็นเอกสาร PDF ต้องไม่ใช้ปุ่มกางคำอธิบาย'
        );
    }
});

test('sla: partial ปุ่มคำอธิบายผูก aria ถูกคู่ และหน้าแจ้งซ่อมใหม่ใช้ตัวเดียวกันหมด', function (): void {
    $root = dirname(__DIR__, 2);
    $partial = (string) file_get_contents($root . '/app/Views/partials/components/info-popover.php');

    // ปุ่มต้องชี้ไปยังกล่องคำอธิบายที่มีอยู่จริง และเริ่มต้นที่สถานะ "ยังไม่กาง"
    assert_true(str_contains($partial, 'data-info-toggle="<?= e($id) ?>"'), 'ปุ่มต้องผูกกับ id ของกล่องคำอธิบาย');
    assert_true(str_contains($partial, 'aria-controls="<?= e($id) ?>"'), 'aria-controls ต้องเป็น id เดียวกับ data-info-toggle');
    assert_true(str_contains($partial, 'aria-expanded="false"'), 'ต้องเริ่มที่สถานะยังไม่กาง');
    assert_true(str_contains($partial, 'id="<?= e($id) ?>" class="field-info-popover" hidden'), 'กล่องคำอธิบายต้องซ่อนไว้ก่อน');

    // หน้าแจ้งซ่อมใหม่เคยเขียน markup ชุดนี้ด้วยมือสามครั้ง — ต้องไม่มีเหลือค้าง
    $create = (string) file_get_contents($root . '/app/Views/tickets/create.php');
    assert_same(3, substr_count($create, "render_partial('partials/components/info-popover'"), 'ทั้งสามช่องต้องใช้ partial ตัวเดียวกัน');
    assert_false(str_contains($create, 'class="field-info-icon"'), 'ต้องไม่มี markup ที่เขียนมือหลงเหลือ');
});

test('a11y: ไม่มีปุ่มคำอธิบายไปโผล่ในบริเวณที่ซ่อนจากโปรแกรมอ่านหน้าจอ', function (): void {
    // หัวตารางคิวงานเป็น aria-hidden="true" (ตัวจริงที่โปรแกรมอ่านหน้าจอใช้คือ caption ของตาราง)
    // ปุ่มที่โฟกัสได้ในบริเวณนั้นจะกลายเป็น "จุดที่กด Tab ไปถึงได้แต่ไม่มีใครอ่านให้ฟัง"
    $root = dirname(__DIR__, 2);
    $offenders = [];
    foreach (glob($root . '/app/Views/**/*.php') ?: [] as $view) {
        $html = (string) file_get_contents($view);
        if (preg_match('/aria-hidden="true"[^>]*>(?:(?!<\/div>|<\/span>).){0,1200}?data-info-toggle/s', $html) === 1) {
            $offenders[] = str_replace($root . '/', '', $view);
        }
    }

    assert_same([], $offenders, 'ปุ่มคำอธิบายอยู่ในบริเวณ aria-hidden: ' . implode(', ', $offenders));
});

test('partials: ไม่มี component ที่ทั้งไม่มีใครเรียกใช้และไม่มีสไตล์รองรับ', function (): void {
    // partials/components/sla-badge.php เคยอยู่ในเทมเพลตโดยไม่มีใครเรียกใช้เลยสักที่ และคลาสของมัน
    // (.sla-badge/.sla-icon) ก็ไม่มีอยู่ใน CSS แปลว่ามันไม่เคยแสดงผลถูกต้องมาก่อน ผู้ซื้อที่เปิดมาอ่านจะนึกว่า
    // เป็นของพร้อมใช้
    //
    // เกณฑ์ตั้งไว้แคบ ๆ ว่า "ไม่มีใครเรียก + ไม่มีสไตล์" ตั้งใจไม่เหมารวม component ที่ยังไม่มีใครเรียกแต่มี
    // สไตล์ครบ (เช่น data-table ที่ประกาศตัวเองเป็นแบบแผนกลางของตารางรายการ — หน้าเว็บเขียน markup เองอยู่
    // ตอนนี้ก็จริง แต่มันคือของที่ควรถูกนำไปใช้ ไม่ใช่ของตาย) การไล่ลบทิ้งจะทำให้แบบแผนนั้นหายไปด้วย
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/app.css');
    $used = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $used .= (string) file_get_contents($file->getPathname());
        }
    }

    $dead = [];
    foreach (glob($root . '/app/Views/partials/components/*.php') ?: [] as $component) {
        $name = basename($component, '.php');
        if (str_contains($used, "partials/components/{$name}")) {
            continue;
        }
        // เอาเฉพาะคลาสที่เขียนตายตัวใน markup — ตัดส่วนที่ประกอบจากตัวแปร PHP ทิ้ง
        // เช่น class="sla-badge sla-{ตัวแปร}" ยังเหลือคำว่า sla-badge ให้ตรวจได้
        preg_match_all('/class="([^"]*)"/i', (string) file_get_contents($component), $m);
        $classes = [];
        foreach ($m[1] as $attribute) {
            $literal = explode('<?', $attribute)[0];
            foreach (preg_split('/\s+/', $literal) ?: [] as $class) {
                // ตัดเศษที่ค้างอยู่หน้าตัวแปร (เช่น "sla-") ทิ้ง — ไม่ใช่ชื่อคลาสจริง
                if (preg_match('/^[a-z][a-z0-9_-]*[a-z0-9]$/i', $class) === 1) {
                    $classes[] = $class;
                }
            }
        }
        $classes = array_unique($classes);
        $styled = false;
        foreach ($classes as $class) {
            // ต้องตรงทั้งชื่อ ไม่ใช่แค่ขึ้นต้นเหมือนกัน — ไม่งั้นเศษอย่าง sla- จะไปแมตช์กับ .sla-countdown
            if (preg_match('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])/i', $css) === 1) {
                $styled = true;
                break;
            }
        }
        if (!$styled && $classes !== []) {
            $dead[] = $name;
        }
    }

    assert_same([], $dead, 'partial ที่ไม่มีใครเรียกและไม่มีสไตล์รองรับ: ' . implode(', ', $dead));
});
