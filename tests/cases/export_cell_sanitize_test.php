<?php
declare(strict_types=1);

use App\Services\AssetService;
use App\Services\ReportService;

// Tests for CSV/spreadsheet formula-injection neutralisation (sanitizeExportCell). A cell whose first
// non-whitespace character is = + - or @ is a formula to Excel/Sheets; the guard prefixes it with a
// single quote so it renders as text. The logic is duplicated verbatim in AssetService and ReportService,
// so both copies are locked here (via call_private) to catch a regression in either exporter. Regression
// target: drop the prefix and a crafted "=cmd|'/c calc'!A1" cell executes on open.

function ecs_asset(): AssetService
{
    return tvm_container()->get(AssetService::class);
}

function ecs_report(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

/** @return array<int, object> both services that carry the duplicated guard */
function ecs_services(): array
{
    return [ecs_asset(), ecs_report()];
}

test('export-cell(deny): a leading = + - @ is neutralised with a single-quote prefix in both exporters', function (): void {
    foreach (ecs_services() as $svc) {
        $label = $svc::class;
        assert_same("'=cmd()", call_private($svc, 'sanitizeExportCell', ['=cmd()']), "$label: = formula neutralised");
        assert_same("'+1234", call_private($svc, 'sanitizeExportCell', ['+1234']), "$label: + neutralised");
        assert_same("'-2+3", call_private($svc, 'sanitizeExportCell', ['-2+3']), "$label: - neutralised");
        assert_same("'@SUM(A1)", call_private($svc, 'sanitizeExportCell', ['@SUM(A1)']), "$label: @ neutralised");
        // the trigger char is the first NON-whitespace one, so a leading space does not smuggle a formula in
        assert_same("' =evil", call_private($svc, 'sanitizeExportCell', [' =evil']), "$label: leading-space formula still neutralised");
    }
});

test('export-cell(allow): ordinary text and non-leading operators are passed through unchanged', function (): void {
    foreach (ecs_services() as $svc) {
        $label = $svc::class;
        assert_same('เครื่องพิมพ์ ชั้น 3', call_private($svc, 'sanitizeExportCell', ['เครื่องพิมพ์ ชั้น 3']), "$label: plain text untouched");
        assert_same('123', call_private($svc, 'sanitizeExportCell', ['123']), "$label: a plain number is left alone");
        assert_same('a=b', call_private($svc, 'sanitizeExportCell', ['a=b']), "$label: = not in the first position is safe");
        assert_same('', call_private($svc, 'sanitizeExportCell', ['']), "$label: an empty cell stays empty");
    }
});
