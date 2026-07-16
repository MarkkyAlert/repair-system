<?php

declare(strict_types=1);

use App\Repositories\AdminRepository;

// perf-review F8: the admin "users" tab paginates (each row renders a full edit form), so the page/DOM is
// bounded as the org grows. getUsersPage returns one page + the true total; getUserFilterOptions still lists
// every user for the audit "filter by user" dropdown. Uses a small perPage against the seeded users so it is
// deterministic without inserting dozens of rows.

function aup_repo(): AdminRepository
{
    return tvm_container()->get(AdminRepository::class);
}

test('F8 (admin users): getUsersPage returns one bounded page plus the true total', function (): void {
    $total = (int) tvm_container()->get(PDO::class)->query('SELECT COUNT(*) FROM users')->fetchColumn();
    assert_true($total >= 3, 'the seeded test DB has several users to page through');

    $page1 = aup_repo()->getUsersPage(1, 2);
    assert_same($total, (int) $page1['total'], 'total is the full user count, not the page size');
    assert_same(2, count($page1['items']), 'page 1 holds exactly perPage rows');
    assert_same((int) ceil($total / 2), (int) $page1['totalPages'], 'totalPages = ceil(total / perPage)');

    // page 2 continues where page 1 left off — no overlap
    $page2 = aup_repo()->getUsersPage(2, 2);
    $page1Ids = array_map(static fn (array $u): int => (int) $u['id'], $page1['items']);
    $page2Ids = array_map(static fn (array $u): int => (int) $u['id'], $page2['items']);
    assert_same([], array_values(array_intersect($page1Ids, $page2Ids)), 'page 2 does not repeat page 1 rows');

    // an out-of-range page clamps to the last page (never empties or errors)
    $beyond = aup_repo()->getUsersPage(9999, 2);
    assert_same((int) $page1['totalPages'], (int) $beyond['page'], 'a page past the end clamps to the last page');
});

test('F8 (admin users): getUserFilterOptions lists every user for the audit filter, regardless of the tab page size', function (): void {
    $total = (int) tvm_container()->get(PDO::class)->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $options = aup_repo()->getUserFilterOptions();

    assert_same($total, count($options), 'the audit user-filter dropdown still sees ALL users, not just one page');
    assert_true(array_key_exists('full_name', $options[0]) && array_key_exists('id', $options[0]), 'options carry id + name');
});
