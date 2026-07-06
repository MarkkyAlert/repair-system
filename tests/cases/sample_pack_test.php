<?php
declare(strict_types=1);

use App\Services\ReportService;

// Tests for the Report Sample Pack (/reports/sample-pack): ReportService::generateSamplePack bundles
// PDF+Excel of 4 headline reports + a Thai README into a single ZIP. Verifies the ZIP is well-formed
// (entry count, per-file magic bytes, README) and that the manager/admin gate holds. The enriched demo
// seeder (DemoDataService) is verified separately in an isolated DB because load() only runs on a fresh
// (zero-ticket) install, which the shared test DB is not.

function sp_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function sp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('sample pack: ZIP bundles 4 PDF + 4 Excel + README with correct magic bytes', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) sp_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'sp_') . '.zip';

    try {
        $pack = sp_service()->generateSamplePack($admin);
        assert_same('application/zip', $pack['content_type'], 'content type is zip');
        assert_true(str_starts_with((string) $pack['file_name'], 'report-sample-pack-'), 'filename is prefixed');
        assert_same("PK\x03\x04", substr((string) $pack['content'], 0, 4), 'payload has zip magic bytes');

        file_put_contents($tmp, (string) $pack['content']);
        $zip = new ZipArchive();
        assert_true($zip->open($tmp) === true, 'zip opens');
        assert_same(9, $zip->numFiles, '4 pdf + 4 xlsx + README = 9 entries');

        $pdfCount = 0;
        $xlsxCount = 0;
        $hasReadme = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $data = (string) $zip->getFromIndex($i);
            if (str_ends_with($name, '.pdf')) {
                $pdfCount++;
                assert_same('%PDF-', substr($data, 0, 5), "$name is a real PDF");
            } elseif (str_ends_with($name, '.xlsx')) {
                $xlsxCount++;
                assert_same("PK\x03\x04", substr($data, 0, 4), "$name is a real xlsx");
            } elseif ($name === 'README.txt') {
                $hasReadme = true;
                assert_true(str_contains($data, 'ชุดตัวอย่างรายงาน'), 'README carries the Thai cover note');
            }
        }
        $zip->close();
        assert_same(4, $pdfCount, 'four report PDFs');
        assert_same(4, $xlsxCount, 'four report Excel files');
        assert_true($hasReadme, 'README.txt present');
    } finally {
        @unlink($tmp);
        sp_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

test('sample pack: manager/admin gate — requester & technician are blocked', function (): void {
    foreach (['requester', 'technician', 'guest'] as $role) {
        $blocked = false;
        try {
            sp_service()->generateSamplePack(['id' => 1, 'role' => $role]);
        } catch (DomainException) {
            $blocked = true;
        }
        assert_true($blocked, "$role must be blocked from the sample pack (ensureCanViewReports)");
    }
});
