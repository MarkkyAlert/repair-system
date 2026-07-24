<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;

// Locks the asset-update optimistic lock (AssetRepository::updateAsset): the UPDATE carries
// WHERE id = ? AND version = :original_version and SET version = version + 1, so a stale edit form (an
// original_version that no longer matches the row) matches zero rows → the re-query confirms the row moved on →
// DomainException, leaving the newer value intact. The integer version increments on every write, so unlike the
// former second-precision updated_at token it also rejects a stale edit that lands in the SAME second (F1).
// Regression target: drop the version guard/increment and a stale edit silently overwrites a newer one.

function aupd_repo(): AssetRepository
{
    return tvm_container()->get(AssetRepository::class);
}

function aupd_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** Seed an asset with a known, deliberately-old updated_at; returns [id, base payload with valid FKs]. */
function aupd_seed(): array
{
    $ref = tvm_container()->get(AssetRepository::class)->getAssetFormReferenceData();
    $catId = (int) ($ref['categories'][0]['id'] ?? 0);
    $locId = (int) ($ref['locations'][0]['id'] ?? 0);
    $code = 'AUPD-' . strtoupper(bin2hex(random_bytes(3)));

    aupd_pdo()->prepare(
        "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at)
         VALUES (?, 'Original Name', ?, ?, 'active', NOW(), '2020-01-01 00:00:00')"
    )->execute([$code, $catId, $locId]);
    $id = (int) aupd_pdo()->lastInsertId();

    $base = [
        'asset_code' => $code,
        'name' => 'Original Name',
        'serial_number' => null,
        'asset_category_id' => $catId,
        'department_id' => null,
        'location_id' => $locId,
        'custodian_user_id' => null,
        'brand' => null,
        'model' => null,
        'vendor' => null,
        'purchase_date' => null,
        'warranty_expires_at' => null,
        'status' => 'active',
        'notes' => null,
    ];

    return [$id, $base];
}

function aupd_service(): \App\Services\AssetService
{
    return tvm_container()->get(\App\Services\AssetService::class);
}

