<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Services\AssetImportService;

// Tests for AssetImportService (CSV asset import) — mirrors user_import_test but per the ASSET rules.
// Two structural differences from the user importer, verified against the code:
//   1. validateRows does NOT check the DB for a duplicate asset_code/serial (no existing-lookup); an
//      in-DB collision surfaces only at executeImport (createAsset throws → the row is skipped).
//   2. executeImport takes a $viewer and throws DomainException unless the viewer is manager/admin.
// parseUploadedFile is exercised here too (the central is_uploaded_file shadow in tests/shadow_functions.php,
// loaded before every case, makes CLI parsing work regardless of file load order). Everything seeded/imported
// is deleted in finally (asset delete cascades its QR token).

function ai_service(): AssetImportService
{
    return tvm_container()->get(AssetImportService::class);
}

function ai_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function ai_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

/** First category/location/department the importer will resolve — read from the SAME source validateRows uses. */
function ai_ref(): array
{
    $ref = tvm_container()->get(AssetRepository::class)->getAssetFormReferenceData();
    $cat = $ref['categories'][0] ?? [];
    $loc = $ref['locations'][0] ?? [];
    $dep = $ref['departments'][0] ?? [];
    return [
        'cat_id' => (int) ($cat['id'] ?? 0), 'cat_code' => (string) ($cat['code'] ?? ''),
        'loc_id' => (int) ($loc['id'] ?? 0), 'loc_code' => (string) ($loc['code'] ?? ''),
        'dep_id' => (int) ($dep['id'] ?? 0), 'dep_code' => (string) ($dep['code'] ?? ''),
    ];
}

function ai_seed_asset(string $code, array $ref): int
{
    ai_pdo()->prepare('INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, "Seed Asset", ?, ?, "active", NOW(), NOW())')
        ->execute([$code, $ref['cat_id'], $ref['loc_id']]);
    return (int) ai_pdo()->lastInsertId();
}

function ai_delete_assets(array $codes): void
{
    foreach ($codes as $code) {
        ai_pdo()->prepare('DELETE FROM assets WHERE asset_code = ?')->execute([$code]); // cascades asset_qr_tokens
    }
}

/** A fully-valid raw CSV row (valid category/location by default); override a key to break one branch. */
function ai_raw(array $ref, array $overrides = []): array
{
    $s = bin2hex(random_bytes(3));
    return array_merge([
        '_line' => 2,
        'asset_code' => 'IMP-' . strtoupper($s),
        'name' => 'Imp Asset',
        'serial_number' => '',
        'category_code' => $ref['cat_code'],
        'location_code' => $ref['loc_code'],
        'department_code' => '',
        'custodian_username' => '',
        'brand' => '',
        'model' => '',
        'vendor' => '',
        'purchase_date' => '',
        'warranty_expires_at' => '',
        'status' => 'active',
        'notes' => '',
    ], $overrides);
}

/** A validateRows-shaped valid row for executeImport. */
function ai_exec_row(array $ref, array $overrides = []): array
{
    $s = bin2hex(random_bytes(4));
    return array_merge([
        'line' => 20,
        'asset_code' => 'IMPX-' . strtoupper($s),
        'name' => 'Exec Asset',
        'serial_number' => '',
        'asset_category_id' => $ref['cat_id'],
        'department_id' => null,
        'location_id' => $ref['loc_id'],
        'custodian_user_id' => null,
        'brand' => '',
        'model' => '',
        'vendor' => '',
        'purchase_date' => '',
        'warranty_expires_at' => '',
        'status' => 'active',
        'notes' => '',
    ], $overrides);
}

function ai_invalid_for(array $result, int $line): ?array
{
    foreach ($result['invalid'] as $entry) {
        if ((int) $entry['line'] === $line) {
            return $entry;
        }
    }
    return null;
}

function ai_has_error(?array $entry, string $needle): bool
{
    if ($entry === null) {
        return false;
    }
    foreach ($entry['errors'] as $error) {
        if (str_contains((string) $error, $needle)) {
            return true;
        }
    }
    return false;
}

// ── validateRows ──

