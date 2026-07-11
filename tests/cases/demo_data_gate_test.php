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

        assert_true($caught instanceof RuntimeException, 'load() throws a RuntimeException when demo data is disabled');
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
