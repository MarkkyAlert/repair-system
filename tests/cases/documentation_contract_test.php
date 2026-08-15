<?php

declare(strict_types=1);

function doc_contract(string $path): string
{
    return (string) file_get_contents(BASE_PATH . '/' . $path);
}

test('docs: every local Markdown link resolves to a shipped file', function (): void {
    $paths = array_merge(
        glob(BASE_PATH . '/*.md') ?: [],
        glob(BASE_PATH . '/docs/*.md') ?: [],
        [BASE_PATH . '/e2e/README.md', BASE_PATH . '/tests/README.md']
    );

    foreach ($paths as $absolutePath) {
        $source = (string) file_get_contents($absolutePath);
        preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $source, $matches);
        foreach ($matches[1] ?? [] as $rawTarget) {
            $target = trim((string) $rawTarget, " <>\t\n\r\0\x0B");
            if ($target === '' || str_starts_with($target, '#') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
                continue;
            }
            $targetPath = rawurldecode(explode('#', $target, 2)[0]);
            $resolved = dirname($absolutePath) . '/' . $targetPath;
            assert_true(file_exists($resolved), str_replace(BASE_PATH . '/', '', $absolutePath) . " has a broken local link: $target");
        }
    }
});

test('docs: the buyer-facing report inventory matches the ten runtime report pages', function (): void {
    $guide = doc_contract('app/Views/reports/guide.php');
    preg_match_all("/'route'\\s*=>\\s*'(\\/reports(?:\\/[^']*)?)'/", $guide, $matches);
    $runtimeRoutes = array_values(array_unique($matches[1] ?? []));

    assert_count(10, $runtimeRoutes);
    assert_true(in_array('/reports', $runtimeRoutes, true), 'the overview/SLA-compliance report is a real report page');
    assert_true(in_array('/reports/executive', $runtimeRoutes, true), 'executive is a separate report page');

    foreach (['README.md', 'REPORT-GUIDE.md', 'CHANGELOG.md'] as $path) {
        $doc = doc_contract($path);
        assert_contains_str('รายงาน 10', $doc, "$path states the measured runtime count");
        assert_false(str_contains($doc, 'รายงาน 9'), "$path must not collapse overview and executive back into nine");
        foreach (['ผลงานทีมช่าง', 'พื้นที่ปัญหา', 'งานค้างตามอายุ', 'งานเปิดซ้ำ', 'ความพึงพอใจ', 'สุขภาพทรัพย์สิน'] as $screenTitle) {
            assert_contains_str($screenTitle, $doc, "$path uses the same report name buyers see in the runtime menu: $screenTitle");
        }
    }
    assert_contains_str('report overview / SLA compliance', doc_contract('docs/uat-report-face-validity.md'), 'UAT covers the tenth page too');
    assert_contains_str('## 4 เรื่องที่ผู้บริหารต้องรู้', doc_contract('REPORT-GUIDE.md'), 'the heading count matches the four numbered interpretation cautions');
});

test('docs: role descriptions preserve the real admin/manager boundaries', function (): void {
    $policy = doc_contract('app/Services/TicketPolicy.php');
    $preview = doc_contract('app/Services/AdminService.php');
    $userGuide = doc_contract('USER-GUIDE.md');
    $adminGuide = doc_contract('ADMIN-GUIDE.md');
    $testingGuide = doc_contract('docs/testing-guide.md');

    assert_contains_str("['technician']", $preview, 'hands-on work is assigned only to the technician role in the runtime preview');
    assert_contains_str('Role::TECHNICIAN', $policy, 'TicketPolicy independently enforces the same role boundary');
    foreach ([$userGuide, $adminGuide, $testingGuide] as $doc) {
        assert_true(
            str_contains($doc, 'ไม่รับ/เริ่ม/resolve')
                || str_contains($doc, 'ไม่รับ/เริ่ม/สรุปผลซ่อม')
                || str_contains($doc, 'รับงาน เริ่มงาน และสรุปผลซ่อมแทนช่างไม่ได้'),
            'docs must say admin cannot perform assigned technician work'
        );
        assert_false(str_contains($doc, 'ทำได้ทุกอย่าง'), 'the old over-broad admin promise must not return');
        assert_false(str_contains($doc, 'ทุกสิทธิ์ของ role อื่น'), 'admin is not a superset of the technician actor');
    }
    assert_contains_str('ทั้งองค์กร', $testingGuide, 'manager visibility is system-wide, not an invented department scope');
    assert_contains_str('ปิดงานสถานะรอตรวจรับแทนผู้แจ้ง', $preview, 'the in-app role matrix includes the admin-only deadlock escape');
    assert_contains_str('สรุปผลซ่อมเพื่อส่งรอตรวจรับ', $preview, 'the role matrix must not call technician resolve the final closure');

    $ticketService = doc_contract('app/Services/TicketService.php');
    $ticketView = doc_contract('app/Views/tickets/show.php');
    assert_contains_str('รับงานและสรุปผลซ่อมส่งตรวจรับ', $ticketService, 'the setup checklist must describe the technician stopping at requester acceptance');
    assert_contains_str('ส่งให้ผู้แจ้งตรวจรับ', $ticketView, 'the resolve form must not tell the technician that resolve is final closure');

    $createView = doc_contract('app/Views/tickets/create.php');
    assert_contains_str('คิวผู้อนุมัติส่วนกลาง', $createView, 'the create screen describes the queue the runtime really uses');
    assert_false(str_contains($createView, 'คิวอนุมัติของแผนก'), 'the UI must not invent department-scoped approval');
    assert_contains_str('คิวผู้อนุมัติส่วนกลาง', $testingGuide, 'the testing guide expects the same notification audience as NotificationService');
});

