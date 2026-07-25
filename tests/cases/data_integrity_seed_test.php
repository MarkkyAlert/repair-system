<?php

declare(strict_types=1);

// Data-integrity regression: import the distributed demo SQL into an isolated database and reject lifecycle
// timestamps that the real workflow cannot produce. This never modifies the shared test or production database.
test('demo-seed(integrity): lifecycle timestamps match assignment, acceptance, and final status', function (): void {
    $config = tvm_container()->get('config')['db'];
    $rootPdo = tvm_container()->get(PDO::class);
    $rootDir = dirname(__DIR__, 2);
    $scratch = 'repair_system_integrity_seed_test';
    $connect = static fn (string $database): PDO => new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    try {
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
        $rootPdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4");
        $database = $connect($scratch);
        $database->exec((string) file_get_contents($rootDir . '/database/schema.sql'));
        $database->exec((string) file_get_contents($rootDir . '/database/seed_reference.sql'));
        $database->exec((string) file_get_contents($rootDir . '/database/seed_demo.sql'));

        assert_same(3, (int) $database->query('SELECT COUNT(*) FROM tickets')->fetchColumn(), 'the real demo pack was imported');

        $violations = (int) $database->query(
            "SELECT COUNT(*)
             FROM tickets t
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE (t.first_response_at IS NOT NULL
                    AND t.assigned_at IS NOT NULL
                    AND t.first_response_at < t.assigned_at)
                OR (t.first_response_at IS NOT NULL
                    AND wo.accepted_at IS NOT NULL
                    AND t.first_response_at <> wo.accepted_at)
                OR (t.status <> 'closed' AND t.closed_at IS NOT NULL)"
        )->fetchColumn();
        assert_same(0, $violations, 'the demo cannot teach reports an impossible lifecycle');

        $completed = $database->query(
            "SELECT status, completed_at, closed_at FROM tickets WHERE ticket_no = 'MT-20260602-0003'"
        )->fetch(PDO::FETCH_ASSOC);
        assert_same('completed', (string) $completed['status'], 'the completed demo example keeps its intended state');
        assert_true($completed['completed_at'] !== null, 'the completed example keeps its completion time');
        assert_true($completed['closed_at'] === null, 'a merely completed example has no later closed-state time');
    } finally {
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    }
});
