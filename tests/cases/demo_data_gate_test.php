<?php
declare(strict_types=1);

use App\Services\DemoDataService;

// Loading sample data must be gated by ALLOW_DEMO_DATA so an admin cannot seed sample users/assets/
// tickets into a production database (template-review F1). The env gate lives in DemoDataService::load()
// — the single chokepoint both the Setup wizard and POST /admin/demo-data/load go through — and fires
// before the existing zero-ticket guard, i.e. before any write. Config is overridden via the container
// (config() reads app('config')) and restored in finally so the shared config never drifts.
test('demo-data gate F1: load() refuses (before writing) when ALLOW_DEMO_DATA is off', function (): void {
    $container = tvm_container();
    $config = $container->get('config');
    $service = $container->get(DemoDataService::class);
    $pdo = $container->get(PDO::class);
    $ticketsBefore = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();

    $off = $config;
    $off['app']['allow_demo_data'] = false;
    $container->instance('config', $off);
    try {
        $caught = null;
        try {
            $service->load(4);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        assert_true($caught instanceof DomainException, 'load() throws a DomainException (expected policy block, flashed not logged) when demo data is disabled');
        assert_true(
            str_contains($caught instanceof Throwable ? $caught->getMessage() : '', 'ALLOW_DEMO_DATA'),
            'the gate message points the operator at the ALLOW_DEMO_DATA flag'
        );
        assert_same(
            $ticketsBefore,
            (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
            'the env gate fires before any write — ticket count is unchanged'
        );
    } finally {
        $container->instance('config', $config); // restore the shared config for the rest of the suite
    }
});

// error-review-4 F4: seedAssets() caught EVERY Throwable and skipped, so a real DB failure looked like a
// duplicate-skip and the seed "succeeded" with missing assets. Only a DomainException (duplicate asset_code/
// serial, or QR-token exhaustion) is a skippable, expected condition; a RuntimeException/PDOException must
// surface so load()'s outer transaction rolls the whole seed back. Drives seedAssets() directly via reflection
// with a fake AssetRepository (other repos are untouched by this step), so no real writes happen.
function demo_seed_assets(\App\Repositories\AssetRepository $fakeAssets): array
{
    $c = tvm_container();
    $svc = new DemoDataService(
        $c->get(\App\Repositories\AdminRepository::class),
        $fakeAssets,
        $c->get(\App\Repositories\TicketRepository::class),
        $c->get(\App\Repositories\TicketReadRepository::class),
        $c->get(PDO::class),
    );
    $seed = new ReflectionMethod($svc, 'seedAssets');
    $seed->setAccessible(true);
    // maps that cover every spec's category+location so each spec reaches createAsset (not the null-guard skip)
    $cats = ['AC' => 1, 'COMPUTER' => 1, 'LIGHTING' => 1, 'PRINTER' => 1];
    $locs = ['MEETING' => 1, 'OFFICE-2F' => 1, 'SERVER' => 1];

    return [$seed, static fn (): array => $seed->invoke($svc, 1, $cats, $locs)];
}

test('demo-data F4: a duplicate asset (DomainException) is skipped so seeding keeps going', function (): void {
    $fakeDup = new class () extends \App\Repositories\AssetRepository {
        public int $calls = 0;

        public function __construct()
        {
        }

        public function createAsset(array $payload): int
        {
            $this->calls++;

            throw new DomainException('รหัส Asset นี้มีอยู่ในระบบแล้ว');
        }
    };

    [, $run] = demo_seed_assets($fakeDup);
    $caught = null;
    $result = null;
    try {
        $result = $run();
    } catch (Throwable $e) {
        $caught = $e;
    }

    assert_true($caught === null, 'a duplicate is swallowed as an expected skip — seedAssets does not throw');
    assert_same(0, $result[1] ?? -1, 'nothing was seeded (all duplicates), but the step completed');
    assert_true($fakeDup->calls >= 1, 'createAsset WAS attempted — proving the DomainException skip path, not the null-guard skip');
});

test('demo-data F4: an operational failure (RuntimeException) surfaces instead of being swallowed as a skip', function (): void {
    $fakeBroken = new class () extends \App\Repositories\AssetRepository {
        public function __construct()
        {
        }

        public function createAsset(array $payload): int
        {
            throw new RuntimeException('ฐานข้อมูลมีปัญหา'); // a real/operational failure, NOT a duplicate
        }
    };

    [, $run] = demo_seed_assets($fakeBroken);
    $caught = null;
    try {
        $run();
    } catch (Throwable $e) {
        $caught = $e;
    }

    assert_true(
        $caught instanceof RuntimeException,
        'a RuntimeException from createAsset propagates (not caught as a skip) so load() can roll back and report the failure'
    );
});
