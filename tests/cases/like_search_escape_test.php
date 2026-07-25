<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;

// bug-hunt R5-3: ticket/asset search wrapped the term in %...% and bound it, but the bound value still carried raw
// LIKE metacharacters. `_` means "any single character" in MySQL LIKE, and asset codes/serials commonly contain it,
// so searching serial "SR<tok>_9" also returned "SR<tok>X9" (and "SR<tok>A9", …) — a wrong result set. like_escape()
// now neutralizes \ % _ so the term matches literally (sql_mode uses \ as LIKE's default escape, no ESCAPE clause).

function lse_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function lse_seed_asset(string $code, string $serial): int
{
    $cat = (int) lse_pdo()->query('SELECT COALESCE((SELECT id FROM asset_categories LIMIT 1), 1)')->fetchColumn();
    $loc = (int) lse_pdo()->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
    lse_pdo()->prepare('INSERT INTO assets (asset_code, name, serial_number, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, "LSE", ?, ?, ?, "active", NOW(), NOW())')
        ->execute([$code, $serial, $cat, $loc]);

    return (int) lse_pdo()->lastInsertId();
}

test('search(R5-3): an underscore in the asset search term is a literal, not a wildcard', function (): void {
    $repo = tvm_container()->get(AssetRepository::class);
    $tok = bin2hex(random_bytes(4));
    $literalSerial = "SR{$tok}_9"; // what the user actually typed
    $decoySerial = "SR{$tok}X9";   // over-matched by the un-escaped pattern (_ = any single char)
    $ids = [];

    try {
        $ids[] = lse_seed_asset("LSEA-$tok", $literalSerial);
        $ids[] = lse_seed_asset("LSEB-$tok", $decoySerial);

        $page = $repo->getAssetListPage(1, 50, ['q' => $literalSerial]);
        $serials = array_map(static fn (array $r): string => (string) ($r['serial_number'] ?? ''), $page['items']);

        assert_true(in_array($literalSerial, $serials, true), 'the literal-serial asset is found');
        assert_true(!in_array($decoySerial, $serials, true), 'the decoy (SR..X9) is NOT matched — the _ is escaped, not a wildcard');
        assert_same(1, (int) $page['total'], 'exactly one asset matches the escaped term (was 2 with the wildcard)');
    } finally {
        foreach ($ids as $id) {
            lse_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
        }
    }
});
