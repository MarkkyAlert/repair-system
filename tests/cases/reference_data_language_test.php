<?php
declare(strict_types=1);

// ข้อมูลตั้งต้นใน seed_reference.sql คือสิ่งที่ "ทุกการติดตั้ง" ต้องนำเข้า (INSTALL.md) — ชื่อในนั้นจึงเป็นคำแรก ๆ
// ที่ผู้ซื้อเห็นบนหน้าจอไทย ทั้งในช่องเลือกตอนแจ้งซ่อม หัวตารางรายงาน และไฟล์ที่ส่งออก
// เดิมเป็นอังกฤษล้วน (Reception Printer Area, Office Equipment, Low/Medium/High/Urgent) ทั้งที่ทั้งระบบเป็นไทย
//
// รหัส (code) ต้องเป็น ASCII ต่อไป: มันคือคีย์ของการนำเข้าไฟล์ CSV และเป็นคีย์ของตัวแปลชื่อความสำคัญในโค้ด
// เปลี่ยนรหัสเมื่อไหร่ ไฟล์นำเข้าของผู้ซื้อจะพังทันที

/** @return array<int, array{table: string, code: string, name: string}> */
function rdl_reference_rows(): array
{
    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seed_reference.sql');
    $rows = [];
    $table = '';
    foreach (explode("\n", $sql) as $line) {
        if (preg_match('/INSERT INTO (\w+)/', $line, $m) === 1) {
            $table = $m[1];
            continue;
        }
        // (id, 'CODE', 'ชื่อ', ... — คอลัมน์ที่สองคือรหัส คอลัมน์ที่สามคือชื่อที่คนอ่าน
        if ($table !== '' && preg_match("/^\s*\(\d+,\s*(?:NULL,\s*)?'([^']+)',\s*'([^']+)'/", $line, $m) === 1) {
            $rows[] = ['table' => $table, 'code' => $m[1], 'name' => $m[2]];
        }
    }

    return $rows;
}

test('seed(reference): ชื่อที่คนอ่านเป็นภาษาไทย ส่วนรหัสยังเป็น ASCII', function (): void {
    $rows = rdl_reference_rows();
    assert_true(count($rows) >= 18, 'อ่านข้อมูลตั้งต้นได้ครบทุกตาราง (ได้ ' . count($rows) . ' แถว)');

    $english = [];
    $nonAscii = [];
    foreach ($rows as $row) {
        if (preg_match('/[ก-๙]/u', $row['name']) !== 1) {
            $english[] = $row['table'] . '.' . $row['code'] . ' = "' . $row['name'] . '"';
        }
        if (preg_match('/^[A-Z0-9_-]+$/', $row['code']) !== 1) {
            $nonAscii[] = $row['table'] . ' → "' . $row['code'] . '"';
        }
    }

    assert_same([], $english, 'ชื่อที่ยังไม่เป็นไทย: ' . implode(', ', $english));
    assert_same([], $nonAscii, 'รหัสต้องเป็น A-Z 0-9 - _ เท่านั้น (เป็นคีย์ของการนำเข้าไฟล์): ' . implode(', ', $nonAscii));
});

test('seed(reference): ชื่อห้ามซ้ำกันในตารางเดียวกัน (ฐานข้อมูลบังคับ UNIQUE ไว้)', function (): void {
    // departments / ticket_categories / asset_categories มี UNIQUE KEY บนคอลัมน์ name — ชื่อไทยที่ซ้ำกัน
    // จะทำให้การนำเข้าไฟล์ตั้งต้นล้มกลางคัน ("เครื่องปรับอากาศ" ซ้ำได้ข้ามตาราง แต่ห้ามซ้ำในตารางเดียวกัน)
    $byTable = [];
    foreach (rdl_reference_rows() as $row) {
        $byTable[$row['table']][] = $row['name'];
    }

    foreach ($byTable as $table => $names) {
        assert_same(count($names), count(array_unique($names)), "ชื่อซ้ำในตาราง {$table}: " . implode(', ', $names));
    }
});

test('seed(priorities): ชื่อระดับความสำคัญตรงกับตัวแปลในโค้ดทุกตัว', function (): void {
    // หน้าจอส่วนใหญ่แสดงชื่อผ่าน priority_label_th() (แปลจากรหัส) แต่หน้าผู้ดูแลกับตัวกรองบางจุดแสดงชื่อ
    // จากฐานข้อมูลตรง ๆ ถ้าสองฝั่งสะกดไม่ตรงกัน งานใบเดียวกันจะขึ้นคนละคำในคนละหน้า
    // (เคยเป็นแบบนั้นจริง: ข้อมูลตัวอย่างเขียน "กลาง"/"ด่วน" ส่วนตัวแปลเขียน "ปานกลาง"/"เร่งด่วน")
    $mismatched = [];
    foreach (rdl_reference_rows() as $row) {
        if ($row['table'] !== 'priorities') {
            continue;
        }
        $expected = priority_label_th($row['code']);
        if ($row['name'] !== $expected) {
            $mismatched[] = $row['code'] . ': ข้อมูลตั้งต้น "' . $row['name'] . '" ≠ ตัวแปล "' . $expected . '"';
        }
    }
    assert_same([], $mismatched, implode(' · ', $mismatched));

    // ตัวโหลดข้อมูลตัวอย่างต้องสะกดเหมือนกันด้วย (คนละไฟล์ คนละเส้นทางติดตั้ง)
    $demo = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/DemoDataService.php');
    foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $code) {
        assert_true(
            preg_match("/'code' => '{$code}', 'name' => '" . preg_quote(priority_label_th($code), '/') . "'/u", $demo) === 1,
            "ข้อมูลตัวอย่างต้องเรียก {$code} ว่า \"" . priority_label_th($code) . '"'
        );
    }
});
