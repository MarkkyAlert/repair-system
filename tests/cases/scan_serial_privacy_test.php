<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Services\AssetService;

// Owner decision (authz audit 2026-07-25): the asset SERIAL must be hidden from unauthenticated QR scanners by
// default — a guest confirms they scanned the right asset from its name + location, not the serial number.
// getScanData($token, $showSerial) blanks the serial at the data layer when $showSerial is false, so it never
// reaches the guest response or page; an authorized viewer (logged-in staff) or the admin setting
// scan_show_serial_to_guest opts it back in. The controller computes $showSerial = auth OR setting.

function ssp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('scan(privacy): a guest scan hides the serial; an authorized scan shows it', function (): void {
    $repo = tvm_container()->get(AssetRepository::class);
    $svc = tvm_container()->get(AssetService::class);
    $ref = $repo->getAssetFormReferenceData();
    $cat = (int) $ref['categories'][0]['id'];
    $loc = (int) $ref['locations'][0]['id'];
    $serial = 'SN-SECRET-' . strtoupper(bin2hex(random_bytes(3)));

    ssp_pdo()->prepare("INSERT INTO assets (asset_code, name, serial_number, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, 'Scan Privacy', ?, ?, ?, 'active', NOW(), NOW())")
        ->execute(['SSP-' . strtoupper(bin2hex(random_bytes(3))), $serial, $cat, $loc]);
    $assetId = (int) ssp_pdo()->lastInsertId();

    try {
        $token = $repo->regenerateQrToken($assetId, null);

        // guest (showSerial=false): name + location are present, serial is blanked out
        $guest = $svc->getScanData($token, false);
        assert_true($guest !== null, 'the scan resolves the asset');
        assert_same('', (string) $guest['asset']['serial_number'], 'a guest scan does NOT carry the serial number');
        assert_same('Scan Privacy', (string) $guest['asset']['name'], 'the guest still sees the asset name (to confirm the asset)');
        assert_true((string) $guest['asset']['location_label'] !== '', 'the guest still sees the location');

        // authorized (showSerial=true): the real serial is returned
        $staff = $svc->getScanData($token, true);
        assert_same($serial, (string) $staff['asset']['serial_number'], 'an authorized viewer (logged-in staff / admin-enabled) sees the real serial');
    } finally {
        ssp_pdo()->prepare('DELETE FROM asset_qr_tokens WHERE asset_id = ?')->execute([$assetId]);
        ssp_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
    }
});
