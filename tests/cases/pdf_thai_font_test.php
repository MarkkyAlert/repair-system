<?php
declare(strict_types=1);

use App\Services\ReportService;
use Smalot\PdfParser\Parser;

// Proves Thai actually RENDERS in exported PDFs (not tofu boxes). The sarabun font is committed to
// resources/fonts and injected via @font-face in ReportExporter::renderPdf, so a fresh deploy
// (composer install, vendor gitignored, no font) still renders Thai. Two levels of proof are needed:
//   - the EMBEDDED font must be Sarabun — this is the real tofu-catcher. If sarabun is unavailable dompdf
//     falls back to DejaVu Sans (which has no Thai glyphs → tofu), and the BaseFont becomes DejaVuSans.
//     (Verified out-of-band: a DejaVu render embeds "DejaVuSans" with no "Sarabun" in the PDF.)
//   - extractable Thai text confirms the Thai content is actually placed (a %PDF- magic-byte check alone
//     passes even for an all-tofu PDF). NOTE: text extraction alone is NOT enough — the ToUnicode map
//     preserves the source text even when the glyphs are tofu, so the embedded-font check is the one that
//     catches a broken font pipeline. (BI-review: PDF artifact validity.)

function ptf_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function ptf_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('pdf export: Thai text renders and is extractable, not tofu (executive PDF end-to-end)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) ptf_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $pdf = (string) ptf_service()->exportExecutiveSummaryPdf($admin, ['preset' => 'month'])['content'];
        assert_same('%PDF-', substr($pdf, 0, 5), 'a valid PDF is produced');

        // tofu-catcher: the Thai font must be EMBEDDED. Fallback to DejaVu (no Thai glyphs) → "DejaVuSans"
        // and no "Sarabun" in the PDF.
        assert_true(
            str_contains($pdf, 'Sarabun'),
            'the PDF embeds the Sarabun font (not a DejaVu fallback = tofu Thai)'
        );

        $text = (new Parser())->parseContent($pdf)->getText();
        assert_true(
            preg_match('/[\x{0E00}-\x{0E7F}]/u', $text) === 1,
            'Thai text is present in the PDF'
        );
        assert_true(
            mb_strpos($text, 'คะแนนเฉลี่ย') !== false,
            'a known Thai KPI label ("คะแนนเฉลี่ย") is placed in the PDF'
        );
    } finally {
        ptf_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
