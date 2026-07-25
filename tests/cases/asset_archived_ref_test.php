<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Services\AssetService;

// bug-hunt R6-A1 (owner decision: keep archive, plug the leaks): an asset whose category/location/department/
// custodian was later archived (is_active=0) must KEEP that value when edited. Before, the edit form's dropdowns
// listed only active rows, so the archived current value had no <option>, the blank placeholder was selected, and
// saving any unrelated field silently nulled the association. getAssetFormReferenceData now unions in the asset's
// current archived refs, so the form shows the value (labeled ปิดใช้งาน) and validation accepts it on save.

function aar_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('asset(R6-A1): editing an asset keeps its archived department instead of silently dropping it', function (): void {
    $svc = tvm_container()->get(AssetService::class);
    $admin = ['id' => 1, 'role' => 'admin'];
    $ref = tvm_container()->get(AssetRepository::class)->getAssetFormReferenceData();
    $catId = (int) $ref['categories'][0]['id'];
    $locId = (int) $ref['locations'][0]['id'];

    // an active department, then an asset bound to it
    $deptCode = 'AARD' . strtoupper(bin2hex(random_bytes(3)));
    aar_pdo()->prepare('INSERT INTO departments (code, name, description, is_active, created_at, updated_at) VALUES (?, ?, "", 1, NOW(), NOW())')
        ->execute([$deptCode, 'AAR Dept ' . $deptCode]);
    $deptId = (int) aar_pdo()->lastInsertId();

    $code = 'AAR-' . strtoupper(bin2hex(random_bytes(3)));
    aar_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, department_id, location_id, status, created_at, updated_at) VALUES (?, 'AAR Asset', ?, ?, ?, 'active', NOW(), NOW())")
        ->execute([$code, $catId, $deptId, $locId]);
    $assetId = (int) aar_pdo()->lastInsertId();

    try {
        // archive the department AFTER the asset is already bound to it
        aar_pdo()->prepare('UPDATE departments SET is_active = 0 WHERE id = ?')->execute([$deptId]);

        // 1) the edit form still shows the archived department (as a labeled option), not a blank
        $form = $svc->getEditFormData($assetId, $admin);
        assert_true($form !== null, 'edit form loads');
        $deptOption = null;
        foreach ($form['departments'] as $d) {
            if ((int) $d['id'] === $deptId) {
                $deptOption = $d;
            }
        }
        assert_true($deptOption !== null, 'the archived department is still an option in the edit form (not dropped)');
        assert_true(str_contains((string) $deptOption['label'], 'ปิดใช้งาน'), 'the archived option is labeled ปิดใช้งาน');
        assert_same((string) $deptId, (string) $form['defaults']['department_id'], "the form keeps the asset's current department selected");

        // 2) saving an unrelated field (notes) with the archived department still bound preserves it — no silent null
        $svc->updateAsset($assetId, $admin, [
            'asset_code' => $code,
            'name' => 'AAR Asset',
            'asset_category_id' => (string) $catId,
            'department_id' => (string) $deptId, // the value the fixed form submits
            'location_id' => (string) $locId,
            'status' => 'active',
            'notes' => 'edited an unrelated field',
            'original_version' => 1,
        ]);
        assert_same($deptId, (int) aar_pdo()->query("SELECT department_id FROM assets WHERE id = $assetId")->fetchColumn(), 'the archived department is preserved after the edit (was silently nulled before the fix)');
    } finally {
        aar_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        aar_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

// bug-hunt R6 Part-3 invariant (owner: reports must still count archived-category assets): an ACTIVE asset whose
// category/location was later archived must NOT vanish from the registry list or the export. The queries INNER JOIN
// asset_categories/locations on id WITHOUT an is_active filter, so they don't drop it — this locks that so a future
// "AND ac.is_active = 1" regression is caught.
test('asset(R6 invariant): an active asset in an archived category still appears in the registry list + export', function (): void {
    $repo = tvm_container()->get(AssetRepository::class);
    $catCode = 'AARC' . strtoupper(bin2hex(random_bytes(3)));
    aar_pdo()->prepare('INSERT INTO asset_categories (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute([$catCode, 'AAR Cat ' . $catCode]);
    $catId = (int) aar_pdo()->lastInsertId();
    $locId = (int) $repo->getAssetFormReferenceData()['locations'][0]['id'];
    $code = 'AARINV-' . strtoupper(bin2hex(random_bytes(3)));
    aar_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, 'AAR Inv', ?, ?, 'active', NOW(), NOW())")
        ->execute([$code, $catId, $locId]);
    $assetId = (int) aar_pdo()->lastInsertId();

    try {
        aar_pdo()->prepare('UPDATE asset_categories SET is_active = 0 WHERE id = ?')->execute([$catId]);

        $page = $repo->getAssetListPage(1, 100, ['q' => $code]);
        $codes = array_map(static fn (array $r): string => (string) ($r['asset_code'] ?? ''), $page['items']);
        assert_true(in_array($code, $codes, true), 'the asset in an archived category still appears in the registry list');

        $export = $repo->getAssetsForExport(['q' => $code]);
        $exportCodes = array_map(static fn (array $r): string => (string) ($r['asset_code'] ?? ''), $export);
        assert_true(in_array($code, $exportCodes, true), 'the asset in an archived category is still exported');
    } finally {
        aar_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        aar_pdo()->prepare('DELETE FROM asset_categories WHERE id = ?')->execute([$catId]);
    }
});