test('docs: SLA inheritance, cycle freeze and per-round labor match runtime hints', function (): void {
    $categoryView = doc_contract('app/Views/admin/tabs/categories.php');
    $ticketView = doc_contract('app/Views/tickets/show.php');
    $userGuide = doc_contract('USER-GUIDE.md');
    $reportGuide = doc_contract('REPORT-GUIDE.md');

    assert_contains_str('เว้นว่าง = ใช้ SLA จาก priority', $categoryView, 'blank inherits priority');
    assert_contains_str('ใส่ 0 = ปิด SLA', $categoryView, 'zero explicitly disables SLA');
    assert_contains_str('เริ่ม SLA รอบใหม่', $ticketView, 'reopen starts a new cycle rather than rewriting the old one');
    assert_contains_str('ผลของรอบเดิมจะเก็บไว้', $ticketView, 'the user-visible hint discloses historical preservation');
    assert_contains_str('เวลาที่ใช้รอบนี้', $userGuide, 'technicians are told not to re-enter cumulative labor');
    assert_contains_str('งวดปิดแล้วกับตัวเลขสด', $reportGuide, 'buyers can distinguish immutable period metrics from live snapshots');
    assert_contains_str('still-`pending`', doc_contract('docs/as-reported-analytics.md'), 'the technical lineage doc states that reassign cannot reset a settled SLA verdict');

    $customize = doc_contract('CUSTOMIZE.md');
    assert_contains_str('สืบทอดค่าจากระดับความสำคัญ', $customize, 'the customization guide explains what a blank category SLA means');
    assert_contains_str('ผลจริงคือปิดการติดตาม SLA', $customize, 'the customization guide warns buyers that zero is not an unspecified value');
});

test('docs: cron wording distinguishes live SLA calculation from persisted alerts', function (): void {
    $ticketReads = doc_contract('app/Repositories/TicketReadRepository.php');
    $ticketService = doc_contract('app/Services/TicketService.php');

    assert_contains_str("status = 'pending' AND ts.target_at < NOW()", $ticketReads, 'live queues classify a pending past-due track without waiting for cron');
    assert_contains_str('$targetTimestamp < time()', $ticketService, 'ticket detail also classifies a pending past-due track at read time');

    foreach (['README.md', 'INSTALL.md', 'REPORT-GUIDE.md', 'docs/testing-guide.md'] as $path) {
        $doc = doc_contract($path);
        assert_false(str_contains($doc, 'SLA จะไม่ถูกนับว่าเกินกำหนด'), "$path must not claim cron owns the live overdue calculation");
    }
    assert_contains_str('หน้าจอและรายงานยังเทียบกำหนดเวลาแล้วแสดงว่าเกินได้', doc_contract('INSTALL.md'), 'INSTALL explains the observable no-cron behavior');
    assert_contains_str('บันทึกผล `breached`', doc_contract('REPORT-GUIDE.md'), 'the report guide explains what cron actually persists');
});

test('docs: archived references and demo-state wording match the data-preservation rules', function (): void {
    $adminGuide = doc_contract('ADMIN-GUIDE.md');
    $setupView = doc_contract('app/Views/setup/index.php');
    $import = doc_contract('app/Services/AssetImportService.php');

    assert_contains_str('รหัสหมวด/สถานที่/แผนกที่ปิดใช้งานแล้วยังนำกลับเข้าได้', $adminGuide, 'archive round-trips are documented');
    assert_contains_str('เว้นผู้ดูแลไว้พร้อมคำเตือน', $adminGuide, 'deactivated custodians are not silently reassigned');
    assert_contains_str('findDeactivatedLogins', $import, 'the runtime distinguishes a departed custodian from an unknown username');
    assert_contains_str('ไม่สร้างสถานะ closed', $setupView, 'the demo description states the reserved-state exception');
    // Anchored on the over-promise itself ("covers EVERY status"), not on the sentence it once appeared in.
    // The old literal quoted the English noun; once that noun was translated the assertion could never fail
    // again and would have kept passing while saying nothing.
    assert_false(str_contains($setupView, 'ครอบทุกสถานะ'), 'the setup wizard must not promise impossible demo coverage — closed is deliberately never produced');
});

test('docs: owner-only commercial placeholders are explicit instead of silently looking complete', function (): void {
    $license = doc_contract('LICENSE.md');
    $readme = doc_contract('README.md');

    foreach (['[ชื่อผู้ขาย / ชื่อบริษัท / นามปากกา]', '[ปี]', '[อีเมล / ช่องทางติดต่อ]'] as $placeholder) {
        assert_contains_str($placeholder, $license, "LICENSE intentionally leaves $placeholder for the owner");
    }
    assert_contains_str('ก่อนส่งมอบ/ขาย — เจ้าของต้องเติมข้อมูลจริงเอง', $readme, 'README must not let a buyer mistake the license template for a completed agreement');
    assert_contains_str('ไม่รวมบริการ support และไม่รวม update', $license, 'the default support contract is explicit');
    assert_contains_str('ไม่รวม support/update เว้นแต่ตกลงแยก', $readme, 'README must summarize the same default support contract as LICENSE');
});
