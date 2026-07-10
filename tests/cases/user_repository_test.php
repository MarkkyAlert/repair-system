<?php
declare(strict_types=1);

use App\Repositories\UserRepository;

// hasActiveAdmin / firstActiveAdmin hold the admin-existence queries that used to be raw SQL inside
// SetupController (moved to the repository so no query text lives in a controller). The setup gate
// (SetupController::hasActiveAdmin → new UserRepository) still delegates here, and the setup_gate
// tests exercise the boolean both ways; these lock the repository methods directly, including the
// role='admin' AND is_active=1 filter and the earliest-id ordering.

function ur_repo(): UserRepository
{
    return tvm_container()->get(UserRepository::class);
}

test('UserRepository.hasActiveAdmin: true when the seed DB has an active admin', function (): void {
    assert_true(ur_repo()->hasActiveAdmin(), 'seed DB has at least one active admin');
});

test('UserRepository.firstActiveAdmin: returns the earliest active admin (id/username/email), filtered by role+active', function (): void {
    $admin = ur_repo()->firstActiveAdmin();

    assert_true(is_array($admin), 'returns a row when an admin exists');
    assert_true((int) ($admin['id'] ?? 0) > 0, 'row carries an id');
    assert_true(array_key_exists('username', $admin), 'row carries username');
    assert_true(array_key_exists('email', $admin), 'row carries email');

    $pdo = tvm_container()->get(PDO::class);

    // The returned user must actually be an active admin (proves the WHERE filter, non-circularly).
    $check = $pdo->query('SELECT role, is_active FROM users WHERE id = ' . (int) $admin['id'])->fetch(PDO::FETCH_ASSOC);
    assert_same('admin', (string) ($check['role'] ?? ''), 'returned user is an admin');
    assert_same(1, (int) ($check['is_active'] ?? 0), 'returned user is active');

    // ...and it must be the EARLIEST such admin (proves ORDER BY id ASC LIMIT 1).
    $earliest = (int) $pdo
        ->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1")
        ->fetchColumn();
    assert_same($earliest, (int) $admin['id'], 'returns the earliest active admin');
});
