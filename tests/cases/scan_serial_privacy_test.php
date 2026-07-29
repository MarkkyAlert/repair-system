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

// ── B-6: ทรัพย์สินที่ปลดระวางแล้วต้องสแกนดูได้ แต่แจ้งซ่อมไม่ได้ ──
// เดิม query ของหน้าสแกนกรอง status IN (active, maintenance) ทรัพย์สินที่ปลดระวาง/จำหน่ายจึงได้ 404 ทั้งที่
// สติกเกอร์ QR ยังติดอยู่บนของจริง — คนที่เดินตรวจนับจะนึกว่าสติกเกอร์เสียหรือระบบล่ม. ขณะเดียวกันหน้ารายละเอียด
// ก็ยังโชว์ปุ่ม "แจ้งปัญหา" ให้ ซึ่งพาไปหน้าสร้าง ticket ที่ไม่มีทรัพย์สินตัวนี้ในรายการเลือก = ได้ ticket ที่ไม่ผูก
// กับทรัพย์สินแบบเงียบ ๆ
test('scan B-6: a retired asset still scans (for stock-taking) but is not reportable', function (): void {
    $repo = tvm_container()->get(AssetRepository::class);
    $svc = tvm_container()->get(AssetService::class);
    $ref = $repo->getAssetFormReferenceData();
    $code = 'B6R-' . strtoupper(bin2hex(random_bytes(3)));

    ssp_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, 'B6 Retired', ?, ?, 'active', NOW(), NOW())")
        ->execute([$code, (int) $ref['categories'][0]['id'], (int) $ref['locations'][0]['id']]);
    $assetId = (int) ssp_pdo()->lastInsertId();

    try {
        $token = $repo->regenerateQrToken($assetId, null);

        $live = $svc->getScanData($token, false);
        assert_true((bool) $live['is_reportable'], 'sanity: an active asset is reportable');

        foreach (['retired', 'disposed'] as $status) {
            ssp_pdo()->prepare('UPDATE assets SET status = ? WHERE id = ?')->execute([$status, $assetId]);
            $scan = $svc->getScanData($token, false);

            assert_true($scan !== null, "a $status asset still resolves — the sticker on the shelf must not 404");
            assert_same('B6 Retired', (string) $scan['asset']['name'], "and still identifies itself so it can be counted");
            assert_false((bool) $scan['is_reportable'], "but a $status asset cannot be reported against");
            assert_contains_str(
                asset_status_label_th($status),
                (string) $scan['asset']['status'],
                'the scan says plainly what state it is in'
            );
        }
    } finally {
        ssp_pdo()->prepare('DELETE FROM asset_qr_tokens WHERE asset_id = ?')->execute([$assetId]);
        ssp_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
    }
});

test('scan B-6: a guest still cannot FILE a request against a retired asset', function (): void {
    $repo = tvm_container()->get(AssetRepository::class);
    $guests = tvm_container()->get(App\Services\GuestTicketService::class);
    $ref = $repo->getAssetFormReferenceData();
    $code = 'B6G-' . strtoupper(bin2hex(random_bytes(3)));

    ssp_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, 'B6 Guest', ?, ?, 'retired', NOW(), NOW())")
        ->execute([$code, (int) $ref['categories'][0]['id'], (int) $ref['locations'][0]['id']]);
    $assetId = (int) ssp_pdo()->lastInsertId();

    try {
        $token = $repo->regenerateQrToken($assetId, null);

        $threw = false;
        try {
            $guests->submitGuestRequest($token, [
                'guest_name' => 'ผู้สแกน',
                'guest_email' => 'b6@example.com',
                'title' => 'ลองแจ้งของที่ปลดระวาง',
                'description' => 'x',
                'form_token' => bin2hex(random_bytes(32)),
            ], '203.0.113.66');
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'viewing is allowed, but actually filing against a retired asset is still refused — the submit path is unchanged');
    } finally {
        ssp_pdo()->prepare('DELETE FROM guest_ticket_requests WHERE asset_id = ?')->execute([$assetId]);
        ssp_pdo()->prepare('DELETE FROM asset_qr_tokens WHERE asset_id = ?')->execute([$assetId]);
        ssp_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
    }
});
