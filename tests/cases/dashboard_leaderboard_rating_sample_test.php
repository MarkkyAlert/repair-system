<?php

declare(strict_types=1);

use App\Core\View;
use App\Services\TicketService;

// AN-02 follow-up (2026-07-26): the same low-data trap survived on a third surface — the dashboard's
// "ช่างเทคนิคดีเด่น" leaderboard. It printed a bare score, so 5.0 earned from ONE review outranked and outshone a
// 4.8 earned from forty. That is the most-viewed page in the product and the table is explicitly a ranking of
// PEOPLE, so the sample size matters more here than anywhere else. Unlike the report surfaces, the leaderboard
// query did not even select a review count, so the fix reaches into the repository.
//
// The label also gated on avg > 0, which meant a technician whose reviews were ALL the lowest score rendered '-'
// ("no reviews yet") instead of the bad score they actually earned — the worst possible direction for the error.

function dlr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function dlr_tech(string $sfx, string $tag): int
{
    dlr_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
                        VALUES (?, ?, "x", ?, "technician", 1, NOW(), NOW())')
        ->execute(["dlr_{$tag}_$sfx", "dlr_{$tag}_$sfx@x.test", "DlrTech$tag $sfx"]);

    return (int) dlr_pdo()->lastInsertId();
}

/** A completed ticket assigned to $techId, optionally rated. */
function dlr_ticket(string $no, int $techId, ?int $score): void
{
    $requested = date('Y-m-d H:i:s', strtotime('-3 days'));
    $resolved = date('Y-m-d H:i:s', strtotime('-2 days'));
    $locationId = (int) dlr_pdo()->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) dlr_pdo()->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) dlr_pdo()->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    dlr_pdo()->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id,
            priority_id, assigned_technician_id, status, approval_status, requested_at, resolved_at, completed_at,
            created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, ?, "completed", "approved", ?, ?, ?, NOW(), NOW())'
    )->execute([$no, 'dlr', $locationId, $catId, $priId, $techId, $requested, $resolved, $resolved]);

    if ($score !== null) {
        dlr_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
                            VALUES (?, 1, ?, 1, ?, "x", ?, ?)')
            ->execute([(int) dlr_pdo()->lastInsertId(), $techId, $score, $resolved, $resolved]);
    }
}

/** The leaderboard row for a technician name, as the dashboard builds it. */
function dlr_row(string $fullName): ?array
{
    $dashboard = tvm_container()->get(TicketService::class)->getDashboardData(['id' => 4, 'role' => 'admin']);
    foreach (($dashboard['highlights']['topTechnicians'] ?? []) as $row) {
        if ((string) ($row['name'] ?? '') === $fullName) {
            return $row;
        }
    }

    return null;
}

function dlr_cleanup(string $sfx, array $techIds): void
{
    dlr_pdo()->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["DLR-$sfx-%"]);
    foreach ($techIds as $id) {
        dlr_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}

test('dashboard leaderboard: a 5.0 from ONE review discloses its sample size on the rendered page', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $techId = dlr_tech($sfx, 'One');
    $name = "DlrTechOne $sfx";

    try {
        dlr_ticket("DLR-$sfx-1", $techId, 5);

        $row = dlr_row($name);
        assert_true($row !== null, 'the technician reaches the leaderboard');
        assert_same('5.0', (string) $row['avg_rating_label'], 'the score itself is unchanged');
        assert_same(1, (int) ($row['rating_count'] ?? -1), 'the leaderboard payload now carries the review count');

        $html = View::capture('dashboard/index', tvm_container()->get(TicketService::class)
            ->getDashboardData(['id' => 4, 'role' => 'admin']));
        assert_contains_str('จาก 1 รีวิว', $html, 'the rendered dashboard tells the reader the 5.0 came from a single review');
    } finally {
        dlr_cleanup($sfx, [$techId]);
    }
});

test('dashboard leaderboard: all-lowest reviews show the real bad score, not "-" (no data)', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $techId = dlr_tech($sfx, 'Low');
    $name = "DlrTechLow $sfx";

    try {
        // every review is the lowest possible score — a real, damning result that must not read as "no data"
        dlr_ticket("DLR-$sfx-1", $techId, 1);
        dlr_ticket("DLR-$sfx-2", $techId, 1);

        $row = dlr_row($name);
        assert_true($row !== null, 'the technician reaches the leaderboard');
        assert_same(
            '1.0',
            (string) $row['avg_rating_label'],
            'an all-1-star average is a real score — showing "-" would hide the worst result behind "no reviews yet"'
        );
        assert_same(2, (int) ($row['rating_count'] ?? -1), 'and it is backed by two reviews');

        // The discriminating case for the gate itself. With scores validated to 1..5 an average can never reach
        // 0 through the UI, so `avg > 0` looked harmless — but ticket_ratings.score is TINYINT UNSIGNED with no
        // CHECK, so an import or migration can store 0. Under the old gate that period read '-' ("nobody has
        // reviewed") while in truth every reviewer had given the floor score. Presence must come from the base.
        dlr_pdo()->prepare('UPDATE ticket_ratings SET score = 0 WHERE technician_id = ?')->execute([$techId]);
        $zero = dlr_row($name);
        assert_same(
            '0.0',
            (string) $zero['avg_rating_label'],
            'a stored zero is a real (terrible) score, not "no data" — the label gates on the review count, not the value'
        );
        assert_same(2, (int) ($zero['rating_count'] ?? -1), 'still two reviews behind it');
    } finally {
        dlr_cleanup($sfx, [$techId]);
    }
});

test('dashboard leaderboard: a technician with no reviews still reads "-" with a zero base', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $techId = dlr_tech($sfx, 'None');
    $name = "DlrTechNone $sfx";

    try {
        dlr_ticket("DLR-$sfx-1", $techId, null); // work done, nobody rated it

        $row = dlr_row($name);
        assert_true($row !== null, 'the technician reaches the leaderboard');
        assert_same('-', (string) $row['avg_rating_label'], 'genuinely no reviews = no score');
        assert_same(0, (int) ($row['rating_count'] ?? -1), 'with an honest zero base');
    } finally {
        dlr_cleanup($sfx, [$techId]);
    }
});
