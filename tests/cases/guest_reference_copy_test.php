<?php
declare(strict_types=1);

use App\Core\View;

// คนที่แจ้งซ่อมผ่าน QR โดยไม่ได้ล็อกอิน ได้เลขที่อ้างอิง (GR-xxxx) มาหนึ่งชุดพร้อมข้อความว่า "เก็บเลขนี้ไว้" —
// แต่บนมือถือการเลือกข้อความทีละตัวอักษรให้ครบพอดีเป็นเรื่องน่ารำคาญ และพลาดง่าย. เลยมีปุ่มคัดลอกให้
//
// สิ่งที่เทสต์นี้ล็อกไว้:
//   1. ปุ่มชี้ไปยัง element ที่มีอยู่จริงในหน้า (selector พิมพ์ผิด = ปุ่มตายเงียบ)
//   2. ปุ่มถูกซ่อนไว้ใน HTML แล้วให้ JS เปิด — ถ้าเบราว์เซอร์ปิด JS ต้องไม่เห็นปุ่มที่กดแล้วไม่เกิดอะไรขึ้น
//   3. ไฟล์ JS ที่หน้าเรียกใช้มีอยู่จริง
//   4. มีทางสำรองแบบ execCommand ด้วย — clipboard API ใช้ได้เฉพาะ https/localhost แต่ระบบภายในองค์กร
//      หลายที่รันบน http ล้วน ถ้ามีแต่ clipboard API ปุ่มจะตายเงียบบนเครื่องลูกค้าจริง

test('guest: หน้ายืนยันการแจ้งมีปุ่มคัดลอกเลขที่อ้างอิงที่ผูกกับเลขจริงในหน้า', function (): void {
    $html = View::capture('scan/report-success', ['requestNo' => 'GR-20260815-0042']);

    assert_true(str_contains($html, 'GR-20260815-0042'), 'หน้ายืนยันแสดงเลขที่อ้างอิง');

    // ปุ่มต้องระบุ selector ของกล่องข้อความ และ selector นั้นต้องมีอยู่จริงในหน้าเดียวกัน
    assert_true(
        preg_match('/data-copy-source="#([A-Za-z0-9_-]+)"/', $html, $m) === 1,
        'มีปุ่มที่ประกาศ data-copy-source'
    );
    assert_true(
        preg_match('/id="' . preg_quote($m[1], '/') . '"[^>]*>GR-20260815-0042</', $html) === 1,
        'data-copy-source ชี้ไปยัง element ที่มีเลขที่อ้างอิงจริง (id="' . $m[1] . '")'
    );

    // ซ่อนไว้ทั้ง attribute hidden และ inline style — .btn เป็น display:inline-flex ซึ่งทับ [hidden] ของเบราว์เซอร์ได้
    assert_true(
        preg_match('/<button[^>]*data-copy-source[^>]*>/', $html, $btn) === 1,
        'ปุ่มคัดลอกเป็น <button>'
    );
    assert_true(str_contains($btn[0], 'hidden'), 'ปุ่มถูกซ่อนไว้ก่อน รอ JS เปิด (ไม่มี JS = ไม่มีปุ่มที่กดแล้วเงียบ)');
    assert_true(str_contains($btn[0], 'display:none'), 'ต้องซ่อนด้วย inline style ด้วย เพราะ .btn ใช้ display:inline-flex ทับ [hidden]');

    // ผลลัพธ์ของการกดต้องประกาศไว้ให้ screen reader ได้ยินด้วย ไม่ใช่เห็นแค่ตัวหนังสือเปลี่ยน
    assert_true(str_contains($html, 'role="status"'), 'มีที่ประกาศผลการคัดลอกสำหรับ screen reader');

    // ไฟล์ JS ที่หน้าเรียกใช้ต้องมีอยู่จริง
    assert_true(
        preg_match('#<script src="[^"]*js/([A-Za-z0-9._-]+)(?:\?[^"]*)?"#', $html, $script) === 1,
        'หน้ายืนยันโหลดสคริปต์ของปุ่มคัดลอก'
    );
    $jsPath = dirname(__DIR__, 2) . '/public/assets/js/' . $script[1];
    assert_true(is_file($jsPath), 'ไฟล์ ' . $script[1] . ' มีอยู่จริงใน public/assets/js');

    $js = (string) file_get_contents($jsPath);
    assert_true(str_contains($js, 'data-copy-source'), 'สคริปต์ผูกกับ [data-copy-source] ตัวเดียวกับที่หน้าใช้');
    assert_true(
        str_contains($js, 'navigator.clipboard.writeText(') && str_contains($js, "document.execCommand('copy')"),
        'ต้องเรียกจริงทั้ง clipboard API และทางสำรอง execCommand (ระบบภายในองค์กรหลายที่รันบน http ล้วน)'
    );
    assert_true(str_contains($js, 'isSecureContext'), 'ต้องเช็ค isSecureContext ก่อนใช้ clipboard API ไม่ใช่รอให้ throw');
});
