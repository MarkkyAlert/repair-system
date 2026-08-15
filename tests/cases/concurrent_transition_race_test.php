<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;

// Two people acting on the same ticket in the same second is ordinary in a busy office: a manager and an admin
// both working the approval queue, or one person double-clicking. Every write path guards against it by locking
// the ticket row (FOR UPDATE) and re-reading the status under that lock before deciding.
//
// That guard was completely unprotected. Deleting the FOR UPDATE left the whole suite green — 825 of 825 — and
// only a genuine race exposed the damage: a manager and an admin approving at the same instant BOTH succeeded,
// writing two approvals into one ticket's history. A single-threaded test cannot see this, because on its own
// the re-read still returns the right answer; only real concurrency tells the truth. The UPDATE statements do
// not restate the expected status either, so this re-read is the only thing standing between the two clicks.
//
// The race below runs real, separately-connected processes released by a shared clock. It can only fail when a
// second actor genuinely gets through, so it never goes red on timing alone — if the two happen not to overlap,
// the round is simply inconclusive and the next one runs.

/** Launch $actors racing the same transition; returns ['ok' => n, 'denied' => n]. */
function ctr_race(int $ticketId, string $action, array $actors): array
{
    $workerPath = tempnam(sys_get_temp_dir(), 'ctr_') . '.php';
    file_put_contents($workerPath, <<<'PHP'
        <?php
        declare(strict_types=1);
        $_ENV['DB_NAME'] = getenv('DB_NAME') ?: 'repair_system_test';
        require BOOTSTRAP_PATH;
        [$s, $ticketId, $action, $actorId, $role, $startAt] = $argv;
        $wf = app(App\Services\TicketWorkflowService::class);
        $viewer = ['id' => (int) $actorId, 'role' => $role];
        while (microtime(true) < (float) $startAt) { usleep(200); }
        try {
            match ($action) {
                'approve' => $wf->approveTicket((int) $ticketId, $viewer, ['note' => '']),
                'accept' => $wf->acceptAssignedWork((int) $ticketId, $viewer, ['accept_note' => '']),
            };
            echo "OK";
        } catch (Throwable $e) {
            echo "DENIED";
        }
        PHP);
    file_put_contents($workerPath, str_replace('BOOTSTRAP_PATH', var_export(BASE_PATH . '/bootstrap.php', true), (string) file_get_contents($workerPath)));

    // one shared release instant, far enough out that every child is already spinning on the barrier
    $startAt = microtime(true) + 1.6;
    $procs = [];
    foreach ($actors as $index => [$actorId, $role]) {
        $cmd = 'DB_NAME=repair_system_test ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath)
            . ' ' . (int) $ticketId . ' ' . escapeshellarg($action) . ' ' . (int) $actorId . ' ' . escapeshellarg($role)
            . ' ' . escapeshellarg((string) $startAt);
        $procs[$index] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$index]);
    }

    $results = ['ok' => 0, 'denied' => 0];
    foreach ($procs as $index => $proc) {
        if (!is_resource($proc)) {
            continue;
        }
        $out = (string) stream_get_contents($pipes[$index][1]);
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        proc_close($proc);
        $results[str_contains($out, 'OK') ? 'ok' : 'denied']++;
    }
    @unlink($workerPath);

    return $results;
}

function ctr_seed_pending_ticket(): int
{
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    return tvm_container()->get(TicketService::class)->createTicket(['id' => 1, 'role' => 'requester'], [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'concurrent approval race',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);
}

test('race: a manager and an admin approving at the same instant produce ONE approval, not two', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $ticketIds = [];

    try {
        // two rounds: a round where the processes miss each other proves nothing, so give it a second chance
        for ($round = 1; $round <= 2; $round++) {
            $ticketId = ctr_seed_pending_ticket();
            $ticketIds[] = $ticketId;

            $result = ctr_race($ticketId, 'approve', [[4, 'admin'], [2, 'manager']]);

            $logs = $pdo->prepare("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id = ? AND action = 'ticket_approved'");
            $logs->execute([$ticketId]);
            $approvedLines = (int) $logs->fetchColumn();

            $status = $pdo->prepare('SELECT status, approval_status FROM tickets WHERE id = ?');
            $status->execute([$ticketId]);
            $ticket = $status->fetch(PDO::FETCH_ASSOC) ?: [];

            assert_same(1, $result['ok'], "round {$round}: exactly one of the two clicks may win");
            assert_same(1, $result['denied'], "round {$round}: the other is refused, not silently accepted");
            assert_same(1, $approvedLines, "round {$round}: the ticket's history records ONE approval — two would be a permanent lie about what happened");
            assert_same('approved', (string) ($ticket['status'] ?? ''), "round {$round}: and the ticket ends up correctly approved");

            $tracks = $pdo->prepare('SELECT COUNT(*) FROM ticket_sla_tracks WHERE ticket_id = ?');
            $tracks->execute([$ticketId]);
            assert_same(2, (int) $tracks->fetchColumn(), "round {$round}: one response deadline and one resolution deadline — not two sets");
        }
    } finally {
        foreach ($ticketIds as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

test('race: the guard that makes this safe is still in the code', function (): void {
    // The race above is the real proof, but it needs concurrency to bite. This states the mechanism plainly so
    // a reader who removes the lock sees why the other test fails, instead of assuming it is flaky.
    $src = (string) file_get_contents(BASE_PATH . '/app/Repositories/TicketRepository.php');

    assert_true(
        (bool) preg_match('/SELECT \$columns\s+FROM tickets\s+WHERE id = :ticket_id\s+LIMIT 1\s+FOR UPDATE/', $src),
        'the shared transition guard still re-reads the ticket row under FOR UPDATE before deciding'
    );
    assert_contains_str(
        'สถานะงานแจ้งซ่อมถูกเปลี่ยนแล้ว',
        $src,
        'and still refuses the loser with a message telling them to refresh, rather than writing a second transition'
    );
});