test('asset(validation): a duplicate asset_code and a warranty-before-purchase date are rejected (round M1)', function (): void {
    // Code correctness already there (AssetRepository maps the UNIQUE violation to a friendly message;
    // validateAssetInput rejects warranty < purchase) — this locks both branches with a regression test.
    [$id, $base] = aupd_seed();
    $admin = ['id' => 1, 'role' => 'admin'];

    try {
        // duplicate asset_code (same as the seeded asset)
        $dup = false;
        try {
            aupd_service()->createAsset($admin, $base);
        } catch (DomainException $e) {
            $dup = str_contains($e->getMessage(), 'มีอยู่ในระบบแล้ว');
        }
        assert_true($dup, 'a duplicate asset_code is rejected with a friendly message');

        // warranty expiry before purchase date
        $badDates = false;
        try {
            aupd_service()->createAsset($admin, array_merge($base, [
                'asset_code' => 'AUPDD-' . strtoupper(bin2hex(random_bytes(3))),
                'purchase_date' => '2024-06-01',
                'warranty_expires_at' => '2024-01-01',
            ]));
        } catch (DomainException) {
            $badDates = true;
        }
        assert_true($badDates, 'warranty expiry before purchase date is rejected');
    } finally {
        aupd_pdo()->prepare("DELETE FROM assets WHERE asset_code LIKE 'AUPDD-%'")->execute();
        aupd_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
});

test('asset(permission): requester/technician cannot create, update or regenerate assets — manager/admin only', function (): void {
    // The controller (store/update/regenerateQr) has AuthMiddleware + CSRF but NO role gate — AssetService::
    // assertManageable (is_manager_or_admin) is the only line of defense. Lock it so dropping that guard is
    // caught: a non-manager passing VALID input must still be rejected before any DB mutation.
    [$id, $base] = aupd_seed();
    $createInput = array_merge($base, ['asset_code' => 'AUPDP-' . strtoupper(bin2hex(random_bytes(3)))]);

    try {
        foreach (['requester', 'technician'] as $role) {
            $viewer = ['id' => 1, 'role' => $role];
            $blocked = 0;
            try {
                aupd_service()->createAsset($viewer, $createInput);
            } catch (DomainException) {
                $blocked++;
            }
            try {
                aupd_service()->updateAsset($id, $viewer, $base);
            } catch (DomainException) {
                $blocked++;
            }
            try {
                aupd_service()->regenerateQrToken($id, $viewer);
            } catch (DomainException) {
                $blocked++;
            }
            assert_same(3, $blocked, "$role must be blocked from all three asset-manage actions");
        }
        assert_same('Original Name', (string) aupd_pdo()->query("SELECT name FROM assets WHERE id = $id")->fetchColumn(), 'the guard fired before any mutation');
    } finally {
        aupd_pdo()->prepare("DELETE FROM assets WHERE asset_code LIKE 'AUPDP-%'")->execute();
        aupd_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
});

test('asset(validation F6): optional text fields over their DB column length are rejected with a friendly message', function (): void {
    [$id, $base] = aupd_seed();
    $admin = ['id' => 4, 'role' => 'admin'];

    try {
        foreach (['brand' => 100, 'model' => 100, 'serial_number' => 100, 'vendor' => 150] as $field => $limit) {
            $threw = false;
            try {
                aupd_service()->updateAsset($id, $admin, array_merge($base, ['original_version' => 1, $field => str_repeat('x', $limit + 1)]));
            } catch (DomainException $e) {
                $threw = str_contains($e->getMessage(), 'ยาวเกินกำหนด');
            }
            assert_true($threw, "$field over $limit is rejected with a friendly message");
        }
        assert_same('Original Name', (string) aupd_pdo()->query("SELECT name FROM assets WHERE id = $id")->fetchColumn(), 'no mutation happened on any rejected update');
    } finally {
        aupd_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
});

test('assetUpdate(optimistic-lock): a fresh update succeeds; a stale one is rejected and does not overwrite', function (): void {
    [$id, $base] = aupd_seed();

    try {
        // update #1 — original_version (1) matches the seeded row → the WHERE matches → succeeds, version → 2.
        // Both updates run in the same wall-clock second: with the old second-precision updated_at token the
        // stale update #2 could have slipped through; the integer version rejects it regardless of timing (F1).
        aupd_repo()->updateAsset($id, array_merge($base, ['name' => 'First Update', 'original_version' => 1]));
        assert_same(
            'First Update',
            (string) aupd_pdo()->query("SELECT name FROM assets WHERE id = $id")->fetchColumn(),
            'the fresh update lands'
        );

        // update #2 — carries the SAME, now-stale original_version (1); the row is at version 2 → rejected
        $rejected = false;
        try {
            aupd_repo()->updateAsset($id, array_merge($base, ['name' => 'Stale Overwrite', 'original_version' => 1]));
        } catch (DomainException $e) {
            $rejected = true;
            assert_contains_str('ถูกแก้ไขโดยผู้ใช้อื่น', $e->getMessage(), 'the stale update reports a conflict');
        }
        assert_true($rejected, 'a stale optimistic-lock update must be rejected');
        assert_same(
            'First Update',
            (string) aupd_pdo()->query("SELECT name FROM assets WHERE id = $id")->fetchColumn(),
            'the stale update did NOT overwrite the newer value (no lost update)'
        );
    } finally {
        aupd_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
});

// bug-hunt A2 (2nd pass): AssetService::validateAssetInput checked asset_code/name length with strlen (BYTES),
// while the column is VARCHAR (characters) and the IMPORT path already uses mb_strlen (LOW#12). A Thai character
// is 3 bytes, so a Thai name well within 200 characters was wrongly rejected on the create/edit FORM. Now mb_strlen.
test('asset(validation) A2: a long Thai name within the 200-char limit is accepted on the create form (bytes != chars)', function (): void {
    [$id, $base] = aupd_seed();
    $admin = ['id' => 1, 'role' => 'admin'];
    $thaiName = str_repeat('ก', 100); // 100 characters = 300 bytes: valid by chars, over-limit by bytes
    assert_true(mb_strlen($thaiName) <= 200 && strlen($thaiName) > 200, 'probe name is >200 bytes but <=200 characters');

    $newId = 0;
    try {
        $newId = aupd_service()->createAsset($admin, array_merge($base, [
            'asset_code' => 'AUPDT-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => $thaiName,
        ]));
        assert_true($newId > 0, 'a 100-character Thai name is within the 200-char limit — the asset is created, not rejected as too long');
        assert_same($thaiName, (string) aupd_pdo()->query("SELECT name FROM assets WHERE id = $newId")->fetchColumn(), 'the full Thai name is stored');
    } finally {
        if ($newId > 0) {
            aupd_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$newId]); // cascades qr tokens
        }
        aupd_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
});