test('assetImport.validateRows: partitions rows by required / status / length / refs / dates, with line + message', function (): void {
    $ref = ai_ref();
    $rows = [
        ai_raw($ref, ['_line' => 2]),                                                            // fully valid
        ai_raw($ref, ['_line' => 3, 'asset_code' => '', 'name' => '']),                          // required
        ai_raw($ref, ['_line' => 4, 'status' => 'bogus']),                                       // status enum
        ai_raw($ref, ['_line' => 5, 'asset_code' => str_repeat('X', 61)]),                       // asset_code > 60
        ai_raw($ref, ['_line' => 6, 'asset_code' => 'DUPCODE']),                                 // dup-in-file (first)
        ai_raw($ref, ['_line' => 7, 'asset_code' => 'DUPCODE']),                                 // dup-in-file (second → flagged)
        ai_raw($ref, ['_line' => 8, 'category_code' => 'NOCAT_ZZZ']),                            // unknown category
        ai_raw($ref, ['_line' => 9, 'location_code' => 'NOLOC_ZZZ']),                            // unknown location
        ai_raw($ref, ['_line' => 10, 'department_code' => 'NODEPT_ZZZ']),                        // unknown department (optional field, provided)
        ai_raw($ref, ['_line' => 11, 'custodian_username' => 'ghost_user_zzz']),                 // unknown custodian
        ai_raw($ref, ['_line' => 12, 'purchase_date' => '2020/01/01']),                          // bad date format
    ];

    $result = ai_service()->validateRows($rows);

    assert_same(11, (int) $result['total'], 'total counts every row');
    $validLines = array_map(static fn (array $r): int => (int) $r['line'], $result['valid']);
    assert_true(in_array(2, $validLines, true), 'the fully-valid row (line 2) is valid');
    assert_true(in_array(6, $validLines, true), 'the FIRST duplicate asset_code (line 6) stays valid');

    assert_true(ai_has_error(ai_invalid_for($result, 3), 'จำเป็นต้องมี'), 'line 3 → asset_code/name required');
    assert_true(ai_has_error(ai_invalid_for($result, 4), 'status ต้องเป็น'), 'line 4 → invalid status');
    assert_true(ai_has_error(ai_invalid_for($result, 5), 'asset_code ยาวเกิน 60'), 'line 5 → asset_code too long');
    assert_true(ai_has_error(ai_invalid_for($result, 7), 'asset_code ซ้ำกับแถวอื่นในไฟล์'), 'line 7 → duplicate asset_code in file');
    assert_true(ai_has_error(ai_invalid_for($result, 8), 'category_code'), 'line 8 → unknown category_code');
    assert_true(ai_has_error(ai_invalid_for($result, 9), 'location_code'), 'line 9 → unknown location_code');
    assert_true(ai_has_error(ai_invalid_for($result, 10), 'department_code'), 'line 10 → unknown department_code');
    assert_true(ai_has_error(ai_invalid_for($result, 11), 'custodian_username'), 'line 11 → unknown custodian_username');
    assert_true(ai_has_error(ai_invalid_for($result, 12), 'purchase_date'), 'line 12 → bad date format');
});

// ── executeImport ──

test('assetImport.executeImport: an admin imports a valid asset (row inserted + QR token generated)', function (): void {
    $ref = ai_ref();
    $row = ai_exec_row($ref);
    try {
        $result = ai_service()->executeImport([$row], ai_admin());
        assert_same(1, (int) $result['imported'], 'one asset imported');
        assert_same(0, count($result['skipped']), 'nothing skipped');

        $asset = ai_pdo()->query('SELECT id, name, status FROM assets WHERE asset_code = ' . ai_pdo()->quote($row['asset_code']))->fetch(PDO::FETCH_ASSOC);
        assert_true($asset !== false, 'the asset row exists');
        assert_same('Exec Asset', $asset['name'], 'name stored');

        $qr = ai_pdo()->prepare('SELECT COUNT(*) FROM asset_qr_tokens WHERE asset_id = ?');
        $qr->execute([(int) $asset['id']]);
        assert_same(1, (int) $qr->fetchColumn(), 'a QR token was generated for the imported asset');
    } finally {
        ai_delete_assets([$row['asset_code']]);
    }
});

