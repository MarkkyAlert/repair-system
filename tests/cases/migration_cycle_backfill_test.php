<?php

declare(strict_types=1);

// ⭐ Migration backfill (ChatGPT R10-F2) — the per-cycle upgrade (database/migrate_sla_rating_cycle.sql) must place
// a LEGACY multi-resolve ticket's single surviving SLA/rating snapshot on its LATEST cycle (1 + reopen count), not
// leave it at cycle 1. The old pre-upgrade code overwrote the snapshot in place, so the one row reflects the most
// recent resolve. If the migration left it at cycle 1, the as-reported trend would read that latest snapshot in the
// FIRST period the ticket closed and show the latest period blank — moving history to the wrong month.
//
// This drives the migration file's ACTUAL backfill UPDATE statements (extracted from the .sql, table names swapped
// to temp tables) so the test breaks if the file's backfill logic is weakened — a genuine power-proof of the file.

function migcycle_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('migration(cycle): the file backfill moves a reopened ticket\'s snapshot to its latest cycle, leaves others at 1 (R10-F2)', function (): void {
    $pdo = migcycle_pdo();

    // synthetic ids kept in temp tables only (never touch real fixtures)
    $reopened = 901; // closed → reopened once → re-closed  ⇒ latest cycle = 1 + 1 = 2
    $control = 902;  // closed once, never reopened          ⇒ stays cycle 1

    // temp tables mirror the PRE-backfill state (column just added with DEFAULT 1, snapshots still at cycle 1)
    $pdo->exec('CREATE TEMPORARY TABLE mig_logs (ticket_id INT NOT NULL, action VARCHAR(50) NOT NULL)');
    $pdo->exec('CREATE TEMPORARY TABLE mig_sla (ticket_id INT NOT NULL, metric_type VARCHAR(20) NOT NULL, cycle INT NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TEMPORARY TABLE mig_ratings (ticket_id INT NOT NULL, cycle INT NOT NULL DEFAULT 1)');

    try {
        // reopened ticket: two resolve events + one reopen; one surviving SLA row per metric + one rating (all cycle 1)
        $pdo->prepare('INSERT INTO mig_logs (ticket_id, action) VALUES (?, ?), (?, ?), (?, ?)')
            ->execute([$reopened, 'ticket_resolved', $reopened, 'ticket_reopened', $reopened, 'ticket_resolved']);
        $pdo->prepare('INSERT INTO mig_sla (ticket_id, metric_type, cycle) VALUES (?, ?, 1), (?, ?, 1)')
            ->execute([$reopened, 'response', $reopened, 'resolution']);
        $pdo->prepare('INSERT INTO mig_ratings (ticket_id, cycle) VALUES (?, 1)')->execute([$reopened]);

        // control ticket: one resolve, never reopened
        $pdo->prepare('INSERT INTO mig_logs (ticket_id, action) VALUES (?, ?)')->execute([$control, 'ticket_resolved']);
        $pdo->prepare('INSERT INTO mig_sla (ticket_id, metric_type, cycle) VALUES (?, ?, 1)')->execute([$control, 'resolution']);
        $pdo->prepare('INSERT INTO mig_ratings (ticket_id, cycle) VALUES (?, 1)')->execute([$control]);

        // ── run the migration file's OWN backfill UPDATEs (table names remapped onto the temp tables) ──
        $migration = (string) file_get_contents(BASE_PATH . '/database/upgrades/migrate_sla_rating_cycle.sql');
        preg_match_all('/UPDATE\b.*?;/is', $migration, $matches);
        $backfills = array_values(array_filter($matches[0], static fn (string $s): bool => str_contains($s, 'latest_cycle')));
        assert_same(2, count($backfills), 'the migration carries two latest-cycle backfill UPDATEs (SLA + ratings)');
        foreach ($backfills as $stmt) {
            $stmt = strtr($stmt, [
                'ticket_sla_tracks' => 'mig_sla',
                'ticket_ratings' => 'mig_ratings',
                'ticket_activity_logs' => 'mig_logs',
            ]);
            $pdo->exec(rtrim(trim($stmt), ';'));
        }

        // reopened ticket: BOTH metric rows + the rating land on cycle 2 (latest = 1 + one reopen)
        $slaCycles = $pdo->query("SELECT metric_type, cycle FROM mig_sla WHERE ticket_id = $reopened")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(2, (int) $slaCycles['resolution'], 'reopened ticket: resolution snapshot backfilled to its latest cycle (2), not left at 1');
        assert_same(2, (int) $slaCycles['response'], 'reopened ticket: response snapshot moves in lockstep to cycle 2');
        assert_same(2, (int) $pdo->query("SELECT cycle FROM mig_ratings WHERE ticket_id = $reopened")->fetchColumn(), 'reopened ticket: rating snapshot backfilled to cycle 2');

        // never-reopened ticket: untouched at cycle 1 (the bulk of real data)
        assert_same(1, (int) $pdo->query("SELECT cycle FROM mig_sla WHERE ticket_id = $control")->fetchColumn(), 'never-reopened ticket stays at cycle 1');
        assert_same(1, (int) $pdo->query("SELECT cycle FROM mig_ratings WHERE ticket_id = $control")->fetchColumn(), 'never-reopened rating stays at cycle 1');
    } finally {
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS mig_logs');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS mig_sla');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS mig_ratings');
    }
});
