<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;

// Locks the QR-regenerate active-token invariant (AssetRepository::regenerateQrToken): each regenerate
// deactivates the current active token (is_active = 0) inside a transaction — behind a FOR UPDATE lock on
// the asset row — before inserting the new one, so an asset always has exactly ONE active QR token.
// Regression target: skip the deactivate and every regenerate leaves another active token behind (a scan
// becomes ambiguous and stale tokens stay valid).

function aqr_repo(): AssetRepository
{
    return tvm_container()->get(AssetRepository::class);
}

function aqr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function aqr_active_count(int $assetId): int
{
    $stmt = aqr_pdo()->prepare('SELECT COUNT(*) FROM asset_qr_tokens WHERE asset_id = ? AND is_active = 1');
    $stmt->execute([$assetId]);

    return (int) $stmt->fetchColumn();
}

test('qrRegenerate(invariant): regenerating twice leaves exactly one active token (the newest)', function (): void {
    $ref = aqr_repo()->getAssetFormReferenceData();
    $catId = (int) ($ref['categories'][0]['id'] ?? 0);
    $locId = (int) ($ref['locations'][0]['id'] ?? 0);
    $code = 'AQR-' . strtoupper(bin2hex(random_bytes(3)));
    aqr_pdo()->prepare(
        "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at)
         VALUES (?, 'QR Asset', ?, ?, 'active', NOW(), NOW())"
    )->execute([$code, $catId, $locId]);
    $assetId = (int) aqr_pdo()->lastInsertId();

    try {
        $token1 = aqr_repo()->regenerateQrToken($assetId, null);
        assert_same(1, aqr_active_count($assetId), 'the first regenerate leaves exactly one active token');

        $token2 = aqr_repo()->regenerateQrToken($assetId, null);
        assert_same(1, aqr_active_count($assetId), 'the second regenerate still leaves exactly one active token (old deactivated)');
        assert_true($token1 !== $token2, 'each regenerate mints a distinct token');

        $activeToken = (string) aqr_pdo()->query(
            "SELECT token FROM asset_qr_tokens WHERE asset_id = $assetId AND is_active = 1"
        )->fetchColumn();
        assert_same($token2, $activeToken, 'the surviving active token is the newest one');
    } finally {
        aqr_pdo()->prepare('DELETE FROM asset_qr_tokens WHERE asset_id = ?')->execute([$assetId]);
        aqr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
    }
});
