<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;

// Tests for the Post-Setup Admin Checklist (admin-only card on /dashboard). Verifies the role gate
// (non-admins get an empty checklist), the admin checklist shape (5 items in order + done_count/total/
// complete), and the countTechnicians() detection signal (active-only). Per-item done-detection depends
// on global config/settings and is verified live; here we lock structure + the one countable signal.

function scl_service(): TicketService
{
    return tvm_container()->get(TicketService::class);
}

test('setup checklist: hidden for non-admins, 5 ordered items for admin', function (): void {
    foreach (['requester', 'technician', 'manager', 'guest'] as $role) {
        $dashboard = scl_service()->getDashboardData(['id' => 1, 'role' => $role], []);
        assert_same([], $dashboard['setupChecklist'], "$role must not see the setup checklist");
    }

    $checklist = scl_service()->getDashboardData(['id' => 4, 'role' => 'admin'], [])['setupChecklist'];
    assert_same(['mail', 'logo', 'users', 'data', 'cron'], array_column($checklist['items'], 'key'), '5 items in expected order');
    assert_same(5, (int) $checklist['total'], 'total is 5');
    assert_true(is_int($checklist['done_count']) && $checklist['done_count'] >= 0 && $checklist['done_count'] <= 5, 'done_count within 0..5');
    assert_same($checklist['done_count'] === 5, $checklist['complete'], 'complete flag equals (done_count===5)');
    foreach ($checklist['items'] as $item) {
        assert_true(is_bool($item['done']) && $item['href'] !== '' && $item['label'] !== '', 'each item has done bool + href + label');
    }
});

test('setup checklist: countTechnicians counts active technicians only', function (): void {
    $reads = tvm_container()->get(TicketReadRepository::class);
    $pdo = tvm_container()->get(PDO::class);
    $before = $reads->countTechnicians();
    $rid = bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', 'เทสช่าง', 'technician', 1)")
        ->execute(["sct_$rid", "sct_$rid@x.t"]);
    $id = (int) $pdo->lastInsertId();

    try {
        assert_same($before + 1, $reads->countTechnicians(), 'active technician increments the count');
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        assert_same($before, $reads->countTechnicians(), 'inactive technician is excluded');
    } finally {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
});
