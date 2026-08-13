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

// The reports write "-" into any cell that has no data (empty is not zero). It starts with a minus, so the
// guard used to prefix it, and every one of those cells reached the reader as "'-" — 27 of them in a single
// twelve-ticket month across the overview, executive and asset CSVs. The .xlsx exports had the same artefact
// until they moved to explicit cell types; CSV kept it.
//
// A lone "-" cannot be a formula: there is nothing after the operator for a spreadsheet to evaluate. So the
// prefix bought no safety and cost the reader a stray apostrophe in every blank cell. Anything with a payload
// after the dash ("-2+3", "-1+1") is still guarded — that is the case the guard exists for.
test('export-cell: the "no data" dash ships clean, while a dash with a payload is still guarded', function (): void {
    assert_same('-', sanitize_export_cell('-'), 'the no-data marker carries no apostrophe into the file');
    // padding is left exactly as the caller wrote it — only the apostrophe decision looks past the spaces
    assert_same(' - ', sanitize_export_cell(' - '), 'a padded dash is passed through, still without a prefix');

    // the guard still covers everything that could actually evaluate
    assert_same("'-2+3", sanitize_export_cell('-2+3'), 'a dash with a payload is still a formula');
    assert_same("'-1+1", sanitize_export_cell('-1+1'), 'and so is the classic injection shape');
    assert_same("'-cmd|'/c calc'!A1", sanitize_export_cell("-cmd|'/c calc'!A1"), 'the DDE shape stays neutralised');

    // negative numbers were already exempt and must stay exempt (they are values, not formulas)
    assert_same('-1.5', sanitize_export_cell('-1.5'), 'a negative number is still a number');

    // files exported before this change still import cleanly: the import rule is untouched
    assert_same('-', unsanitize_import_cell("'-"), 'an older file that guarded the dash still round-trips back to "-"');
});

test('export-cell round-trip: a genuine apostrophe before a formula opener is preserved', function (): void {
    foreach (["'=SUM(A1:A2)", "'+66 81 234 5678", "'-2+3", "'@Home", "''=literal"] as $original) {
        $exported = sanitize_export_cell($original);
        assert_same("'" . $original, $exported, "the genuine apostrophe is escaped for '{$original}'");
        assert_same($original, unsanitize_import_cell($exported), "round-trip restores '{$original}' byte-for-byte");
    }

    assert_same("'hello", sanitize_export_cell("'hello"), 'an apostrophe before ordinary text needs no escape');
    assert_same("'hello", unsanitize_import_cell("'hello"), 'ordinary apostrophe-prefixed text is not stripped');
    assert_same("'-1", sanitize_export_cell("'-1"), 'an apostrophe before a plain negative number is not formula-like');
    assert_same("'-1", unsanitize_import_cell("'-1"), 'a genuine apostrophe before a negative number is preserved');
});
