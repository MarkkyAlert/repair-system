<?php
declare(strict_types=1);

// Tests for CSV/spreadsheet formula-injection neutralisation (sanitize_export_cell). A cell whose first
// non-whitespace character is = + - or @ is a formula to Excel/Sheets; the guard prefixes it with a single
// quote so it renders as text. This used to be duplicated verbatim in AssetService and ReportService; it is
// now the one shared helper both exporters call, so a single set of assertions locks the guard. Regression
// target: drop the prefix and a crafted "=cmd|'/c calc'!A1" cell executes on open.

test('export-cell(deny): a leading = + - @ is neutralised with a single-quote prefix', function (): void {
    assert_same("'=cmd()", sanitize_export_cell('=cmd()'), '= formula neutralised');
    assert_same("'+1234", sanitize_export_cell('+1234'), '+ neutralised');
    assert_same("'-2+3", sanitize_export_cell('-2+3'), '- neutralised');
    assert_same("'@SUM(A1)", sanitize_export_cell('@SUM(A1)'), '@ neutralised');
    // the trigger char is the first NON-whitespace one, so a leading space does not smuggle a formula in
    assert_same("' =evil", sanitize_export_cell(' =evil'), 'leading-space formula still neutralised');
});

test('export-cell(allow): ordinary text and non-leading operators are passed through unchanged', function (): void {
    assert_same('เครื่องพิมพ์ ชั้น 3', sanitize_export_cell('เครื่องพิมพ์ ชั้น 3'), 'plain text untouched');
    assert_same('123', sanitize_export_cell('123'), 'a plain number is left alone');
    assert_same('a=b', sanitize_export_cell('a=b'), '= not in the first position is safe');
    assert_same('', sanitize_export_cell(''), 'an empty cell stays empty');
});