test('assetImport.executeImport: a non-manager viewer is refused (permission guard throws before any write)', function (): void {
    $ref = ai_ref();
    $row = ai_exec_row($ref);
    $threw = false;
    try {
        ai_service()->executeImport([$row], ['id' => 1, 'role' => 'requester']);
    } catch (DomainException $e) {
        $threw = true;
        assert_same('คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR', $e->getMessage());
    }
    assert_true($threw, 'a requester cannot run an asset import');
    // and nothing was written
    $count = ai_pdo()->query('SELECT COUNT(*) FROM assets WHERE asset_code = ' . ai_pdo()->quote($row['asset_code']))->fetchColumn();
    assert_same(0, (int) $count, 'no asset was created when permission was denied');
});

test('assetImport.executeImport(resilience): a row colliding at insert is skipped; the rest still import', function (): void {
    $ref = ai_ref();
    $s = bin2hex(random_bytes(4));
    $clashCode = 'CLASH-' . strtoupper($s);
    $seedId = ai_seed_asset($clashCode, $ref);
    $survivor = ai_exec_row($ref, ['asset_code' => 'OK-' . strtoupper($s), 'line' => 32]);
    try {
        $rows = [
            ai_exec_row($ref, ['asset_code' => $clashCode, 'line' => 31]), // collides on asset_code
            $survivor,
        ];

        $result = ai_service()->executeImport($rows, ai_admin());

        assert_same(1, (int) $result['imported'], 'only the non-colliding asset imported (batch did not abort)');
        assert_same(1, count($result['skipped']), 'exactly one row skipped');
        assert_same($clashCode, (string) $result['skipped'][0]['asset_code'], 'the colliding asset_code is the one skipped');
        assert_same(31, (int) $result['skipped'][0]['line'], 'the skipped entry keeps its line');

        $survivorExists = ai_pdo()->query('SELECT COUNT(*) FROM assets WHERE asset_code = ' . ai_pdo()->quote($survivor['asset_code']))->fetchColumn();
        assert_same(1, (int) $survivorExists, 'the survivor asset was inserted despite the earlier collision');
    } finally {
        ai_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$seedId]);
        ai_delete_assets([$survivor['asset_code']]);
    }
});

// ── parseUploadedFile / ParsesCsvUpload (now covered here too — order-independent since the shadow is central) ──

function ai_tmp_csv(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'aimp_') . '.csv';
    file_put_contents($path, $bytes);
    return $path;
}

function ai_file(string $csv, string $name = 'assets.csv'): array
{
    return ['name' => $name, 'tmp_name' => ai_tmp_csv($csv), 'size' => strlen($csv), 'error' => UPLOAD_ERR_OK];
}

test('assetImport.parseUploadedFile: parses a valid CSV (header/escaping/_line/blank-skip) and rejects a missing column', function (): void {
    $header = 'asset_code,name,serial_number,category_code,location_code,department_code,custodian_username,brand,model,vendor,purchase_date,warranty_expires_at,status,notes';
    $csv = $header . "\n"
        . "AST-1,\"Printer, Color\",SN1,CAT,LOC,,,HP,M1,ACME,2024-01-01,2025-01-01,active,ok\n"
        . "\n" // blank line — skipped
        . "AST-2,Router,SN2,CAT,LOC,,,TP,R2,ACME,,,active,\n";
    $file = ai_file($csv);
    try {
        $rows = ai_service()->parseUploadedFile($file);
        assert_same(2, count($rows), 'the blank line is skipped; two data rows remain');
        assert_same(2, (int) $rows[0]['_line'], 'first data row is line 2 (header is line 1)');
        assert_same('AST-1', $rows[0]['asset_code'], 'columns are keyed by header name');
        assert_same('Printer, Color', $rows[0]['name'], 'a quoted field with a comma is parsed by fgetcsv');
        assert_same(4, (int) $rows[1]['_line'], 'the second data row keeps its real line number (blank line counted)');
    } finally {
        @unlink($file['tmp_name']);
    }

    // header missing the 'notes' column → rejected
    $missing = ai_file("asset_code,name,serial_number,category_code,location_code,department_code,custodian_username,brand,model,vendor,purchase_date,warranty_expires_at,status\nAST-9,X,,CAT,LOC,,,,,,,,active\n");
    try {
        $threw = false;
        try {
            ai_service()->parseUploadedFile($missing);
        } catch (DomainException $e) {
            $threw = true;
            assert_true(str_contains($e->getMessage(), 'ไม่ครบ column'), 'message names the missing column: ' . $e->getMessage());
        }
        assert_true($threw, 'a CSV missing a required column is rejected');
    } finally {
        @unlink($missing['tmp_name']);
    }
});
