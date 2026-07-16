<?php

declare(strict_types=1);

use App\Services\AssetService;

// perf-review F1: the asset QR print sheet renders up to 500 PNGs — one request per <img>. generateQrPng must
// cache rendered bytes on the private filesystem (storage/qr-cache), keyed by asset id + token, so repeat
// requests serve the cached file instead of re-rendering (the measured ~8.7s/500 wall-time cost). Regenerating
// the token must purge the stale file so a new code never serves the old image.

function qrc_service(): AssetService
{
    return tvm_container()->get(AssetService::class);
}

function qrc_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

/** @return list<string> */
function qrc_cache_files(int $assetId): array
{
    return glob(storage_path('qr-cache/' . $assetId . '-*.png')) ?: [];
}

function qrc_clear(int $assetId): void
{
    foreach (qrc_cache_files($assetId) as $file) {
        @unlink($file);
    }
}

test('F1 (qr cache): the second render of the same asset is served from cache, not re-rendered', function (): void {
    $assetId = 1;
    qrc_clear($assetId);

    try {
        // first call renders and writes exactly one cache file
        $first = qrc_service()->generateQrPng($assetId, qrc_admin());
        assert_true(strlen($first) > 0, 'first call returns PNG bytes');
        $files = qrc_cache_files($assetId);
        assert_same(1, count($files), 'the first render writes one cache file');

        // Overwrite the cache with a sentinel. A cache-READ (the fix) returns exactly this; a RE-RENDER
        // ignores the file and returns real PNG bytes. So this pins "served from cache, not re-rendered".
        file_put_contents($files[0], 'CACHED-SENTINEL-BYTES');

        $second = qrc_service()->generateQrPng($assetId, qrc_admin());
        assert_same('CACHED-SENTINEL-BYTES', $second, 'the second call must serve the cached file, not re-render');
    } finally {
        qrc_clear($assetId);
    }
});

test('F1 (qr cache): regenerating the token purges the stale cached PNG', function (): void {
    $assetId = 1;
    $pdo = tvm_container()->get(PDO::class);
    qrc_clear($assetId);
    // snapshot the currently-active token so we can fully restore it (regenerate deactivates it + inserts a new row)
    $tokenFloor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM asset_qr_tokens')->fetchColumn();
    $activeStmt = $pdo->prepare('SELECT id FROM asset_qr_tokens WHERE asset_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $activeStmt->execute([$assetId]);
    $activeTokenId = (int) $activeStmt->fetchColumn();

    try {
        qrc_service()->generateQrPng($assetId, qrc_admin());
        assert_same(1, count(qrc_cache_files($assetId)), 'a cache file exists for the current token');

        // a new token means the old cached image is stale — regenerate must remove it
        qrc_service()->regenerateQrToken($assetId, qrc_admin());
        assert_same(0, count(qrc_cache_files($assetId)), 'regenerating the token purged the stale cache file');
    } finally {
        // restore fully: drop the row the regenerate inserted AND re-activate the original token it deactivated,
        // so findAssetById (which filters is_active = 1) still resolves asset 1's token for later tests
        $pdo->prepare('DELETE FROM asset_qr_tokens WHERE asset_id = ? AND id > ?')->execute([$assetId, $tokenFloor]);
        $pdo->prepare('UPDATE asset_qr_tokens SET is_active = 1 WHERE id = ?')->execute([$activeTokenId]);
        qrc_clear($assetId);
    }
});
